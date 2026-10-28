<?php

require_once __DIR__ . "/../../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/NotificacaoController.php";

PermissaoController::exigirPerfil(["Morador"]);

$usuarioId =
    $_SESSION["usuario_id"] ?? null;

if (!$usuarioId) {

    header("Location: ../login.php");
    exit;
}

$controller =
    new NotificacaoController();


function resolverLinkNotificacaoMorador(
    string $tipo
): string {

    return match (trim($tipo)) {
        "Encomenda" => "encomendas.php",
        "Reserva" => "index.php",
        "Ocorrencia" => "index.php",
        "Cobranca" => "index.php",
        "Autorizacao" => "autorizacoes.php",
        "Visitante" => "autorizacoes.php",
        "Aviso" => "publicacoes.php",
        "Enquete" => "enquetes.php",
        "Achado_Perdido" => "achados_perdidos.php",
        "Documento" => "index.php",
        default => "index.php"
    };
}


// ==========================================================
// PROCESSAR AÇÕES
// ==========================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $acao =
        $_POST["acao"] ?? "";

    // ------------------------------------------------------
    // MARCAR UMA COMO LIDA
    // ------------------------------------------------------

    if ($acao === "ler") {

        $id =
            (int) ($_POST["id"] ?? 0);

        $destino = "notificacoes.php";

        if ($id > 0) {

            $notificacao =
                $controller->buscarPorId(
                    $id,
                    (int) $usuarioId
                );

            if ($notificacao !== null) {

                $controller->marcarComoLida(
                    $id,
                    (int) $usuarioId
                );

                $destino =
                    resolverLinkNotificacaoMorador(
                        $notificacao->getTipo()
                    );
            }
        }

        header(
            "Location: " . $destino
        );

        exit;
    }


    // ------------------------------------------------------
    // MARCAR TODAS COMO LIDAS
    // ------------------------------------------------------

    elseif ($acao === "ler_todas") {

        $controller->marcarTodasComoLidas(
            (int) $usuarioId
        );

        header(
            "Location: notificacoes.php"
        );

        exit;
    }


    // ------------------------------------------------------
    // EXCLUIR
    // ------------------------------------------------------

    elseif ($acao === "excluir") {

        $id =
            (int) ($_POST["id"] ?? 0);

        if ($id > 0) {

            $controller->excluir(
                $id,
                (int) $usuarioId
            );
        }

        header(
            "Location: notificacoes.php"
        );

        exit;
    }
}


// ==========================================================
// BUSCAR NOTIFICAÇÕES
// ==========================================================

$notificacoes =
    $controller->listarPorUsuario(
        (int) $usuarioId
    );

$totalNaoLidas =
    $controller->contarNaoLidas(
        (int) $usuarioId
    );

?>
<!DOCTYPE html>

<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Notificações - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body class="bg-light">


