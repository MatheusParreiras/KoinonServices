<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Pagination;
use App\Core\TenantModel;

/**
 * Notice board `notices` (tenant-scoped).
 *
 * Every query here contains "n.condominium_id = :tenant" and gets its parameters
 * from scoped(), so it can only ever return the current condominium's notices.
 */
final class Notice extends TenantModel
{
    public const PRIORITIES = ['normal', 'important', 'urgent'];
    public const TITLE_MAX = 150;
    public const BODY_MAX = 10000;

    protected string $table = 'notices';

    // condominium_id is intentionally absent: TenantModel::insert() sets it.
    protected array $fillable = [
        'author_user_id',
        'author_super_admin_id',
        'title',
        'body',
        'priority',
        'is_pinned',
        'status',
        'publish_at',
        'expires_at',
    ];

    /** Shared column list: the author name comes from either author column. */
    private const DISPLAY_SELECT = <<<'SQL'
        SELECT n.id, n.title, n.body, n.priority, n.is_pinned, n.publish_at, n.expires_at,
               u.full_name AS author_name
          FROM notices n
          LEFT JOIN users u ON u.id = COALESCE(n.author_user_id, n.author_super_admin_id)
         WHERE n.condominium_id = :tenant
        SQL;

    /**
     * Notices currently visible on the homepage: published, already started, not
     * expired; pinned first, then newest. Served by index
     * ix_notices_homepage (condominium_id, status, is_pinned, publish_at).
     *
     * @return list<array<string, mixed>>
     */
    public function current(int $limit = 50): array
    {
        $sql = self::DISPLAY_SELECT . <<<'SQL'
               AND n.status = 'published'
               AND n.publish_at <= UTC_TIMESTAMP()
               AND (n.expires_at IS NULL OR n.expires_at > UTC_TIMESTAMP())
             ORDER BY n.is_pinned DESC, n.publish_at DESC
             LIMIT :limit
            SQL;

        return $this->fetchAll($sql, $this->scoped(['limit' => $limit]));
    }

    /** One notice of the current tenant in display format, or null. */
    public function findForDisplay(int $id): ?array
    {
        return $this->fetchOne(self::DISPLAY_SELECT . ' AND n.id = :id', $this->scoped(['id' => $id]));
    }

    /**
     * Every notice of the tenant for the management list (Phase 5), with a
     * computed visibility state: scheduled | visible | expired | archived | draft.
     *
     * @return list<array<string, mixed>>
     */
    public function forAdmin(Pagination $pagination): array
    {
        return $this->fetchAll(
            "SELECT n.id, n.title, n.priority, n.is_pinned, n.status, n.publish_at, n.expires_at,
                    u.full_name AS author_name,
                    CASE
                      WHEN n.status <> 'published' THEN n.status
                      WHEN n.publish_at > UTC_TIMESTAMP() THEN 'scheduled'
                      WHEN n.expires_at IS NOT NULL AND n.expires_at <= UTC_TIMESTAMP() THEN 'expired'
                      ELSE 'visible'
                    END AS visibility
               FROM notices n
               LEFT JOIN users u ON u.id = COALESCE(n.author_user_id, n.author_super_admin_id)
              WHERE n.condominium_id = :tenant
              ORDER BY n.is_pinned DESC, n.publish_at DESC, n.id DESC
              LIMIT :limit OFFSET :offset",
            $this->scoped(['limit' => $pagination->limit(), 'offset' => $pagination->offset()])
        );
    }

    public function countAll(): int
    {
        $row = $this->fetchOne('SELECT COUNT(*) AS total FROM notices WHERE condominium_id = :tenant', $this->scoped());

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Deletes a notice of the current tenant (read receipts go with it through
     * ON DELETE CASCADE). Returns false when the id is not in this tenant.
     */
    public function delete(int $id): bool
    {
        return $this->execute(
            'DELETE FROM notices WHERE id = :id AND condominium_id = :tenant',
            $this->scoped(['id' => $id])
        ) === 1;
    }
}
