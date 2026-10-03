<?php
/**
 * HTML body of the activation e-mail. E-mail clients ignore external CSS, so
 * styles are inline here (the web CSP does not apply to e-mails).
 *
 * @var string $name
 * @var string $link
 * @var int    $hours
 */
?>
<!doctype html>
<html lang="pt-BR">
<body style="margin:0;padding:24px;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center">
        <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #d9dee5;border-radius:6px;">
            <tr><td style="padding:24px 32px;border-bottom:3px solid #1d3c6e;font-size:20px;font-weight:bold;color:#1d3c6e;">Koinon</td></tr>
            <tr><td style="padding:32px;font-size:15px;line-height:1.6;">
                <p>Olá, <?= e($name) ?>!</p>
                <p>Confirme seu e-mail para ativar sua conta no Koinon.</p>
                <p style="margin:32px 0;">
                    <a href="<?= e($link) ?>" style="background:#1d3c6e;color:#ffffff;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:bold;">Confirmar e-mail</a>
                </p>
                <p style="font-size:13px;color:#5f6b7a;">
                    O link vale por <?= e($hours) ?> horas e só pode ser usado uma vez.<br>
                    Se o botão não funcionar, copie este endereço no navegador:<br>
                    <span style="word-break:break-all;"><?= e($link) ?></span>
                </p>
                <p style="font-size:13px;color:#5f6b7a;">Se você não criou esta conta, ignore este e-mail.</p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
