<?php
/**
 * @var string      $email           Previously typed e-mail (re-filled after an error).
 * @var string|null $unverifiedEmail Set when the password was right but the e-mail is unconfirmed.
 */
?>
<h1 class="auth__title">Entrar</h1>

<?php if ($unverifiedEmail !== null): ?>
    <div class="callout">
        <p>Não recebeu o e-mail de ativação? Podemos enviar um novo link.</p>
        <form method="post" action="/verify-email/resend">
            <?= csrf_field() ?>
            <input type="hidden" name="email" value="<?= e($unverifiedEmail) ?>">
            <button type="submit" class="btn">Reenviar link de ativação</button>
        </form>
    </div>
<?php endif; ?>

<form method="post" action="/login" class="form" novalidate>
    <?= csrf_field() ?>

    <label class="form__field">
        <span class="form__label">E-mail</span>
        <input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
    </label>

    <label class="form__field">
        <span class="form__label">Senha</span>
        <input type="password" name="password" autocomplete="current-password" required>
    </label>

    <button type="submit" class="btn btn--primary btn--block">Entrar</button>
</form>

<p class="auth__links">
    <a href="/verify-email/resend">Não recebeu o e-mail de ativação?</a>
</p>
