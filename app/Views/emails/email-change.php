<?php
/**
 * Confirmation of a new e-mail address, sent TO the new address: clicking the
 * link proves the requester controls it.
 *
 * @var string $name
 * @var string $newEmail
 * @var string $link  Built from APP_URL, never from the request's Host header.
 * @var int    $hours Validity.
 */
?>
<p>Olá, <?= e($name) ?>!</p>
<p>
    Foi solicitado que o e-mail de acesso da sua conta no Koinon passe a ser
    <strong><?= e($newEmail) ?></strong>. Confirme que este endereço é seu:
</p>
<p style="margin:32px 0;">
    <a href="<?= e($link) ?>" style="background:#1d3c6e;color:#ffffff;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:bold;">Confirmar novo e-mail</a>
</p>
<p style="font-size:13px;color:#5f6b7a;">
    O link vale por <?= e($hours) ?> horas e só pode ser usado uma vez. Até a confirmação,
    o e-mail antigo continua valendo para entrar.<br>
    Se o botão não funcionar, copie este endereço no navegador:<br>
    <span style="word-break:break-all;"><?= e($link) ?></span>
</p>
<p style="font-size:13px;color:#5f6b7a;">Se você não pediu esta alteração, ignore este e-mail.</p>
