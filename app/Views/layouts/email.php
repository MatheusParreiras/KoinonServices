<?php
/**
 * Shared shell of the Phase 5 HTML e-mails (same look as emails/verify.php).
 * E-mail clients ignore external CSS, so styles are inline here; the web CSP
 * does not apply to e-mails.
 *
 * @var string $content Rendered e-mail body (already escaped by its template).
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
                <?= $content /* already-escaped e-mail body */ ?>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
