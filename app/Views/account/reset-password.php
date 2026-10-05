<?php
/**
 * Landing page of the reset link: valid | expired | invalid
 * (PasswordResetService constants).
 *
 * @var string                $state
 * @var string                $token
 * @var array<string, string> $errors
 */

use App\Services\PasswordResetService;
?>
<h1 class="auth__title">Redefinir senha</h1>

<?php if ($state === PasswordResetService::VALID): ?>
    <form method="post" action="/account/reset-password" class="form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label class="form__field">
            <span class="form__label">Nova senha</span>
            <input type="password" name="password" autocomplete="new-password" required autofocus>
            <?= field_error($errors, 'password') ?>
        </label>
        <label class="form__field">
            <span class="form__label">Repita a nova senha</span>
            <input type="password" name="password_confirmation" autocomplete="new-password" required>
            <?= field_error($errors, 'password_confirmation') ?>
        </label>
        <p class="form__hint">Depois da troca, todas as sessões abertas da sua conta serão encerradas.</p>
        <button type="submit" class="btn btn--primary btn--block">Salvar nova senha</button>
    </form>

<?php elseif ($state === PasswordResetService::EXPIRED): ?>
    <div class="alert alert--warning">Este link expirou. Peça um novo.</div>
    <a class="btn btn--primary btn--block" href="/account/forgot-password">Pedir novo link</a>

<?php else: ?>
    <div class="alert alert--error">Link inválido ou já utilizado. Peça um novo link se ainda precisar trocar a senha.</div>
    <a class="btn btn--block" href="/account/forgot-password">Pedir novo link</a>
<?php endif; ?>
