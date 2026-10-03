<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Bookings of common areas (`reservations`). starts_at/ends_at and
 * reservation_date are LOCAL wall-clock values of the condominium (migration 0002).
 */
final class Reservation extends TenantModel
{
    /** Statuses that hold the area (a cancelled or rejected booking frees it). */
    public const ACTIVE_STATUSES = ['pending', 'approved'];

    public const STATUS_LABELS = [
        'pending'   => 'Aguardando aprovação',
        'approved'  => 'Confirmada',
        'rejected'  => 'Recusada',
        'cancelled' => 'Cancelada',
        'completed' => 'Concluída',
    ];

    protected string $table = 'reservations';

    protected array $fillable = [
        'common_area_id',
        'reservation_date',
        'starts_at',
        'ends_at',
        'seat_number',
        'unit_id',
        'requested_by_user_id',
        'guest_count',
        'status',
        'decided_at',
        'notes',
    ];

    /**
     * Active reservations of an area that OVERLAP the requested range, locked
     * until the transaction ends.
     *
     * Overlap rule: existing.start < new.end AND existing.end > new.start.
     * Touching ranges (one ends 14:00, the next starts 14:00) do not overlap.
     * Uses ix_reservations_overlap (condominium_id, common_area_id, reservation_date, ...).
     * Call only after CommonArea::lockForBooking() in the same transaction.
     *
     * @return list<array{id: int}>
     */
    public function lockOverlapping(int $areaId, string $date, string $startsAt, string $endsAt): array
    {
        return $this->fetchAll(
            "SELECT id
               FROM reservations
              WHERE condominium_id = :tenant
                AND common_area_id = :area_id
                AND reservation_date = :reservation_date
                AND status IN ('pending', 'approved')
                AND starts_at < :new_end
                AND ends_at > :new_start
              FOR UPDATE",
            $this->scoped([
                'area_id'          => $areaId,
                'reservation_date' => $date,
                'new_end'          => $endsAt,
                'new_start'        => $startsAt,
            ])
        );
    }

    /** Future active bookings a unit already holds for an area (per-unit limit). */
    public function countUpcomingForUnit(int $areaId, int $unitId, string $nowLocal): int
    {
        $row = $this->fetchOne(
            "SELECT COUNT(*) AS total
               FROM reservations
              WHERE condominium_id = :tenant
                AND common_area_id = :area_id
                AND unit_id = :unit_id
                AND status IN ('pending', 'approved')
                AND ends_at > :now_local",
            $this->scoped(['area_id' => $areaId, 'unit_id' => $unitId, 'now_local' => $nowLocal])
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Upcoming reservations (today onwards).
     *
     * @param list<int>|null $unitIds null = every unit of the tenant (staff);
     *                                a list = only these units (a resident's own units).
     * @return list<array<string, mixed>>
     */
    public function upcoming(string $today, ?array $unitIds): array
    {
        if ($unitIds === []) {
            return []; // a resident with no linked unit sees nothing, never "everything"
        }

        $params = $this->scoped(['today' => $today]);
        $unitFilter = '';
        if ($unitIds !== null) {
            [$placeholders, $unitParams] = $this->inList('unit', $unitIds);
            $unitFilter = " AND r.unit_id IN ({$placeholders})";
            $params += $unitParams;
        }

        return $this->fetchAll(
            'SELECT r.id, r.unit_id, r.reservation_date, r.starts_at, r.ends_at, r.guest_count, r.status,
                    r.decision_note, a.name AS area_name, ' . Unit::LABEL_SQL . ' AS unit_label,
                    us.full_name AS requested_by_name
               FROM reservations r
               JOIN common_areas a ON a.condominium_id = r.condominium_id AND a.id = r.common_area_id
               JOIN units u        ON u.condominium_id = r.condominium_id AND u.id = r.unit_id
               JOIN users us       ON us.id = r.requested_by_user_id
              WHERE r.condominium_id = :tenant
                AND r.reservation_date >= :today' . $unitFilter . '
              ORDER BY r.starts_at
              LIMIT 300',
            $params
        );
    }

    /** One reservation of the current tenant with its area rules, locked for a status change. */
    public function lockWithArea(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT r.id, r.unit_id, r.status, r.starts_at, a.cancel_deadline_hours
               FROM reservations r
               JOIN common_areas a ON a.condominium_id = r.condominium_id AND a.id = r.common_area_id
              WHERE r.id = :id AND r.condominium_id = :tenant
              FOR UPDATE',
            $this->scoped(['id' => $id])
        );
    }

    /** Cancels an active reservation of this tenant. */
    public function cancel(int $id, int $userId, ?string $reason): bool
    {
        return $this->execute(
            "UPDATE reservations
                SET status = 'cancelled',
                    cancelled_at = UTC_TIMESTAMP(),
                    cancelled_by_user_id = :user_id,
                    cancellation_reason = :reason
              WHERE id = :id
                AND condominium_id = :tenant
                AND status IN ('pending', 'approved')",
            $this->scoped(['id' => $id, 'user_id' => $userId, 'reason' => $reason])
        ) === 1;
    }

    /** Approves or rejects a PENDING reservation of this tenant ($status: approved|rejected). */
    public function decide(int $id, string $status, int $userId, ?string $note): bool
    {
        return $this->execute(
            "UPDATE reservations
                SET status = :status,
                    decided_by_user_id = :user_id,
                    decided_at = UTC_TIMESTAMP(),
                    decision_note = :note
              WHERE id = :id
                AND condominium_id = :tenant
                AND status = 'pending'",
            $this->scoped(['id' => $id, 'status' => $status, 'user_id' => $userId, 'note' => $note])
        ) === 1;
    }
}
