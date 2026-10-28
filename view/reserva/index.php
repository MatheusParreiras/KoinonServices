<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirGestao();

require_once __DIR__ . "/../../controller/ReservaController.php";

$controller =
    new ReservaController();


$reservas =
    $controller->listar();


$mensagem = "";

$tipoMensagem =
    "success";


/*
 * ==========================================================
 * EXCLUSÃO
 * ==========================================================
 */

if (
    isset($_GET["excluir"]) &&
    is_numeric($_GET["excluir"])
) {

    $id =
        (int) $_GET["excluir"];


    if (
        $controller->excluir(
            $id
        )
    ) {

        $mensagem =
            "Reserva excluída com sucesso.";

        $tipoMensagem =
            "success";

    } else {

        $mensagem =
            "Não foi possível excluir a reserva.";

        $tipoMensagem =
            "danger";
    }


    $reservas =
        $controller->listar();
}


/*
 * ==========================================================
 * ALTERAÇÃO DE STATUS
 * ==========================================================
 */

if (
    isset($_GET["status"]) &&
    isset($_GET["id"]) &&
    is_numeric($_GET["id"])
) {

    $id =
        (int) $_GET["id"];


    $status =
        $_GET["status"];


    $statusPermitidos = [
        "Pendente",
        "Aguardando_Pagamento",
        "Confirmada",
        "Cancelada",
        "Concluida"
    ];


    if (
        in_array(
            $status,
            $statusPermitidos,
            true
        )
    ) {

        if (
            $controller->alterarStatus(
                $id,
                $status
            )
        ) {

            $mensagem =
                "Status da reserva atualizado com sucesso.";

            $tipoMensagem =
                "success";

        } else {

            $mensagem =
                $controller->getUltimoErro();

            if (
                $mensagem === ""
            ) {

                $mensagem =
                    "Não foi possível atualizar o status.";
            }

            $tipoMensagem =
                "danger";
        }


        $reservas =
            $controller->listar();
    }
}


/*
 * ==========================================================
 * MENSAGENS
 * ==========================================================
 */

