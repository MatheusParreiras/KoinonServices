<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/LocalReservaController.php";

PermissaoController::exigirGestao();

$controller =
    new LocalReservaController();

$mensagem = "";

$tipoMensagem = "success";


/*
 * ==========================================================
 * DESATIVAR
 * ==========================================================
 */

if (
    isset($_GET["desativar"]) &&
    is_numeric($_GET["desativar"])
) {

    $id =
        (int) $_GET["desativar"];

    if (
        $controller->desativar($id)
    ) {

        $mensagem =
            "Local desativado com sucesso. "
            . "Ele não poderá receber novas reservas.";

    } else {

        $mensagem =
            "Não foi possível desativar o local.";

        $tipoMensagem = "danger";
    }
}


/*
 * ==========================================================
 * REATIVAR
 * ==========================================================
 */

if (
    isset($_GET["reativar"]) &&
    is_numeric($_GET["reativar"])
) {

    $id =
        (int) $_GET["reativar"];

    if (
        $controller->reativar($id)
    ) {

        $mensagem =
            "Local reativado com sucesso.";

    } else {

        $mensagem =
            "Não foi possível reativar o local.";

        $tipoMensagem = "danger";
    }
}


/*
 * ==========================================================
 * MENSAGENS
 * ==========================================================
 */

if (isset($_GET["sucesso"])) {

    if (
        $_GET["sucesso"] === "1"
    ) {

        $mensagem =
            "Local de reserva cadastrado com sucesso.";

    } elseif (
        $_GET["sucesso"] === "2"
    ) {

        $mensagem =
            "Local de reserva atualizado com sucesso.";
    }
}


if (isset($_GET["erro"])) {

    $tipoMensagem = "danger";

    if (
        $_GET["erro"] === "local_invalido"
    ) {

        $mensagem =
            "Local de reserva inválido.";

    } elseif (
        $_GET["erro"] === "local_nao_encontrado"
    ) {

        $mensagem =
            "Local de reserva não encontrado.";
    }
}


/*
 * ==========================================================
 * LISTAGEM
 * ==========================================================
 */

$locais =
    $controller->listar();


/*
 * ==========================================================
 * FORMATAR DURAÇÃO
 * ==========================================================
 */

function formatarDuracao(
    int $minutos
): string {

    $horas =
        intdiv(
            $minutos,
            60
        );

    $minutosRestantes =
        $minutos % 60;


    if (
        $horas > 0 &&
        $minutosRestantes > 0
    ) {

        return
            $horas
            . "h "
            . $minutosRestantes
            . "min";
    }


    if ($horas > 0) {

        return
            $horas
            . "h";
    }


    return
        $minutos
        . "min";
}


/*
 * ==========================================================
 * DIAS
 * ==========================================================
 */

function diasPermitidos(
    $local
): string {

    $dias = [];


    if (
        $local->getDomingo()
    ) {

        $dias[] = "Dom";
    }


    if (
        $local->getSegunda()
    ) {

        $dias[] = "Seg";
    }


    if (
        $local->getTerca()
    ) {

        $dias[] = "Ter";
    }


    if (
        $local->getQuarta()
    ) {

        $dias[] = "Qua";
    }


    if (
        $local->getQuinta()
    ) {

        $dias[] = "Qui";
    }


    if (
        $local->getSexta()
    ) {

        $dias[] = "Sex";
    }


    if (
        $local->getSabado()
    ) {

        $dias[] = "Sáb";
    }


    return implode(
        ", ",
        $dias
    );
}


/*
 * ==========================================================
 * PERÍODO
 * ==========================================================
 */

