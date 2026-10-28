<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header(
    "Content-Type: application/json; charset=UTF-8"
);

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/EncomendaController.php";

PermissaoController::exigirPortaria();


$unidadeId =
    (int) (
        $_GET["unidade_id"]
        ?? 0
    );


if (
    $unidadeId <= 0
) {

    echo json_encode(
        [],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


try {

    $controller =
        new EncomendaController();


    $moradores =
        $controller
            ->listarMoradoresPorUnidade(
                $unidadeId
            );


    echo json_encode(
        $moradores,
        JSON_UNESCAPED_UNICODE
    );

} catch (
    Throwable $e
) {

    http_response_code(500);


    echo json_encode(
        [
            "erro" =>
                "Não foi possível carregar os moradores."
        ],
        JSON_UNESCAPED_UNICODE
    );
}