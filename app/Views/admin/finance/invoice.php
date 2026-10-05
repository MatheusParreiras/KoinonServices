<?php
/**
 * One invoice: details, payments, and (when open) the manual payment and
 * cancellation forms. Invoices are never deleted.
 *
 * @var array<string, mixed>  $invoice        Invoice::findDetailed() row (+ payments).
 * @var array<string, string> $types
 * @var array<string, string> $statusLabels
 * @var array<string, string> $paymentMethods
 * @var string                $today
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$overdue = (int) $invoice['is_overdue'] === 1;
$id = (int) $invoice['id'];
?>
<section class="page-header">
    <div>
        <p class="page-header__eyebrow"><a href="/admin/finance/invoices">← Cobranças</a></p>
        <h1 class="page-header__title">Cobrança nº <?= e($invoice['invoice_number']) ?></h1>
        <p class="page-header__subtitle">
            <?= e($invoice['unit_label']) ?> ·
            <?= $overdue ? pill('overdue', 'Vencida') : pill((string) $invoice['status'], $statusLabels[$invoice['status']] ?? (string) $invoice['status']) ?>
        </p>
    </div>
</section>

<?= field_error($errors, 'general') ?>

<div class="grid-2">
    <section class="panel panel--padded">
        <h2 class="panel__title">Dados</h2>
        <dl class="details">
            <dt>Tipo</dt><dd><?= e($types[$invoice['invoice_type']] ?? $invoice['invoice_type']) ?></dd>
            <dt>Referência</dt><dd><?= e(substr((string) $invoice['reference_month'], 5, 2) . '/' . substr((string) $invoice['reference_month'], 0, 4)) ?></dd>
            <dt>Emissão</dt><dd><?= e(date_br((string) $invoice['issue_date'])) ?></dd>
            <dt>Vencimento</dt><dd><?= e(date_br((string) $invoice['due_date'])) ?></dd>
            <dt>Valor</dt><dd class="strong"><?= e(money_br((string) $invoice['total_amount'])) ?></dd>
            <?php if ($invoice['paid_at'] !== null): ?>
                <dt>Pago em</dt><dd><?= e(local_datetime((string) $invoice['paid_at'], 'd/m/Y')) ?></dd>
            <?php endif; ?>
            <?php if ($invoice['status'] === 'cancelled'): ?>
                <dt>Cancelada em</dt><dd><?= e(local_datetime((string) $invoice['cancelled_at'])) ?></dd>
                <dt>Motivo</dt><dd class="prewrap"><?= e($invoice['cancellation_reason']) ?></dd>
            <?php endif; ?>
            <?php if ($invoice['notes'] !== null): ?>
                <dt>Observações</dt><dd class="prewrap"><?= e($invoice['notes']) ?></dd>
            <?php endif; ?>
        </dl>

        <?php if ($invoice['payments'] !== []): ?>
            <h3 class="panel__subtitle">Pagamentos</h3>
            <table class="table table--compact">
                <thead><tr><th>Data</th><th>Forma</th><th class="num">Valor</th><th>Registrado por</th></tr></thead>
                <tbody>
                <?php foreach ($invoice['payments'] as $payment): ?>
                    <tr>
                        <td><?= e(local_datetime((string) $payment['paid_at'], 'd/m/Y')) ?></td>
                        <td><?= e($paymentMethods[$payment['payment_method']] ?? $payment['payment_method']) ?></td>
                        <td class="num"><?= e(money_br((string) $payment['amount'])) ?></td>
                        <td><?= e($payment['recorded_by'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <?php if ($invoice['status'] === 'open'): ?>
        <div class="stack">
            <section class="panel panel--padded">
                <h2 class="panel__title">Registrar pagamento</h2>
                <form method="post" action="/admin/finance/invoices/<?= e($id) ?>/payment" class="form" novalidate
                      data-confirm="Registrar o pagamento integral de <?= e(money_br((string) $invoice['total_amount'])) ?>?">
                    <?= csrf_field() ?>
                    <div class="form__row">
                        <label class="form__field">
                            <span class="form__label">Data do pagamento</span>
                            <input type="date" name="paid_on" required max="<?= e($today) ?>" value="<?= e($old['paid_on'] ?? $today) ?>">
                            <?= field_error($errors, 'paid_on') ?>
                        </label>
                        <label class="form__field">
                            <span class="form__label">Forma</span>
                            <select name="payment_method">
                                <?php foreach ($paymentMethods as $code => $label): ?>
                                    <option value="<?= e($code) ?>"<?= ($old['payment_method'] ?? 'pix') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error($errors, 'payment_method') ?>
                        </label>
                    </div>
                    <label class="form__field">
                        <span class="form__label">Observações (opcional)</span>
                        <textarea name="notes" rows="2" maxlength="500"><?= e($old['notes'] ?? '') ?></textarea>
                        <?= field_error($errors, 'notes') ?>
                    </label>
                    <p class="form__hint">O valor registrado é o total da cobrança: <?= e(money_br((string) $invoice['total_amount'])) ?>.</p>
                    <button type="submit" class="btn btn--primary">Marcar como paga</button>
                </form>
            </section>

            <section class="panel panel--padded">
                <h2 class="panel__title">Cancelar cobrança</h2>
                <form method="post" action="/admin/finance/invoices/<?= e($id) ?>/cancel" class="form" novalidate
                      data-confirm="Cancelar a cobrança nº <?= e($invoice['invoice_number']) ?>? Ela continuará no histórico como cancelada.">
                    <?= csrf_field() ?>
                    <label class="form__field">
                        <span class="form__label">Motivo</span>
                        <input type="text" name="cancellation_reason" minlength="5" maxlength="255" required>
                        <?= field_error($errors, 'cancellation_reason') ?>
                    </label>
                    <button type="submit" class="btn btn--danger">Cancelar cobrança</button>
                </form>
            </section>
        </div>
    <?php endif; ?>
</div>
