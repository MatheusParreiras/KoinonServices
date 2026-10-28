<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/MoradorController.php";

PermissaoController::exigirPerfil([
    "Morador"
]);


$usuarioId =
    (int) $_SESSION["usuario_id"];

$reservaId =
    isset($_GET["id"])
        ? (int) $_GET["id"]
        : 0;


if ($reservaId <= 0) {

    header(
        "Location: nova_reserva.php?reserva=erro"
    );

    exit;
}


$controller =
    new MoradorController();


$resultado =
    $controller->cancelarReserva(
        $reservaId,
        $usuarioId
    );


if ($resultado) {

    header(
        "Location: nova_reserva.php?reserva=cancelada"
    );

    exit;
}


header(
    "Location: nova_reserva.php?reserva=erro"
);

exit;