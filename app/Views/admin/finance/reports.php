<?php
/**
 * Financial reports. The period totals are loaded by public/assets/js/admin/reports.js
 * from GET /admin/finance/reports/period and written with textContent; the
 * delinquency table is rendered here. Both CSV exports are plain GET downloads.
 *
 * @var string                     $defaultFrom
 * @var string                     $defaultTo
 * @var list<array<string, mixed>> $delinquency
 * @var string                     $delinquencyTotal DECIMAL string.
 * @var string                     $today
 */
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Relatórios financeiros</h1>
        <p class="page-header__subtitle">Valores calculados pelo banco de dados, em reais</p>
    </div>
</section>

<section class="panel panel--padded">
    <h2 class="panel__title">Relatório do período</h2>
    <form class="filters filters--flush" id="period-form" action="/admin/finance/reports/period" novalidate>
        <label class="filters__field">
            <span class="form__label">Mês</span>
            <input type="month" name="month" value="<?= e(substr($defaultFrom, 0, 7)) ?>">
        </label>
        <span class="filters__or muted">ou</span>
        <label class="filters__field">
            <span class="form__label">De</span>
            <input type="date" name="from" required value="<?= e($defaultFrom) ?>">
        </label>
        <label class="filters__field">
            <span class="form__label">Até</span>
            <input type="date" name="to" required value="<?= e($defaultTo) ?>">
        </label>
        <button type="submit" class="btn btn--primary">Gerar relatório</button>
        <a class="btn" id="period-csv" href="/admin/finance/reports/period.csv?from=<?= e($defaultFrom) ?>&amp;to=<?= e($defaultTo) ?>" download>Exportar CSV</a>
        <span class="feedback" id="period-feedback" role="status"></span>
    </form>

    <div class="kpis" id="period-totals" aria-live="polite" aria-busy="true">
        <div class="kpi">
            <span class="kpi__label">Total faturado</span>
            <strong class="kpi__value" data-total="billed">—</strong>
            <span class="kpi__hint" data-total="invoice_count"></span>
        </div>
        <div class="kpi kpi--success">
            <span class="kpi__label">Total recebido</span>
            <strong class="kpi__value" data-total="received">—</strong>
            <span class="kpi__hint" data-total="payment_count"></span>
        </div>
        <div class="kpi">
            <span class="kpi__label">A vencer</span>
            <strong class="kpi__value" data-total="pending">—</strong>
        </div>
        <div class="kpi kpi--danger">
            <span class="kpi__label">Vencido</span>
            <strong class="kpi__value" data-total="overdue">—</strong>
            <span class="kpi__hint" data-total="overdue_count"></span>
        </div>
    </div>
    <p class="form__hint">Faturado, a vencer e vencido consideram as cobranças com vencimento no período; recebido considera os pagamentos registrados no período.</p>
</section>

<section class="panel">
    <h2 class="panel__title panel__title--bar">
        Inadimplência em <?= e(date_br($today)) ?>
        <a class="btn btn--small panel__title-action" href="/admin/finance/reports/delinquency.csv" download>Exportar CSV</a>
    </h2>
    <?php if ($delinquency === []): ?>
        <p class="state">Nenhuma unidade com cobranças vencidas.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Unidade</th><th class="num">Cobranças vencidas</th><th class="num">Valor devido</th><th>Vencimento mais antigo</th><th class="num">Dias em atraso</th><th class="table__action"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($delinquency as $row): ?>
                    <tr class="row--overdue">
                        <td class="strong"><?= e($row['unit_label']) ?></td>
                        <td class="num"><?= e((int) $row['overdue_count']) ?></td>
                        <td class="num"><?= e(money_br((string) $row['amount_owed'])) ?></td>
                        <td><?= e(date_br((string) $row['oldest_due_date'])) ?></td>
                        <td class="num"><?= e((int) $row['days_overdue']) ?></td>
                        <td class="table__action">
                            <a class="btn btn--small" href="/admin/finance/invoices?status=overdue&amp;unit_id=<?= e($row['unit_id']) ?>">Ver cobranças</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr><th>Total</th><th></th><th class="num"><?= e(money_br($delinquencyTotal)) ?></th><th colspan="3"></th></tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</section>
