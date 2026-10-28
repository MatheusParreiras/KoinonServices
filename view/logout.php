<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
 * Limpa todos os dados da sessão
 */
$_SESSION = [];

/*
 * Remove o cookie da sessão
 */
if (ini_get("session.use_cookies")) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

/*
 * Encerra a sessão
 */
session_destroy();

/*
 * Volta para a página inicial
 */
header(
    "Location: /koinon-service/"
);

exit;