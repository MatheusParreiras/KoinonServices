<?php
/**
 * Reservations: booking form (validated client-side by reservations.js and
 * again on the server) and the list of upcoming reservations.
 *
 * Area rules are exposed as data-* attributes so the client can give instant
 * feedback; the server re-checks every one of them.
 *
 * @var list<array<string, mixed>>          $areas
 * @var list<array{id: int, label: string}> $units        Units the user may book for.
 * @var list<array<string, mixed>>          $reservations
 * @var bool                                $canBook
 * @var bool                                $canManage
 * @var list<int>                           $myUnitIds
 * @var string                              $today        Local Y-m-d.
 * @var array<string, string>               $statusLabels
 * @var array<string, string>               $errors
 * @var array<string, string>               $old
 */
$selected = static fn (string $field, string|int $value): string
    => (string) ($old[$field] ?? '') === (string) $value ? ' selected' : '';
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Reservas</h1>
        <p class="page-header__subtitle">Churrasqueira, salão de festas e academia</p>
    </div>
</section>

<div class="columns">
    <?php if ($canBook): ?>
        <div class="columns__side">
            <section class="panel panel--padded">
                <h2 class="panel__title">Nova reserva</h2>

                <?php if ($units === []): ?>
                    <div class="alert alert--warning">Sua conta ainda não está vinculada a uma unidade. Fale com a administração.</div>
                <?php else: ?>
                    <form method="post" action="/reservations" class="form" id="booking-form" novalidate data-today="<?= e($today) ?>">
                        <?= csrf_field() ?>
                        <div class="alert alert--error" data-form-error <?= isset($errors['general']) ? '' : 'hidden' ?>><?= e($errors['general'] ?? '') ?></div>

                        <label class="form__field">
                            <span class="form__label">Área</span>
                            <select name="common_area_id" required>
                                <option value="">Selecione…</option>
                                <?php foreach ($areas as $area): ?>
                                    <option value="<?= e($area['id']) ?>"
                                            data-max-people="<?= e($area['max_people'] ?? '') ?>"
                                            data-max-days="<?= e($area['max_advance_days']) ?>"
                                            data-min-hours="<?= e($area['min_advance_hours']) ?>"
                                            data-approval="<?= (int) $area['requires_approval'] ?>"
                                        <?= $selected('common_area_id', $area['id']) ?>>
                                        <?= e($area['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="form__hint" data-area-hint></span>
                            <?= field_error($errors, 'common_area_id') ?>
                        </label>

                        <label class="form__field">
                            <span class="form__label">Unidade</span>
                            <select name="unit_id" required>
                                <?php if (count($units) > 1): ?><option value="">Selecione…</option><?php endif; ?>
                                <?php foreach ($units as $unit): ?>
                                    <option value="<?= e($unit['id']) ?>"<?= $selected('unit_id', $unit['id']) ?>><?= e($unit['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error($errors, 'unit_id') ?>
                        </label>

                        <label class="form__field">
                            <span class="form__label">Data</span>
                            <input type="date" name="reservation_date" required min="<?= e($today) ?>"
                                   value="<?= e($old['reservation_date'] ?? '') ?>">
                            <?= field_error($errors, 'reservation_date') ?>
                        </label>

                        <div class="form__row">
                            <label class="form__field">
                                <span class="form__label">Início</span>
                                <input type="time" name="start_time" required step="900" value="<?= e($old['start_time'] ?? '') ?>">
                                <?= field_error($errors, 'start_time') ?>
                            </label>
                            <label class="form__field">
                                <span class="form__label">Término</span>
                                <input type="time" name="end_time" required step="900" value="<?= e($old['end_time'] ?? '') ?>">
                                <?= field_error($errors, 'end_time') ?>
                            </label>
                        </div>

                        <label class="form__field">
                            <span class="form__label">Número de convidados</span>
                            <input type="number" name="guest_count" min="0" max="500" value="<?= e($old['guest_count'] ?? '0') ?>">
                            <?= field_error($errors, 'guest_count') ?>
                        </label>

                        <label class="form__field">
                            <span class="form__label">Observações (opcional)</span>
                            <textarea name="notes" rows="3" maxlength="500"><?= e($old['notes'] ?? '') ?></textarea>
                        </label>

                        <button type="submit" class="btn btn--primary">Reservar</button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>

    <div class="columns__main">
        <section class="panel">
            <h2 class="panel__title panel__title--bar">Próximas reservas</h2>
            <?php if ($reservations === []): ?>
                <p class="state">Nenhuma reserva a partir de hoje.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th>Data</th><th>Horário</th><th>Área</th><th>Unidade</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($reservations as $r): ?>
                            <?php
                            $isActive = in_array($r['status'], ['pending', 'approved'], true);
                            $isMine = in_array((int) $r['unit_id'], $myUnitIds, true);
                            ?>
                            <tr>
                                <td><?= e(date_br($r['reservation_date'])) ?></td>
                                <td><?= e(substr((string) $r['starts_at'], 11, 5)) ?>–<?= e(substr((string) $r['ends_at'], 11, 5)) ?></td>
                                <td><?= e($r['area_name']) ?></td>
                                <td>
                                    <?= e($r['unit_label']) ?>
                                    <?php if ($canManage): ?><div class="muted small"><?= e($r['requested_by_name']) ?></div><?php endif; ?>
                                </td>
                                <td><span class="pill pill--<?= e($r['status']) ?>"><?= e($statusLabels[$r['status']] ?? $r['status']) ?></span></td>
                                <td class="table__action">
                                    <?php if ($isActive && $canManage && $r['status'] === 'pending'): ?>
                                        <form method="post" action="/reservations/<?= e($r['id']) ?>/decision" class="inline-form">
                                            <?= csrf_field() ?>
                                            <button type="submit" name="decision" value="approved" class="btn btn--small btn--primary">Aprovar</button>
                                            <button type="submit" name="decision" value="rejected" class="btn btn--small">Recusar</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($isActive && ($canManage || $isMine)): ?>
                                        <form method="post" action="/reservations/<?= e($r['id']) ?>/cancel" class="inline-form" data-confirm="Cancelar esta reserva?">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn--small btn--danger-ghost">Cancelar</button>
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
    </div>
</div>
