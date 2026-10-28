<?php

require_once __DIR__ . "/../../service/Sessao.php";

Sessao::iniciar();

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/RegistroAcessoController.php";

try {

    PermissaoController::exigirPortaria();

} catch (Throwable $e) {

    http_response_code(403);

    echo '
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">

        <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
        >

        <title>Acesso negado - Koinon Services</title>

        <link
            href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
            rel="stylesheet"
        >
    
    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

    <body class="bg-light">

        <div class="container py-5">

            <div class="row justify-content-center">

                <div class="col-md-7">

                    <div class="card shadow-sm border-0">

                        <div class="card-body text-center p-5">

                            <div
                                class="mb-3"
                                style="font-size:55px;"
                            >
                                🔒
                            </div>

                            <h2 class="fw-bold mb-3">
                                Acesso negado
                            </h2>

                            <p class="text-muted mb-4">
                                Você não possui permissão para acessar
                                os registros de acesso.
                            </p>

                            <a
                                href="../dashboard.php"
                                class="btn btn-primary"
                            >
                                Voltar ao Dashboard
                            </a>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    

<script src="../../assets/js/script.js"></script>

</body>
    </html>
    ';

    exit;
}


/*
|--------------------------------------------------------------------------
| FILTROS
|--------------------------------------------------------------------------
*/

$dataInicial =
    isset($_GET["data_inicial"])
        ? trim($_GET["data_inicial"])
        : "";

$dataFinal =
    isset($_GET["data_final"])
        ? trim($_GET["data_final"])
        : "";

$direcao =
    isset($_GET["direcao"])
        ? trim($_GET["direcao"])
        : "";

$metodoLeitura =
    isset($_GET["metodo_leitura"])
        ? trim($_GET["metodo_leitura"])
        : "";


/*
|--------------------------------------------------------------------------
| BUSCA
|--------------------------------------------------------------------------
*/

$controller =
    new RegistroAcessoController();

$registros =
    $controller->listarComFiltros(
        $dataInicial !== ""
            ? $dataInicial
            : null,

        $dataFinal !== ""
            ? $dataFinal
            : null,

        $direcao !== ""
            ? $direcao
            : null,

        $metodoLeitura !== ""
            ? $metodoLeitura
            : null
    );


/*
|--------------------------------------------------------------------------
| RESUMO
|--------------------------------------------------------------------------
*/

$totalRegistros =
    count($registros);

$totalEntradas = 0;
$totalSaidas = 0;
$totalQR = 0;

foreach ($registros as $registro) {

    if (
        $registro->getDirecao()
        === "Entrada"
    ) {

        $totalEntradas++;
    }

    if (
        $registro->getDirecao()
        === "Saida"
    ) {

        $totalSaidas++;
    }

    if (
        $registro->getMetodoLeitura()
        === "QR_Code"
    ) {

        $totalQR++;
    }
}


/*
|--------------------------------------------------------------------------
| USUÁRIO LOGADO
|--------------------------------------------------------------------------
*/

$usuarioNome =
    method_exists(
        "PermissaoController",
        "usuarioNome"
    )
        ? PermissaoController::usuarioNome()
        : ($_SESSION["usuario_nome"] ?? "Usuário");

$usuarioPerfil =
    method_exists(
        "PermissaoController",
        "usuarioPerfil"
    )
        ? PermissaoController::usuarioPerfil()
        : ($_SESSION["perfil"] ?? "Usuário");

?>

<!DOCTYPE html>

