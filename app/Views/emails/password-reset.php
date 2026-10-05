<?php
/**
 * Password reset link.
 *
 * @var string $name
 * @var string $link    Built from APP_URL, never from the request's Host header.
 * @var int    $minutes Validity.
 */
?>
<p>Olá, <?= e($name) ?>!</p>
<p>Recebemos um pedido para redefinir a senha da sua conta no Koinon.</p>
<p style="margin:32px 0;">
    <a href="<?= e($link) ?>" style="background:#1d3c6e;color:#ffffff;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:bold;">Redefinir senha</a>
</p>
<p style="font-size:13px;color:#5f6b7a;">
    O link vale por <?= e($minutes) ?> minutos e só pode ser usado uma vez.<br>
    Se o botão não funcionar, copie este endereço no navegador:<br>
    <span style="word-break:break-all;"><?= e($link) ?></span>
</p>
<p style="font-size:13px;color:#5f6b7a;">
    Se você não pediu a redefinição, ignore este e-mail: sua senha continua a mesma.
</p>
