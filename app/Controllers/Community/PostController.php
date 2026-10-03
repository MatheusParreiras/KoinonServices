<?php

declare(strict_types=1);

namespace App\Controllers\Community;

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostReport;
use App\Models\SocialCategory;
use App\Services\CommunityService;

/**
 * The Community tab: page shell, feed JSON, and post creation.
 */
final class PostController extends CommunityController
{
    private const PAGE_SIZE = 10;
    private const MAX_OFFSET = 5000;

    /** GET /community: the page. Cards are rendered by community/feed.js from the JSON feed. */
    public function index(): Response
    {
        $this->requireRole(self::MEMBERS);

        return $this->view('community/index', [
            'title'        => 'Comunidade',
            'categories'   => (new SocialCategory())->active(),
            'reasons'      => PostReport::REASONS,
            'bodyMax'      => Post::BODY_MAX,
            'commentMax'   => PostComment::BODY_MAX,
            'canModerate'  => Auth::hasRole(self::MODERATION_VIEWERS),
            'activeTab'    => 'feed',
            'showModerationTab' => Auth::hasRole(self::MODERATION_VIEWERS),
            'scripts'      => ['js/community/feed.js'],
        ], layout: 'layouts/community');
    }

    /**
     * GET /api/community/posts?category=pets&offset=10&anchor=123
     *
     * 200 {"status":"success","data":{"posts":[...],"has_more":bool,"next_offset":int,"anchor":int}}
     * 422 unknown category or invalid paging values.
     */
    public function feed(): Response
    {
        $this->requireRole(self::MEMBERS);

        // Category filter: only codes from the allowlist (social_categories) are accepted.
        $categoryId = null;
        $code = (string) $this->request->query('category', '');
        if ($code !== '') {
            $category = (new SocialCategory())->findByCode($code);
            if ($category === null) {
                return $this->failure('Categoria inválida.', 422, ['category' => 'Categoria inválida.']);
            }
            $categoryId = $category['id'];
        }

        $offset = filter_var($this->request->query('offset', '0'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => self::MAX_OFFSET],
        ]);
        $anchor = filter_var($this->request->query('anchor', '0'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if ($offset === false || $anchor === false) {
            return $this->failure('Parâmetros de paginação inválidos.', 422);
        }

        $posts = new Post();
        // First page: pin the feed to the newest post that exists right now.
        $anchor = $anchor === 0 ? $posts->latestId() : $anchor;

        // The viewer is the session user (Auth::id()); "liked"/"reported" are computed for them only.
        $rows = $posts->feed((int) Auth::id(), $categoryId, $anchor, self::PAGE_SIZE + 1, $offset);
        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);

        // ONE extra query for the latest 3 comments of every post on the page.
        $comments = (new PostComment())->latestForPosts(array_map(static fn (array $p): int => (int) $p['id'], $rows));

        return $this->success([
            'posts'       => array_map(fn (array $p): array => $this->presentPost($p, $comments[(int) $p['id']] ?? []), $rows),
            'has_more'    => $hasMore,
            'next_offset' => $offset + count($rows),
            'anchor'      => $anchor,
        ]);
    }

    /**
     * POST /api/community/posts  body: {"category": "pets", "body": "..."}
     *
     * Checks: CSRF (route middleware) → role → JSON well-formed (400) → rate
     * limit (429) → validation (422) → insert with the session's tenant and user.
     * 201 {"status":"success","data":{"post":{...}}}
     */
    public function store(): Response
    {
        $this->requireRole(self::MEMBERS);
        if ($error = $this->malformedBody()) {
            return $error;
        }
        if (!RateLimiter::attempt('community.write', 10, 60)) {
            return $this->failure('Você está publicando rápido demais. Aguarde um minuto.', 429);
        }

        $errors = [];
        $category = (new SocialCategory())->findByCode($this->request->string('category'));
        if ($category === null) {
            $errors['category'] = 'Escolha uma categoria.';
        }
        $body = self::cleanText($this->request->string('body'));
        if ($body === '') {
            $errors['body'] = 'Escreva alguma coisa antes de publicar.';
        } elseif (mb_strlen($body) > Post::BODY_MAX) {
            $errors['body'] = 'A publicação pode ter até ' . Post::BODY_MAX . ' caracteres.';
        }
        if ($errors !== []) {
            return $this->failure('Verifique os campos destacados.', 422, $errors);
        }

        // author = session user; condominium = session tenant (set by TenantModel::insert()).
        $post = (new CommunityService())->createPost((int) Auth::id(), (int) $category['id'], $body);

        return $this->success(['post' => $this->presentPost($post)], 201);
    }
}
