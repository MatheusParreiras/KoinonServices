<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\TenantContext;
use App\Core\Validator;
use App\Models\Invoice;
use App\Models\Unit;
use App\Services\BusinessRuleException;
use App\Services\CsvExporter;
use App\Services\InvoiceService;
use DateTimeImmutable;

/**
 * Financial reports, the invoice list, manual payment and cancellation
 * (Property Manager, Phase 5).
 *
 * MONEY: every total is computed by MySQL on DECIMAL columns and travels as a
 * string ("1234.50"). PHP only formats it (money_br / CsvExporter::decimal);
 * there is no float arithmetic anywhere in this controller.
 */
final class FinanceController extends AdminController
{
    /** Longest period a report may cover, to keep the queries bounded. */
    private const MAX_PERIOD_DAYS = 366;

    /** GET /admin/finance/reports: page with the period form; totals load through reports.js. */
    public function reports(): Response
    {
        $this->requireRole(self::MANAGERS);
        $today = TenantContext::today();
        $invoices = new Invoice();

        return $this->view('admin/finance/reports', [
            'title'            => 'Relatórios financeiros',
            'activeNav'        => 'admin-finance',
            'defaultFrom'      => substr($today, 0, 8) . '01',
            'defaultTo'        => (new DateTimeImmutable($today))->modify('last day of this month')->format('Y-m-d'),
            'delinquency'      => $invoices->delinquency($today),
            'delinquencyTotal' => $invoices->delinquencyTotal($today),
            'today'            => $today,
            'scripts'          => ['js/admin/reports.js'],
        ]);
    }

    /** GET /admin/finance/reports/period?from=Y-m-d&to=Y-m-d (JSON) */
    public function period(): Response
    {
        $this->requireRole(self::MANAGERS);
        [$from, $to, $errors] = $this->periodFromQuery();
        if ($errors !== []) {
            return $this->failure('Período inválido.', 422, $errors);
        }

        return $this->success(['from' => $from, 'to' => $to] + $this->periodTotals($from, $to));
    }

    /** GET /admin/finance/reports/period.csv?from=&to= */
    public function periodCsv(): Response
    {
        $this->requireRole(self::MANAGERS);
        [$from, $to, $errors] = $this->periodFromQuery();
        if ($errors !== []) {
            throw new HttpException(422, (string) reset($errors));
        }

        $today = TenantContext::today();
        $totals = $this->periodTotals($from, $to);
        $rows = [
            ['Relatório do período', date_br($from) . ' a ' . date_br($to)],
            ['Condomínio', (string) TenantContext::name()],
            ['Total faturado', CsvExporter::decimal($totals['billed'])],
            ['Total recebido', CsvExporter::decimal($totals['received'])],
            ['Total a vencer', CsvExporter::decimal($totals['pending'])],
            ['Total vencido', CsvExporter::decimal($totals['overdue'])],
            [],
            ['Nº', 'Unidade', 'Tipo', 'Referência', 'Vencimento', 'Valor', 'Situação', 'Pago em'],
        ];
        foreach ((new Invoice())->periodLines($from, $to, $today) as $line) {
            $rows[] = [
                (int) $line['invoice_number'],
                (string) $line['unit_label'],
                Invoice::TYPES[$line['invoice_type']] ?? (string) $line['invoice_type'],
                substr((string) $line['reference_month'], 0, 7),
                date_br((string) $line['due_date']),
                CsvExporter::decimal((string) $line['total_amount']),
                (int) $line['is_overdue'] === 1 ? 'Vencida' : (Invoice::STATUS_LABELS[$line['status']] ?? (string) $line['status']),
                $line['paid_at'] === null ? '' : local_datetime((string) $line['paid_at'], 'd/m/Y'),
            ];
        }

        return Response::file((new CsvExporter())->build($rows), 'text/csv; charset=UTF-8', "relatorio-{$from}-a-{$to}.csv");
    }

    /** GET /admin/finance/reports/delinquency.csv */
    public function delinquencyCsv(): Response
    {
        $this->requireRole(self::MANAGERS);
        $today = TenantContext::today();
        $invoices = new Invoice();

        $rows = [['Unidade', 'Cobranças vencidas', 'Valor devido', 'Vencimento mais antigo', 'Dias em atraso']];
        foreach ($invoices->delinquency($today) as $row) {
            $rows[] = [
                (string) $row['unit_label'],
                (int) $row['overdue_count'],
                CsvExporter::decimal((string) $row['amount_owed']),
                date_br((string) $row['oldest_due_date']),
                (int) $row['days_overdue'],
            ];
        }
        $rows[] = ['Total', '', CsvExporter::decimal($invoices->delinquencyTotal($today)), '', ''];

        return Response::file((new CsvExporter())->build($rows), 'text/csv; charset=UTF-8', "inadimplencia-{$today}.csv");
    }

