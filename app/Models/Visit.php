<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Entry/exit events at the gate (`visits`). entry_at/exit_at are UTC.
 */
final class Visit extends TenantModel
{
    public const TYPES = [
        'guest'            => 'Visitante',
        'service_provider' => 'Prestador de serviço',
        'delivery'         => 'Entregador',
        'other'            => 'Outro',
    ];

    protected string $table = 'visits';

    protected array $fillable = [
        'visitor_id',
        'unit_id',
        'visit_type',
        'vehicle_plate',
        'status',
        'entry_at',
        'created_by_user_id',
        'entry_registered_by_user_id',
        'notes',
    ];

    /**
     * Shared SELECT. Every JOIN repeats the condominium_id equality: the composite
     * foreign keys already guarantee it, and repeating it means the query stays
     * tenant-safe even if copied somewhere without those guarantees.
     */
    private const LIST_SELECT = 'SELECT vi.id, vi.visit_type, vi.vehicle_plate, vi.status, vi.entry_at, vi.exit_at,
               v.full_name, v.document_number, ' . Unit::LABEL_SQL . ' AS unit_label
          FROM visits vi
          JOIN visitors v ON v.condominium_id = vi.condominium_id AND v.id = vi.visitor_id
          JOIN units u    ON u.condominium_id = vi.condominium_id AND u.id = vi.unit_id
         WHERE vi.condominium_id = :tenant ';

    /**
     * Visitors currently inside (served by ix_visits_gate).
     *
     * @return list<array<string, mixed>>
     */
    public function currentlyInside(): array
    {
        return $this->fetchAll(
            self::LIST_SELECT . "AND vi.status = 'inside' ORDER BY vi.entry_at DESC LIMIT 200",
            $this->scoped()
        );
    }

    /**
     * Most recent exits, for the desk's history panel.
     *
     * @return list<array<string, mixed>>
     */
    public function recentExits(int $limit = 15): array
    {
        return $this->fetchAll(
            self::LIST_SELECT . "AND vi.status = 'exited' ORDER BY vi.exit_at DESC LIMIT :limit",
            $this->scoped(['limit' => $limit])
        );
    }

    /**
     * Records the exit atomically.
     *
     * The WHERE clause carries the whole rule: same tenant, and still inside.
     * A second click, or a tampered id from another condominium, changes zero
     * rows, so the caller can tell "already exited / not found" apart from success.
     */
    public function registerExit(int $id, int $userId): bool
    {
        return $this->execute(
            "UPDATE visits
                SET status = 'exited',
                    exit_at = UTC_TIMESTAMP(),
                    exit_registered_by_user_id = :user_id
              WHERE id = :id
                AND condominium_id = :tenant
                AND status = 'inside'",
            $this->scoped(['id' => $id, 'user_id' => $userId])
        ) === 1;
    }
}
