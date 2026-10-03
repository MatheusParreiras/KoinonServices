<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Comments (`post_comments`), tenant-scoped.
 */
final class PostComment extends TenantModel
{
    public const BODY_MAX = 1000;

    protected string $table = 'post_comments';

    protected array $fillable = ['post_id', 'author_user_id', 'body'];

    private const SELECT = 'SELECT c.id, c.post_id, c.body, c.created_at, c.author_user_id, u.full_name AS author_name
          FROM post_comments c
          JOIN users u ON u.id = c.author_user_id ';

    /**
     * The latest $perPost visible comments of EACH post in $postIds, in ONE query.
     *
     * ROW_NUMBER() numbers each post's comments newest-first, and the outer query
     * keeps numbers 1..$perPost. This avoids running one query per post on the
     * page (the "N+1" problem). Served by ix_post_comments_thread
     * (condominium_id, post_id, status, id). The IN list has one bound
     * placeholder per id (Model::inList), never concatenated values.
     *
     * @param list<int> $postIds
     * @return array<int, list<array<string, mixed>>> post_id => comments, oldest first
     */
    public function latestForPosts(array $postIds, int $perPost = 3): array
    {
        if ($postIds === []) {
            return [];
        }
        [$placeholders, $idParams] = $this->inList('post', $postIds);

        $rows = $this->fetchAll(
            "SELECT id, post_id, body, created_at, author_user_id, author_name
               FROM (
                    SELECT c.id, c.post_id, c.body, c.created_at, c.author_user_id, u.full_name AS author_name,
                           ROW_NUMBER() OVER (PARTITION BY c.post_id ORDER BY c.id DESC) AS rn
                      FROM post_comments c
                      JOIN users u ON u.id = c.author_user_id
                     WHERE c.condominium_id = :tenant
                       AND c.status = 'visible'
                       AND c.post_id IN ({$placeholders})
               ) ranked
              WHERE rn <= :per_post
              ORDER BY post_id, id",
            $this->scoped(['per_post' => $perPost] + $idParams)
        );

        $byPost = [];
        foreach ($rows as $row) {
            $byPost[(int) $row['post_id']][] = $row;
        }

        return $byPost;
    }

    /**
     * Every visible comment of one post (the "see all comments" link).
     * The caller must first confirm that the post is visible in this tenant.
     *
     * @return list<array<string, mixed>>
     */
    public function forPost(int $postId, int $limit = 200): array
    {
        return $this->fetchAll(
            self::SELECT . "WHERE c.condominium_id = :tenant AND c.post_id = :post_id AND c.status = 'visible'
              ORDER BY c.id LIMIT :limit",
            $this->scoped(['post_id' => $postId, 'limit' => $limit])
        );
    }

    /** One comment with author name and timestamp (the response after creating it). */
    public function findWithAuthor(int $id): ?array
    {
        return $this->fetchOne(
            self::SELECT . 'WHERE c.condominium_id = :tenant AND c.id = :id',
            $this->scoped(['id' => $id])
        );
    }
}
