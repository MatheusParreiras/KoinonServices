<?php

declare(strict_types=1);

namespace App\Controllers\Community;

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Models\PostComment;
use App\Services\BusinessRuleException;
use App\Services\CommunityService;

/**
 * Comments on posts.
 */
final class CommentController extends CommunityController
{
    /**
     * GET /api/community/posts/{id}/comments: every visible comment of a post.
     * 200 {"status":"success","data":{"comments":[...]}} / 404
     */
    public function index(string $id): Response
    {
        $this->requireRole(self::MEMBERS);

        try {
            $comments = (new CommunityService())->allComments((int) $id);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e);
        }

        return $this->success(['comments' => array_map([$this, 'presentComment'], $comments)]);
    }

    /**
     * POST /api/community/posts/{id}/comments  body: {"body": "..."}
     *
     * CSRF (middleware) → role → 400 malformed JSON → 429 rate limit →
     * 422 validation → 404 if the post is not in this condominium (service) →
     * 201 {"status":"success","data":{"comment":{...},"comments_count":int}}
     */
    public function store(string $id): Response
    {
        $this->requireRole(self::MEMBERS);
        if ($error = $this->malformedBody()) {
            return $error;
        }
        if (!RateLimiter::attempt('community.write', 10, 60)) {
            return $this->failure('Você está comentando rápido demais. Aguarde um minuto.', 429);
        }

        $body = self::cleanText($this->request->string('body'));
        if ($body === '' || mb_strlen($body) > PostComment::BODY_MAX) {
            $message = $body === ''
                ? 'O comentário não pode ficar vazio.'
                : 'O comentário pode ter até ' . PostComment::BODY_MAX . ' caracteres.';

            return $this->failure($message, 422, ['body' => $message]);
        }

        try {
            $result = (new CommunityService())->addComment((int) $id, (int) Auth::id(), $body);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e);
        }

        return $this->success([
            'comment'        => $this->presentComment($result['comment']),
            'comments_count' => $result['comments_count'],
        ], 201);
    }
}