<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Registros de Acesso - Koinon Services</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >

    <style>

        body {
            background-color: #f5f7fa;
        }

        .page-header {
            background: #ffffff;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .card-resumo {
            border: 0;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .card-resumo .numero {
            font-size: 28px;
            font-weight: bold;
        }

        .tabela-container {
            background: #ffffff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .badge-metodo {
            font-size: 12px;
        }

        .table td,
        .table th {
            vertical-align: middle;
        }

    </style>

</head>

<body>

<div class="container-fluid py-4">


    <!-- CABEÇALHO -->

    <div class="page-header">

        <div
            class="d-flex justify-content-between align-items-center flex-wrap gap-3"
        >

            <div>

                <h2 class="fw-bold mb-1">
                    Registros de Acesso
                </h2>

                <p class="text-muted mb-0">
                    Controle de entradas e saídas do condomínio
                </p>

            </div>

            <div class="text-end">

                <div class="fw-semibold">
                    <?= htmlspecialchars($usuarioNome ?? "Usuário") ?>
                </div>

                <small class="text-muted">
                    <?= htmlspecialchars($usuarioPerfil ?? "Usuário") ?>
                </small>

            </div>

        </div>

    </div>


    <!-- RESUMO -->

    <div class="row g-3 mb-4">

        <div class="col-md-3">

            <div class="card card-resumo">

                <div class="card-body">

                    <div class="text-muted">
                        Total de registros
                    </div>

                    <div class="numero">
                        <?= $totalRegistros ?>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card card-resumo">

                <div class="card-body">

                    <div class="text-muted">
                        Entradas
                    </div>

                    <div class="numero text-success">
                        <?= $totalEntradas ?>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card card-resumo">

                <div class="card-body">

                    <div class="text-muted">
                        Saídas
                    </div>

                    <div class="numero text-danger">
                        <?= $totalSaidas ?>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card card-resumo">

                <div class="card-body">

                    <div class="text-muted">
                        Acessos via QR Code
                    </div>

                    <div class="numero text-primary">
                        <?= $totalQR ?>
                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- FILTROS -->

    <div class="card shadow-sm border-0 mb-4">

        <div class="card-body">

            <h5 class="fw-bold mb-3">
                Filtros
            </h5>

            <form
                method="GET"
                action=""
            >

                <div class="row g-3">

                    <div class="col-md-3">

                        <label class="form-label">
                            Data inicial
                        </label>

                        <input
                            type="date"
                            name="data_inicial"
                            class="form-control"
                            value="<?= htmlspecialchars($dataInicial) ?>"
                        >

                    </div>


                    <div class="col-md-3">

                        <label class="form-label">
                            Data final
                        </label>

                        <input
                            type="date"
                            name="data_final"
                            class="form-control"
                            value="<?= htmlspecialchars($dataFinal) ?>"
                        >

                    </div>


                    <div class="col-md-3">

                        <label class="form-label">
                            Direção
                        </label>

                        <select
                            name="direcao"
                            class="form-select"
                        >

                            <option value="">
                                Todas
                            </option>

                            <option
                                value="Entrada"
                                <?= $direcao === "Entrada"
                                    ? "selected"
                                    : "" ?>
                            >
                                Entrada
                            </option>

                            <option
                                value="Saida"
                                <?= $direcao === "Saida"
                                    ? "selected"
                                    : "" ?>
                            >
                                Saída
                            </option>

                        </select>

                    </div>


                    <div class="col-md-3">

                        <label class="form-label">
                            Método de leitura
                        </label>

                        <select
                            name="metodo_leitura"
                            class="form-select"
                        >

                            <option value="">
                                Todos
                            </option>

                            <option
                                value="QR_Code"
                                <?= $metodoLeitura === "QR_Code"
                                    ? "selected"
                                    : "" ?>
                            >
                                QR Code
                            </option>

                            <option
                                value="Facial"
                                <?= $metodoLeitura === "Facial"
                                    ? "selected"
                                    : "" ?>
                            >
                                Facial
                            </option>

                            <option
                                value="Placa_LPR"
                                <?= $metodoLeitura === "Placa_LPR"
                                    ? "selected"
                                    : "" ?>
                            >
                                Placa LPR
                            </option>

                            <option
                                value="Tag_RFID"
                                <?= $metodoLeitura === "Tag_RFID"
                                    ? "selected"
                                    : "" ?>
                            >
                                Tag RFID
                            </option>

                            <option
                                value="Manual"
                                <?= $metodoLeitura === "Manual"
                                    ? "selected"
                                    : "" ?>
                            >
                                Manual
                            </option>

                        </select>

                    </div>


                    <div class="col-12">

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            🔎 Filtrar
                        </button>

                        <a
                            href="index.php"
                            class="btn btn-outline-secondary"
                        >
                            Limpar filtros
                        </a>

                        <a
                            href="../dashboard.php"
                            class="btn btn-outline-dark"
                        >
                            Voltar
                        </a>

                    </div>

                </div>

            </form>

        </div>

    </div>


    <!-- HISTÓRICO -->

    <div class="tabela-container">

        <div
            class="d-flex justify-content-between align-items-center mb-3"
        >

            <h5 class="fw-bold mb-0">
                Histórico de acessos
            </h5>

            <span class="badge bg-secondary">
                <?= $totalRegistros ?> registro(s)
            </span>

        </div>


        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead class="table-light">

                    <tr>

                        <th>Data/Hora</th>

                        <th>Usuário</th>

                        <th>Visitante</th>

                        <th>Tipo</th>

                        <th>Direção</th>

                        <th>Método</th>

                        <th>Observação</th>

                    </tr>

                </thead>

                <tbody>

                <?php if (empty($registros)): ?>

                    <tr>

                        <td
                            colspan="7"
                            class="text-center text-muted py-4"
                        >
                            Nenhum registro de acesso encontrado.
                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($registros as $registro): ?>

                        <?php

                        $direcaoAtual =
                            $registro->getDirecao();

                        $metodoAtual =
                            $registro->getMetodoLeitura();

                        /*
                         * Os nomes vêm do JOIN realizado
                         * no RegistroAcessoDAO.
                         */
                        $nomeUsuario =
                            $registro->getUsuarioNome()
                            ?? null;

                        $nomeVisitante =
                            $registro->getVisitanteNome()
                            ?? null;

                        $tipoVisitante =
                            $registro->getVisitanteTipo()
                            ?? null;

                        ?>

                        <tr>


                            <!-- DATA -->

                            <td>

                                <?php

                                $dataHora =
                                    $registro->getDataHora();

                                if ($dataHora) {

                                    $timestamp =
                                        strtotime($dataHora);

                                    echo $timestamp
                                        ? date(
                                            "d/m/Y H:i:s",
                                            $timestamp
                                        )
                                        : htmlspecialchars(
                                            $dataHora
                                        );

                                } else {

                                    echo "Não informado";

                                }

                                ?>

                            </td>


                            <!-- USUÁRIO -->

                            <td>

                                <?php if (
                                    $nomeUsuario !== null &&
                                    trim($nomeUsuario) !== ""
                                ): ?>

                                    <span class="fw-semibold">
                                        <?= htmlspecialchars($nomeUsuario) ?>
                                    </span>

                                <?php else: ?>

                                    <span class="text-muted">
                                        Não informado
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- VISITANTE -->

                            <td>

                                <?php if (
                                    $nomeVisitante !== null &&
                                    trim($nomeVisitante) !== ""
                                ): ?>

                                    <span class="fw-semibold">
                                        <?= htmlspecialchars($nomeVisitante) ?>
                                    </span>

                                <?php else: ?>

                                    <span class="text-muted">
                                        Não informado
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- TIPO -->

                            <td>

                                <?php if (
                                    $tipoVisitante !== null &&
                                    trim($tipoVisitante) !== ""
                                ): ?>

                                    <?php

                                    $tipoFormatado =
                                        match ($tipoVisitante) {

                                            "Prestador_Servico" =>
                                                "Prestador de Serviço",

                                            "Entregador" =>
                                                "Entregador",

                                            default =>
                                                "Visitante"
                                        };

                                    ?>

                                    <span class="badge bg-light text-dark border">
                                        <?= htmlspecialchars($tipoFormatado) ?>
                                    </span>

                                <?php else: ?>

                                    <span class="text-muted">
                                        -
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- DIREÇÃO -->

                            <td>

                                <?php if (
                                    $direcaoAtual === "Entrada"
                                ): ?>

                                    <span class="badge bg-success">
                                        Entrada
                                    </span>

                                <?php else: ?>

                                    <span class="badge bg-danger">
                                        Saída
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- MÉTODO -->

                            <td>

                                <?php

                                $nomeMetodo =
                                    match ($metodoAtual) {

                                        "QR_Code" =>
                                            "QR Code",

                                        "Placa_LPR" =>
                                            "Placa LPR",

                                        "Tag_RFID" =>
                                            "Tag RFID",

                                        "Facial" =>
                                            "Facial",

                                        default =>
                                            "Manual"
                                    };

                                $classeMetodo =
                                    match ($metodoAtual) {

                                        "QR_Code" =>
                                            "bg-primary",

                                        "Facial" =>
                                            "bg-success",

                                        "Placa_LPR" =>
                                            "bg-warning text-dark",

                                        "Tag_RFID" =>
                                            "bg-info text-dark",

                                        default =>
                                            "bg-secondary"
                                    };

                                ?>

                                <span
                                    class="badge <?= $classeMetodo ?>"
                                >
                                    <?= $nomeMetodo ?>
                                </span>

                            </td>


                            <!-- OBSERVAÇÃO -->

                            <td>

                                <?php

                                $observacao =
                                    $registro->getObservacao();

                                if (
                                    $observacao !== null &&
                                    trim($observacao) !== ""
                                ) {

                                    echo htmlspecialchars(
                                        $observacao
                                    );

                                } else {

                                    echo "-";

                                }

                                ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"
></script>
</body>

</html>