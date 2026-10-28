<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirGestao();

require_once __DIR__ . "/../../controller/ReservaController.php";
require_once __DIR__ . "/../../controller/UsuarioController.php";
require_once __DIR__ . "/../../controller/LocalReservaController.php";

$reservaController =
    new ReservaController();

$usuarioController =
    new UsuarioController();

$localReservaController =
    new LocalReservaController();


if (
    !isset($_GET["id"]) ||
    !is_numeric($_GET["id"])
) {

    die("Reserva inválida.");
}


$id =
    (int) $_GET["id"];


$reserva =
    $reservaController->buscarPorId(
        $id
    );


if (!$reserva) {

    die("Reserva não encontrada.");
}


$usuarios =
    $usuarioController->listar();


$locais =
    $localReservaController->listarAtivos();


/*
 * Também adiciona o local atual caso ele esteja inativo.
 * Isso permite editar uma reserva antiga sem perder
 * a referência ao local.
 */

$localAtual = null;


if (
    $reserva->getLocalReservaId()
) {

    $localAtual =
        $localReservaController->buscarPorId(
            $reserva->getLocalReservaId()
        );


    if (
        $localAtual &&
        !$localAtual->isAtivo()
    ) {

        $jaExiste = false;


        foreach (
            $locais as $local
        ) {

            if (
                $local->getId() ===
                $localAtual->getId()
            ) {

                $jaExiste = true;

                break;
            }
        }


        if (!$jaExiste) {

            $locais[] =
                $localAtual;
        }
    }
}


$erro = "";


/*
 * ==========================================================
 * POST
 * ==========================================================
 */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
) {

    $usuarioId =
        isset($_POST["usuario_id"])
            ? (int) $_POST["usuario_id"]
            : 0;


    $localReservaId =
        isset($_POST["local_reserva_id"])
            ? (int) $_POST["local_reserva_id"]
            : 0;


    $listaPresenca =
        ReservaDTO::listaDeTexto($_POST["lista_presenca"] ?? "");


    $dataInicio =
        trim(
            $_POST["data_inicio"] ?? ""
        );


    $dataFim =
        trim(
            $_POST["data_fim"] ?? ""
        );


    $status =
        $_POST["status"] ?? "Pendente";


    if ($usuarioId <= 0) {

        $erro =
            "Selecione o usuário responsável pela reserva.";

    } elseif ($localReservaId <= 0) {

        $erro =
            "Selecione o local que será reservado.";

    } elseif ($dataInicio === "") {

        $erro =
            "Informe a data e hora de início.";

    } elseif ($dataFim === "") {

        $erro =
            "Informe a data e hora de término.";

    } elseif (
        strtotime($dataFim) <=
        strtotime($dataInicio)
    ) {

        $erro =
            "A data/hora final deve ser posterior à data/hora inicial.";

    } elseif (
        !in_array(
            $status,
            [
                "Pendente",
                "Aguardando_Pagamento",
                "Confirmada",
                "Cancelada",
                "Concluida"
            ],
            true
        )
    ) {

        $erro =
            "Status da reserva inválido.";

    } else {

        $timestampInicio =
            strtotime(
                $dataInicio
            );


        $timestampFim =
            strtotime(
                $dataFim
            );


        if (
            $timestampInicio === false ||
            $timestampFim === false
        ) {

            $erro =
                "A data ou horário informado é inválido.";

        } else {

            $dataInicioMysql =
                date(
                    "Y-m-d H:i:s",
                    $timestampInicio
                );


            $dataFimMysql =
                date(
                    "Y-m-d H:i:s",
                    $timestampFim
                );


            $reserva->setUsuarioId(
                $usuarioId
            );


            $reserva->setLocalReservaId(
                $localReservaId
            );


            $reserva->setListaPresenca($listaPresenca);


            $reserva->setDataInicio(
                $dataInicioMysql
            );


            $reserva->setDataFim(
                $dataFimMysql
            );


            $reserva->setStatus(
                $status
            );


            /*
             * A taxa será recalculada automaticamente
             * conforme o local selecionado.
             */

            if (
                $reservaController->editar(
                    $reserva
                )
            ) {

                header(
                    "Location: index.php?sucesso=2"
                );

                exit;

            } else {

                $erro =
                    $reservaController->getUltimoErro();

                if (
                    $erro === ""
                ) {

                    $erro =
                        "Não foi possível editar a reserva.";
                }
            }
        }
    }
}


/*
 * ==========================================================
 * VALORES PARA EXIBIÇÃO
 * ==========================================================
 */

$valorUsuario =
    $_POST["usuario_id"]
    ?? $reserva->getUsuarioId();


