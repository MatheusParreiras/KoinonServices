<?php
/**
 * "Esqueci minha senha". The answer is always the same message (see
 * PasswordResetController::SENT_MESSAGE), whatever e-mail is typed.
 */
?>
<h1 class="auth__title">Esqueci minha senha</h1>
<p>Informe o e-mail da sua conta. Se ela existir e estiver ativa, enviaremos um link para criar uma nova senha.</p>

<form method="post" action="/account/forgot-password" class="form" novalidate>
    <?= csrf_field() ?>
    <label class="form__field">
        <span class="form__label">E-mail</span>
        <input type="email" name="email" autocomplete="email" required autofocus>
    </label>
    <button type="submit" class="btn btn--primary btn--block">Enviar link</button>
</form>

<p class="auth__links"><a href="/login">Voltar ao login</a></p>
