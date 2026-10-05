<?php
/**
 * @var array<string, mixed>  $notice
 * @var string                $expiresLocal datetime-local value in the condominium's zone ('' = none).
 * @var array<string, string> $errors
 */
$priorities = ['normal' => 'Normal', 'important' => 'Importante', 'urgent' => 'Urgente'];
?>
<section class="page-header">
    <div>
        <p class="page-header__eyebrow"><a href="/admin/notices">← Avisos</a></p>
        <h1 class="page-header__title">Editar aviso</h1>
    </div>
</section>

<section class="panel panel--padded panel--narrow">
    <form method="post" action="/admin/notices/<?= e($notice['id']) ?>" class="form" novalidate>
        <?= csrf_field() ?>
        <label class="form__field">
            <span class="form__label">Título</span>
            <input type="text" name="title" maxlength="150" required value="<?= e($notice['title']) ?>">
            <?= field_error($errors, 'title') ?>
        </label>
        <label class="form__field">
            <span class="form__label">Mensagem</span>
            <textarea name="body" rows="10" maxlength="10000" required><?= e($notice['body']) ?></textarea>
            <?= field_error($errors, 'body') ?>
        </label>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Prioridade</span>
                <select name="priority">
                    <?php foreach ($priorities as $code => $label): ?>
                        <option value="<?= e($code) ?>"<?= $notice['priority'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="form__field">
                <span class="form__label">Expira em (opcional)</span>
                <input type="datetime-local" name="expires_at" value="<?= e($expiresLocal) ?>">
                <?= field_error($errors, 'expires_at') ?>
            </label>
        </div>
        <label class="form__check">
            <input type="checkbox" name="is_pinned" value="1"<?= (int) $notice['is_pinned'] === 1 ? ' checked' : '' ?>>
            Fixar no topo do mural
        </label>
        <div class="form__actions">
            <a class="btn" href="/admin/notices">Cancelar</a>
            <button type="submit" class="btn btn--primary">Salvar</button>
        </div>
    </form>
</section>
