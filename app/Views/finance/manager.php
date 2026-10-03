<?php
/**
 * Manager view: financial status of every unit and the "new charge" form.
 * The Super Admin sees the table but not the form ($canManage = false).
 *
 * @var bool                                $canManage
 * @var list<array<string, mixed>>          $overview
 * @var list<array{id: int, label: string}> $units
 * @var list<array{id: int, name: string}>  $categories
 * @var array<string, string>               $types
 * @var string                              $today
 * @var array<string, string>               $errors
 * @var array<string, string>               $old
 */
$selected = static fn (string $field, string|int $value): string
    => (string) ($old[$field] ?? '') === (string) $value ? ' selected' : '';
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Financeiro</h1>
        <p class="page-header__subtitle">Situação das unidades e lançamento de cobranças</p>
    </div>
</section>

<div class="columns">
    <?php if ($canManage): ?>
        <div class="columns__side">
            <section class="panel panel--padded">
                <h2 class="panel__title">Nova cobrança</h2>
                <form method="post" action="/finance/charges" class="form" novalidate>
                    <?= csrf_field() ?>
                    <?= field_error($errors, 'general') ?>
                    <label class="form__field">
                        <span class="form__label">Unidade</span>
                        <select name="unit_id" required>
                            <option value="">Selecione…</option>
                            <?php foreach ($units as $unit): ?>
                                <option value="<?= e($unit['id']) ?>"<?= $selected('unit_id', $unit['id']) ?>><?= e($unit['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= field_error($errors, 'unit_id') ?>
                    </label>
                    <div class="form__row">
                        <label class="form__field">
                            <span class="form__label">Tipo</span>
                            <select name="invoice_type">
                                <?php foreach ($types as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= $selected('invoice_type', $value) ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error($errors, 'invoice_type') ?>
                        </label>
                        <label class="form__field">
                            <span class="form__label">Categoria</span>
                            <select name="category_id" required>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= e($category['id']) ?>"<?= $selected('category_id', $category['id']) ?>><?= e($category['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error($errors, 'category_id') ?>
                        </label>
                    </div>
                    <label class="form__field">
                        <span class="form__label">Descrição</span>
                        <input type="text" name="description" maxlength="200" required value="<?= e($old['description'] ?? '') ?>">
                        <?= field_error($errors, 'description') ?>
                    </label>
                    <div class="form__row">
                        <label class="form__field">
                            <span class="form__label">Mês de referência</span>
                            <input type="month" name="reference_month" required
                                   value="<?= e($old['reference_month'] ?? substr($today, 0, 7)) ?>">
                            <?= field_error($errors, 'reference_month') ?>
                        </label>
                        <label class="form__field">
                            <span class="form__label">Vencimento</span>
                            <input type="date" name="due_date" required min="<?= e($today) ?>" value="<?= e($old['due_date'] ?? '') ?>">
                            <?= field_error($errors, 'due_date') ?>
                        </label>
                    </div>
                    <label class="form__field">
                        <span class="form__label">Valor (R$)</span>
                        <!-- type="text" + inputmode: accepts "1.234,56"; the server parses it as an exact decimal. -->
                        <input type="text" name="amount" inputmode="decimal" placeholder="0,00" required value="<?= e($old['amount'] ?? '') ?>">
                        <?= field_error($errors, 'amount') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">Observações (opcional)</span>
                        <textarea name="notes" rows="2" maxlength="500"><?= e($old['notes'] ?? '') ?></textarea>
                    </label>
                    <button type="submit" class="btn btn--primary">Lançar cobrança</button>
                </form>
            </section>
        </div>
    <?php endif; ?>

    <div class="columns__main">
        <section class="panel">
            <h2 class="panel__title panel__title--bar">Situação por unidade</h2>
            <?php if ($overview === []): ?>
                <p class="state">Nenhuma unidade cadastrada.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Unidade</th><th class="num">Em aberto</th><th class="num">Valor em aberto</th>
                            <th class="num">Vencidas</th><th class="num">Valor vencido</th><th>Próx. vencimento</th><th>Situação</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($overview as $row): ?>
                            <?php $overdue = (int) $row['overdue_count'] > 0; ?>
                            <tr class="<?= $overdue ? 'row--overdue' : '' ?>">
                                <td><?= e($row['unit_label']) ?></td>
                                <td class="num"><?= e((int) $row['open_count']) ?></td>
                                <td class="num"><?= e(money_br((string) $row['open_amount'])) ?></td>
                                <td class="num"><?= e((int) $row['overdue_count']) ?></td>
                                <td class="num"><?= e(money_br((string) $row['overdue_amount'])) ?></td>
                                <td><?= e(date_br($row['next_due_date'])) ?></td>
                                <td>
                                    <?php if ($overdue): ?>
                                        <span class="pill pill--overdue">Inadimplente</span>
                                    <?php elseif ((int) $row['open_count'] > 0): ?>
                                        <span class="pill pill--open">Em dia</span>
                                    <?php else: ?>
                                        <span class="pill pill--paid">Sem pendências</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
