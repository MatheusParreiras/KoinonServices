<?php
/**
 * "Minha conta": profile (+ avatar), password and e-mail change.
 * There is no user id anywhere in these forms: the server always acts on the
 * logged-in user.
 *
 * @var array<string, mixed>  $account     The logged-in user's row.
 * @var bool                  $hasAvatar
 * @var int                   $passwordMin
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Minha conta</h1>
        <p class="page-header__subtitle"><?= e($account['email']) ?></p>
    </div>
</section>

<?= field_error($errors, 'general') ?>

<div class="grid-2">
    <section class="panel panel--padded">
        <h2 class="panel__title">Perfil</h2>
        <form method="post" action="/account/profile" class="form" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <div class="avatar-row">
                <?php if ($hasAvatar): ?>
                    <img class="avatar" src="/account/avatar" alt="Sua foto" width="64" height="64">
                <?php else: ?>
                    <span class="avatar avatar--empty" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $account['full_name'], 0, 1))) ?></span>
                <?php endif; ?>
                <label class="form__field">
                    <span class="form__label">Foto (JPG, PNG ou WebP, até 1 MB)</span>
                    <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp">
                    <?= field_error($errors, 'avatar') ?>
                </label>
            </div>
            <?php if ($hasAvatar): ?>
                <label class="form__check">
                    <input type="checkbox" name="remove_avatar" value="1"> Remover foto atual
                </label>
            <?php endif; ?>
            <label class="form__field">
                <span class="form__label">Nome completo</span>
                <input type="text" name="full_name" maxlength="150" required autocomplete="name"
                       value="<?= e($old['full_name'] ?? $account['full_name']) ?>">
                <?= field_error($errors, 'full_name') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Telefone (opcional)</span>
                <input type="tel" name="phone" maxlength="30" autocomplete="tel"
                       value="<?= e($old['phone'] ?? $account['phone']) ?>">
                <?= field_error($errors, 'phone') ?>
            </label>
            <div class="form__actions">
                <button type="submit" class="btn btn--primary">Salvar perfil</button>
            </div>
        </form>
    </section>

    <div class="stack">
        <section class="panel panel--padded">
            <h2 class="panel__title">Alterar senha</h2>
            <form method="post" action="/account/password" class="form" novalidate>
                <?= csrf_field() ?>
                <label class="form__field">
                    <span class="form__label">Senha atual</span>
                    <input type="password" name="current_password" autocomplete="current-password" required>
                    <?= field_error($errors, 'current_password') ?>
                </label>
                <label class="form__field">
                    <span class="form__label">Nova senha (mínimo <?= e($passwordMin) ?> caracteres)</span>
                    <input type="password" name="password" autocomplete="new-password" required minlength="<?= e($passwordMin) ?>">
                    <?= field_error($errors, 'password') ?>
                </label>
                <label class="form__field">
                    <span class="form__label">Repita a nova senha</span>
                    <input type="password" name="password_confirmation" autocomplete="new-password" required>
                    <?= field_error($errors, 'password_confirmation') ?>
                </label>
                <p class="form__hint">Ao trocar a senha, as outras sessões abertas da sua conta são encerradas.</p>
                <div class="form__actions">
                    <button type="submit" class="btn btn--primary">Alterar senha</button>
                </div>
            </form>
        </section>

        <section class="panel panel--padded">
            <h2 class="panel__title">Alterar e-mail</h2>
            <form method="post" action="/account/email" class="form" novalidate>
                <?= csrf_field() ?>
                <label class="form__field">
                    <span class="form__label">Novo e-mail</span>
                    <input type="email" name="new_email" maxlength="254" required autocomplete="email"
                           value="<?= e($old['new_email'] ?? '') ?>">
                    <?= field_error($errors, 'new_email') ?>
                </label>
                <label class="form__field">
                    <span class="form__label">Sua senha</span>
                    <input type="password" name="email_password" autocomplete="current-password" required>
                    <?= field_error($errors, 'email_password') ?>
                </label>
                <p class="form__hint">Enviaremos um link para o novo endereço. O e-mail só muda depois da confirmação.</p>
                <div class="form__actions">
                    <button type="submit" class="btn">Enviar confirmação</button>
                </div>
            </form>
        </section>
    </div>
</div>
