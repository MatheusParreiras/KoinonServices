<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;
use InvalidArgumentException;

/**
 * Social network posts (`posts`), tenant-scoped.
 *
 * Every query contains "p.condominium_id = :tenant" with the value from
 * scoped(), which is TenantContext::id(), resolved from the session. A post id
 * from another condominium is therefore never found, liked, commented on or reported.
 *
 * like_count, comment_count and report_count are denormalised counters. They
 * change only inside a transaction that holds the post's row lock (see
 * lockPublished()), so they stay equal to the real number of rows.
 */
final class Post extends TenantModel
{
    public const BODY_MAX = 2000;

    protected string $table = 'posts';

    protected array $fillable = ['author_user_id', 'category_id', 'body'];

    /**
     * Columns shared by the feed and single-card queries.
     *
     * liked_by_me / reported_by_me use the ACTOR's id (:viewer_a / :viewer_b,
     * both bound to the session user id). A client cannot ask "as user X".
     * Two placeholders are needed because native prepared statements cannot
     * reuse a named parameter.
     */
    private const CARD_SELECT = <<<'SQL'
        SELECT p.id, p.body, p.created_at, p.edited_at, p.like_count, p.comment_count,
               p.author_user_id, u.full_name AS author_name,
               c.code AS category_code, c.name AS category_name,
               EXISTS (SELECT 1 FROM post_likes pl
                        WHERE pl.condominium_id = p.condominium_id
                          AND pl.post_id = p.id AND pl.user_id = :viewer_a) AS liked_by_me,
               EXISTS (SELECT 1 FROM post_reports pr
                        WHERE pr.condominium_id = p.condominium_id
                          AND pr.post_id = p.id AND pr.reporter_user_id = :viewer_b) AS reported_by_me
          FROM posts p
          JOIN users u             ON u.id = p.author_user_id
          JOIN social_categories c ON c.id = p.category_id
         WHERE p.condominium_id = :tenant
           AND p.status = 'published'
        SQL;

    /**
     * One page of the feed, newest first.
     *
     * Pagination is LIMIT/OFFSET (bound as integers) under a fixed anchor:
     * "p.id <= :anchor" pins the result set to the posts that existed when the
     * user opened the feed. New posts published meanwhile do not shift the
     * offsets, so "Load more" never repeats or skips a post. Fetch $limit + 1
     * rows to know whether another page exists. Served by ix_posts_feed /
     * ix_posts_category_feed.
     *
     * @return list<array<string, mixed>>
     */
    public function feed(int $viewerId, ?int $categoryId, int $anchorId, int $limit, int $offset): array
    {
        $sql = self::CARD_SELECT . ' AND p.id <= :anchor';
        $params = $this->scoped([
            'viewer_a' => $viewerId,
            'viewer_b' => $viewerId,
            'anchor'   => $anchorId,
            'limit'    => $limit,   // int -> bound as PDO::PARAM_INT (Model::run)
            'offset'   => $offset,
        ]);
        if ($categoryId !== null) {
            $sql .= ' AND p.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        return $this->fetchAll($sql . ' ORDER BY p.id DESC LIMIT :limit OFFSET :offset', $params);
    }

    /** Highest published post id of the tenant: the anchor of a fresh feed. */
    public function latestId(): int
    {
        $row = $this->fetchOne(
            "SELECT COALESCE(MAX(id), 0) AS max_id FROM posts WHERE condominium_id = :tenant AND status = 'published'",
            $this->scoped()
        );

        return (int) ($row['max_id'] ?? 0);
    }

    /** One published post in card format (after creating it), seen by $viewerId. */
    public function findCard(int $id, int $viewerId): ?array
    {
        return $this->fetchOne(
            self::CARD_SELECT . ' AND p.id = :id',
            $this->scoped(['viewer_a' => $viewerId, 'viewer_b' => $viewerId, 'id' => $id])
        );
    }

    /**
     * THE TENANT CHECK BEFORE ANY INTERACTION: loads the post only if it belongs
     * to the current condominium AND is published, and locks its row until the
     * transaction ends.
     *
     * The lock serialises all likes, comments and reports of one post, which
     * keeps the counters exact under concurrent clicks. A null result means the
     * post does not exist, belongs to another condominium, or was removed. The
     * caller answers 404 in all three cases.
     */
    public function lockPublished(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT id, author_user_id, status, like_count, comment_count, report_count
               FROM posts
              WHERE id = :id AND condominium_id = :tenant AND status = 'published'
              FOR UPDATE",
            $this->scoped(['id' => $id])
        );
    }

