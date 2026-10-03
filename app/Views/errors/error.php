<?php
/**
 * Generic error page. Shows only user-safe text plus a request id that support
 * can match against storage/logs.
 *
 * @var int    $status
 * @var string $message
 * @var string $requestId
 */
?>
<h1 class="auth__title">Erro <?= e($status) ?></h1>
<p><?= e($message) ?></p>
<p class="muted">Código de referência: <code><?= e($requestId) ?></code></p>
<p class="auth__links"><a href="/">Voltar ao início</a></p>
