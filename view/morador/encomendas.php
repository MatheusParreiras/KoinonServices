<?php

require_once __DIR__ . "/../../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../model/dao/MoradorDAO.php";

PermissaoController::exigirPerfil(["Morador"]);

$usuarioId = (int) ($_SESSION["usuario_id"] ?? 0);

if ($usuarioId <= 0) {
    header("Location: ../login.php");
    exit;
}

$dao = new MoradorDAO();

/*
 * A listagem do MoradorDAO já é limitada à unidade do usuário logado:
 *
 * WHERE u.id = :usuario_id
 *
 * Portanto, mesmo informando ?id= na URL, somente encomendas
 * pertencentes à unidade do morador poderão ser encontradas.
 */
$encomendas = $dao->listarEncomendas($usuarioId);

$idSelecionado = (int) ($_GET["id"] ?? 0);

$encomendaSelecionada = null;

if ($idSelecionado > 0) {

    foreach ($encomendas as $encomenda) {

        if (
            (int) ($encomenda["id"] ?? 0)
            === $idSelecionado
        ) {

            $encomendaSelecionada = $encomenda;

            break;
        }
    }
}


/*
 * ==========================================================
 * FORMATAR DATA
 * ==========================================================
 */

function formatarDataEncomenda(
    ?string $data
): string {

    if (
        empty($data)
    ) {

        return "—";
    }

    $timestamp = strtotime(
        $data
    );

    if (
        $timestamp === false
    ) {

        return htmlspecialchars(
            $data
        );
    }

    return date(
        "d/m/Y H:i",
        $timestamp
    );
}


/*
 * ==========================================================
 * NOME DO STATUS
 * ==========================================================
 */

function statusLabelEncomenda(
    string $status
): string {

    return match ($status) {

        "Aguardando_Retirada"
            => "Aguardando retirada",

        "Entregue"
            => "Retirada realizada",

        "Devolvido"
            => "Devolvida",

        default
            => $status
    };
}


/*
 * ==========================================================
 * CLASSE DO STATUS
 * ==========================================================
 */

function statusClasseEncomenda(
    string $status
): string {

    return match ($status) {

        "Aguardando_Retirada"
            => "bg-warning text-dark",

        "Entregue"
            => "bg-success",

        "Devolvido"
            => "bg-secondary",

        default
            => "bg-dark"
    };
}


/*
 * ==========================================================
 * CONTADORES
 * ==========================================================
 */

$totalEncomendas =
    count($encomendas);

$aguardando = 0;

$retiradas = 0;

$devolvidas = 0;


foreach (
    $encomendas
    as $item
) {

    $statusItem =
        $item["status"] ?? "";


    if (
        $statusItem
        === "Aguardando_Retirada"
    ) {

        $aguardando++;

    } elseif (
        $statusItem
        === "Entregue"
    ) {

        $retiradas++;

    } elseif (
        $statusItem
        === "Devolvido"
    ) {

        $devolvidas++;
    }
}

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
        Minhas Encomendas - Koinon Services
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >


    <style>

        .encomenda-card {

            transition:
                transform .15s ease,
                box-shadow .15s ease;

        }


        .encomenda-card:hover {

            transform:
                translateY(-2px);

            box-shadow:
                0 .5rem 1rem
                rgba(0,0,0,.10)
                !important;

        }


        .foto-encomenda {

            width: 100%;

            max-height: 260px;

            object-fit: cover;

            border-radius: .5rem;

        }


        .foto-miniatura {

            width: 90px;

            height: 70px;

            object-fit: cover;

            border-radius: .4rem;

        }

    </style>


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>


<body class="bg-light">