if (
    isset($_GET["sucesso"])
) {

    if (
        $_GET["sucesso"] == "1"
    ) {

        $mensagem =
            "Reserva cadastrada com sucesso.";

        $tipoMensagem =
            "success";
    }


    if (
        $_GET["sucesso"] == "2"
    ) {

        $mensagem =
            "Reserva editada com sucesso.";

        $tipoMensagem =
            "success";
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
        Reservas - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container-fluid mt-4">

    <!-- CABEÇALHO -->

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2>
                Reservas
            </h2>

            <p class="text-muted mb-0">
                Gerenciamento das reservas das áreas comuns.
            </p>

        </div>


        <div>

            <a
                href="cadastrar.php"
                class="btn btn-primary"
            >
                + Nova Reserva
            </a>


            <a
                href="locais.php"
                class="btn btn-outline-primary"
            >
                Locais de Reserva
            </a>


            <a
                href="../dashboard.php"
                class="btn btn-secondary"
            >
                Voltar
            </a>

        </div>

    </div>


    <!-- MENSAGEM -->

    <?php if (!empty($mensagem)): ?>

        <div
            class="alert alert-<?= htmlspecialchars($tipoMensagem) ?> alert-dismissible fade show"
            role="alert"
        >

            <?= htmlspecialchars($mensagem) ?>


            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- TABELA -->

    <div class="card shadow-sm">

        <div class="card-header">

            <strong>
                Lista de Reservas
            </strong>

        </div>


        <div class="card-body">

            <?php if (empty($reservas)): ?>

                <div class="alert alert-info mb-0">

                    Nenhuma reserva cadastrada.

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table
                        class="table table-bordered table-hover align-middle"
                    >

                        <thead class="table-dark">

                            <tr>

                                <th>
                                    Usuário
                                </th>

                                <th>
                                    Espaço
                                </th>

                                <th>
                                    Convidados
                                </th>

                                <th>
                                    Data/Hora Inicial
                                </th>

                                <th>
                                    Data/Hora Final
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Taxa
                                </th>

                                <th>
                                    Criado em
                                </th>

                                <th>
                                    Ações
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($reservas as $reserva): ?>

                            <?php

                            $status =
                                $reserva->getStatus();


                            switch ($status) {

                                case "Confirmada":

                                    $classeStatus =
                                        "success";

                                    break;


                                case "Aguardando_Pagamento":

                                    $classeStatus =
                                        "warning text-dark";

                                    break;


                                case "Cancelada":

                                    $classeStatus =
                                        "danger";

                                    break;


                                case "Concluida":

                                    $classeStatus =
                                        "secondary";

                                    break;


                                default:

                                    $classeStatus =
                                        "warning";

                                    break;
                            }

                            ?>


                            <tr>

                                <!-- USUÁRIO -->

                                <td>

                                    <?= htmlspecialchars(
                                        $reserva->getUsuarioNome()
                                        ?? "Não informado"
                                    ) ?>

                                </td>


                                <!-- ESPAÇO -->

                                <td>

                                    <?= htmlspecialchars(
                                        $reserva->getEspacoNome()
                                    ) ?>

                                </td>


                                <!-- PESSOAS -->

                                <td>

                                    <?= $reserva->getQuantidadePessoas() ?> convidado(s)

                                </td>


                                <!-- DATA INICIAL -->

                                <td>

                                    <?php

                                    $dataInicio =
                                        $reserva->getDataInicio();


                                    if ($dataInicio) {

                                        echo date(
                                            "d/m/Y H:i",
                                            strtotime(
                                                $dataInicio
                                            )
                                        );
                                    }

                                    ?>

                                </td>


                                <!-- DATA FINAL -->

                                <td>

                                    <?php

                                    $dataFim =
                                        $reserva->getDataFim();


                                    if ($dataFim) {

                                        echo date(
                                            "d/m/Y H:i",
                                            strtotime(
                                                $dataFim
                                            )
                                        );
                                    }

                                    ?>

                                </td>


                                <!-- STATUS -->

                                <td>

                                    <span
                                        class="badge bg-<?= $classeStatus ?>"
                                    >

                                        <?= htmlspecialchars(
                                            $status === "Aguardando_Pagamento"
                                                ? "Aguardando pagamento"
                                                : $status
                                        ) ?>

                                    </span>

                                </td>


                                <!-- TAXA -->

                                <td>

                                    R$

                                    <?= number_format(
                                        $reserva->getValorTaxa(),
                                        2,
                                        ",",
                                        "."
                                    ) ?>

                                </td>


                                <!-- CRIADO EM -->

                                <td>

                                    <?php

                                    $createdAt =
                                        $reserva->getCreatedAt();


                                    if ($createdAt) {

                                        echo date(
                                            "d/m/Y H:i",
                                            strtotime(
                                                $createdAt
                                            )
                                        );
                                    }

                                    ?>

                                </td>


                                <!-- AÇÕES -->

                                <td>

                                    <div
                                        class="d-flex gap-1 flex-wrap"
                                    >

                                        <a
                                            href="editar.php?id=<?= $reserva->getId() ?>"
                                            class="btn btn-sm btn-warning"
                                        >
                                            Editar
                                        </a>


                                        <?php if ($status === "Pendente"): ?>

                                            <a
                                                href="index.php?id=<?= $reserva->getId() ?>&status=Confirmada"
                                                class="btn btn-sm btn-success"
                                                onclick="return confirm('<?= $reserva->getValorTaxa() > 0 ? 'Aprovar esta reserva? Ela ficará aguardando o pagamento do morador e será confirmada automaticamente quando for paga.' : 'Confirmar esta reserva?' ?>')"
                                            >
                                                <?= $reserva->getValorTaxa() > 0 ? 'Aprovar' : 'Confirmar' ?>
                                            </a>


                                            <a
                                                href="index.php?id=<?= $reserva->getId() ?>&status=Cancelada"
                                                class="btn btn-sm btn-danger"
                                                onclick="return confirm('Cancelar esta reserva?')"
                                            >
                                                Cancelar
                                            </a>


                                        <?php elseif ($status === "Aguardando_Pagamento"): ?>

                                            <a
                                                href="../financeiro/index.php"
                                                class="btn btn-sm btn-outline-primary"
                                            >
                                                💳 Ver cobrança
                                            </a>


                                            <a
                                                href="index.php?id=<?= $reserva->getId() ?>&status=Cancelada"
                                                class="btn btn-sm btn-danger"
                                                onclick="return confirm('Cancelar esta reserva e a cobrança pendente?')"
                                            >
                                                Cancelar
                                            </a>

                                        <?php elseif ($status === "Confirmada"): ?>

                                            <a
                                                href="index.php?id=<?= $reserva->getId() ?>&status=Concluida"
                                                class="btn btn-sm btn-secondary"
                                                onclick="return confirm('Marcar esta reserva como concluída?')"
                                            >
                                                Concluir
                                            </a>


                                            <a
                                                href="index.php?id=<?= $reserva->getId() ?>&status=Cancelada"
                                                class="btn btn-sm btn-danger"
                                                onclick="return confirm('Cancelar esta reserva?')"
                                            >
                                                Cancelar
                                            </a>

                                        <?php endif; ?>


                                        <a
                                            href="index.php?excluir=<?= $reserva->getId() ?>"
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('Tem certeza que deseja excluir esta reserva?')"
                                        >
                                            Excluir
                                        </a>

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
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>
<script src="../../assets/js/script.js"></script>

</body>

</html>