<?php
/**
 * Landing page of the e-mail change link (AccountService::EMAIL_* states).
 *
 * @var string $state
 * @var string $token
 */

use App\Services\AccountService;
?>
<h1 class="auth__title">Confirmar novo e-mail</h1>

<?php if ($state === AccountService::EMAIL_VALID): ?>
    <p>Clique no botão para confirmar que este endereço é seu. Depois disso, use-o para entrar.</p>
    <form method="post" action="/account/email/confirm" class="form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <button type="submit" class="btn btn--primary btn--block">Confirmar novo e-mail</button>
    </form>

<?php elseif ($state === AccountService::EMAIL_EXPIRED): ?>
    <div class="alert alert--warning">Este link expirou. Faça a alteração de novo em "Minha conta".</div>

<?php elseif ($state === AccountService::EMAIL_TAKEN): ?>
    <div class="alert alert--error">Este e-mail já está em uso por outra conta. A alteração foi cancelada.</div>

<?php else: ?>
    <div class="alert alert--error">Link inválido ou já utilizado.</div>
<?php endif; ?>

<p class="auth__links"><a href="/login">Ir para o login</a></p>