    /** GET /admin/finance/invoices?status=&unit_id=&from=&to=&page= */
    public function invoices(): Response
    {
        $this->requireRole(self::MANAGERS);
        $today = TenantContext::today();
        $units = (new Unit())->active();

        $filters = [];
        $query = [];
        $status = $this->request->queryString('status');
        if (in_array($status, Invoice::LIST_FILTERS, true)) {
            $filters['status'] = $query['status'] = $status;
        }
        $unitId = (int) $this->request->queryString('unit_id');
        if (in_array($unitId, array_map(static fn (array $u): int => (int) $u['id'], $units), true)) {
            $filters['unit_id'] = $unitId;   // allowlist: this tenant's units only
            $query['unit_id'] = (string) $unitId;
        }
        foreach (['from', 'to'] as $key) {
            $value = $this->request->queryString($key);
            if (self::isDate($value)) {
                $filters[$key] = $query[$key] = $value;
            }
        }

        $invoices = new Invoice();
        $pagination = $this->pagination(30)->withTotal($invoices->countForAdmin($filters, $today));

        return $this->view('admin/finance/invoices', [
            'title'        => 'Cobranças',
            'activeNav'    => 'admin-invoices',
            'invoices'     => $invoices->searchForAdmin($filters, $today, $pagination),
            'units'        => $units,
            'query'        => $query,
            'pagination'   => $pagination->toArray(),
            'types'        => Invoice::TYPES,
            'statusLabels' => Invoice::STATUS_LABELS,
        ]);
    }

    /** GET /admin/finance/invoices/{id} */
    public function show(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $today = TenantContext::today();
        $invoice = (new Invoice())->findDetailed((int) $id, $today) ?? throw new HttpException(404);   // tenant-scoped

        return $this->view('admin/finance/invoice', [
            'title'          => 'Cobrança nº ' . $invoice['invoice_number'],
            'activeNav'      => 'admin-invoices',
            'invoice'        => $invoice,
            'types'          => Invoice::TYPES,
            'statusLabels'   => Invoice::STATUS_LABELS,
            'paymentMethods' => Invoice::PAYMENT_METHODS,
            'today'          => $today,
            'scripts'        => ['js/admin/confirm.js'],
        ]);
    }

    /** POST /admin/finance/invoices/{id}/payment */
    public function pay(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $back = "/admin/finance/invoices/{$id}";

        $v = new Validator($this->request);
        $paidOn = $v->date('paid_on', 'Data do pagamento');
        $method = $v->enum('payment_method', 'Forma de pagamento', array_keys(Invoice::PAYMENT_METHODS));
        $notes = $v->string('notes', 'Observações', 1, 500, required: false);
        if ($paidOn !== null && $paidOn > TenantContext::today()) {
            $v->addError('paid_on', 'A data do pagamento não pode estar no futuro.');
        }
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back, 422, ['paid_on', 'payment_method', 'notes']);
        }

        try {
            (new InvoiceService())->recordPayment((int) $id, (string) $paidOn, (string) $method, $notes, (int) Auth::id(), $this->request);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, $back);
        }

        return $this->done('Pagamento registrado.', $back);
    }

    /** POST /admin/finance/invoices/{id}/cancel */
    public function cancel(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $back = "/admin/finance/invoices/{$id}";

        $v = new Validator($this->request);
        $reason = $v->string('cancellation_reason', 'Motivo', 5, 255);
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back);
        }

        try {
            (new InvoiceService())->cancel((int) $id, (string) $reason, $this->request);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, $back);
        }

        return $this->done('Cobrança cancelada.', $back);
    }

    /**
     * Reads and checks ?from=&to= (local dates, inclusive range).
     *
     * @return array{0: string, 1: string, 2: array<string, string>}
     */
    private function periodFromQuery(): array
    {
        $from = $this->request->queryString('from');
        $to = $this->request->queryString('to');
        $errors = [];
        if (!self::isDate($from)) {
            $errors['from'] = 'Data inicial inválida.';
        }
        if (!self::isDate($to)) {
            $errors['to'] = 'Data final inválida.';
        }
        if ($errors === []) {
            $days = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');
            if ($days < 0) {
                $errors['to'] = 'A data final deve ser igual ou posterior à inicial.';
            } elseif ($days >= self::MAX_PERIOD_DAYS) {
                $errors['to'] = 'O período pode ter no máximo ' . self::MAX_PERIOD_DAYS . ' dias.';
            }
        }

        return [$from, $to, $errors];
    }

    /** @return array<string, string|int> */
    private function periodTotals(string $from, string $to): array
    {
        // Payments are UTC DATETIMEs: the local days [from, to] become the UTC
        // interval [local midnight of from, local midnight of the day after to).
        $fromUtc = TenantContext::localToUtc($from);
        $toUtcExcl = TenantContext::localToUtc((new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d'));

        return (new Invoice())->periodTotals($from, $to, $fromUtc, $toUtcExcl, TenantContext::today());
    }

    private static function isDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
