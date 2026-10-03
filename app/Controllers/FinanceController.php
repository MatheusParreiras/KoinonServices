<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Core\TenantContext;
use App\Core\Validator;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\UnitResident;
use App\Services\BusinessRuleException;
use App\Services\InvoiceService;

/**
 * Financial module (Financeiro): charges per unit and each unit's status.
 */
final class FinanceController extends Controller
{
    public const VIEWERS = [Auth::SUPER_ADMIN, 'manager', 'resident'];

    /** Only managers create charges. Residents and the read-only Super Admin get 403. */
    public const MANAGERS = ['manager'];

    /** See the whole condominium's financial overview. */
    private const SEE_ALL = [Auth::SUPER_ADMIN, 'manager'];

    private const KEEP = ['unit_id', 'category_id', 'invoice_type', 'reference_month', 'due_date', 'amount', 'description', 'notes'];

    /**
     * GET /finance
     * Staff: overview of every unit + charge form. Resident: their own unit's bills.
     */
    public function index(): Response
    {
        $this->requireRole(self::VIEWERS);
        $today = TenantContext::today();

        if (!Auth::hasRole(self::SEE_ALL)) {
            // Resident: bills of the units they own or rent (dependents excluded),
            // looked up from THEIR user id, never from the request.
            $unitIds = (new UnitResident())->activeUnitIds((int) Auth::id(), billingOnly: true);
            $invoices = (new Invoice())->forUnits($unitIds, $today);

            return $this->view('finance/resident', [
                'title'        => 'Minhas cobranças',
                'activeNav'    => 'finance',
                'hasUnits'     => $unitIds !== [],
                'pending'      => array_values(array_filter($invoices, static fn (array $i): bool => $i['status'] === 'open')),
                'paid'         => array_values(array_filter($invoices, static fn (array $i): bool => $i['status'] === 'paid')),
                'types'        => Invoice::TYPES,
            ]);
        }

        $canManage = Auth::hasRole(self::MANAGERS);
        $categories = new FinancialCategory();
        if ($canManage && $categories->activeIncome() === []) {
            $categories->ensureDefaults();
        }

        return $this->view('finance/manager', [
            'title'      => 'Financeiro',
            'activeNav'  => 'finance',
            'canManage'  => $canManage,
            'overview'   => (new Invoice())->unitOverview($today),
            'units'      => (new Unit())->active(),
            'categories' => $categories->activeIncome(),
            'types'      => Invoice::TYPES,
            'today'      => $today,
        ]);
    }

    /**
     * POST /finance/charges: creates a charge for a unit.
     *
     * The route already requires role "manager"; requireRole() repeats it here
     * so the rule holds even if the route table is edited by mistake.
     */
    public function storeCharge(): Response
    {
        $this->requireRole(self::MANAGERS);

        $v = new Validator($this->request);
        $unitId = $v->id('unit_id', 'a unidade');
        $categoryId = $v->id('category_id', 'a categoria');
        $type = $v->enum('invoice_type', 'Tipo de cobrança', array_keys(Invoice::TYPES));
        $month = $v->month('reference_month', 'Mês de referência');
        $dueDate = $v->date('due_date', 'Vencimento');
        $amount = $v->money('amount', 'Valor'); // canonical decimal string, never float
        $description = $v->string('description', 'Descrição', 3, 200);
        $notes = $v->string('notes', 'Observações', 1, 500, required: false);

        if ($dueDate !== null && $dueDate < TenantContext::today()) {
            $v->addError('due_date', 'O vencimento não pode ser anterior a hoje.');
        }
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/finance', 422, self::KEEP);
        }

        try {
            $result = (new InvoiceService())->createCharge([
                'unit_id'         => (int) $unitId,
                'category_id'     => (int) $categoryId,
                'invoice_type'    => (string) $type,
                'reference_month' => (string) $month,
                'due_date'        => (string) $dueDate,
                'amount'          => (string) $amount,
                'description'     => (string) $description,
                'notes'           => $notes,
            ], (int) Auth::id());
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], '/finance', $e->status(), self::KEEP);
        }

        return $this->done("Cobrança nº {$result['invoice_number']} criada.", '/finance', ['invoice' => $result], 201);
    }
}
