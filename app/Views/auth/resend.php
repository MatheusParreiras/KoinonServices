<?php
/**
 * @var string $email
 */
?>
<h1 class="auth__title">Reenviar link de ativação</h1>
<p>Informe o e-mail da sua conta. Se ela ainda não foi ativada, enviaremos um novo link.</p>

<form method="post" action="/verify-email/resend" class="form" novalidate>
    <?= csrf_field() ?>
    <label class="form__field">
        <span class="form__label">E-mail</span>
        <input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" required autofocus>
    </label>
    <button type="submit" class="btn btn--primary btn--block">Enviar link</button>
</form>

<p class="auth__links"><a href="/login">Voltar ao login</a></p>