<div class="container py-4">


    <!-- =====================================================
         CABEÇALHO
    ====================================================== -->

    <div
        class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4"
    >

        <div>

            <h2 class="mb-1">

                📦 Minhas encomendas

            </h2>


            <p class="text-muted mb-0">

                Acompanhe as encomendas recebidas
                pela portaria da sua unidade.

            </p>

        </div>


        <div class="d-flex gap-2">

            <a
                href="index.php"
                class="btn btn-outline-secondary"
            >

                ← Voltar

            </a>

        </div>

    </div>


    <!-- =====================================================
         ENCOMENDA SELECIONADA
    ====================================================== -->

    <?php if (
        $encomendaSelecionada !== null
    ): ?>


        <div
            class="alert alert-info shadow-sm d-flex justify-content-between align-items-center gap-3"
        >

            <div>

                <strong>

                    📌 Encomenda selecionada

                </strong>


                <div class="small mt-1">

                    A encomenda selecionada foi aberta a partir da notificação.

                </div>

            </div>


            <a
                href="encomendas.php"
                class="btn btn-sm btn-outline-primary"
            >

                Ver todas

            </a>

        </div>


    <?php elseif (
        $idSelecionado > 0
    ): ?>


        <div
            class="alert alert-warning shadow-sm"
        >

            ⚠️ A encomenda solicitada não está
            disponível para este usuário.

        </div>


    <?php endif; ?>


    <!-- =====================================================
         RESUMO
    ====================================================== -->

    <div class="row g-3 mb-4">


        <!-- TOTAL -->

        <div class="col-md-3">

            <div class="card shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small">

                        Total

                    </div>


                    <div class="fs-3 fw-bold">

                        <?= $totalEncomendas ?>

                    </div>

                </div>

            </div>

        </div>


        <!-- AGUARDANDO -->

        <div class="col-md-3">

            <div class="card shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small">

                        Aguardando retirada

                    </div>


                    <div
                        class="fs-3 fw-bold text-warning-emphasis"
                    >

                        <?= $aguardando ?>

                    </div>

                </div>

            </div>

        </div>


        <!-- RETIRADAS -->

        <div class="col-md-3">

            <div class="card shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small">

                        Retiradas

                    </div>


                    <div
                        class="fs-3 fw-bold text-success"
                    >

                        <?= $retiradas ?>

                    </div>

                </div>

            </div>

        </div>


        <!-- DEVOLVIDAS -->

        <div class="col-md-3">

            <div class="card shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small">

                        Devolvidas

                    </div>


                    <div
                        class="fs-3 fw-bold text-secondary"
                    >

                        <?= $devolvidas ?>

                    </div>

                </div>

            </div>

        </div>


    </div>


    <!-- =====================================================
         SEM ENCOMENDAS
    ====================================================== -->

    <?php if (
        empty($encomendas)
    ): ?>


        <div
            class="alert alert-info shadow-sm"
        >

            📦 Você não possui encomendas
            registradas para sua unidade.

        </div>


    <?php else: ?>


        <!-- =================================================
             LISTAGEM
        ================================================== -->

        <div class="row g-4">


            <?php foreach (
                $encomendas
                as $encomenda
            ): ?>


                <?php

                $id =
                    (int)
                    ($encomenda["id"] ?? 0);


                $status =
                    (string)
                    ($encomenda["status"] ?? "");


                $selecionada =
                    $idSelecionado > 0
                    &&
                    $id === $idSelecionado;


                $fotoUrl =
                    trim(
                        (string)
                        ($encomenda["foto_url"] ?? "")
                    );


                $descricao =
                    trim(
                        (string)
                        ($encomenda["descricao"] ?? "")
                    );


                $rastreio =
                    trim(
                        (string)
                        ($encomenda["codigo_rastreio"] ?? "")
                    );

                ?>


                <div class="col-lg-6">


                    <div
                        id="encomenda-<?= $id ?>"
                        class="
                            card
                            shadow-sm
                            h-100
                            encomenda-card
                            <?= $selecionada
                                ? "border-primary border-2"
                                : ""
                            ?>
                        "
                    >


                        <!-- =================================
                             CABEÇALHO CARD
                        ================================== -->

                        <div
                            class="
                                card-header
                                d-flex
                                justify-content-between
                                align-items-center
                            "
                        >

                            <strong>

                                📦 Encomenda

                            </strong>


                            <span
                                class="
                                    badge
                                    <?= htmlspecialchars(
                                        statusClasseEncomenda(
                                            $status
                                        )
                                    ) ?>
                                "
                            >

                                <?= htmlspecialchars(
                                    statusLabelEncomenda(
                                        $status
                                    )
                                ) ?>

                            </span>

                        </div>


                        <!-- =================================
                             CORPO
                        ================================== -->

                        <div class="card-body">


                            <!-- FOTO -->

                            <?php if (
                                $fotoUrl !== ""
                            ): ?>


                                <div class="mb-3">


                                    <a
                                        href="<?= htmlspecialchars(
                                            $fotoUrl
                                        ) ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >


                                        <img
                                            src="<?= htmlspecialchars(
                                                $fotoUrl
                                            ) ?>"
                                            alt="Foto da encomenda"
                                            class="foto-encomenda"
                                            loading="lazy"
                                        >


                                    </a>


                                </div>


                            <?php endif; ?>


                            <!-- DESCRIÇÃO -->

                            <div class="mb-3">


                                <div
                                    class="text-muted small"
                                >

                                    Descrição

                                </div>


                                <div class="fw-semibold">

                                    <?php

                                    if (
                                        $descricao !== ""
                                    ) {

                                        echo nl2br(
                                            htmlspecialchars(
                                                $descricao
                                            )
                                        );

                                    } else {

                                        echo "Sem descrição";
                                    }

                                    ?>

                                </div>


                            </div>


                            <!-- INFORMAÇÕES -->

                            <div class="row g-3">


                                <!-- UNIDADE -->

                                <div class="col-sm-6">


                                    <div
                                        class="text-muted small"
                                    >

                                        Unidade

                                    </div>


                                    <div>

                                        <?= htmlspecialchars(
                                            (string)
                                            (
                                                $encomenda[
                                                    "identificacao"
                                                ]
                                                ??
                                                "Não informada"
                                            )
                                        ) ?>

                                    </div>


                                </div>


                                <!-- RASTREIO -->

                                <div class="col-sm-6">


                                    <div
                                        class="text-muted small"
                                    >

                                        Código de rastreio

                                    </div>


                                    <div>

                                        <?php if (
                                            $rastreio !== ""
                                        ): ?>

                                            <?= htmlspecialchars(
                                                $rastreio
                                            ) ?>

                                        <?php else: ?>

                                            Não informado

                                        <?php endif; ?>

                                    </div>


                                </div>


                                <!-- RECEBIMENTO -->

                                <div class="col-sm-6">


                                    <div
                                        class="text-muted small"
                                    >

                                        Recebida em

                                    </div>


                                    <div>

                                        <?= formatarDataEncomenda(
                                            $encomenda[
                                                "data_recebimento"
                                            ]
                                            ??
                                            null
                                        ) ?>

                                    </div>


                                </div>


                                <!-- RETIRADA -->

                                <div class="col-sm-6">


                                    <div
                                        class="text-muted small"
                                    >

                                        Retirada em

                                    </div>


                                    <div>

                                        <?= formatarDataEncomenda(
                                            $encomenda[
                                                "data_retirada"
                                            ]
                                            ??
                                            null
                                        ) ?>

                                    </div>


                                </div>


                            </div>


                            <!-- =================================
                                 STATUS
                            ================================== -->


                            <?php if (
                                $status
                                ===
                                "Aguardando_Retirada"
                            ): ?>


                                <div
                                    class="
                                        alert
                                        alert-warning
                                        mt-4
                                        mb-0
                                    "
                                >

                                    📍 Sua encomenda está
                                    aguardando retirada
                                    na portaria.

                                </div>


                            <?php elseif (
                                $status
                                ===
                                "Entregue"
                            ): ?>


                                <div
                                    class="
                                        alert
                                        alert-success
                                        mt-4
                                        mb-0
                                    "
                                >

                                    ✅ Esta encomenda já foi
                                    marcada como retirada.

                                </div>


                            <?php elseif (
                                $status
                                ===
                                "Devolvido"
                            ): ?>


                                <div
                                    class="
                                        alert
                                        alert-secondary
                                        mt-4
                                        mb-0
                                    "
                                >

                                    ↩️ Esta encomenda foi
                                    marcada como devolvida.

                                </div>


                            <?php endif; ?>


                        </div>


                        <!-- =================================
                             RODAPÉ
                        ================================== -->

                        <div
                            class="card-footer bg-white"
                        >

                            <small
                                class="text-muted"
                            >

                                Última situação:

                                <?= htmlspecialchars(
                                    statusLabelEncomenda(
                                        $status
                                    )
                                ) ?>

                            </small>

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


<?php if (
    $idSelecionado > 0
    &&
    $encomendaSelecionada !== null
): ?>


<script>

document.addEventListener(
    "DOMContentLoaded",
    function () {

        const elemento =
            document.getElementById(
                "encomenda-<?= $idSelecionado ?>"
            );


        if (elemento) {

            elemento.scrollIntoView({

                behavior: "smooth",

                block: "center"

            });

        }

    }
);

</script>


<?php endif; ?>
<script src="../../assets/js/script.js"></script>

</body>

</html>
