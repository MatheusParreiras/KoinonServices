<?php

declare(strict_types=1);

namespace App\Controllers\Community;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Services\BusinessRuleException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Shared base of the Community (social network) controllers.
 *
 * Every JSON endpoint answers with the same envelope:
 *   success: {"status": "success", "data": {...}}
 *   error:   {"status": "error", "message": "...", "errors"?: {field: message}}
 * with Content-Type: application/json (set by Response::json).
 *
 * JSON carries RAW text (no HTML escaping). The browser inserts it with
 * textContent, which never interprets markup. Escaping here as well would show
 * "&amp;" to users; escaping is the job of the output layer (e() in PHP views,
 * textContent in JS).
 */
abstract class CommunityController extends Controller
{
    /** May read the feed, post, like, comment and report (Phase 1: social.use). */
    public const MEMBERS = ['manager', 'resident'];

    /** May open the moderation view. The Super Admin can look but not act (see Assumptions). */
    public const MODERATION_VIEWERS = [Auth::SUPER_ADMIN, 'manager'];

    /** May delete posts and dismiss reports. */
    public const MODERATORS = ['manager'];

    /**
     * 400 when the client sent a JSON body that is not a JSON object.
     * Call at the start of every action that reads a body.
     */
    protected function malformedBody(): ?Response
    {
        return $this->request->hasMalformedJson()
            ? $this->failure('O corpo da requisição não é um JSON válido.', 400)
            : null;
    }

    /** Turns a business-rule failure (404/409/422) into the error envelope. */
    protected function ruleFailure(BusinessRuleException $e): Response
    {
        $errors = $e->field() !== null ? [$e->field() => $e->getMessage()] : [];

        return $this->failure($e->getMessage(), $e->status(), $errors);
    }

    /**
     * Normalises user text: CRLF becomes LF, invisible control characters are
     * removed (except line breaks and tabs), and the ends are trimmed. This is
     * NOT escaping: the text is stored as typed and escaped at output.
     */
    protected static function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';

        return trim($text);
    }

    /**
     * Post row (Post::CARD_SELECT) to the JSON shape used by community/render.js.
     *
     * Only what the card shows is exposed: no user ids, just "is_me" flags
     * computed against the session user.
     *
     * @param array<string, mixed>             $post
     * @param list<array<string, mixed>>       $latestComments
     * @return array<string, mixed>
     */
    protected function presentPost(array $post, array $latestComments = []): array
    {
        $me = (int) Auth::id();
        $isMine = (int) $post['author_user_id'] === $me;

        return [
            'id'              => (int) $post['id'],
            'body'            => (string) $post['body'],
            'category'        => ['code' => (string) $post['category_code'], 'name' => (string) $post['category_name']],
            'author'          => ['name' => (string) $post['author_name'], 'is_me' => $isMine],
            'created_at'      => self::isoUtc((string) $post['created_at']),
            'likes_count'     => (int) $post['like_count'],
            'comments_count'  => (int) $post['comment_count'],
            'liked'           => (bool) $post['liked_by_me'],
            'reported'        => (bool) $post['reported_by_me'],
            'can_report'      => !$isMine,
            'latest_comments' => array_map([$this, 'presentComment'], $latestComments),
        ];
    }

    /**
     * @param array<string, mixed> $comment
     * @return array<string, mixed>
     */
    protected function presentComment(array $comment): array
    {
        return [
            'id'         => (int) $comment['id'],
            'post_id'    => (int) $comment['post_id'],
            'body'       => (string) $comment['body'],
            'author'     => [
                'name'  => (string) $comment['author_name'],
                'is_me' => (int) $comment['author_user_id'] === (int) Auth::id(),
            ],
            'created_at' => self::isoUtc((string) $comment['created_at']),
        ];
    }

    /** UTC DATETIME from MySQL → ISO 8601 with "Z", formatted in local time by the browser. */
    protected static function isoUtc(string $datetime): string
    {
        return (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
