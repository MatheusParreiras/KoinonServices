<?php
/**
 * Invitation to join a condominium. Every value is escaped: the inviter's and
 * the condominium's names were typed by people and may contain HTML.
 *
 * @var string $name             Invitee name.
 * @var string $condominiumName
 * @var string $roleLabel        e.g. "Morador"
 * @var string $inviterName
 * @var string $link             Built from APP_URL, never from the request's Host header.
 * @var int    $hours            Validity.
 * @var bool   $needsPassword    New account: the invitee will choose a password.
 */
?>
<p>Olá, <?= e($name) ?>!</p>
<p>
    <?= e($inviterName) ?> convidou você para acessar o condomínio
    <strong><?= e($condominiumName) ?></strong> no Koinon como <strong><?= e($roleLabel) ?></strong>.
</p>
<p>
    <?= $needsPassword
        ? 'Clique no botão abaixo para aceitar o convite e criar sua senha.'
        : 'Clique no botão abaixo para aceitar o convite. Depois, entre com o e-mail e a senha que você já usa no Koinon.' ?>
</p>
<p style="margin:32px 0;">
    <a href="<?= e($link) ?>" style="background:#1d3c6e;color:#ffffff;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:bold;">Aceitar convite</a>
</p>
<p style="font-size:13px;color:#5f6b7a;">
    O convite vale por <?= e($hours) ?> horas e só pode ser usado uma vez.<br>
    Se o botão não funcionar, copie este endereço no navegador:<br>
    <span style="word-break:break-all;"><?= e($link) ?></span>
</p>
<p style="font-size:13px;color:#5f6b7a;">Se você não esperava este convite, ignore este e-mail.</p>
