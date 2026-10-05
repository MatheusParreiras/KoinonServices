<?php
/**
 * @var array<string, mixed>  $unit
 * @var array<string, string> $types
 * @var array<string, string> $errors
 */
?>
<section class="page-header">
    <div>
        <p class="page-header__eyebrow"><a href="/admin/units">← Unidades</a></p>
        <h1 class="page-header__title">Editar unidade</h1>
    </div>
</section>

<section class="panel panel--padded panel--narrow">
    <form method="post" action="/admin/units/<?= e($unit['id']) ?>" class="form" novalidate>
        <?= csrf_field() ?>
        <?= field_error($errors, 'general') ?>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Bloco/Torre</span>
                <input type="text" name="building" maxlength="30" value="<?= e($unit['building']) ?>">
                <?= field_error($errors, 'building') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Número</span>
                <input type="text" name="unit_number" maxlength="20" required value="<?= e($unit['unit_number']) ?>">
                <?= field_error($errors, 'unit_number') ?>
            </label>
        </div>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Andar</span>
                <input type="number" name="floor_number" min="-5" max="200" value="<?= e($unit['floor_number']) ?>">
                <?= field_error($errors, 'floor_number') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Tipo</span>
                <select name="unit_type">
                    <?php foreach ($types as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $unit['unit_type'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label class="form__check">
            <input type="checkbox" name="is_active" value="1"<?= (int) $unit['is_active'] === 1 ? ' checked' : '' ?>>
            Unidade ativa (inativas não aparecem em reservas, cobranças e portaria; o histórico é mantido)
        </label>
        <div class="form__actions">
            <a class="btn" href="/admin/units">Cancelar</a>
            <button type="submit" class="btn btn--primary">Salvar</button>
        </div>
    </form>
</section>
