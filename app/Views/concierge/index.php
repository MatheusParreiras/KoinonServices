<?php
/**
 * Concierge desk. Async actions (register exit, confirm pickup) are plain
 * <form data-async> elements posting to /api/... and are handled by
 * public/assets/js/core/async-form.js. Without JavaScript they still render,
 * but those buttons need JS to submit (the API answers JSON).
 *
 * LGPD: visitor RGs are only shown masked (mask_document).
 *
 * @var bool                                $canOperate False for the read-only Super Admin.
 * @var list<array{id: int, label: string}> $units
 * @var list<array<string, mixed>>          $inside
 * @var list<array<string, mixed>>          $recentExits
 * @var list<array<string, mixed>>          $packages
 * @var array<string, string>               $visitTypes
 * @var array<string, string>               $sizes
 * @var array<string, string>               $errors
 * @var array<string, string>               $old
 */
$selected = static fn (string $field, string|int $value): string
    => (string) ($old[$field] ?? '') === (string) $value ? ' selected' : '';
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Portaria</h1>
        <p class="page-header__subtitle">Controle de visitantes e encomendas</p>
    </div>
</section>

<div class="columns">
    <?php if ($canOperate): ?>
        <div class="columns__side">
            <section class="panel panel--padded">
                <h2 class="panel__title">Registrar entrada de visitante</h2>
                <form method="post" action="/concierge/visits" class="form" novalidate>
                    <?= csrf_field() ?>
                    <?= field_error($errors, 'general') ?>
                    <label class="form__field">
                        <span class="form__label">Nome completo</span>
                        <input type="text" name="full_name" maxlength="150" required value="<?= e($old['full_name'] ?? '') ?>">
                        <?= field_error($errors, 'full_name') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">RG</span>
                        <!-- autocomplete off: the desk computer is shared; the browser must not remember IDs. -->
                        <input type="text" name="document_number" maxlength="20" required autocomplete="off" inputmode="numeric">
                        <?= field_error($errors, 'document_number') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">Unidade visitada</span>
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
                            <select name="visit_type">
                                <?php foreach ($visitTypes as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= $selected('visit_type', $value) ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="form__field">
                            <span class="form__label">Placa (opcional)</span>
                            <input type="text" name="vehicle_plate" maxlength="8" value="<?= e($old['vehicle_plate'] ?? '') ?>">
                        </label>
                    </div>
                    <button type="submit" class="btn btn--primary">Registrar entrada</button>
                </form>
            </section>

            <section class="panel panel--padded">
                <h2 class="panel__title">Registrar encomenda</h2>
                <form method="post" action="/concierge/packages" class="form" novalidate>
                    <?= csrf_field() ?>
                    <label class="form__field">
                        <span class="form__label">Unidade de destino</span>
                        <select name="unit_id" required>
                            <option value="">Selecione…</option>
                            <?php foreach ($units as $unit): ?>
                                <option value="<?= e($unit['id']) ?>"><?= e($unit['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="form__row">
                        <label class="form__field">
                            <span class="form__label">Transportadora</span>
                            <input type="text" name="carrier" maxlength="80">
                        </label>
                        <label class="form__field">
                            <span class="form__label">Tamanho</span>
                            <select name="package_size">
                                <?php foreach ($sizes as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= $value === 'small' ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="form__row">
                        <label class="form__field">
                            <span class="form__label">Rastreio</span>
                            <input type="text" name="tracking_code" maxlength="60">
                        </label>
                        <label class="form__field">
                            <span class="form__label">Local (prateleira)</span>
                            <input type="text" name="storage_location" maxlength="60">
                        </label>
                    </div>
                    <label class="form__field">
                        <span class="form__label">Descrição</span>
                        <input type="text" name="description" maxlength="255">
                    </label>
                    <button type="submit" class="btn btn--primary">Registrar e avisar morador</button>
                </form>
            </section>
        </div>
    <?php endif; ?>

    <div class="columns__main">
        <section class="panel">
            <h2 class="panel__title panel__title--bar">
                Visitantes no condomínio <span class="counter" data-counter="inside"><?= e(count($inside)) ?></span>
            </h2>
            <?php if ($inside === []): ?>
                <p class="state">Nenhum visitante no condomínio.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th>Nome</th><th>RG</th><th>Unidade</th><th>Tipo</th><th>Entrada</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($inside as $visit): ?>
                            <tr data-row>
                                <td><?= e($visit['full_name']) ?></td>
                                <td class="mono"><?= e(mask_document((string) $visit['document_number'])) ?></td>
                                <td><?= e($visit['unit_label']) ?></td>
                                <td><?= e($visitTypes[$visit['visit_type']] ?? $visit['visit_type']) ?></td>
                                <td><?= e(local_datetime($visit['entry_at'], 'H:i')) ?></td>
                                <td class="table__action" data-status-cell>
                                    <?php if ($canOperate): ?>
                                        <form method="post" action="/api/concierge/visits/<?= e($visit['id']) ?>/exit"
                                              data-async="visit-exit" data-decrement="inside">
                                            <button type="submit" class="btn btn--small">Registrar saída</button>
                                            <span class="feedback" data-feedback role="status"></span>
                                        </form>
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
            <h2 class="panel__title panel__title--bar">
                Encomendas aguardando retirada <span class="counter" data-counter="packages"><?= e(count($packages)) ?></span>
            </h2>
            <?php if ($packages === []): ?>
                <p class="state">Nenhuma encomenda na portaria.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th>Unidade</th><th>Encomenda</th><th>Recebida</th><th>Local</th><th>Retirada</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($packages as $package): ?>
                            <tr data-row>
                                <td><?= e($package['unit_label']) ?></td>
                                <td>
                                    <?= e($package['carrier'] ?? 'Sem transportadora') ?>
                                    <span class="muted">· <?= e($sizes[$package['package_size']] ?? '') ?></span>
                                    <?php if (!empty($package['description'])): ?>
                                        <div class="muted small"><?= e($package['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(local_datetime($package['received_at'])) ?></td>
                                <td><?= e($package['storage_location'] ?? '—') ?></td>
                                <td class="table__action" data-status-cell>
                                    <?php if ($canOperate): ?>
                                        <form method="post" action="/api/concierge/packages/<?= e($package['id']) ?>/pickup"
                                              class="inline-form" data-async="package-pickup" data-decrement="packages" novalidate>
                                            <input type="text" name="pickup_code" placeholder="Código" required
                                                   inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="off"
                                                   aria-label="Código de retirada">
                                            <input type="text" name="picked_up_by_name" placeholder="Quem retirou" required
                                                   maxlength="150" aria-label="Nome de quem retirou">
                                            <button type="submit" class="btn btn--small btn--primary">Confirmar</button>
                                            <span class="feedback" data-feedback role="status"></span>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">Aguardando</span>
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
            <h2 class="panel__title panel__title--bar">Últimas saídas</h2>
            <?php if ($recentExits === []): ?>
                <p class="state">Nenhuma saída registrada.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table table--compact">
                        <thead><tr><th>Nome</th><th>Unidade</th><th>Entrada</th><th>Saída</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentExits as $visit): ?>
                            <tr>
                                <td><?= e($visit['full_name']) ?></td>
                                <td><?= e($visit['unit_label']) ?></td>
                                <td><?= e(local_datetime($visit['entry_at'])) ?></td>
                                <td><?= e(local_datetime($visit['exit_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
