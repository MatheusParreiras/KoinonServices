<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\TenantCounter;
use App\Models\Unit;
use PDOException;

/**
 * Creating charges (invoices) for units, and (Phase 5) recording a manual
 * payment or cancelling an invoice. Invoices are never hard-deleted: a
 * cancelled invoice stays in the table with its reason.
 */
final class InvoiceService
{
    public function __construct(
        private readonly Invoice $invoices = new Invoice(),
        private readonly InvoiceItem $items = new InvoiceItem(),
        private readonly Unit $units = new Unit(),
        private readonly FinancialCategory $categories = new FinancialCategory(),
        private readonly TenantCounter $counters = new TenantCounter()
    ) {
    }

    /**
     * Creates an open invoice with one line for a unit of the current condominium.
     *
     * @param array{unit_id: int, category_id: int, invoice_type: string, reference_month: string,
     *              due_date: string, amount: string, description: string, notes: ?string} $data
     *        amount is a canonical decimal string from Validator::money(), never a float.
     * @return array{id: int, invoice_number: int}
     * @throws BusinessRuleException
     */
    public function createCharge(array $data, int $managerUserId): array
    {
        // Ownership checks: both ids must belong to THIS condominium. TenantModel
        // scoping makes another tenant's id behave exactly like a missing one.
        if ($this->units->findActive($data['unit_id']) === null) {
            throw new BusinessRuleException('Unidade não encontrada neste condomínio.', 422, 'unit_id');
        }
        if ($this->categories->findActiveIncome($data['category_id']) === null) {
            throw new BusinessRuleException('Categoria inválida.', 422, 'category_id');
        }

        try {
            return Database::transaction(function () use ($data, $managerUserId): array {
                $number = $this->counters->next('invoice');
                $invoiceId = $this->invoices->insert([
                    'unit_id'            => $data['unit_id'],
                    'invoice_number'     => $number,
                    'invoice_type'       => $data['invoice_type'],
                    'reference_month'    => $data['reference_month'],
                    'issue_date'         => TenantContext::today(),
                    'due_date'           => $data['due_date'],
                    'total_amount'       => $data['amount'], // decimal string, bound as-is
                    'status'             => 'open',
                    'notes'              => $data['notes'],
                    'created_by_user_id' => $managerUserId,
                ]);
                $this->items->insert([
                    'invoice_id'  => $invoiceId,
                    'category_id' => $data['category_id'],
                    'description' => $data['description'],
                    'amount'      => $data['amount'],
                ]);

                return ['id' => $invoiceId, 'invoice_number' => $number];
            });
        } catch (PDOException $e) {
            // 1062 = duplicate key. The only reachable one is uq_invoices_one_monthly_fee:
            // the schema allows one live monthly fee per unit per month.
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new BusinessRuleException(
                    'Esta unidade já tem uma taxa condominial para o mês de referência informado.',
                    409,
                    'reference_month'
                );
            }
            throw $e;
        }
    }

    /**
     * Records a full manual payment of an open invoice.
     *
     * One transaction: lock the invoice (tenant-scoped), check it is still
     * open, insert the payment with the invoice's own DECIMAL total (a string
     * read from MySQL, never recomputed in PHP), mark the invoice paid, audit.
     * The row lock makes a double click (or two managers) record one payment only.
     *
     * @param string $paidOn Local date Y-m-d (not in the future; checked by the caller).
     * @throws BusinessRuleException 404 not in this condominium, 409 not open.
     */
    public function recordPayment(int $invoiceId, string $paidOn, string $method, ?string $notes, int $managerUserId, Request $request): void
    {
        Database::transaction(function () use ($invoiceId, $paidOn, $method, $notes, $managerUserId, $request): void {
            $invoice = $this->invoices->lockForUpdate($invoiceId)
                ?? throw new BusinessRuleException('Cobrança não encontrada.', 404);
            if ($invoice['status'] !== 'open') {
                throw new BusinessRuleException('Só é possível registrar pagamento de cobranças em aberto.', 409);
            }
            if ($paidOn < (string) $invoice['issue_date']) {
                throw new BusinessRuleException('A data do pagamento não pode ser anterior à emissão.', 422, 'paid_on');
            }

            // Local midnight of the payment date, stored in UTC like every DATETIME.
            $paidAtUtc = TenantContext::localToUtc($paidOn);
            (new Payment())->insert([
                'invoice_id'          => $invoiceId,
                'amount'              => (string) $invoice['total_amount'],
                'paid_at'             => $paidAtUtc,
                'payment_method'      => $method,
                'recorded_by_user_id' => $managerUserId,
                'notes'               => $notes,
            ]);
            $this->invoices->markPaid($invoiceId, $paidAtUtc);

            (new AuditLogger($request))->tenant('invoice.paid', 'invoice', $invoiceId, [
                'invoice_number' => (int) $invoice['invoice_number'],
                'amount'         => (string) $invoice['total_amount'],
                'paid_on'        => $paidOn,
                'method'         => $method,
            ]);
        });
    }

    /**
     * Cancels an open invoice (kept, with its reason; never deleted).
     *
     * @throws BusinessRuleException 404 not in this condominium, 409 not open.
     */
    public function cancel(int $invoiceId, string $reason, Request $request): void
    {
        Database::transaction(function () use ($invoiceId, $reason, $request): void {
            $invoice = $this->invoices->lockForUpdate($invoiceId)
                ?? throw new BusinessRuleException('Cobrança não encontrada.', 404);
            if ($invoice['status'] !== 'open') {
                throw new BusinessRuleException('Só é possível cancelar cobranças em aberto.', 409);
            }

            $this->invoices->cancel($invoiceId, $reason);
            (new AuditLogger($request))->tenant('invoice.cancelled', 'invoice', $invoiceId, [
                'invoice_number' => (int) $invoice['invoice_number'],
                'amount'         => (string) $invoice['total_amount'],
                'reason'         => $reason,
            ]);
        });
    }
}