$valorLocal =
    $_POST["local_reserva_id"]
    ?? $reserva->getLocalReservaId();


$valorQuantidade =
    $_POST["lista_presenca"]
    ?? implode("\n", $reserva->getListaPresenca());


$valorStatus =
    $_POST["status"]
    ?? $reserva->getStatus();


$valorTaxa =
    number_format(
        $reserva->getValorTaxa(),
        2,
        ",",
        "."
    );


$valorDataInicio =
    $_POST["data_inicio"]
    ?? "";


if (
    $valorDataInicio === "" &&
    $reserva->getDataInicio()
) {

    $valorDataInicio =
        date(
            "Y-m-d\TH:i",
            strtotime(
                $reserva->getDataInicio()
            )
        );
}


$valorDataFim =
    $_POST["data_fim"]
    ?? "";


if (
    $valorDataFim === "" &&
    $reserva->getDataFim()
) {

    $valorDataFim =
        date(
            "Y-m-d\TH:i",
            strtotime(
                $reserva->getDataFim()
            )
        );
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
        Editar Reserva - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container mt-4">

    <div class="row justify-content-center">

        <div class="col-md-8">

            <div class="card shadow">

                <div class="card-header bg-warning">

                    <h4 class="mb-0">
                        Editar Reserva
                    </h4>

                </div>


                <div class="card-body">

                    <?php if (!empty($erro)): ?>

                        <div class="alert alert-danger">

                            <?= htmlspecialchars($erro) ?>

                        </div>

                    <?php endif; ?>


                    <form method="POST">


                        <!-- USUÁRIO -->

                        <div class="mb-3">

                            <label
                                for="usuario_id"
                                class="form-label"
                            >
                                Usuário responsável
                            </label>


                            <select
                                name="usuario_id"
                                id="usuario_id"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Selecione o usuário
                                </option>


                                <?php foreach ($usuarios as $usuario): ?>

                                    <option
                                        value="<?= $usuario->getId() ?>"
                                        <?= (
                                            $valorUsuario ==
                                            $usuario->getId()
                                        )
                                            ? "selected"
                                            : ""
                                        ?>
                                    >

                                        <?= htmlspecialchars(
                                            $usuario->getNome()
                                        ) ?>

                                        -

                                        <?= htmlspecialchars(
                                            $usuario->getPerfil()
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- LOCAL -->

                        <div class="mb-3">

                            <label
                                for="local_reserva_id"
                                class="form-label"
                            >
                                Local de reserva
                            </label>


                            <select
                                name="local_reserva_id"
                                id="local_reserva_id"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Selecione o local
                                </option>


                                <?php foreach ($locais as $local): ?>

                                    <option
                                        value="<?= $local->getId() ?>"
                                        data-capacidade="<?= $local->getCapacidade() ?>"
                                        data-gratuito="<?= $local->isGratuito() ? "1" : "0" ?>"
                                        data-valor="<?= number_format(
                                            $local->getValor(),
                                            2,
                                            ",",
                                            "."
                                        ) ?>"
                                        data-horario-inicio="<?= htmlspecialchars(
                                            $local->getHorarioInicio()
                                        ) ?>"
                                        data-horario-fim="<?= htmlspecialchars(
                                            $local->getHorarioFim()
                                        ) ?>"
                                        <?= (
                                            $valorLocal ==
                                            $local->getId()
                                        )
                                            ? "selected"
                                            : ""
                                        ?>
                                    >

                                        <?= htmlspecialchars(
                                            $local->getNome()
                                        ) ?>

                                        <?php if (!$local->isAtivo()): ?>

                                            - Inativo

                                        <?php endif; ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>


                            <div
                                id="informacoes-local"
                                class="form-text mt-2"
                            ></div>

                        </div>


                        <!-- QUANTIDADE -->

                        <div class="mb-3">

                            <label
                                for="lista_presenca"
                                class="form-label"
                            >
                                Lista de presença (um convidado por linha)
                            </label>


                            <textarea
                                name="lista_presenca"
                                id="lista_presenca"
                                class="form-control"
                                rows="4"
                            ><?= htmlspecialchars(
                                $valorQuantidade
                            ) ?></textarea>


                            <div class="form-text">
                                A lista não pode ultrapassar a capacidade do local.
                            </div>

                        </div>


                        <!-- DATA INÍCIO -->

                        <div class="mb-3">

                            <label
                                for="data_inicio"
                                class="form-label"
                            >
                                Data e hora de início
                            </label>


                            <input
                                type="datetime-local"
                                name="data_inicio"
                                id="data_inicio"
                                class="form-control"
                                value="<?= htmlspecialchars(
                                    $valorDataInicio
                                ) ?>"
                                required
                            >

                        </div>


                        <!-- DATA FIM -->

                        <div class="mb-3">

                            <label
                                for="data_fim"
                                class="form-label"
                            >
                                Data e hora de término
                            </label>


                            <input
                                type="datetime-local"
                                name="data_fim"
                                id="data_fim"
                                class="form-control"
                                value="<?= htmlspecialchars(
                                    $valorDataFim
                                ) ?>"
                                required
                            >

                        </div>


                        <!-- STATUS -->

                        <div class="mb-3">

                            <label
                                for="status"
                                class="form-label"
                            >
                                Status
                            </label>


                            <select
                                name="status"
                                id="status"
                                class="form-select"
                                required
                            >

                                <option
                                    value="Pendente"
                                    <?= $valorStatus === "Pendente"
                                        ? "selected"
                                        : ""
                                    ?>
                                >
                                    Pendente
                                </option>


                                <option
                                    value="Aguardando_Pagamento"
                                    <?= $valorStatus === "Aguardando_Pagamento"
                                        ? "selected"
                                        : ""
                                    ?>
                                >
                                    Aguardando pagamento
                                </option>


                                <option
                                    value="Confirmada"
                                    <?= $valorStatus === "Confirmada"
                                        ? "selected"
                                        : ""
                                    ?>
                                >
                                    Confirmada
                                </option>


                                <option
                                    value="Cancelada"
                                    <?= $valorStatus === "Cancelada"
                                        ? "selected"
                                        : ""
                                    ?>
                                >
                                    Cancelada
                                </option>


                                <option
                                    value="Concluida"
                                    <?= $valorStatus === "Concluida"
                                        ? "selected"
                                        : ""
                                    ?>
                                >
                                    Concluída
                                </option>

                            </select>

                        </div>


                        <!-- TAXA -->

                        <div class="mb-3">

                            <label
                                for="valor_taxa"
                                class="form-label"
                            >
                                Taxa da reserva
                            </label>


                            <div class="input-group">

                                <span class="input-group-text">
                                    R$
                                </span>


                                <input
                                    type="text"
                                    id="valor_taxa"
                                    class="form-control"
                                    value="<?= htmlspecialchars(
                                        $valorTaxa
                                    ) ?>"
                                    readonly
                                >

                            </div>


                            <div class="form-text">
                                O valor é definido automaticamente conforme o local escolhido.
                            </div>

                        </div>


                        <!-- BOTÕES -->

                        <div class="d-flex gap-2">

                            <button
                                type="submit"
                                class="btn btn-warning"
                            >
                                Salvar Alterações
                            </button>


                            <a
                                href="index.php"
                                class="btn btn-secondary"
                            >
                                Cancelar
                            </a>

                        </div>

                    </form>

                </div>

            </div>

        </div>

    </div>

