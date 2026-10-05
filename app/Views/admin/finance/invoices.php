<?php
/**
 * Invoice list with filters; each row links to its detail page, where payment
 * and cancellation are recorded.
 *
 * @var list<array<string, mixed>>          $invoices
 * @var list<array{id: int, label: string}> $units
 * @var array<string, string>               $query
 * @var array{page: int, per_page: int, total: int, pages: int} $pagination
 * @var array<string, string>               $types
 * @var array<string, string>               $statusLabels
 */
$statusFilters = ['open' => 'Em aberto', 'overdue' => 'Vencidas', 'paid' => 'Pagas', 'cancelled' => 'Canceladas'];
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Cobranças</h1>
        <p class="page-header__subtitle">Registre pagamentos manuais ou cancele cobranças em aberto</p>
    </div>
    <a class="btn" href="/finance">Lançar cobrança</a>
</section>

<section class="panel">
    <form class="filters" method="get" action="/admin/finance/invoices">
        <label class="filters__field">
            <span class="sr-only">Situação</span>
            <select name="status">
                <option value="">Todas as situações</option>
                <?php foreach ($statusFilters as $code => $label): ?>
                    <option value="<?= e($code) ?>"<?= ($query['status'] ?? '') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filters__field">
            <span class="sr-only">Unidade</span>
            <select name="unit_id">
                <option value="">Todas as unidades</option>
                <?php foreach ($units as $unit): ?>
                    <option value="<?= e($unit['id']) ?>"<?= ($query['unit_id'] ?? '') === (string) $unit['id'] ? ' selected' : '' ?>><?= e($unit['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filters__field">
            <span class="form__label">Vencimento de</span>
            <input type="date" name="from" value="<?= e($query['from'] ?? '') ?>">
        </label>
        <label class="filters__field">
            <span class="form__label">até</span>
            <input type="date" name="to" value="<?= e($query['to'] ?? '') ?>">
        </label>
        <button type="submit" class="btn">Filtrar</button>
    </form>

    <?php if ($invoices === []): ?>
        <p class="state">Nenhuma cobrança encontrada.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Nº</th><th>Unidade</th><th>Tipo</th><th>Referência</th><th>Vencimento</th><th class="num">Valor</th><th>Situação</th><th class="table__action"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($invoices as $invoice): ?>
                    <?php $overdue = (int) $invoice['is_overdue'] === 1; ?>
                    <tr class="<?= $overdue ? 'row--overdue' : '' ?>">
                        <td class="mono"><?= e($invoice['invoice_number']) ?></td>
                        <td><?= e($invoice['unit_label']) ?></td>
                        <td><?= e($types[$invoice['invoice_type']] ?? $invoice['invoice_type']) ?></td>
                        <td><?= e(substr((string) $invoice['reference_month'], 5, 2) . '/' . substr((string) $invoice['reference_month'], 0, 4)) ?></td>
                        <td><?= e(date_br((string) $invoice['due_date'])) ?></td>
                        <td class="num"><?= e(money_br((string) $invoice['total_amount'])) ?></td>
                        <td><?= $overdue ? pill('overdue', 'Vencida') : pill((string) $invoice['status'], $statusLabels[$invoice['status']] ?? (string) $invoice['status']) ?></td>
                        <td class="table__action"><a class="btn btn--small" href="/admin/finance/invoices/<?= e($invoice['id']) ?>">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= partial('pagination', ['pagination' => $pagination, 'basePath' => '/admin/finance/invoices', 'query' => $query]) ?>
</section>
