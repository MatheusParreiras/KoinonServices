<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/MoradorController.php";

PermissaoController::exigirMorador();

header("Content-Type: application/json; charset=utf-8");

try {

    $espacoNome =
        trim($_GET["espaco_nome"] ?? "");

    $dataInicio =
        trim($_GET["data_inicio"] ?? "");

    $dataFim =
        trim($_GET["data_fim"] ?? "");


    if (
        $espacoNome === "" ||
        $dataInicio === "" ||
        $dataFim === ""
    ) {

        echo json_encode([
            "sucesso" => false,
            "disponivel" => false,
            "mensagem" =>
                "Informe o local, início e término da reserva."
        ]);

        exit;
    }


    /*
     * Converte o formato enviado pelo
     * input datetime-local para MySQL.
     *
     * Exemplo:
     *
     * 2026-09-23T08:00
     *
     * vira:
     *
     * 2026-09-23 08:00:00
     */

    $dataInicioMysql =
        str_replace(
            "T",
            " ",
            $dataInicio
        );

    $dataFimMysql =
        str_replace(
            "T",
            " ",
            $dataFim
        );


    if (
        strlen($dataInicioMysql) === 16
    ) {

        $dataInicioMysql .= ":00";
    }


    if (
        strlen($dataFimMysql) === 16
    ) {

        $dataFimMysql .= ":00";
    }


    $controller =
        new MoradorController();


    $resultado =
        $controller->verificarDisponibilidadeReserva(
            $espacoNome,
            $dataInicioMysql,
            $dataFimMysql
        );


    echo json_encode([
        "sucesso" => true,
        "disponivel" =>
            $resultado["disponivel"],
        "mensagem" =>
            $resultado["mensagem"]
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "sucesso" => false,
        "disponivel" => false,
        "mensagem" =>
            "Não foi possível verificar a disponibilidade."
    ]);
}