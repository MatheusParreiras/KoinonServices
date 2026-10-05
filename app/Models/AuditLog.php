<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Pagination;
use App\Core\Request;

/**
 * Append-only security trail `audit_logs` (mixed scope: condominium_id is NULL
 * for platform-level events such as logins).
 *
 * Append-only three times over: this class has no update/delete method ($fillable
 * is empty, so the inherited insert()/update() refuse to run), the migration 0003
 * triggers reject UPDATE and DELETE in the database, and the application user
 * should only be granted INSERT and SELECT on the table.
 */
final class AuditLog extends Model
{
    /** Action prefixes offered in the filter (the part before the first dot). */
    public const MODULES = [
        'auth'          => 'Autenticação',
        'account'       => 'Conta',
        'invitation'    => 'Convites',
        'member'        => 'Usuários',
        'condominium'   => 'Condomínios',
        'unit'          => 'Unidades',
        'common_area'   => 'Áreas comuns',
        'notice'        => 'Avisos',
        'invoice'       => 'Financeiro',
        'community'     => 'Comunidade',
        'support'       => 'Acesso de suporte',
    ];

    protected string $table = 'audit_logs';

    /**
     * @param string               $action        e.g. "auth.login_failed", "notice.published"
     * @param array<string, mixed> $details       Extra context. Never passwords or tokens.
     */
    public function record(
        string $action,
        Request $request,
        ?int $actorUserId = null,
        ?int $condominiumId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = []
    ): void {
        $this->execute(
            'INSERT INTO audit_logs
                (condominium_id, actor_user_id, action_code, entity_type, entity_id, details, ip_address, user_agent)
             VALUES
                (:condominium_id, :actor_user_id, :action_code, :entity_type, :entity_id, :details, INET6_ATON(:ip), :user_agent)',
            [
                'condominium_id' => $condominiumId,
                'actor_user_id'  => $actorUserId,
                'action_code'    => $action,
                'entity_type'    => $entityType,
                'entity_id'      => $entityId,
                'details'        => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'ip'             => $request->ip(),
                'user_agent'     => $request->userAgent(),
            ]
        );
    }

    /**
     * One page of entries, newest first, with the actor and condominium names.
     *
     * TENANT ISOLATION: a Property Manager's view passes TenantContext::id() as
     * $condominiumId, which becomes a mandatory "condominium_id = :condominium"
     * condition. Only the Super Admin's view passes null (all entries), and the
     * Super Admin may then narrow it with $filters['condominium_id'].
     *
     * @param array{module?: string, from_utc?: string, to_utc?: string, condominium_id?: int, q?: string} $filters
     *        Already validated: module is a key of MODULES, dates are UTC "Y-m-d H:i:s".
     * @return list<array<string, mixed>>
     */
    public function search(?int $condominiumId, array $filters, Pagination $pagination): array
    {
        [$where, $params] = $this->filterSql($condominiumId, $filters);

        return $this->fetchAll(
            "SELECT a.id, a.created_at, a.action_code, a.entity_type, a.entity_id, a.details,
                    INET6_NTOA(a.ip_address) AS ip, a.condominium_id,
                    c.name AS condominium_name, u.full_name AS actor_name, u.email AS actor_email
               FROM audit_logs a
               LEFT JOIN users u ON u.id = a.actor_user_id
               LEFT JOIN condominiums c ON c.id = a.condominium_id
              WHERE {$where}
              ORDER BY a.created_at DESC, a.id DESC
              LIMIT :limit OFFSET :offset",
            $params + ['limit' => $pagination->limit(), 'offset' => $pagination->offset()]
        );
    }

    /**
     * Total for the same filters as search().
     *
     * @param array{module?: string, from_utc?: string, to_utc?: string, condominium_id?: int, q?: string} $filters
     */
    public function count(?int $condominiumId, array $filters): int
    {
        [$where, $params] = $this->filterSql($condominiumId, $filters);
        $row = $this->fetchOne(
            "SELECT COUNT(*) AS total FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id WHERE {$where}",
            $params
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Newest entries for the platform overview.
     *
     * @return list<array<string, mixed>>
     */
    public function latest(int $limit): array
    {
        return $this->fetchAll(
            'SELECT a.id, a.created_at, a.action_code, a.condominium_id, c.name AS condominium_name,
                    u.full_name AS actor_name
               FROM audit_logs a
               LEFT JOIN users u ON u.id = a.actor_user_id
               LEFT JOIN condominiums c ON c.id = a.condominium_id
              ORDER BY a.created_at DESC, a.id DESC
              LIMIT :limit',
            ['limit' => $limit]
        );
    }

    /**
     * WHERE clause from fixed SQL fragments; every value is a bound parameter.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filterSql(?int $condominiumId, array $filters): array
    {
        $conditions = ['1 = 1'];
        $params = [];

        $tenant = $condominiumId ?? ($filters['condominium_id'] ?? null);
        if ($tenant !== null) {
            $conditions[] = 'a.condominium_id = :condominium';
            $params['condominium'] = (int) $tenant;
        }
        if (isset($filters['module']) && array_key_exists($filters['module'], self::MODULES)) {
            $conditions[] = 'a.action_code LIKE :module';
            $params['module'] = $filters['module'] . '.%';
        }
        if (isset($filters['from_utc'])) {
            $conditions[] = 'a.created_at >= :from_utc';
            $params['from_utc'] = $filters['from_utc'];
        }
        if (isset($filters['to_utc'])) {
            $conditions[] = 'a.created_at < :to_utc';
            $params['to_utc'] = $filters['to_utc'];
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            // Actor name or e-mail. LIKE wildcards typed by the user are escaped.
            $conditions[] = '(u.full_name LIKE :q_name OR u.email LIKE :q_email)';
            $like = self::likeContains($filters['q']);
            $params['q_name'] = $like;
            $params['q_email'] = $like;
        }

        return [implode(' AND ', $conditions), $params];
    }
}
