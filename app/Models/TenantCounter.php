<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;
use LogicException;

/**
 * Gap-free per-condominium numbering (`tenant_counters`): invoice numbers and
 * occurrence protocol numbers.
 */
final class TenantCounter extends TenantModel
{
    protected string $table = 'tenant_counters';

    /**
     * Returns the next number of $counter ('invoice' | 'occurrence') for the
     * current tenant and advances it.
     *
     * Must run inside the transaction that inserts the numbered row. The
     * counter row stays locked (FOR UPDATE) until commit, so concurrent
     * requests get distinct numbers, and a rollback also rolls the counter
     * back, so no number is ever skipped.
     */
    public function next(string $counter): int
    {
        if (!$this->db()->inTransaction()) {
            throw new LogicException('TenantCounter::next() must run inside a transaction.');
        }

        // Creates the row the first time a tenant needs this counter.
        $this->execute(
            'INSERT INTO tenant_counters (condominium_id, counter_name, next_value)
             VALUES (:tenant, :counter, 1)
             ON DUPLICATE KEY UPDATE next_value = next_value',
            $this->scoped(['counter' => $counter])
        );

        $row = $this->fetchOne(
            'SELECT next_value FROM tenant_counters
              WHERE condominium_id = :tenant AND counter_name = :counter
              FOR UPDATE',
            $this->scoped(['counter' => $counter])
        );

        $this->execute(
            'UPDATE tenant_counters SET next_value = next_value + 1
              WHERE condominium_id = :tenant AND counter_name = :counter',
            $this->scoped(['counter' => $counter])
        );

        return (int) $row['next_value'];
    }
}
