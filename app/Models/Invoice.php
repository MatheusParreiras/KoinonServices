<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Pagination;
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

    /** Manual payment methods (payments.payment_method ENUM). */
    public const PAYMENT_METHODS = [
        'pix'           => 'PIX',
        'bank_slip'     => 'Boleto',
        'bank_transfer' => 'Transferência',
        'cash'          => 'Dinheiro',
        'card'          => 'Cartão',
        'other'         => 'Outro',
    ];

    /** Filters of the admin invoice list ("overdue" is derived, never stored). */
    public const LIST_FILTERS = ['open', 'overdue', 'paid', 'cancelled'];

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

    // ------------------------------------------------------------------
    // Phase 5: reports, list and state changes. Every amount is summed by
    // MySQL on DECIMAL columns and returned as a string; PHP never does
    // arithmetic on money.
    // ------------------------------------------------------------------

    /**
     * Totals of one period, by due date (accrual view) plus cash received by
     * payment date. "Pending" = open and not yet due; "overdue" = open and past
     * due. Each placeholder is used once (native prepared statements).
     *
     * @param string $from      First local date, Y-m-d.
     * @param string $to        Last local date, Y-m-d (inclusive).
     * @param string $fromUtc   Local midnight of $from, in UTC (payments.paid_at is UTC).
     * @param string $toUtcExcl Local midnight of the day after $to, in UTC.
     * @return array<string, string|int>
     */
    public function periodTotals(string $from, string $to, string $fromUtc, string $toUtcExcl, string $today): array
    {
        $totals = $this->fetchOne(
            "SELECT COUNT(*) AS invoice_count,
                    COALESCE(SUM(i.total_amount), 0) AS billed,
                    COALESCE(SUM(CASE WHEN i.status = 'paid' THEN i.total_amount END), 0) AS billed_paid,
                    COALESCE(SUM(CASE WHEN i.status = 'open' AND i.due_date >= :today_a THEN i.total_amount END), 0) AS pending,
                    COALESCE(SUM(CASE WHEN i.status = 'open' AND i.due_date < :today_b THEN i.total_amount END), 0) AS overdue,
                    COALESCE(SUM(i.status = 'open' AND i.due_date < :today_c), 0) AS overdue_count
               FROM invoices i
              WHERE i.condominium_id = :tenant
                AND i.status IN ('open', 'paid')
                AND i.due_date BETWEEN :from AND :to",
            $this->scoped(['today_a' => $today, 'today_b' => $today, 'today_c' => $today, 'from' => $from, 'to' => $to])
        ) ?? [];

        $received = $this->fetchOne(
            "SELECT COUNT(*) AS payment_count, COALESCE(SUM(p.amount), 0) AS received
               FROM payments p
              WHERE p.condominium_id = :tenant
                AND p.status = 'confirmed'
                AND p.paid_at >= :from_utc AND p.paid_at < :to_utc",
            $this->scoped(['from_utc' => $fromUtc, 'to_utc' => $toUtcExcl])
        ) ?? [];

        return [
            'invoice_count' => (int) ($totals['invoice_count'] ?? 0),
            'billed'        => (string) ($totals['billed'] ?? '0.00'),
            'billed_paid'   => (string) ($totals['billed_paid'] ?? '0.00'),
            'pending'       => (string) ($totals['pending'] ?? '0.00'),
            'overdue'       => (string) ($totals['overdue'] ?? '0.00'),
            'overdue_count' => (int) ($totals['overdue_count'] ?? 0),
            'payment_count' => (int) ($received['payment_count'] ?? 0),
            'received'      => (string) ($received['received'] ?? '0.00'),
        ];
    }

    /**
     * Invoices due in the period (detail lines of the period CSV).
     *
     * @return list<array<string, mixed>>
     */
    public function periodLines(string $from, string $to, string $today): array
    {
        return $this->fetchAll(
            "SELECT i.invoice_number, i.invoice_type, i.reference_month, i.due_date, i.total_amount, i.status,
                    i.paid_at, " . Unit::LABEL_SQL . " AS unit_label,
                    (i.status = 'open' AND i.due_date < :today) AS is_overdue
               FROM invoices i
               JOIN units u ON u.condominium_id = i.condominium_id AND u.id = i.unit_id
              WHERE i.condominium_id = :tenant
                AND i.status IN ('open', 'paid')
                AND i.due_date BETWEEN :from AND :to
              ORDER BY i.due_date, i.invoice_number
              LIMIT 20000",
            $this->scoped(['today' => $today, 'from' => $from, 'to' => $to])
        );
    }

    /**
     * Units with overdue invoices, most overdue first.
     *
     * @return list<array<string, mixed>>
     */
    public function delinquency(string $today): array
    {
        return $this->fetchAll(
            "SELECT u.id AS unit_id, " . Unit::LABEL_SQL . " AS unit_label,
                    COUNT(*) AS overdue_count,
                    SUM(i.total_amount) AS amount_owed,
                    MIN(i.due_date) AS oldest_due_date,
                    DATEDIFF(:today_a, MIN(i.due_date)) AS days_overdue
               FROM invoices i
               JOIN units u ON u.condominium_id = i.condominium_id AND u.id = i.unit_id
              WHERE i.condominium_id = :tenant
                AND i.status = 'open'
                AND i.due_date < :today_b
              GROUP BY u.id, u.building, u.unit_number
              ORDER BY days_overdue DESC, amount_owed DESC",
            $this->scoped(['today_a' => $today, 'today_b' => $today])
        );
    }

    /** Sum of every overdue amount (footer of the delinquency report). */
    public function delinquencyTotal(string $today): string
    {
        $row = $this->fetchOne(
            "SELECT COALESCE(SUM(total_amount), 0) AS total FROM invoices
              WHERE condominium_id = :tenant AND status = 'open' AND due_date < :today",
            $this->scoped(['today' => $today])
        );

        return (string) ($row['total'] ?? '0.00');
    }

    /**
     * Admin invoice list.
     *
     * @param array{status?: string, unit_id?: int, from?: string, to?: string} $filters Already validated.
     * @return list<array<string, mixed>>
     */
    public function searchForAdmin(array $filters, string $today, Pagination $pagination): array
    {
        [$where, $params] = $this->adminFilterSql($filters, $today);

        return $this->fetchAll(
            "SELECT i.id, i.invoice_number, i.invoice_type, i.reference_month, i.due_date, i.total_amount,
                    i.status, i.paid_at, i.cancelled_at, " . Unit::LABEL_SQL . " AS unit_label,
                    (i.status = 'open' AND i.due_date < :today_row) AS is_overdue
               FROM invoices i
               JOIN units u ON u.condominium_id = i.condominium_id AND u.id = i.unit_id
              WHERE {$where}
              ORDER BY i.due_date DESC, i.invoice_number DESC
              LIMIT :limit OFFSET :offset",
            $params + ['today_row' => $today, 'limit' => $pagination->limit(), 'offset' => $pagination->offset()]
        );
    }

    /** @param array{status?: string, unit_id?: int, from?: string, to?: string} $filters */
    public function countForAdmin(array $filters, string $today): int
    {
        [$where, $params] = $this->adminFilterSql($filters, $today);
        $row = $this->fetchOne("SELECT COUNT(*) AS total FROM invoices i WHERE {$where}", $params);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * One invoice of the tenant with its unit label and payments, for the detail page.
     *
     * @return array<string, mixed>|null
     */
    public function findDetailed(int $id, string $today): ?array
    {
        $invoice = $this->fetchOne(
            "SELECT i.*, " . Unit::LABEL_SQL . " AS unit_label,
                    (i.status = 'open' AND i.due_date < :today) AS is_overdue
               FROM invoices i
               JOIN units u ON u.condominium_id = i.condominium_id AND u.id = i.unit_id
              WHERE i.condominium_id = :tenant AND i.id = :id",
            $this->scoped(['today' => $today, 'id' => $id])
        );
        if ($invoice === null) {
            return null;
        }
        $invoice['payments'] = $this->fetchAll(
            'SELECT p.amount, p.paid_at, p.payment_method, p.status, p.notes, us.full_name AS recorded_by
               FROM payments p
               LEFT JOIN users us ON us.id = p.recorded_by_user_id
              WHERE p.condominium_id = :tenant AND p.invoice_id = :id
              ORDER BY p.paid_at',
            $this->scoped(['id' => $id])
        );

        return $invoice;
    }

    /**
     * Locks an invoice row of the tenant for a state change.
     *
     * @return array<string, mixed>|null
     */
    public function lockForUpdate(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM invoices WHERE id = :id AND condominium_id = :tenant FOR UPDATE',
            $this->scoped(['id' => $id])
        );
    }

    public function markPaid(int $id, string $paidAtUtc): void
    {
        $this->execute(
            "UPDATE invoices SET status = 'paid', paid_at = :paid_at
              WHERE id = :id AND condominium_id = :tenant AND status = 'open'",
            $this->scoped(['paid_at' => $paidAtUtc, 'id' => $id])
        );
    }

    public function cancel(int $id, string $reason): void
    {
        $this->execute(
            "UPDATE invoices SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(), cancellation_reason = :reason
              WHERE id = :id AND condominium_id = :tenant AND status = 'open'",
            $this->scoped(['reason' => $reason, 'id' => $id])
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function adminFilterSql(array $filters, string $today): array
    {
        $conditions = ['i.condominium_id = :tenant'];   // tenant scope
        $params = [];
        $status = $filters['status'] ?? null;
        if ($status === 'overdue') {
            $conditions[] = "i.status = 'open' AND i.due_date < :today";
            $params['today'] = $today;
        } elseif ($status !== null) {
            $conditions[] = 'i.status = :status';
            $params['status'] = $status;
        } else {
            $conditions[] = "i.status <> 'draft'";
        }
        if (isset($filters['unit_id'])) {
            $conditions[] = 'i.unit_id = :unit_id';
            $params['unit_id'] = $filters['unit_id'];
        }
        if (isset($filters['from'])) {
            $conditions[] = 'i.due_date >= :from';
            $params['from'] = $filters['from'];
        }
        if (isset($filters['to'])) {
            $conditions[] = 'i.due_date <= :to';
            $params['to'] = $filters['to'];
        }

        return [implode(' AND ', $conditions), $this->scoped($params)];
    }
}
