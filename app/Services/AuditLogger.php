<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\AuditLog;

/**
 * Writes security and administrative events to `audit_logs` (append-only).
 *
 * Three entry points make the tenant of every entry explicit:
 *  - tenant():   a Property Manager (or other member) acting inside the session's
 *                condominium. The condominium id is TenantContext::id(), never
 *                a value from the request.
 *  - platform(): a Super Admin acting on the platform, optionally on one
 *                condominium that the caller has already validated to exist.
 *  - record():   anything else (public flows such as a password reset, where the
 *                actor is the account the token belongs to).
 *
 * Details are passed through redact() so a careless caller cannot write a
 * password or a raw token into the log (Phase 1, NFR-OPS-02).
 */
final class AuditLogger
{
    /** Detail keys whose values are never stored, at any nesting depth. */
    private const SECRET_KEYS = '/pass(word)?|token|secret|hash/i';

    public function __construct(
        private readonly Request $request,
        private readonly AuditLog $log = new AuditLog()
    ) {
    }

    /**
     * An action inside the current condominium by the logged-in user.
     *
     * @param array<string, mixed> $details
     */
    public function tenant(string $action, ?string $entityType = null, ?int $entityId = null, array $details = []): void
    {
        $this->record($action, Auth::id(), TenantContext::id(), $entityType, $entityId, $details);
    }

    /**
     * A Super Admin action. $condominiumId is the target tenant (already
     * validated by the caller) or null for purely platform-level events.
     *
     * @param array<string, mixed> $details
     */
    public function platform(
        string $action,
        ?int $condominiumId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = []
    ): void {
        $this->record($action, Auth::id(), $condominiumId, $entityType, $entityId, $details);
    }

    /**
     * Generic entry with an explicit actor and condominium.
     *
     * @param array<string, mixed> $details
     */
    public function record(
        string $action,
        ?int $actorUserId,
        ?int $condominiumId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = []
    ): void {
        $this->log->record($action, $this->request, $actorUserId, $condominiumId, $entityType, $entityId, self::redact($details));
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private static function redact(array $details): array
    {
        foreach ($details as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEYS, $key) === 1) {
                $details[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $details[$key] = self::redact($value);
            }
        }

        return $details;
    }
}
