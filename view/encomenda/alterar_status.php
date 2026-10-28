<?php

require_once __DIR__ . "/../../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/EncomendaController.php";

PermissaoController::exigirPortaria();


$id =
    (int) (
        $_GET["id"]
        ?? 0
    );


$status =
    $_GET["status"]
    ?? "";


$statusPermitidos = [
    "Aguardando_Retirada",
    "Devolvido"
];


if (
    $id <= 0 ||
    !in_array(
        $status,
        $statusPermitidos,
        true
    )
) {

    header(
        "Location: index.php?erro=1"
    );

    exit;
}


$controller =
    new EncomendaController();


$sucesso =
    $controller->alterarStatus(
        $id,
        $status
    );


if (
    $sucesso
) {

    if (
        $status ===
        "Devolvido"
    ) {

        header(
            "Location: index.php?sucesso=devolvida"
        );

    } else {

        header(
            "Location: index.php?sucesso=reaberta"
        );
    }

    exit;
}


header(
    "Location: index.php?erro=1"
);

exit;