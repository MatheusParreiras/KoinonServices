<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base class for every model whose table has a `condominium_id` column.
 *
 * TENANT ISOLATION: every query this class builds is scoped to
 * TenantContext::id(), the tenant the TenantMiddleware resolved from the
 * server-side session. No method accepts a condominium id from the caller, so a
 * controller cannot pass a tampered value through, even by mistake.
 *
 *  - find(42) runs "WHERE id = 42 AND condominium_id = <current tenant>". A
 *    record from another condominium is simply not found, so the user gets the
 *    same 404 as for an id that does not exist, and learns nothing.
 *  - insert() discards any condominium_id in $data and sets the current tenant.
 *  - update()/delete() only touch rows of the current tenant.
 *  - Hand-written queries in subclasses must contain
 *    "condominium_id = :tenant" and pass their parameters through scoped().
 *
 * The composite foreign keys of the Phase 1 schema are the database-level
 * backstop: even a buggy hand-written query cannot link rows across tenants.
 */
abstract class TenantModel extends Model
{
    /** Returns a row of the current tenant by id, or null (also for other tenants' ids). */
    public function find(int $id): ?array
    {
        return $this->selectWhere(['id' => $id, 'condominium_id' => $this->tenantId()]);
    }

    /**
     * Inserts a row owned by the current tenant.
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        unset($data['condominium_id']); // never trust a tenant id supplied by the caller

        return $this->insertRow($this->onlyFillable($data) + ['condominium_id' => $this->tenantId()]);
    }

    /**
     * Updates a row of the current tenant; rows of other tenants are untouched (returns 0).
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): int
    {
        unset($data['condominium_id']); // a row can never be moved to another tenant

        return $this->updateWhere($this->onlyFillable($data), ['id' => $id, 'condominium_id' => $this->tenantId()]);
    }

    /** The tenant every query of this model is restricted to. */
    protected function tenantId(): int
    {
        return TenantContext::id();
    }

    /**
     * Adds the current tenant as the ":tenant" parameter of a hand-written query.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function scoped(array $params = []): array
    {
        return ['tenant' => $this->tenantId()] + $params;
    }
}