<div class="container py-4">


    <!-- =====================================================
         CABEÇALHO
    ====================================================== -->

    <div
        class="d-flex justify-content-between align-items-center mb-4"
    >

        <div>

            <h2 class="mb-1">
                🔔 Notificações
            </h2>

            <p class="text-muted mb-0">

                Avisos e atualizações do condomínio.

            </p>

        </div>


        <a
            href="index.php"
            class="btn btn-outline-secondary"
        >
            ← Voltar
        </a>

    </div>


    <!-- =====================================================
         RESUMO
    ====================================================== -->

    <div
        class="card shadow-sm mb-4"
    >

        <div class="card-body">

            <div
                class="d-flex justify-content-between align-items-center"
            >

                <div>

                    <strong>
                        Notificações não lidas:
                    </strong>

                    <span
                        class="badge text-bg-danger ms-2"
                    >
                        <?php
                        echo $totalNaoLidas;
                        ?>
                    </span>

                </div>


                <?php if (
                    $totalNaoLidas > 0
                ): ?>

                    <form method="POST">

                        <input
                            type="hidden"
                            name="acao"
                            value="ler_todas"
                        >

                        <button
                            type="submit"
                            class="btn btn-outline-primary btn-sm"
                        >
                            ✓ Marcar todas como lidas
                        </button>

                    </form>

                <?php endif; ?>

            </div>

        </div>

    </div>


    <!-- =====================================================
         LISTAGEM
    ====================================================== -->

    <?php if (
        empty($notificacoes)
    ): ?>

        <div
            class="alert alert-info shadow-sm"
        >

            🔔 Você não possui notificações.

        </div>

    <?php else: ?>

        <div class="row g-3">

            <?php foreach (
                $notificacoes as $notificacao
            ): ?>

                <?php

                $tipo =
                    $notificacao->getTipo();

                $icone = "🔔";

                if (
                    $tipo === "Encomenda"
                ) {
                    $icone = "📦";
                } elseif (
                    $tipo === "Visitante"
                ) {
                    $icone = "🚪";
                } elseif (
                    $tipo === "Aviso"
                ) {
                    $icone = "📢";
                } elseif (
                    $tipo === "Achado_Perdido"
                ) {
                    $icone = "🔎";
                } elseif (
                    $tipo === "Enquete"
                ) {
                    $icone = "📊";
                } elseif (
                    $tipo === "Reserva"
                ) {
                    $icone = "📅";
                } elseif (
                    $tipo === "Ocorrencia"
                ) {
                    $icone = "⚠️";
                } elseif (
                    $tipo === "Cobranca"
                ) {
                    $icone = "💰";
                } elseif (
                    $tipo === "Documento"
                ) {
                    $icone = "📄";
                } elseif (
                    $tipo === "Autorizacao"
                ) {
                    $icone = "🚗";
                }

                $lida =
                    $notificacao->isLida();

                ?>


                <div class="col-12">

                    <div
                        class="card shadow-sm
                        <?php
                        echo !$lida
                            ? "border-primary"
                            : "";
                        ?>"
                    >

                        <div class="card-body">


                            <div
                                class="d-flex justify-content-between align-items-start"
                            >

                                <div class="d-flex gap-3">

                                    <div
                                        class="fs-3"
                                    >
                                        <?php
                                        echo $icone;
                                        ?>
                                    </div>


                                    <div>

                                        <h5
                                            class="mb-1"
                                        >

                                            <?php
                                            echo htmlspecialchars(
                                                $notificacao->getTitulo()
                                            );
                                            ?>

                                            <?php if (
                                                !$lida
                                            ): ?>

                                                <span
                                                    class="badge text-bg-primary ms-2"
                                                >
                                                    Nova
                                                </span>

                                            <?php endif; ?>

                                        </h5>


                                        <p
                                            class="mb-2"
                                        >

                                            <?php
                                            echo nl2br(
                                                htmlspecialchars(
                                                    $notificacao->getMensagem()
                                                )
                                            );
                                            ?>

                                        </p>


                                        <?php
                                        $data =
                                            $notificacao->getCreatedAt();
                                        ?>

                                        <?php if (
                                            $data
                                        ): ?>

                                            <small
                                                class="text-muted"
                                            >

                                                📅

                                                <?php
                                                echo date(
                                                    "d/m/Y H:i",
                                                    strtotime(
                                                        $data
                                                    )
                                                );
                                                ?>

                                            </small>

                                        <?php endif; ?>

                                    </div>

                                </div>


                                <!-- AÇÕES -->

                                <div
                                    class="d-flex gap-2"
                                >


                                    <?php

                                    $link =
                                        resolverLinkNotificacaoMorador(
                                            $notificacao->getTipo()
                                        );

                                    ?>

                                    <?php if (
                                        !empty($link)
                                    ): ?>

                                        <a
                                            href="<?php
                                                echo htmlspecialchars(
                                                    $link
                                                );
                                            ?>"
                                            class="btn btn-outline-primary btn-sm"
                                        >
                                            Abrir
                                        </a>

                                    <?php endif; ?>


                                    <?php if (
                                        !$lida
                                    ): ?>

                                        <form method="POST">

                                            <input
                                                type="hidden"
                                                name="acao"
                                                value="ler"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?php
                                                    echo (int)
                                                        $notificacao->getId();
                                                ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-outline-success btn-sm"
                                            >
                                                ✓ Lida
                                            </button>

                                        </form>

                                    <?php endif; ?>


                                    <form
                                        method="POST"
                                        onsubmit="return confirm('Deseja excluir esta notificação?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="acao"
                                            value="excluir"
                                        >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?php
                                                echo (int)
                                                    $notificacao->getId();
                                            ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-outline-danger btn-sm"
                                        >
                                            🗑
                                        </button>

                                    </form>


                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"
></script>
<script src="../../assets/js/script.js"></script>

</body>

</html>