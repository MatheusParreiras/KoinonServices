<?php

require_once __DIR__ . "/../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../controller/PermissaoController.php";
require_once __DIR__ . "/../controller/NotificacaoController.php";


// ==========================================================
// EXIGIR USUÁRIO LOGADO
// ==========================================================

PermissaoController::exigirUsuario();

$usuarioId =
    PermissaoController::usuarioId();

$usuarioNome =
    PermissaoController::usuarioNome();

$usuarioPerfil =
    PermissaoController::usuarioPerfil();


if (!$usuarioId) {

    header(
        "Location: login.php"
    );

    exit;
}


// ==========================================================
// CONTROLLER
// ==========================================================

$controller =
    new NotificacaoController();


// ==========================================================
// RESOLVER DESTINO DAS NOTIFICAÇÕES
// ==========================================================

function resolverLinkNotificacao(
    ?string $link,
    string $perfil,
    string $tipo
): string {

    $tipo = trim($tipo);

    if ($perfil === "Morador") {

        return match ($tipo) {
            "Encomenda" => "morador/encomendas.php",
            "Reserva" => "morador/index.php",
            "Ocorrencia" => "morador/index.php",
            "Cobranca" => "morador/index.php",
            "Autorizacao" => "morador/autorizacoes.php",
            "Visitante" => "morador/autorizacoes.php",
            "Aviso" => "morador/publicacoes.php",
            "Enquete" => "morador/enquetes.php",
            "Achado_Perdido" => "morador/achados_perdidos.php",
            "Documento" => "morador/index.php",
            default => "morador/index.php"
        };
    }

    return match ($tipo) {
        "Encomenda" => "encomenda/index.php",
        "Reserva" => "reserva/index.php",
        "Ocorrencia" => "ocorrencia/index.php",
        "Cobranca" => "financeiro/index.php",
        "Autorizacao" => "autorizacao_acesso/index.php",
        "Visitante" => "portaria/index.php",
        "Aviso", "Enquete", "Achado_Perdido" => "publicacao/index.php",
        "Documento" => "documento/index.php",
        default => "dashboard.php"
    };
}


