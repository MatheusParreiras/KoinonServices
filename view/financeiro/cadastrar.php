<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirGestao();

require_once __DIR__ . "/../../controller/CobrancaController.php";
require_once __DIR__ . "/../../controller/UnidadeController.php";

$cobrancaController = new CobrancaController();
$unidadeController = new UnidadeController();

$unidades = $unidadeController->listar();

$erro = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $unidadeId = filter_input(
        INPUT_POST,
        "unidade_id",
        FILTER_VALIDATE_INT
    );

    $tipoCobranca = trim($_POST["tipo_cobranca"] ?? "");
    $mesReferencia = trim($_POST["mes_referencia"] ?? "");
    $descricao = trim($_POST["descricao"] ?? "");
    $valor = str_replace(",", ".", trim($_POST["valor"] ?? ""));
    $vencimento = trim($_POST["vencimento"] ?? "");
    $linhaDigitavel = "";
    $linkBoleto = "";
    $status = trim($_POST["status"] ?? "Pendente");

    $tiposPermitidos = [
        "Taxa_Condominial",
        "Reserva",
        "Multa",
        "Taxa_Extraordinaria",
        "Servico",
        "Outros"
    ];

    $statusPermitidos = [
        "Pendente",
        "Pago",
        "Vencido",
        "Cancelado"
    ];

    if (!$unidadeId) {

        $erro = "Selecione uma unidade.";

    } elseif (!in_array($tipoCobranca, $tiposPermitidos, true)) {

        $erro = "Selecione um tipo de cobrança válido.";

    } elseif ($mesReferencia === "") {

        $erro = "Informe o mês de referência.";

    } elseif ($descricao === "") {

        $erro = "Informe a descrição da cobrança.";

    } elseif (!is_numeric($valor) || (float) $valor < 0) {

        $erro = "Informe um valor válido.";

    } elseif ($vencimento === "") {

        $erro = "Informe o vencimento.";

    } elseif (!in_array($status, $statusPermitidos, true)) {

        $erro = "Status inválido.";

    } else {

        if (
            $status === "Pendente" &&
            $vencimento < date("Y-m-d")
        ) {
            $status = "Vencido";
        }

        $cobranca = new CobrancaDTO();

        $cobranca->setUnidadeId((int) $unidadeId);
        $cobranca->setTipoCobranca($tipoCobranca);
        $cobranca->setMesReferencia($mesReferencia);
        $cobranca->setDescricao($descricao);
        $cobranca->setValor((float) $valor);
        $cobranca->setVencimento($vencimento);
        $cobranca->setLinhaDigitavel(
            $linhaDigitavel !== ""
                ? $linhaDigitavel
                : null
        );
        $cobranca->setLinkBoleto(
            $linkBoleto !== ""
                ? $linkBoleto
                : null
        );
        $cobranca->setStatus($status);

        if ($cobrancaController->cadastrar($cobranca)) {

            header(
                "Location: index.php?sucesso=1"
            );
            exit;

        } else {

            $erro = "Não foi possível cadastrar a cobrança.";
        }
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

    <title>Nova Cobrança - Koinon Services</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container mt-5">

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2>
                Nova Cobrança
            </h2>

            <p class="text-muted mb-0">
                Cadastro de valores a serem cobrados das unidades.
            </p>

        </div>

        <a
            href="index.php"
            class="btn btn-secondary"
        >
            Voltar
        </a>

    </div>

    <?php if ($erro !== ""): ?>

        <div class="alert alert-danger">

            <?= htmlspecialchars($erro) ?>

        </div>

    <?php endif; ?>


    <div class="card shadow">

        <div class="card-body">

            <form method="POST">

                <div class="row">

                    <!-- UNIDADE -->

                    <div class="col-md-6 mb-3">

                        <label
                            for="unidade_id"
                            class="form-label"
                        >
                            Unidade *
                        </label>

                        <select
                            name="unidade_id"
                            id="unidade_id"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Selecione a unidade
                            </option>

                            <?php foreach ($unidades as $unidade): ?>

                                <option
                                    value="<?= $unidade->getId() ?>"
                                    <?= (
                                        isset($_POST["unidade_id"]) &&
                                        $_POST["unidade_id"] == $unidade->getId()
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >

                                    <?= htmlspecialchars(
                                        $unidade->getIdentificacao()
                                    ) ?>

                                    -
                                    <?= htmlspecialchars(
                                        $unidade->getTipoUnidade()
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- TIPO -->

                    <div class="col-md-6 mb-3">

                        <label
                            for="tipo_cobranca"
                            class="form-label"
                        >
                            Tipo de cobrança *
                        </label>

                        <select
                            name="tipo_cobranca"
                            id="tipo_cobranca"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Selecione o tipo
                            </option>

                            <option
                                value="Taxa_Condominial"
                                <?= (
                                    ($_POST["tipo_cobranca"] ?? "")
                                    === "Taxa_Condominial"
                                )
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Taxa Condominial
                            </option>

                            <option
                                value="Reserva"
                                <?= (
                                    ($_POST["tipo_cobranca"] ?? "")
                                    === "Reserva"
                                )
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Reserva de Área Comum
                            </option>

                            <option
                                value="Multa"
                                <?= (
                                    ($_POST["tipo_cobranca"] ?? "")
                                    === "Multa"
                                )
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Multa
                            </option>

                            <option
                                value="Taxa_Extraordinaria"
                                <?= (
                                    ($_POST["tipo_cobranca"] ?? "")
                                    === "Taxa_Extraordinaria"
                                )
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Taxa Extraordinária
                            </option>

                            <option
                                value="Servico"
                                <?= (
                                    ($_POST["tipo_cobranca"] ?? "")
                                    === "Servico"
                                )
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Serviço
                            </option>

                            <option
                                value="Outros"
                                <?= (
                                    ($_POST["tipo_cobranca"] ?? "")
                                    === "Outros"
                                )
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Outros
                            </option>

                        </select>

                    </div>


                    <!-- MÊS -->

                    <div class="col-md-4 mb-3">

                        <label
                            for="mes_referencia"
                            class="form-label"
                        >
                            Mês de referência *
                        </label>

                        <input
                            type="month"
                            name="mes_referencia"
                            id="mes_referencia"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $_POST["mes_referencia"] ?? ""
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- VALOR -->

                    <div class="col-md-4 mb-3">

                        <label
                            for="valor"
                            class="form-label"
                        >
                            Valor *
                        </label>

                        <input
                            type="number"
                            name="valor"
                            id="valor"
                            class="form-control"
                            step="0.01"
                            min="0"
                            value="<?= htmlspecialchars(
                                $_POST["valor"] ?? ""
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- VENCIMENTO -->

                    <div class="col-md-4 mb-3">

                        <label
                            for="vencimento"
                            class="form-label"
                        >
                            Vencimento *
                        </label>

                        <input
                            type="date"
                            name="vencimento"
                            id="vencimento"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $_POST["vencimento"] ?? ""
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- DESCRIÇÃO -->

                    <div class="col-md-12 mb-3">

                        <label
                            for="descricao"
                            class="form-label"
                        >
                            Descrição *
                        </label>

                        <input
                            type="text"
                            name="descricao"
                            id="descricao"
                            class="form-control"
                            maxlength="150"
                            placeholder="Ex.: Taxa condominial de setembro"
                            value="<?= htmlspecialchars(
                                $_POST["descricao"] ?? ""
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- FORMAS DE PAGAMENTO (automáticas) -->

                    <div class="col-12 mb-3">

                        <div class="alert alert-success mb-0">

                            <strong>💳 Formas de pagamento automáticas</strong><br>

                            Ao salvar, a cobrança já fica disponível para o morador pagar por
                            <strong>Boleto</strong> (linha digitável e código de barras gerados pelo sistema),
                            <strong>Pix</strong> (copia e cola) ou <strong>Cartão</strong>
                            (crédito ou débito). Não é preciso informar linha digitável nem link.

                        </div>

                    </div>


                    <!-- STATUS -->

                    <div class="col-md-6 mb-3">

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
                        >

                            <?php
                            $statusAtual =
                                $_POST["status"] ?? "Pendente";
                            ?>

                            <option
                                value="Pendente"
                                <?= $statusAtual === "Pendente"
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Pendente
                            </option>

                            <option
                                value="Vencido"
                                <?= $statusAtual === "Vencido"
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Vencido
                            </option>

                            <option
                                value="Pago"
                                <?= $statusAtual === "Pago"
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Pago
                            </option>

                            <option
                                value="Cancelado"
                                <?= $statusAtual === "Cancelado"
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Cancelado
                            </option>

                        </select>

                    </div>

                </div>


                <hr>

                <div class="d-flex gap-2">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        Cadastrar Cobrança
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
<script src="../../assets/js/script.js"></script>

</body>

</html>