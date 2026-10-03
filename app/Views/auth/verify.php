<?php
/**
 * Landing page of the activation link, in one of these states (VerificationResult):
 * valid | password_required | expired | already_used | invalid.
 *
 * @var string                $state
 * @var string                $token
 * @var bool                  $needsPassword
 * @var int                   $passwordMin
 * @var array<string, string> $errors
 */

use App\Services\VerificationResult;
?>
<h1 class="auth__title">Confirmar e-mail</h1>

<?php if ($state === VerificationResult::VALID || $state === VerificationResult::PASSWORD_REQUIRED): ?>
    <p>
        <?= $needsPassword
            ? 'Para ativar sua conta, escolha uma senha e confirme.'
            : 'Clique no botão abaixo para confirmar seu e-mail e ativar sua conta.' ?>
    </p>

    <form method="post" action="/verify-email" class="form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <?php if ($needsPassword): ?>
            <label class="form__field">
                <span class="form__label">Nova senha (mínimo <?= e($passwordMin) ?> caracteres)</span>
                <input type="password" name="password" autocomplete="new-password" required minlength="<?= e($passwordMin) ?>">
                <?php if (isset($errors['password'])): ?>
                    <span class="form__error"><?= e($errors['password']) ?></span>
                <?php endif; ?>
            </label>
            <label class="form__field">
                <span class="form__label">Repita a senha</span>
                <input type="password" name="password_confirmation" autocomplete="new-password" required>
                <?php if (isset($errors['password_confirmation'])): ?>
                    <span class="form__error"><?= e($errors['password_confirmation']) ?></span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <button type="submit" class="btn btn--primary btn--block">Confirmar meu e-mail</button>
    </form>

<?php elseif ($state === VerificationResult::EXPIRED): ?>
    <div class="alert alert--warning">Este link expirou. Peça um novo link de ativação.</div>
    <a class="btn btn--primary btn--block" href="/verify-email/resend">Enviar novo link</a>

<?php elseif ($state === VerificationResult::ALREADY_USED): ?>
    <div class="alert alert--success">Esta conta já foi ativada. Você já pode entrar.</div>
    <a class="btn btn--primary btn--block" href="/login">Ir para o login</a>

<?php else: ?>
    <div class="alert alert--error">Link de ativação inválido. Confira se copiou o endereço completo ou peça um novo link.</div>
    <a class="btn btn--block" href="/verify-email/resend">Enviar novo link</a>
<?php endif; ?>