</div>


<script>

const selectLocal =
    document.getElementById(
        "local_reserva_id"
    );

const quantidadePessoas =
    document.getElementById(
        "lista_presenca"
    );

const valorTaxa =
    document.getElementById(
        "valor_taxa"
    );

const informacoesLocal =
    document.getElementById(
        "informacoes-local"
    );


function atualizarLocal()
{
    const option =
        selectLocal.options[
            selectLocal.selectedIndex
        ];


    if (
        !option ||
        !option.value
    ) {

        quantidadePessoas.removeAttribute(
            "max"
        );

        informacoesLocal.innerHTML =
            "";

        return;
    }


    const capacidade =
        option.dataset.capacidade;


    const gratuito =
        option.dataset.gratuito === "1";


    const valor =
        option.dataset.valor;


    const horarioInicio =
        option.dataset.horarioInicio
        || "";


    const horarioFim =
        option.dataset.horarioFim
        || "";


    if (capacidade) {

        quantidadePessoas.max =
            capacidade;
    }


    valorTaxa.value =
        gratuito
            ? "0,00"
            : valor;


    let texto =
        "Capacidade: "
        + capacidade
        + " pessoa(s).";


    if (gratuito) {

        texto +=
            " Local gratuito.";

    } else {

        texto +=
            " Taxa: R$ "
            + valor
            + ".";
    }


    if (
        horarioInicio &&
        horarioFim
    ) {

        texto +=
            " Funcionamento: "
            + horarioInicio.substring(0, 5)
            + " às "
            + horarioFim.substring(0, 5)
            + ".";
    }


    informacoesLocal.innerHTML =
        texto;
}


selectLocal.addEventListener(
    "change",
    atualizarLocal
);


atualizarLocal();

</script>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>
<script src="../../assets/js/script.js"></script>

</body>

</html>