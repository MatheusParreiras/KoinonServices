<?php
/**
 * Invitation landing page (InvitationService::ACCEPT_* states). Condominium,
 * role and e-mail are passed only for a usable token.
 *
 * @var string                $state
 * @var string                $token
 * @var string|null           $condominiumName
 * @var string|null           $roleLabel
 * @var string|null           $email
 * @var bool                  $needsPassword
 * @var int                   $passwordMin
 * @var array<string, string> $errors
 */

use App\Services\InvitationService;
?>
<h1 class="auth__title">Aceitar convite</h1>

<?php if ($state === InvitationService::ACCEPT_VALID || $state === InvitationService::ACCEPT_PASSWORD_REQUIRED): ?>
    <p>
        Você foi convidado para acessar <strong><?= e($condominiumName) ?></strong>
        como <strong><?= e($roleLabel) ?></strong>.
    </p>
    <p class="muted">Conta: <?= e($email) ?></p>

    <form method="post" action="/account/invitation" class="form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <?php if ($needsPassword): ?>
            <label class="form__field">
                <span class="form__label">Crie sua senha (mínimo <?= e($passwordMin) ?> caracteres)</span>
                <input type="password" name="password" autocomplete="new-password" required minlength="<?= e($passwordMin) ?>">
                <?= field_error($errors, 'password') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Repita a senha</span>
                <input type="password" name="password_confirmation" autocomplete="new-password" required>
                <?= field_error($errors, 'password_confirmation') ?>
            </label>
        <?php else: ?>
            <p class="form__hint">Você já tem uma conta no Koinon: depois de aceitar, entre com sua senha de sempre.</p>
        <?php endif; ?>

        <button type="submit" class="btn btn--primary btn--block">Aceitar convite</button>
    </form>

<?php elseif ($state === InvitationService::ACCEPT_EXPIRED): ?>
    <div class="alert alert--warning">Este convite expirou. Peça à administração do condomínio que o reenvie.</div>

<?php elseif ($state === InvitationService::ACCEPT_USED): ?>
    <div class="alert alert--success">Este convite já foi aceito. Você já pode entrar.</div>
    <a class="btn btn--primary btn--block" href="/login">Ir para o login</a>

<?php else: ?>
    <div class="alert alert--error">Convite inválido ou cancelado. Confira se copiou o endereço completo ou fale com a administração.</div>
<?php endif; ?>
