<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\PostReport;

/**
 * Social network interactions: create, like/unlike, comment, report.
 *
 * Every interaction with an existing post follows the same pattern, inside one
 * transaction:
 *   1. Post::lockPublished($postId): the TENANT CHECK. It returns the post only
 *      if it belongs to the session's condominium and is published, and locks
 *      it. Otherwise 404.
 *   2. Write the like/comment/report with $actorId, the SESSION user id passed
 *      in by the controller. No user id is ever read from the request.
 *   3. Adjust the post's counter while the lock is held, so counts stay exact.
 */
final class CommunityService
{
    /** Pending reports that automatically hide a post until a manager reviews it (Phase 1, A-12). */
    public const AUTO_HIDE_THRESHOLD = 3;

    public function __construct(
        private readonly Post $posts = new Post(),
        private readonly PostLike $likes = new PostLike(),
        private readonly PostComment $comments = new PostComment(),
        private readonly PostReport $reports = new PostReport()
    ) {
    }

    /**
     * Publishes a post and returns it in card format.
     * $categoryId has already been resolved from the allowlist by the controller.
     *
     * @return array<string, mixed>
     */
    public function createPost(int $actorId, int $categoryId, string $body): array
    {
        $id = $this->posts->insert([
            'author_user_id' => $actorId,  // the session user, never a client value
            'category_id'    => $categoryId,
            'body'           => $body,     // raw text; escaped at output time only
        ]);

        return (array) $this->posts->findCard($id, $actorId);
    }

    /**
     * Sets the actor's like on or off. Idempotent: liking twice keeps one like
     * (primary key), and unliking twice removes nothing the second time. The
     * counter only moves when a row was really inserted or deleted.
     *
     * @return array{liked: bool, likes_count: int} The authoritative state.
     * @throws BusinessRuleException 404 when the post is not visible in this condominium.
     */
    public function setLike(int $postId, int $actorId, bool $like): array
    {
        return Database::transaction(function () use ($postId, $actorId, $like): array {
            $post = $this->lockPublished($postId);
            $count = (int) $post['like_count'];

            if ($like && $this->likes->add($postId, $actorId)) {
                $this->posts->adjustCounter($postId, 'like_count', +1);
                $count++;
            } elseif (!$like && $this->likes->remove($postId, $actorId)) {
                $this->posts->adjustCounter($postId, 'like_count', -1);
                $count = max(0, $count - 1);
            }

            // $count is exact: the row lock guarantees nobody changed it since we read it.
            return ['liked' => $like, 'likes_count' => $count];
        });
    }

    /**
     * Adds a comment by the actor and returns it with author name and timestamp.
     *
     * @return array{comment: array<string, mixed>, comments_count: int}
     * @throws BusinessRuleException 404
     */
    public function addComment(int $postId, int $actorId, string $body): array
    {
        return Database::transaction(function () use ($postId, $actorId, $body): array {
            $post = $this->lockPublished($postId);

            // TenantModel::insert() sets condominium_id from the session's tenant,
            // and the composite FK (condominium_id, post_id) proves the post is in it.
            $commentId = $this->comments->insert([
                'post_id'        => $postId,
                'author_user_id' => $actorId,
                'body'           => $body,
            ]);
            $this->posts->adjustCounter($postId, 'comment_count', +1);

            return [
                'comment'        => (array) $this->comments->findWithAuthor($commentId),
                'comments_count' => (int) $post['comment_count'] + 1,
            ];
        });
    }

    /**
     * Visible comments of a post, after the tenant check (no lock needed for reading).
     *
     * @return list<array<string, mixed>>
     * @throws BusinessRuleException 404
     */
    public function allComments(int $postId): array
    {
        $post = $this->posts->find($postId); // TenantModel::find(): id AND condominium_id
        if ($post === null || $post['status'] !== 'published') {
            throw new BusinessRuleException('Publicação não encontrada.', 404);
        }

        return $this->comments->forPost($postId);
    }

    /**
     * Reports a post on behalf of the actor.
     *
     * @return array{hidden: bool} Whether the post was auto-hidden by this report.
     * @throws BusinessRuleException 404 not visible, 409 already reported, 422 own post
     */
    public function report(int $postId, int $actorId, string $reason, ?string $details): array
    {
        return Database::transaction(function () use ($postId, $actorId, $reason, $details): array {
            $post = $this->lockPublished($postId);

            if ((int) $post['author_user_id'] === $actorId) {
                throw new BusinessRuleException('Você não pode denunciar a sua própria publicação.', 422);
            }
            if (!$this->reports->file($postId, $actorId, $reason, $details)) {
                throw new BusinessRuleException('Você já denunciou esta publicação. A administração vai analisar.', 409);
            }

            $this->posts->adjustCounter($postId, 'report_count', +1);
            $hidden = (int) $post['report_count'] + 1 >= self::AUTO_HIDE_THRESHOLD;
            if ($hidden) {
                $this->posts->hide($postId);
            }

            return ['hidden' => $hidden];
        });
    }

    /**
     * @return array<string, mixed>
     * @throws BusinessRuleException 404
     */
    private function lockPublished(int $postId): array
    {
        return $this->posts->lockPublished($postId)
            ?? throw new BusinessRuleException('Publicação não encontrada ou removida.', 404);
    }
}
