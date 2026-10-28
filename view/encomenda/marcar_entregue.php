<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/EncomendaController.php";

PermissaoController::exigirPortaria();

$id = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

if (!$id) {
    header(
        "Location: index.php?erro=id_invalido"
    );
    exit;
}

$controller = new EncomendaController();

$encomenda = $controller->buscarPorId(
    (int) $id
);

if (!$encomenda) {
    header(
        "Location: index.php?erro=encomenda_nao_encontrada"
    );
    exit;
}

if (
    $encomenda->getStatus()
    !== "Aguardando_Retirada"
) {
    header(
        "Location: index.php?erro=status_invalido"
    );
    exit;
}

/*
 * A portaria apenas confirma que o morador
 * retirou a encomenda.
 *
 * O morador destinatário NÃO é substituído.
 */

$sucesso = $controller->marcarEntregue(
    (int) $id
);

if ($sucesso) {
    header(
        "Location: index.php?sucesso=entregue"
    );
    exit;
}

header(
    "Location: index.php?erro=nao_foi_possivel_entregar"
);

exit;