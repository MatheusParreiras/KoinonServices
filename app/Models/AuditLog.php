<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Request;

/**
 * Append-only security trail `audit_logs` (mixed scope: condominium_id is NULL
 * for platform-level events such as logins).
 */
final class AuditLog extends Model
{
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
}
