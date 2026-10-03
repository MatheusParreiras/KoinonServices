<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Bookable common areas of the current condominium (`common_areas`).
 */
final class CommonArea extends TenantModel
{
    /**
     * Areas every condominium starts with (Phase 3 scope: BBQ, Party Room, Gym).
     * bookings_per_slot: 1 = exclusive use; the gym accepts several people at once.
     */
    private const DEFAULTS = [
        ['Churrasqueira', 'bbq', 30, 1, 1, 24, 90],
        ['Salão de festas', 'party_room', 80, 1, 1, 48, 120],
        ['Academia', 'gym', 10, 8, 0, 1, 7],
    ];

    protected string $table = 'common_areas';

    /**
     * Active areas, for the booking form.
     *
     * @return list<array<string, mixed>>
     */
    public function active(): array
    {
        return $this->fetchAll(
            'SELECT id, name, area_type, max_people, bookings_per_slot, requires_approval,
                    booking_fee, min_advance_hours, max_advance_days
               FROM common_areas
              WHERE condominium_id = :tenant AND is_active = 1
              ORDER BY name',
            $this->scoped()
        );
    }

    /**
     * Locks the area row for the rest of the transaction.
     *
     * This is the serialisation point against double booking: every booking
     * of the same area must take this lock first, so two concurrent requests
     * for overlapping times run one after the other, and the second one sees
     * the first one's reservation in its overlap check. Locking only the
     * existing reservation rows would not be enough: when no reservation
     * exists yet there is nothing to lock, and both inserts would succeed.
     */
    public function lockForBooking(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT id, name, is_active, max_people, bookings_per_slot, requires_approval,
                    min_advance_hours, max_advance_days, max_active_per_unit, cancel_deadline_hours
               FROM common_areas
              WHERE id = :id AND condominium_id = :tenant
              FOR UPDATE',
            $this->scoped(['id' => $id])
        );
    }

    /**
     * Creates the default areas for this tenant if they are missing. Idempotent:
     * the unique key (condominium_id, name) turns repeats into no-ops.
     */
    public function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as [$name, $type, $maxPeople, $perSlot, $approval, $minHours, $maxDays]) {
            $this->execute(
                'INSERT INTO common_areas
                    (condominium_id, name, area_type, max_people, bookings_per_slot, requires_approval,
                     min_advance_hours, max_advance_days)
                 VALUES (:tenant, :name, :area_type, :max_people, :per_slot, :approval, :min_hours, :max_days)
                 ON DUPLICATE KEY UPDATE id = id',
                $this->scoped([
                    'name'       => $name,
                    'area_type'  => $type,
                    'max_people' => $maxPeople,
                    'per_slot'   => $perSlot,
                    'approval'   => $approval,
                    'min_hours'  => $minHours,
                    'max_days'   => $maxDays,
                ])
            );
        }
    }
}
