<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Reports (`post_reports`). UNIQUE (condominium_id, post_id, reporter_user_id)
 * allows one report per person per post.
 */
final class PostReport extends TenantModel
{
    public const REASONS = [
        'spam'       => 'Spam ou propaganda',
        'offensive'  => 'Conteúdo ofensivo',
        'harassment' => 'Assédio ou intimidação',
        'scam'       => 'Golpe ou fraude',
        'other'      => 'Outro motivo',
    ];

    protected string $table = 'post_reports';

    /**
     * Files a report by $reporterId (the session user).
     *
     * ON DUPLICATE KEY UPDATE makes "already reported" an ordinary outcome
     * (0 affected rows) instead of an exception, and still reports any other
     * error, unlike INSERT IGNORE.
     *
     * @return bool True for a new report, false when this user had already reported the post.
     */
    public function file(int $postId, int $reporterId, string $reason, ?string $details): bool
    {
        return $this->execute(
            'INSERT INTO post_reports (condominium_id, post_id, reporter_user_id, reason, details)
             VALUES (:tenant, :post_id, :reporter_id, :reason, :details)
             ON DUPLICATE KEY UPDATE id = id',
            $this->scoped([
                'post_id'     => $postId,
                'reporter_id' => $reporterId,
                'reason'      => $reason,
                'details'     => $details,
            ])
        ) === 1;
    }

    /**
     * Pending reports of several posts, for the moderation view.
     *
     * The reporter's identity is deliberately NOT selected: the moderator needs
     * the reason, not who complained (data minimisation, and it protects
     * reporters from retaliation by neighbours).
     *
     * @param list<int> $postIds
     * @return array<int, list<array<string, mixed>>> post_id => reports
     */
    public function pendingForPosts(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        [$placeholders, $idParams] = $this->inList('post', $postIds);

        $rows = $this->fetchAll(
            "SELECT id, post_id, reason, details, created_at
               FROM post_reports
              WHERE condominium_id = :tenant
                AND status = 'pending'
                AND post_id IN ({$placeholders})
              ORDER BY created_at",
            $this->scoped($idParams)
        );

        $byPost = [];
        foreach ($rows as $row) {
            $byPost[(int) $row['post_id']][] = $row;
        }

        return $byPost;
    }

    /** Marks every pending report of a post as dismissed by $moderatorId. */
    public function dismissPending(int $postId, int $moderatorId): int
    {
        return $this->execute(
            "UPDATE post_reports
                SET status = 'dismissed', reviewed_by_user_id = :moderator_id, reviewed_at = UTC_TIMESTAMP()
              WHERE condominium_id = :tenant AND post_id = :post_id AND status = 'pending'",
            $this->scoped(['post_id' => $postId, 'moderator_id' => $moderatorId])
        );
    }

    /**
     * Reason counts of a post's pending reports (kept in the audit log when the post is deleted).
     *
     * @return array<string, int>
     */
    public function pendingReasonCounts(int $postId): array
    {
        $rows = $this->fetchAll(
            "SELECT reason, COUNT(*) AS total FROM post_reports
              WHERE condominium_id = :tenant AND post_id = :post_id AND status = 'pending'
              GROUP BY reason",
            $this->scoped(['post_id' => $postId])
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['reason']] = (int) $row['total'];
        }

        return $counts;
    }
}
