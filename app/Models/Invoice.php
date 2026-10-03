<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Bills issued to units (`invoices`). Amounts are DECIMAL(12,2): PDO returns
 * them as strings ("1234.50") and they are kept as strings in PHP, never floats.
 *
 * "Overdue" is not stored: it is computed as status = 'open' AND due_date < today
 * (the condominium's local date, passed in by the caller).
 */
final class Invoice extends TenantModel
{
    public const TYPES = [
        'monthly_fee'   => 'Taxa condominial',
        'extraordinary' => 'Taxa extra',
        'fine'          => 'Multa',
        'other'         => 'Outro',
    ];

    public const STATUS_LABELS = [
        'draft'     => 'Rascunho',
        'open'      => 'Em aberto',
        'paid'      => 'Paga',
        'cancelled' => 'Cancelada',
    ];

    protected string $table = 'invoices';

    protected array $fillable = [
        'unit_id',
        'invoice_number',
        'invoice_type',
        'reference_month',
        'issue_date',
        'due_date',
        'total_amount',
        'status',
        'notes',
        'created_by_user_id',
    ];

    /**
     * Bills of the given units: a resident's own units only.
     *
     * The unit ids come from UnitResident::activeUnitIds() for the logged-in
     * user, never from the request. That is the Resident ownership filter, on
     * top of the tenant filter.
     *
     * @param list<int> $unitIds
     * @return list<array<string, mixed>>
     */
    public function forUnits(array $unitIds, string $today): array
    {
        if ($unitIds === []) {
            return [];
        }
        [$placeholders, $unitParams] = $this->inList('unit', $unitIds);

        return $this->fetchAll(
            "SELECT i.id, i.invoice_number, i.invoice_type, i.reference_month, i.due_date, i.total_amount,
                    i.status, i.paid_at, " . Unit::LABEL_SQL . " AS unit_label,
                    (i.status = 'open' AND i.due_date < :today) AS is_overdue
               FROM invoices i
               JOIN units u ON u.condominium_id = i.condominium_id AND u.id = i.unit_id
              WHERE i.condominium_id = :tenant
                AND i.unit_id IN ({$placeholders})
                AND i.status IN ('open', 'paid')
              ORDER BY i.status = 'paid', i.due_date DESC
              LIMIT 120",
            $this->scoped(['today' => $today] + $unitParams)
        );
    }

    /**
     * Financial status of every active unit of the tenant (manager overview).
     * Each placeholder is used once: native prepared statements do not allow
     * reusing a named parameter, hence today_a / today_b.
     *
     * @return list<array<string, mixed>>
     */
    public function unitOverview(string $today): array
    {
        return $this->fetchAll(
            "SELECT u.id, " . Unit::LABEL_SQL . " AS unit_label,
                    COALESCE(SUM(i.status = 'open'), 0) AS open_count,
                    COALESCE(SUM(i.status = 'open' AND i.due_date < :today_a), 0) AS overdue_count,
                    COALESCE(SUM(CASE WHEN i.status = 'open' THEN i.total_amount END), 0) AS open_amount,
                    COALESCE(SUM(CASE WHEN i.status = 'open' AND i.due_date < :today_b THEN i.total_amount END), 0)
                        AS overdue_amount,
                    MIN(CASE WHEN i.status = 'open' THEN i.due_date END) AS next_due_date
               FROM units u
               LEFT JOIN invoices i ON i.condominium_id = u.condominium_id AND i.unit_id = u.id
              WHERE u.condominium_id = :tenant
                AND u.is_active = 1
              GROUP BY u.id, u.building, u.unit_number
              ORDER BY u.building, LENGTH(u.unit_number), u.unit_number",
            $this->scoped(['today_a' => $today, 'today_b' => $today])
        );
    }
}
