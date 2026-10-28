<?php

require_once __DIR__ . "/../../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/AutorizacaoAcessoController.php";

PermissaoController::exigirMorador();

$usuarioId =
    (int) $_SESSION["usuario_id"];

$id =
    isset($_GET["id"])
        ? (int) $_GET["id"]
        : 0;

if ($id <= 0) {

    header(
        "Location: autorizacoes.php"
    );

    exit;
}

$controller =
    new AutorizacaoAcessoController();

$resultado =
    $controller->cancelar(
        $id,
        $usuarioId
    );

if ($resultado) {

    header(
        "Location: autorizacoes.php?cancelada=1"
    );

    exit;
}

header(
    "Location: autorizacoes.php?erro=1"
);

exit;