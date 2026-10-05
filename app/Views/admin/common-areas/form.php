<?php
/**
 * Create / edit a common area.
 *
 * @var array<string, mixed>|null $area   null when creating.
 * @var array<string, string>      $types
 * @var array<string, string>      $errors
 * @var array<string, string>      $old
 */
$value = static fn (string $field, mixed $default = ''): string
    => (string) ($old[$field] ?? ($area[$field] ?? $default));
$time = static fn (string $field): string => substr($value($field), 0, 5);
$action = $area === null ? '/admin/common-areas' : '/admin/common-areas/' . (int) $area['id'];
?>
<section class="page-header">
    <div>
        <p class="page-header__eyebrow"><a href="/admin/common-areas">← Áreas comuns</a></p>
        <h1 class="page-header__title"><?= $area === null ? 'Nova área comum' : e($area['name']) ?></h1>
    </div>
</section>

<section class="panel panel--padded panel--narrow">
    <form method="post" action="<?= e($action) ?>" class="form" novalidate>
        <?= csrf_field() ?>
        <?= field_error($errors, 'general') ?>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Nome</span>
                <input type="text" name="name" maxlength="80" required value="<?= e($value('name')) ?>">
                <?= field_error($errors, 'name') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Tipo</span>
                <select name="area_type">
                    <?php foreach ($types as $code => $label): ?>
                        <option value="<?= e($code) ?>"<?= $value('area_type', 'other') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Capacidade (pessoas)</span>
                <input type="number" name="max_people" min="1" max="2000" value="<?= e($value('max_people')) ?>">
                <?= field_error($errors, 'max_people') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Reservas simultâneas</span>
                <input type="number" name="bookings_per_slot" min="1" max="50" value="<?= e($value('bookings_per_slot', '1')) ?>">
                <span class="form__hint">1 = uso exclusivo (salão, churrasqueira).</span>
                <?= field_error($errors, 'bookings_per_slot') ?>
            </label>
        </div>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Abre às</span>
                <input type="time" name="opens_at" value="<?= e($time('opens_at')) ?>">
                <?= field_error($errors, 'opens_at') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Fecha às</span>
                <input type="time" name="closes_at" value="<?= e($time('closes_at')) ?>">
                <?= field_error($errors, 'closes_at') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Duração máxima (min)</span>
                <input type="number" name="max_duration_minutes" min="30" max="1440" value="<?= e($value('max_duration_minutes')) ?>">
                <?= field_error($errors, 'max_duration_minutes') ?>
            </label>
        </div>
        <p class="form__hint">Deixe os horários em branco para permitir reservas a qualquer hora do dia.</p>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Antecedência mínima (horas)</span>
                <input type="number" name="min_advance_hours" min="0" max="720" value="<?= e($value('min_advance_hours', '24')) ?>">
                <?= field_error($errors, 'min_advance_hours') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Antecedência máxima (dias)</span>
                <input type="number" name="max_advance_days" min="1" max="365" value="<?= e($value('max_advance_days', '90')) ?>">
                <?= field_error($errors, 'max_advance_days') ?>
            </label>
        </div>
        <label class="form__field">
            <span class="form__label">Regras de uso (opcional)</span>
            <textarea name="rules" rows="4" maxlength="2000"><?= e($value('rules')) ?></textarea>
            <?= field_error($errors, 'rules') ?>
        </label>
        <label class="form__check">
            <input type="checkbox" name="requires_approval" value="1"<?= (int) $value('requires_approval', '1') === 1 ? ' checked' : '' ?>>
            Reservas precisam de aprovação do síndico
        </label>
        <?php if ($area !== null): ?>
            <label class="form__check">
                <input type="checkbox" name="is_active" value="1"<?= (int) $area['is_active'] === 1 ? ' checked' : '' ?>>
                Área ativa (desativar impede novas reservas; as existentes são mantidas)
            </label>
        <?php endif; ?>
        <div class="form__actions">
            <a class="btn" href="/admin/common-areas">Cancelar</a>
            <button type="submit" class="btn btn--primary">Salvar</button>
        </div>
    </form>
</section>
