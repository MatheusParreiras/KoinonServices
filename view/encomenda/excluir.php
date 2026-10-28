<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/EncomendaController.php";

PermissaoController::exigirAdministrador();


$id =
    (int) (
        $_GET["id"]
        ?? 0
    );


if (
    $id <= 0
) {

    header(
        "Location: index.php?erro=1"
    );

    exit;
}


$controller =
    new EncomendaController();


if (
    $controller->excluir(
        $id
    )
) {

    header(
        "Location: index.php?sucesso=excluida"
    );

    exit;
}


header(
    "Location: index.php?erro=1"
);

exit;