<?php

require_once __DIR__ . "/../../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/CobrancaController.php";

PermissaoController::exigirGestao();

$controller = new CobrancaController();

$id = isset($_GET["id"])
    ? (int) $_GET["id"]
    : 0;

if ($id <= 0) {

    header("Location: index.php");

    exit;
}

$cobranca =
    $controller->buscarPorId($id);

if (!$cobranca) {

    header("Location: index.php?erro=nao_encontrado");

    exit;
}

$erro = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $tipoCobranca =
        trim($_POST["tipo_cobranca"] ?? "");

    $mesReferencia =
        trim($_POST["mes_referencia"] ?? "");

    $descricao =
        trim($_POST["descricao"] ?? "");

    $valor =
        str_replace(
            ",",
            ".",
            trim($_POST["valor"] ?? "0")
        );

    $vencimento =
        trim($_POST["vencimento"] ?? "");

    $linhaDigitavel =
        trim($_POST["linha_digitavel"] ?? "");

    $linkBoleto =
        trim($_POST["link_boleto"] ?? "");

    $status =
        trim($_POST["status"] ?? "Pendente");


    if (
        $tipoCobranca === "" ||
        $mesReferencia === "" ||
        $descricao === "" ||
        $valor === "" ||
        $vencimento === ""
    ) {

        $erro =
            "Preencha todos os campos obrigatórios.";

    } else {

        $cobranca->setTipoCobranca(
            $tipoCobranca
        );

        $cobranca->setMesReferencia(
            $mesReferencia
        );

        $cobranca->setDescricao(
            $descricao
        );

        $cobranca->setValor(
            (float) $valor
        );

        $cobranca->setVencimento(
            $vencimento
        );

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

        $cobranca->setStatus(
            $status
        );


        try {

            if ($controller->editar($cobranca)) {

                header(
                    "Location: index.php?sucesso=editado"
                );

                exit;
            }

            $erro =
                "Não foi possível salvar as alterações.";

        } catch (Throwable $e) {

            $erro =
                "Erro ao salvar a cobrança.";
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

    <title>Editar Cobrança - Koinon Services</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container mt-5">

    <div class="card shadow">

        <div class="card-header bg-primary text-white">

            <h4 class="mb-0">
                ✏️ Editar Cobrança
            </h4>

        </div>

        <div class="card-body">

            <?php if (!empty($erro)): ?>

                <div class="alert alert-danger">

                    <?= htmlspecialchars($erro) ?>

                </div>

            <?php endif; ?>


            <form method="POST">

                <div class="row">

                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Tipo da cobrança
                        </label>

                        <select
                            name="tipo_cobranca"
                            class="form-select"
                            required
                        >

                            <?php

                            $tipos = [
                                "Taxa_Condominial",
                                "Reserva",
                                "Multa",
                                "Taxa_Extraordinaria",
                                "Servico",
                                "Outros"
                            ];

                            foreach ($tipos as $tipo):

                            ?>

                                <option
                                    value="<?= $tipo ?>"
                                    <?= $cobranca->getTipoCobranca() === $tipo
                                        ? "selected"
                                        : "" ?>
                                >

                                    <?= htmlspecialchars($tipo) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Mês de referência
                        </label>

                        <input
                            type="month"
                            name="mes_referencia"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $cobranca->getMesReferencia()
                            ) ?>"
                            required
                        >

                    </div>

                </div>


                <div class="mb-3">

                    <label class="form-label">
                        Descrição
                    </label>

                    <input
                        type="text"
                        name="descricao"
                        class="form-control"
                        value="<?= htmlspecialchars(
                            $cobranca->getDescricao()
                        ) ?>"
                        required
                    >

                </div>


                <div class="row">

                    <div class="col-md-4 mb-3">

                        <label class="form-label">
                            Valor
                        </label>

                        <input
                            type="number"
                            name="valor"
                            class="form-control"
                            step="0.01"
                            min="0"
                            value="<?= number_format(
                                (float) $cobranca->getValor(),
                                2,
                                ".",
                                ""
                            ) ?>"
                            required
                        >

                    </div>


                    <div class="col-md-4 mb-3">

                        <label class="form-label">
                            Vencimento
                        </label>

                        <input
                            type="date"
                            name="vencimento"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $cobranca->getVencimento()
                            ) ?>"
                            required
                        >

                    </div>


                    <div class="col-md-4 mb-3">

                        <label class="form-label">
                            Status
                        </label>

                        <select
                            name="status"
                            class="form-select"
                        >

                            <?php

                            $statusPermitidos = [
                                "Pendente",
                                "Pago",
                                "Vencido",
                                "Cancelado"
                            ];

                            foreach (
                                $statusPermitidos
                                as $status
                            ):

                            ?>

                                <option
                                    value="<?= $status ?>"
                                    <?= $cobranca->getStatus() === $status
                                        ? "selected"
                                        : "" ?>
                                >

                                    <?= $status ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>


                <div class="mb-3">

                    <label class="form-label">
                        Linha digitável
                    </label>

                    <input
                        type="text"
                        name="linha_digitavel"
                        class="form-control"
                        value="<?= htmlspecialchars(
                            $cobranca->getLinhaDigitavel() ?? ""
                        ) ?>"
                    >

                </div>


                <div class="mb-4">

                    <label class="form-label">
                        Link do boleto
                    </label>

                    <input
                        type="url"
                        name="link_boleto"
                        class="form-control"
                        value="<?= htmlspecialchars(
                            $cobranca->getLinkBoleto() ?? ""
                        ) ?>"
                    >

                </div>


                <div class="d-flex gap-2">

                    <button
                        type="submit"
                        class="btn btn-success"
                    >
                        💾 Salvar Alterações
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