<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\PostReport;

/**
 * Moderation decisions on reported posts. Callers must already have checked the
 * moderator role; every query here is scoped to the current condominium.
 */
final class ModerationService
{
    public function __construct(
        private readonly Post $posts = new Post(),
        private readonly PostReport $reports = new PostReport(),
        private readonly AuditLog $audit = new AuditLog()
    ) {
    }

    /**
     * Reported posts with their pending reports, for the moderation view.
     *
     * @return list<array<string, mixed>> each post has a 'reports' list
     */
    public function queue(): array
    {
        $posts = $this->posts->reportedForModeration();
        $reports = $this->reports->pendingForPosts(array_map(static fn (array $p): int => (int) $p['id'], $posts));

        foreach ($posts as &$post) {
            $post['reports'] = $reports[(int) $post['id']] ?? [];
        }
        unset($post); // break the reference left by foreach

        return $posts;
    }

    /**
     * Deletes a post. Likes, comments, images and reports go with it through the
     * schema's ON DELETE CASCADE.
     *
     * The post's text is gone after this, so a summary is written to the audit
     * log first, inside the same transaction: who wrote it, how many reports
     * and for which reasons. The text itself is not kept.
     *
     * @throws BusinessRuleException 404 when the post is not in this condominium.
     */
    public function deletePost(int $postId, int $moderatorId, Request $request): void
    {
        Database::transaction(function () use ($postId, $moderatorId, $request): void {
            $post = $this->posts->lockForModeration($postId)
                ?? throw new BusinessRuleException('Publicação não encontrada.', 404);

            $this->audit->record(
                'community.post_deleted',
                $request,
                $moderatorId,
                TenantContext::id(),
                'post',
                $postId,
                [
                    'author_user_id' => (int) $post['author_user_id'],
                    'category_id'    => (int) $post['category_id'],
                    'likes'          => (int) $post['like_count'],
                    'comments'       => (int) $post['comment_count'],
                    'report_reasons' => $this->reports->pendingReasonCounts($postId),
                ]
            );

            $this->posts->deleteForModeration($postId);
        });
    }

    /**
     * Dismisses all pending reports of a post and puts it back in the feed if it
     * had been auto-hidden.
     *
     * @return int Number of reports dismissed.
     * @throws BusinessRuleException 404 not in this condominium, 409 nothing pending
     */
    public function dismissReports(int $postId, int $moderatorId, Request $request): int
    {
        return Database::transaction(function () use ($postId, $moderatorId, $request): int {
            $this->posts->lockForModeration($postId)
                ?? throw new BusinessRuleException('Publicação não encontrada.', 404);

            $dismissed = $this->reports->dismissPending($postId, $moderatorId);
            if ($dismissed === 0) {
                throw new BusinessRuleException('Esta publicação não tem denúncias pendentes.', 409);
            }

            $this->posts->restoreAfterDismissal($postId, $moderatorId);
            $this->audit->record(
                'community.reports_dismissed',
                $request,
                $moderatorId,
                TenantContext::id(),
                'post',
                $postId,
                ['dismissed' => $dismissed]
            );

            return $dismissed;
        });
    }
}