function periodoLimiteTexto(
    string $periodo
): string {

    if (
        $periodo === "Mes"
    ) {

        return "mês";
    }


    if (
        $periodo === "Total"
    ) {

        return "total";
    }


    return "dia";
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
        Locais de Reserva - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>


<body class="bg-light">


<div class="container-fluid py-4">


    <!-- ======================================================
         CABEÇALHO
    ======================================================= -->

    <div
        class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4"
    >

        <div>

            <h2>
                🏢 Locais de Reserva
            </h2>

            <p class="text-muted mb-0">

                Cadastre os locais e defina as regras
                utilizadas nas reservas dos moradores.

            </p>

        </div>


        <div class="d-flex gap-2">

            <a
                href="cadastrar_local.php"
                class="btn btn-primary"
            >
                + Novo Local
            </a>


            <a
                href="index.php"
                class="btn btn-secondary"
            >
                Reservas
            </a>


            <a
                href="../dashboard.php"
                class="btn btn-outline-secondary"
            >
                Voltar
            </a>

        </div>

    </div>


    <!-- ======================================================
         MENSAGEM
    ======================================================= -->

    <?php if (
        $mensagem !== ""
    ): ?>

        <div
            class="alert alert-<?= htmlspecialchars(
                $tipoMensagem
            ) ?>
            alert-dismissible fade show"
            role="alert"
        >

            <?= htmlspecialchars(
                $mensagem
            ) ?>


            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- ======================================================
         CARD
    ======================================================= -->

    <div class="card shadow-sm">


        <div class="card-header">

            <strong>
                Locais cadastrados
            </strong>

        </div>


        <div class="card-body">


            <?php if (
                empty($locais)
            ): ?>

                <div class="alert alert-info mb-0">

                    Nenhum local de reserva cadastrado.

                </div>

            <?php else: ?>


                <div class="table-responsive">


                    <table
                        class="table table-bordered table-hover align-middle"
                    >


                        <thead class="table-dark">

                            <tr>

                                <th>Local</th>

                                <th>Capacidade</th>

                                <th>Valor</th>

                                <th>Horário</th>

                                <th>Duração</th>

                                <th>Antecedência</th>

                                <th>Limite</th>

                                <th>Dias</th>

                                <th>Status</th>

                                <th>Ações</th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php foreach (
                            $locais
                            as $local
                        ): ?>


                            <tr>


                                <td>

                                    <strong>

                                        <?= htmlspecialchars(
                                            $local->getNome()
                                        ) ?>

                                    </strong>


                                    <?php if (
                                        $local->getDescricao()
                                        !== ""
                                    ): ?>

                                        <br>

                                        <small class="text-muted">

                                            <?= htmlspecialchars(
                                                $local->getDescricao()
                                            ) ?>

                                        </small>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= $local->getCapacidade() ?>

                                    pessoas

                                </td>


                                <td>


                                    <?php if (
                                        $local->getGratuito()
                                    ): ?>

                                        <span
                                            class="badge text-bg-success"
                                        >

                                            Gratuito

                                        </span>

                                    <?php else: ?>

                                        R$

                                        <?= number_format(
                                            $local->getValor(),
                                            2,
                                            ",",
                                            "."
                                        ) ?>

                                    <?php endif; ?>


                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        substr(
                                            $local->getHorarioInicio(),
                                            0,
                                            5
                                        )
                                    ) ?>

                                    às

                                    <?= htmlspecialchars(
                                        substr(
                                            $local->getHorarioFim(),
                                            0,
                                            5
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <?= formatarDuracao(
                                        $local->getDuracaoMinutosMinima()
                                    ) ?>

                                    a

                                    <?= formatarDuracao(
                                        $local->getDuracaoMinutosMaxima()
                                    ) ?>

                                </td>


                                <td>

                                    <?= $local->getAntecedenciaMinDias() ?>

                                    a

                                    <?= $local->getAntecedenciaMaxDias() ?>

                                    dias

                                </td>


                                <td>

                                    <?= $local->getLimiteReservasUnidade() ?>

                                    por

                                    <?= periodoLimiteTexto(
                                        $local->getPeriodoLimite()
                                    ) ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        diasPermitidos(
                                            $local
                                        )
                                    ) ?>

                                </td>


                                <td>


                                    <?php if (
                                        $local->getAtivo()
                                    ): ?>

                                        <span
                                            class="badge text-bg-success"
                                        >

                                            Ativo

                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="badge text-bg-secondary"
                                        >

                                            Inativo

                                        </span>

                                    <?php endif; ?>


                                </td>


                                <td>

                                    <div
                                        class="d-flex flex-wrap gap-1"
                                    >


                                        <a
                                            href="editar_local.php?id=<?= $local->getId() ?>"
                                            class="btn btn-sm btn-warning"
                                        >

                                            Editar

                                        </a>


                                        <a
                                            href="gerenciar_fotos.php?id=<?= $local->getId() ?>"
                                            class="btn btn-sm btn-info text-white"
                                        >

                                            📷 Álbum

                                        </a>


                                        <?php if (
                                            $local->getAtivo()
                                        ): ?>


                                            <a
                                                href="locais.php?desativar=<?= $local->getId() ?>"
                                                class="btn btn-sm btn-danger"
                                                onclick="return confirm('Desativar este local? Ele não poderá receber novas reservas enquanto estiver inativo.');"
                                            >

                                                Desativar

                                            </a>


                                        <?php else: ?>


                                            <a
                                                href="locais.php?reativar=<?= $local->getId() ?>"
                                                class="btn btn-sm btn-success"
                                            >

                                                Reativar

                                            </a>


                                        <?php endif; ?>


                                    </div>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                        </tbody>

                    </table>

                </div>


            <?php endif; ?>


        </div>

    </div>


</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"
></script>
<script src="../../assets/js/script.js"></script>

</body>

</html>