    /**
     * Adds $delta to one counter. The column name is chosen from a whitelist,
     * never from input, because identifiers cannot be bound as parameters.
     * GREATEST() keeps an UNSIGNED counter from underflowing.
     */
    public function adjustCounter(int $id, string $counter, int $delta): void
    {
        $column = match ($counter) {
            'like_count', 'comment_count', 'report_count' => $counter,
            default => throw new InvalidArgumentException("Unknown counter {$counter}."),
        };

        $this->execute(
            "UPDATE posts SET {$column} = GREATEST(CAST({$column} AS SIGNED) + :delta, 0)
              WHERE id = :id AND condominium_id = :tenant",
            $this->scoped(['id' => $id, 'delta' => $delta])
        );
    }

    /** Hides a post pending review (automatic, after too many reports). */
    public function hide(int $id): void
    {
        $this->execute(
            "UPDATE posts SET status = 'hidden' WHERE id = :id AND condominium_id = :tenant AND status = 'published'",
            $this->scoped(['id' => $id])
        );
    }

    // ---------------------------------------------------------------- moderation

    /**
     * Posts of this condominium with PENDING reports, most reported first.
     * Includes auto-hidden posts, which are waiting for exactly this review.
     *
     * @return list<array<string, mixed>>
     */
    public function reportedForModeration(): array
    {
        return $this->fetchAll(
            "SELECT p.id, p.body, p.status, p.created_at, p.like_count, p.comment_count,
                    u.full_name AS author_name, c.name AS category_name, c.code AS category_code,
                    COUNT(r.id) AS pending_reports,
                    MAX(r.created_at) AS last_reported_at
               FROM posts p
               JOIN post_reports r
                 ON r.condominium_id = p.condominium_id AND r.post_id = p.id AND r.status = 'pending'
               JOIN users u             ON u.id = p.author_user_id
               JOIN social_categories c ON c.id = p.category_id
              WHERE p.condominium_id = :tenant
                AND p.status IN ('published', 'hidden')
              GROUP BY p.id, p.body, p.status, p.created_at, p.like_count, p.comment_count,
                       u.full_name, c.name, c.code
              ORDER BY pending_reports DESC, last_reported_at DESC
              LIMIT 100",
            $this->scoped()
        );
    }

    /** Locks a post of this tenant for a moderation decision (any status). */
    public function lockForModeration(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT id, author_user_id, category_id, status, like_count, comment_count, report_count
               FROM posts WHERE id = :id AND condominium_id = :tenant FOR UPDATE',
            $this->scoped(['id' => $id])
        );
    }

    /**
     * Deletes a post of this tenant. The schema's ON DELETE CASCADE removes its
     * likes, comments, images and reports in the same statement.
     */
    public function deleteForModeration(int $id): bool
    {
        return $this->execute(
            'DELETE FROM posts WHERE id = :id AND condominium_id = :tenant',
            $this->scoped(['id' => $id])
        ) === 1;
    }

    /** After reports are dismissed: back to published, pending counter reset, moderator recorded. */
    public function restoreAfterDismissal(int $id, int $moderatorId): void
    {
        $this->execute(
            "UPDATE posts
                SET status = 'published', report_count = 0,
                    moderated_by_user_id = :moderator_id, moderated_at = UTC_TIMESTAMP()
              WHERE id = :id AND condominium_id = :tenant AND status IN ('published', 'hidden')",
            $this->scoped(['id' => $id, 'moderator_id' => $moderatorId])
        );
    }
}
