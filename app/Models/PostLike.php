<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Likes (`post_likes`). PRIMARY KEY (condominium_id, post_id, user_id) makes a
 * second like by the same person impossible at the database level.
 */
final class PostLike extends TenantModel
{
    protected string $table = 'post_likes';

    /**
     * Adds the like of $userId (always the session user, never a client value).
     *
     * ON DUPLICATE KEY UPDATE turns a repeated like into a no-op: affected rows
     * are 1 for a new like and 0 when it already existed. Unlike INSERT IGNORE,
     * it does not silence other errors (e.g. a foreign-key failure).
     *
     * @return bool True when a new like was created.
     */
    public function add(int $postId, int $userId): bool
    {
        return $this->execute(
            'INSERT INTO post_likes (condominium_id, post_id, user_id)
             VALUES (:tenant, :post_id, :user_id)
             ON DUPLICATE KEY UPDATE created_at = created_at',
            $this->scoped(['post_id' => $postId, 'user_id' => $userId])
        ) === 1;
    }

    /**
     * Removes the like of $userId. All three key columns are in the WHERE, so a
     * user can only ever remove their own like, in their own condominium.
     *
     * @return bool True when a like was removed.
     */
    public function remove(int $postId, int $userId): bool
    {
        return $this->execute(
            'DELETE FROM post_likes
              WHERE condominium_id = :tenant AND post_id = :post_id AND user_id = :user_id',
            $this->scoped(['post_id' => $postId, 'user_id' => $userId])
        ) === 1;
    }
}
