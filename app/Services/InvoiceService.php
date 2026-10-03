<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\TenantContext;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TenantCounter;
use App\Models\Unit;
use PDOException;

/**
 * Creating charges (invoices) for units.
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
}
