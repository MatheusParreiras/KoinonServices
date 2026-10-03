<?php

declare(strict_types=1);

namespace App\Controllers\Community;

use App\Core\Auth;
use App\Core\Response;
use App\Services\BusinessRuleException;
use App\Services\CommunityService;

/**
 * Likes, as two idempotent endpoints rather than one blind toggle:
 *
 *   POST   /api/community/posts/{id}/like   → liked
 *   DELETE /api/community/posts/{id}/like   → not liked
 *
 * The client says which state it wants, so a retried or duplicated request
 * cannot flip the like back. The database PRIMARY KEY (post, user) ensures
 * there is at most one like per person. Both answer
 *   200 {"status":"success","data":{"liked":bool,"likes_count":int}}
 * with the authoritative count, which the UI uses to reconcile its optimistic update.
 */
final class LikeController extends CommunityController
{
    /** POST /api/community/posts/{id}/like (CSRF via route middleware). */
    public function store(string $id): Response
    {
        return $this->setLike((int) $id, true);
    }

    /** DELETE /api/community/posts/{id}/like (CSRF via route middleware). */
    public function destroy(string $id): Response
    {
        return $this->setLike((int) $id, false);
    }

    private function setLike(int $postId, bool $like): Response
    {
        $this->requireRole(self::MEMBERS);

        try {
            // Tenant check (post in this condominium, published) happens inside the
            // service under a row lock; the actor is always the session user.
            $state = (new CommunityService())->setLike($postId, (int) Auth::id(), $like);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e);
        }

        return $this->success($state);
    }
}
