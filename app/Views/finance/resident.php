<?php
/**
 * A resident's own bills. $pending and $paid only contain invoices of units
 * the resident owns or rents (filtered in SQL by FinanceController/Invoice::forUnits).
 * Amounts arrive as DECIMAL strings and are formatted with money_br(), never cast to float.
 *
 * @var bool                       $hasUnits
 * @var list<array<string, mixed>> $pending
 * @var list<array<string, mixed>> $paid
 * @var array<string, string>      $types
 */
$overdueCount = count(array_filter($pending, static fn (array $i): bool => (bool) $i['is_overdue']));
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Minhas cobranças</h1>
        <p class="page-header__subtitle">Boletos e taxas da sua unidade</p>
    </div>
</section>

<?php if (!$hasUnits): ?>
    <div class="alert alert--warning">
        Nenhuma unidade vinculada à sua conta como proprietário ou inquilino. Fale com a administração.
    </div>
<?php else: ?>
    <?php if ($overdueCount > 0): ?>
        <div class="alert alert--error" role="alert">
            Você tem <?= e($overdueCount) ?> cobrança(s) vencida(s). Regularize para evitar multa e juros.
        </div>
    <?php endif; ?>

    <section class="panel">
        <h2 class="panel__title panel__title--bar">Em aberto</h2>
        <?php if ($pending === []): ?>
            <p class="state">Nenhuma cobrança em aberto. Tudo em dia!</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr><th>Nº</th><th>Unidade</th><th>Descrição</th><th>Referência</th><th>Vencimento</th><th class="num">Valor</th><th>Situação</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pending as $invoice): ?>
                        <?php $overdue = (bool) $invoice['is_overdue']; ?>
                        <tr class="<?= $overdue ? 'row--overdue' : '' ?>">
                            <td class="mono"><?= e($invoice['invoice_number']) ?></td>
                            <td><?= e($invoice['unit_label']) ?></td>
                            <td><?= e($types[$invoice['invoice_type']] ?? $invoice['invoice_type']) ?></td>
                            <td><?= e(substr(date_br((string) $invoice['reference_month']), 3)) /* "mm/YYYY" */ ?></td>
                            <td><?= e(date_br($invoice['due_date'])) ?></td>
                            <td class="num"><?= e(money_br((string) $invoice['total_amount'])) ?></td>
                            <td>
                                <?php if ($overdue): ?>
                                    <span class="pill pill--overdue">Vencida</span>
                                <?php else: ?>
                                    <span class="pill pill--open">A vencer</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2 class="panel__title panel__title--bar">Pagas</h2>
        <?php if ($paid === []): ?>
            <p class="state">Nenhuma cobrança paga ainda.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--compact">
                    <thead>
                    <tr><th>Nº</th><th>Unidade</th><th>Descrição</th><th>Vencimento</th><th>Pago em</th><th class="num">Valor</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($paid as $invoice): ?>
                        <tr>
                            <td class="mono"><?= e($invoice['invoice_number']) ?></td>
                            <td><?= e($invoice['unit_label']) ?></td>
                            <td><?= e($types[$invoice['invoice_type']] ?? $invoice['invoice_type']) ?></td>
                            <td><?= e(date_br($invoice['due_date'])) ?></td>
                            <td><?= e(local_datetime($invoice['paid_at'], 'd/m/Y')) ?></td>
                            <td class="num"><?= e(money_br((string) $invoice['total_amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