// ==========================================================
// PROCESSAR AÇÕES
// ==========================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $acao =
        $_POST["acao"] ?? "";

    $id =
        (int) (
            $_POST["id"] ?? 0
        );


    // ------------------------------------------------------
    // MARCAR UMA COMO LIDA
    // ------------------------------------------------------

    if ($acao === "ler") {

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

                $destino = resolverLinkNotificacao(
                    $notificacao->getLink(),
                    (string) $usuarioPerfil,
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

    if ($acao === "ler_todas") {

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

    if ($acao === "excluir") {

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


// ==========================================================
// TEXTO DO PERFIL
// ==========================================================

$perfilLabel = match ($usuarioPerfil) {

    "Morador" =>
        "Morador",

    "Porteiro" =>
        "Porteiro",

    "Sindico" =>
        "Síndico",

    "Administrador" =>
        "Administrador",

    default =>
        "Usuário"
};

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
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../assets/css/estilo.css">

</head>


<body class="bg-light">


<!-- ==========================================================
     NAVBAR
========================================================== -->

<nav class="navbar navbar-dark bg-primary">

    <div class="container">

        <div>

            <a
                href="dashboard.php"
                class="navbar-brand fw-bold text-decoration-none"
            >

                🏢 Koinon Services

            </a>

        </div>


        <div
            class="d-flex align-items-center gap-3"
        >

            <div class="text-white text-end">

                <div class="fw-semibold">

                    <?= htmlspecialchars(
                        $usuarioNome ?? "Usuário"
                    ) ?>

                </div>

                <small>

                    <?= htmlspecialchars(
                        $perfilLabel
                    ) ?>

                </small>

            </div>


            <a
                href="logout.php"
                class="btn btn-outline-light btn-sm"
            >

                Sair

            </a>

        </div>

    </div>

</nav>


<!-- ==========================================================
     CONTEÚDO
========================================================== -->

<div class="container py-5">


    <!-- CABEÇALHO -->

    <div
        class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"
    >

        <div>

            <h2 class="mb-1">

                🔔 Notificações

            </h2>

            <p class="text-muted mb-0">

                Avisos e atualizações relacionados
                à sua conta no Koinon Services.

            </p>

        </div>


        <a
            href="dashboard.php"
            class="btn btn-outline-secondary"
        >

            ← Voltar ao Dashboard

        </a>

    </div>


    <!-- ======================================================
         RESUMO
    ======================================================= -->

    <div class="card shadow-sm border-0 mb-4">

        <div class="card-body">

            <div
                class="d-flex justify-content-between align-items-center flex-wrap gap-3"
            >

                <div>

                    <strong>

                        Notificações não lidas:

                    </strong>


                    <span
                        class="badge text-bg-danger ms-2"
                    >

                        <?= $totalNaoLidas ?>

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


    <!-- ======================================================
         LISTAGEM
    ======================================================= -->

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
                $notificacoes
                as $notificacao
            ): ?>


                <?php

                $lida =
                    $notificacao->getLida();

                $tipo =
                    $notificacao->getTipo();

                $titulo =
                    $notificacao->getTitulo();

                $mensagem =
                    $notificacao->getMensagem();

                $link =
                    resolverLinkNotificacao(
                        $notificacao->getLink(),
                        (string) $usuarioPerfil,
                        $tipo
                    );

                $data =
                    $notificacao->getCreatedAt();

                ?>


                <div class="col-12">

                    <div
                        class="card shadow-sm border-0
                        <?= $lida
                            ? ""
                            : "border-start border-4 border-primary"
                        ?>"
                    >

                        <div class="card-body">

                            <div
                                class="d-flex justify-content-between align-items-start gap-3"
                            >

                                <div class="flex-grow-1">


                                    <!-- TÍTULO -->

                                    <div
                                        class="d-flex align-items-center gap-2 mb-2 flex-wrap"
                                    >

                                        <h5 class="mb-0">

                                            <?= htmlspecialchars(
                                                $titulo
                                            ) ?>

                                        </h5>


                                        <?php if (
                                            !$lida
                                        ): ?>

                                            <span
                                                class="badge text-bg-primary"
                                            >

                                                Nova

                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="badge text-bg-secondary"
                                            >

                                                Lida

                                            </span>

                                        <?php endif; ?>

                                    </div>


                                    <!-- TIPO -->

                                    <div class="mb-2">

                                        <span
                                            class="badge text-bg-light border"
                                        >

                                            <?= htmlspecialchars(
                                                $tipo
                                            ) ?>

                                        </span>

                                    </div>


                                    <!-- MENSAGEM -->

                                    <p class="mb-2">

                                        <?= nl2br(
                                            htmlspecialchars(
                                                $mensagem
                                            )
                                        ) ?>

                                    </p>


                                    <!-- DATA -->

                                    <?php if (
                                        $data
                                    ): ?>

                                        <small
                                            class="text-muted"
                                        >

                                            📅

                                            <?= date(
                                                "d/m/Y H:i",
                                                strtotime(
                                                    $data
                                                )
                                            ) ?>

                                        </small>

                                    <?php endif; ?>

                                </div>


                                <!-- AÇÕES -->

                                <div
                                    class="d-flex flex-column gap-2"
                                >


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
                                                value="<?= (int) $notificacao->getId() ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-outline-primary"
                                            >

                                                ✓ Ler

                                            </button>

                                        </form>

                                    <?php endif; ?>


                                    <?php if (
                                        !empty($link)
                                    ): ?>

                                        <a
                                            href="<?= htmlspecialchars(
                                                $link
                                            ) ?>"
                                            class="btn btn-sm btn-primary"
                                        >

                                            Abrir

                                        </a>

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
                                            value="<?= (int) $notificacao->getId() ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                        >

                                            🗑 Excluir

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
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>
<script src="../assets/js/script.js"></script>

</body>

</html>