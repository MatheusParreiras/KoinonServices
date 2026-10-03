<?php
/**
 * @var array<string, string>               $types
 * @var array<string, string>               $categories
 * @var list<array{id: int, label: string}> $units Only the reporter's own units.
 * @var array<string, string>               $errors
 * @var array<string, string>               $old
 */
$selected = static fn (string $field, string|int $value): string
    => (string) ($old[$field] ?? '') === (string) $value ? ' selected' : '';
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Nova ocorrência</h1>
        <p class="page-header__subtitle">A administração responderá por aqui. Só você e a administração veem esta ocorrência.</p>
    </div>
</section>

<section class="panel panel--padded panel--narrow">
    <form method="post" action="/occurrences" class="form" novalidate>
        <?= csrf_field() ?>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Tipo</span>
                <select name="occurrence_type" required>
                    <?php foreach ($types as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $selected('occurrence_type', $value) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'occurrence_type') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Categoria</span>
                <select name="category" required>
                    <?php foreach ($categories as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $selected('category', $value) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'category') ?>
            </label>
        </div>

        <label class="form__field">
            <span class="form__label">Título</span>
            <input type="text" name="title" maxlength="150" required value="<?= e($old['title'] ?? '') ?>">
            <?= field_error($errors, 'title') ?>
        </label>

        <label class="form__field">
            <span class="form__label">Descrição</span>
            <textarea name="description" rows="7" maxlength="5000" required><?= e($old['description'] ?? '') ?></textarea>
            <?= field_error($errors, 'description') ?>
        </label>

        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Local (opcional)</span>
                <input type="text" name="location" maxlength="120" placeholder="Ex.: garagem, bloco B" value="<?= e($old['location'] ?? '') ?>">
                <?= field_error($errors, 'location') ?>
            </label>
            <?php if ($units !== []): ?>
                <label class="form__field">
                    <span class="form__label">Sua unidade (opcional)</span>
                    <select name="unit_id">
                        <option value="">—</option>
                        <?php foreach ($units as $unit): ?>
                            <option value="<?= e($unit['id']) ?>"<?= $selected('unit_id', $unit['id']) ?>><?= e($unit['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error($errors, 'unit_id') ?>
                </label>
            <?php endif; ?>
        </div>

        <div class="form__actions">
            <a class="btn" href="/occurrences">Cancelar</a>
            <button type="submit" class="btn btn--primary">Registrar ocorrência</button>
        </div>
    </form>
</section>
