<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Deliveries held at the concierge desk (`packages`).
 */
final class Package extends TenantModel
{
    public const SIZES = [
        'envelope' => 'Envelope',
        'small'    => 'Pequeno',
        'medium'   => 'Médio',
        'large'    => 'Grande',
    ];

    protected string $table = 'packages';

    protected array $fillable = [
        'unit_id',
        'carrier',
        'tracking_code',
        'description',
        'package_size',
        'storage_location',
        'pickup_code',
        'received_by_user_id',
    ];

    /**
     * Packages waiting at the desk (served by ix_packages_desk).
     * pickup_code is NOT selected: the desk asks the resident for it instead of reading it.
     *
     * @return list<array<string, mixed>>
     */
    public function awaitingPickup(): array
    {
        return $this->fetchAll(
            "SELECT p.id, p.carrier, p.tracking_code, p.description, p.package_size, p.storage_location,
                    p.received_at, " . Unit::LABEL_SQL . " AS unit_label
               FROM packages p
               JOIN units u ON u.condominium_id = p.condominium_id AND u.id = p.unit_id
              WHERE p.condominium_id = :tenant
                AND p.status = 'awaiting_pickup'
              ORDER BY p.received_at",
            $this->scoped()
        );
    }

    /**
     * Loads a package of the current tenant and LOCKS it until the transaction
     * ends, so two simultaneous pickups of the same package cannot both pass
     * the status and code checks.
     */
    public function lockForPickup(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT id, status, pickup_code
               FROM packages
              WHERE id = :id AND condominium_id = :tenant
              FOR UPDATE',
            $this->scoped(['id' => $id])
        );
    }

    /** Marks the package as collected. Only changes a package of this tenant that is still waiting. */
    public function markPickedUp(int $id, string $pickedUpByName, int $userId): bool
    {
        return $this->execute(
            "UPDATE packages
                SET status = 'picked_up',
                    picked_up_at = UTC_TIMESTAMP(),
                    picked_up_by_name = :picked_up_by_name,
                    handed_over_by_user_id = :user_id
              WHERE id = :id
                AND condominium_id = :tenant
                AND status = 'awaiting_pickup'",
            $this->scoped(['id' => $id, 'picked_up_by_name' => $pickedUpByName, 'user_id' => $userId])
        ) === 1;
    }

    /** Records that at least one resident was e-mailed. */
    public function markNotified(int $id): void
    {
        $this->execute(
            'UPDATE packages SET resident_notified_at = UTC_TIMESTAMP()
              WHERE id = :id AND condominium_id = :tenant',
            $this->scoped(['id' => $id])
        );
    }

    /** Pickup details after a successful pickup (for the JSON response). */
    public function pickupSummary(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT id, status, picked_up_at, picked_up_by_name
               FROM packages
              WHERE id = :id AND condominium_id = :tenant',
            $this->scoped(['id' => $id])
        );
    }
}
