<?php

require_once __DIR__ . "/../../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . '/../../controller/RelatorioController.php';
require_once __DIR__ . '/../../controller/PermissaoController.php';

PermissaoController::exigirGestao();

$controller = new RelatorioController();

/*
|--------------------------------------------------------------------------
| Filtros
|--------------------------------------------------------------------------
*/

$dataInicio = isset($_GET['data_inicio'])
    ? trim($_GET['data_inicio'])
    : '';

$dataFim = isset($_GET['data_fim'])
    ? trim($_GET['data_fim'])
    : '';

/*
|--------------------------------------------------------------------------
| Validação das datas
|--------------------------------------------------------------------------
*/

$dataInicioValida = '';

if (
    preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio)
) {
    $dataInicioValida = $dataInicio;
}

$dataFimValida = '';

if (
    preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)
) {
    $dataFimValida = $dataFim;
}

/*
|--------------------------------------------------------------------------
| Se o período estiver invertido, troca automaticamente.
|--------------------------------------------------------------------------
*/

if (
    $dataInicioValida !== '' &&
    $dataFimValida !== '' &&
    $dataInicioValida > $dataFimValida
) {
    $temporaria = $dataInicioValida;
    $dataInicioValida = $dataFimValida;
    $dataFimValida = $temporaria;
}

/*
|--------------------------------------------------------------------------
| Dados
|--------------------------------------------------------------------------
*/

$resumo = $controller->resumoGeral(
    $dataInicioValida ?: null,
    $dataFimValida ?: null
);

$usuariosPorPerfil = $controller->usuariosPorPerfil();

$reservasPorStatus = $controller->reservasPorStatus(
    $dataInicioValida ?: null,
    $dataFimValida ?: null
);

$ocorrenciasPorStatus = $controller->ocorrenciasPorStatus(
    $dataInicioValida ?: null,
    $dataFimValida ?: null
);

$encomendasPorStatus = $controller->encomendasPorStatus(
    $dataInicioValida ?: null,
    $dataFimValida ?: null
);

$cobrancasPorStatus = $controller->cobrancasPorStatus(
    $dataInicioValida ?: null,
    $dataFimValida ?: null
);

$acessosPorMetodo = $controller->acessosPorMetodo(
    $dataInicioValida ?: null,
    $dataFimValida ?: null
);

$acessosPorDirecao = $controller->acessosPorDirecao(
    $dataInicioValida ?: null,
    $dataFimValida ?: null
);

$reservasRecentes = $controller->reservasRecentes(
    10,
    $dataInicioValida ?: null,
    $dataFimValida ?: null
);

$ocorrenciasRecentes = $controller->ocorrenciasRecentes(
    10,
    $dataInicioValida ?: null,
    $dataFimValida ?: null
);

$encomendasRecentes = $controller->encomendasRecentes(
    10,
    $dataInicioValida ?: null,
    $dataFimValida ?: null
);

$acessosRecentes = $controller->acessosRecentes(
    10,
    $dataInicioValida ?: null,
    $dataFimValida ?: null
);

$totalCobrancas = $controller->totalCobrancas(
    $dataInicioValida ?: null,
    $dataFimValida ?: null
);

/*
|--------------------------------------------------------------------------
| Funções auxiliares
|--------------------------------------------------------------------------
*/

function e($valor): string
{
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES,
        'UTF-8'
    );
}

function formatarDataRelatorio(
    ?string $data
): string {
    if (!$data) {
        return '-';
    }

    try {
        return (new DateTime($data))
            ->format('d/m/Y H:i');
    } catch (Exception $e) {
        return '-';
    }
}

function moedaRelatorio($valor): string
{
    return 'R$ ' . number_format(
        (float) $valor,
        2,
        ',',
        '.'
    );
}

function statusRelatorio(
    ?string $status
): string {
    if (!$status) {
        return '-';
    }

    $mapa = [
        'Pendente' => 'Pendente',
        'Aguardando_Pagamento' => 'Aguardando pagamento',
        'Confirmada' => 'Confirmada',
        'Cancelada' => 'Cancelada',
        'Concluida' => 'Concluída',

        'Aberta' => 'Aberta',
        'Em_Andamento' => 'Em andamento',
        'Resolvida' => 'Resolvida',

        'Aguardando_Retirada' => 'Aguardando retirada',
        'Entregue' => 'Entregue',
        'Devolvido' => 'Devolvido',

        'Pago' => 'Pago',
        'Vencido' => 'Vencido',

        'Ativo' => 'Ativo',
        'Expirado' => 'Expirado',
        'Utilizado' => 'Utilizado'
    ];

    return $mapa[$status] ?? str_replace(
        '_',
        ' ',
        $status
    );
}

function badgeRelatorio(
    ?string $status
): string {
    $classes = [
        'Pendente' => 'warning text-dark',
        'Aguardando_Pagamento' => 'warning text-dark',
        'Confirmada' => 'primary',
        'Cancelada' => 'danger',
        'Concluida' => 'success',

        'Aberta' => 'danger',
        'Em_Andamento' => 'warning text-dark',
        'Resolvida' => 'success',

        'Aguardando_Retirada' => 'warning text-dark',
        'Entregue' => 'success',
        'Devolvido' => 'secondary',

        'Pago' => 'success',
        'Vencido' => 'danger',

        'Ativo' => 'success',
        'Expirado' => 'secondary',
        'Utilizado' => 'primary'
    ];

    $classe = $classes[$status] ?? 'secondary';

    return '<span class="badge bg-' .
        $classe .
        '">' .
        e(statusRelatorio($status)) .
        '</span>';
}

/*
|--------------------------------------------------------------------------
| Dados para gráficos
|--------------------------------------------------------------------------
*/

$graficoUsuariosLabels = [];
$graficoUsuariosValores = [];

foreach ($usuariosPorPerfil as $item) {
    $graficoUsuariosLabels[] = $item['perfil'];
    $graficoUsuariosValores[] = (int) $item['quantidade'];
}

$graficoReservasLabels = [];
$graficoReservasValores = [];

foreach ($reservasPorStatus as $item) {
    $graficoReservasLabels[] = statusRelatorio(
        $item['status']
    );

    $graficoReservasValores[] = (int) $item['quantidade'];
}

$graficoOcorrenciasLabels = [];
$graficoOcorrenciasValores = [];

foreach ($ocorrenciasPorStatus as $item) {
    $graficoOcorrenciasLabels[] = statusRelatorio(
        $item['status']
    );

    $graficoOcorrenciasValores[] = (int) $item['quantidade'];
}

$graficoEncomendasLabels = [];
$graficoEncomendasValores = [];

foreach ($encomendasPorStatus as $item) {
    $graficoEncomendasLabels[] = statusRelatorio(
        $item['status']
    );

    $graficoEncomendasValores[] = (int) $item['quantidade'];
}

$graficoAcessosLabels = [];
$graficoAcessosValores = [];

foreach ($acessosPorMetodo as $item) {
    $graficoAcessosLabels[] = str_replace(
        '_',
        ' ',
        $item['metodo_leitura']
    );

    $graficoAcessosValores[] = (int) $item['quantidade'];
}

$periodoTexto = 'Todos os registros';

if (
    $dataInicioValida &&
    $dataFimValida
) {
    $periodoTexto =
        'De ' .
        date('d/m/Y', strtotime($dataInicioValida)) .
        ' até ' .
        date('d/m/Y', strtotime($dataFimValida));
} elseif ($dataInicioValida) {
    $periodoTexto =
        'A partir de ' .
        date('d/m/Y', strtotime($dataInicioValida));
} elseif ($dataFimValida) {
    $periodoTexto =
        'Até ' .
        date('d/m/Y', strtotime($dataFimValida));
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
        Relatórios - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>

        body {
            background: #f5f7fa;
        }

        .card {
            border: 0;
            border-radius: 16px;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.06);
        }

        .metric-card {
            min-height: 135px;
        }

        .metric-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            background: #f1f3f5;
        }

        .metric-number {
            font-size: 28px;
            font-weight: 700;
        }

        .chart-container {
            position: relative;
            height: 310px;
        }

        .table-responsive {
            border-radius: 12px;
        }

        .table {
            margin-bottom: 0;
        }

        .filter-card {
            background: #ffffff;
        }

        .periodo-atual {
            font-size: 14px;
            color: #6c757d;
        }

        @media print {

            body {
                background: #ffffff !important;
            }

            .nao-imprimir {
                display: none !important;
            }

            .card {
                box-shadow: none !important;
                border: 1px solid #ddd;
            }

            .chart-container {
                height: 280px;
            }

            .table-responsive {
                overflow: visible !important;
            }

            .table {
                font-size: 11px;
            }
        }

    </style>


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container-fluid py-4">

    <!-- CABEÇALHO -->

    <div
        class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"
    >

        <div>

            <h1 class="fw-bold mb-1">
                📊 Relatórios e indicadores
            </h1>

            <div class="periodo-atual">
                Período:
                <strong>
                    <?= e($periodoTexto) ?>
                </strong>
            </div>

        </div>

        <div class="d-flex gap-2 nao-imprimir">

            <button
                type="button"
                class="btn btn-outline-secondary"
                onclick="window.print()"
            >
                🖨️ Imprimir
            </button>

        </div>

    </div>

    <!-- FILTRO DE PERÍODO -->

    <div class="card filter-card mb-4 nao-imprimir">

        <div class="card-body">

            <form
                method="GET"
                action=""
                class="row g-3 align-items-end"
            >

                <div class="col-md-4">

                    <label
                        for="data_inicio"
                        class="form-label fw-semibold"
                    >
                        Data inicial
                    </label>

                    <input
                        type="date"
                        class="form-control"
                        id="data_inicio"
                        name="data_inicio"
                        value="<?= e($dataInicioValida) ?>"
                    >

                </div>

                <div class="col-md-4">

                    <label
                        for="data_fim"
                        class="form-label fw-semibold"
                    >
                        Data final
                    </label>

                    <input
                        type="date"
                        class="form-control"
                        id="data_fim"
                        name="data_fim"
                        value="<?= e($dataFimValida) ?>"
                    >

                </div>

                <div class="col-md-4 d-flex gap-2">

                    <button
                        type="submit"
                        class="btn btn-primary flex-fill"
                    >
                        🔎 Aplicar filtro
                    </button>

                    <a
                        href="index.php"
                        class="btn btn-outline-secondary"
                    >
                        Limpar
                    </a>

                </div>

            </form>

        </div>

    </div>

    <!-- INDICADORES -->

    <div class="row g-3 mb-4">

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card metric-card h-100">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="text-muted">
                                Usuários ativos
                            </div>

                            <div class="metric-number">
                                <?= e($resumo['usuarios_ativos']) ?>
                            </div>

                        </div>

                        <div class="metric-icon">
                            👥
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card metric-card h-100">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="text-muted">
                                Moradores
                            </div>

                            <div class="metric-number">
                                <?= e($resumo['moradores']) ?>
                            </div>

                        </div>

                        <div class="metric-icon">
                            🏠
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card metric-card h-100">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="text-muted">
                                Unidades
                            </div>

                            <div class="metric-number">
                                <?= e($resumo['unidades']) ?>
                            </div>

                        </div>

                        <div class="metric-icon">
                            🏢
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card metric-card h-100">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="text-muted">
                                Reservas
                            </div>

                            <div class="metric-number">
                                <?= e($resumo['reservas']) ?>
                            </div>

                        </div>

                        <div class="metric-icon">
                            📅
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card metric-card h-100">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="text-muted">
                                Ocorrências
                            </div>

                            <div class="metric-number">
                                <?= e($resumo['ocorrencias']) ?>
                            </div>

                        </div>

                        <div class="metric-icon">
                            ⚠️
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card metric-card h-100">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="text-muted">
                                Encomendas
                            </div>

                            <div class="metric-number">
                                <?= e($resumo['encomendas']) ?>
                            </div>

                        </div>

                        <div class="metric-icon">
                            📦
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card metric-card h-100">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="text-muted">
                                Visitantes
                            </div>

                            <div class="metric-number">
                                <?= e($resumo['visitantes']) ?>
                            </div>

                        </div>

                        <div class="metric-icon">
                            🚶
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card metric-card h-100">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="text-muted">
                                Registros de acesso
                            </div>

                            <div class="metric-number">
                                <?= e($resumo['acessos']) ?>
                            </div>

                        </div>

                        <div class="metric-icon">
                            🔐
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- GRÁFICOS -->

    <div class="row g-4 mb-4">

        <div class="col-lg-6">

            <div class="card h-100">

                <div class="card-body">

                    <h5 class="fw-bold mb-3">
                        👥 Usuários por perfil
                    </h5>

                    <div class="chart-container">

                        <canvas id="graficoUsuarios"></canvas>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-lg-6">

            <div class="card h-100">

                <div class="card-body">

                    <h5 class="fw-bold mb-3">
                        📅 Reservas por status
                    </h5>

                    <div class="chart-container">

                        <canvas id="graficoReservas"></canvas>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-lg-6">

            <div class="card h-100">

                <div class="card-body">

                    <h5 class="fw-bold mb-3">
                        ⚠️ Ocorrências por status
                    </h5>

                    <div class="chart-container">

                        <canvas id="graficoOcorrencias"></canvas>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-lg-6">

            <div class="card h-100">

                <div class="card-body">

                    <h5 class="fw-bold mb-3">
                        📦 Encomendas por status
                    </h5>

                    <div class="chart-container">

                        <canvas id="graficoEncomendas"></canvas>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- RESUMOS -->

    <div class="row g-4 mb-4">

        <div class="col-lg-4">

            <div class="card h-100">

                <div class="card-body">

                    <h5 class="fw-bold mb-3">
                        📅 Reservas
                    </h5>

                    <?php if (empty($reservasPorStatus)): ?>

                        <p class="text-muted mb-0">
                            Nenhuma reserva encontrada.
                        </p>

                    <?php else: ?>

                        <?php foreach ($reservasPorStatus as $item): ?>

                            <div
                                class="d-flex justify-content-between align-items-center border-bottom py-2"
                            >

                                <span>
                                    <?= e(statusRelatorio($item['status'])) ?>
                                </span>

                                <span class="badge bg-primary">
                                    <?= e($item['quantidade']) ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <div class="col-lg-4">

            <div class="card h-100">

                <div class="card-body">

                    <h5 class="fw-bold mb-3">
                        ⚠️ Ocorrências
                    </h5>

                    <?php if (empty($ocorrenciasPorStatus)): ?>

                        <p class="text-muted mb-0">
                            Nenhuma ocorrência encontrada.
                        </p>

                    <?php else: ?>

                        <?php foreach ($ocorrenciasPorStatus as $item): ?>

                            <div
                                class="d-flex justify-content-between align-items-center border-bottom py-2"
                            >

                                <span>
                                    <?= e(statusRelatorio($item['status'])) ?>
                                </span>

                                <span class="badge bg-primary">
                                    <?= e($item['quantidade']) ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <div class="col-lg-4">

            <div class="card h-100">

                <div class="card-body">

                    <h5 class="fw-bold mb-3">
                        📦 Encomendas
                    </h5>

                    <?php if (empty($encomendasPorStatus)): ?>

                        <p class="text-muted mb-0">
                            Nenhuma encomenda encontrada.
                        </p>

                    <?php else: ?>

                        <?php foreach ($encomendasPorStatus as $item): ?>

                            <div
                                class="d-flex justify-content-between align-items-center border-bottom py-2"
                            >

                                <span>
                                    <?= e(statusRelatorio($item['status'])) ?>
                                </span>

                                <span class="badge bg-primary">
                                    <?= e($item['quantidade']) ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

    <!-- FINANCEIRO / ACESSOS -->

    <div class="row g-4 mb-4">

        <div class="col-lg-6">

            <div class="card h-100">

                <div class="card-body">

                    <h5 class="fw-bold mb-3">
                        💰 Cobranças
                    </h5>

                    <div class="mb-3">

                        <div class="text-muted">
                            Total no período
                        </div>

                        <div class="fs-3 fw-bold">
                            <?= e(moedaRelatorio($totalCobrancas)) ?>
                        </div>

                    </div>

                    <?php if (empty($cobrancasPorStatus)): ?>

                        <p class="text-muted mb-0">
                            Nenhuma cobrança encontrada.
                        </p>

                    <?php else: ?>

                        <?php foreach ($cobrancasPorStatus as $item): ?>

                            <div
                                class="d-flex justify-content-between align-items-center border-bottom py-2"
                            >

                                <div>

                                    <?= badgeRelatorio(
                                        $item['status']
                                    ) ?>

                                </div>

                                <div class="text-end">

                                    <strong>
                                        <?= e($item['quantidade']) ?>
                                    </strong>

                                    <small class="text-muted">
                                        (<?= e(
                                            moedaRelatorio(
                                                $item['total']
                                            )
                                        ) ?>)
                                    </small>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <div class="col-lg-6">

            <div class="card h-100">

                <div class="card-body">

                    <h5 class="fw-bold mb-3">
                        🔐 Acessos por método
                    </h5>

                    <div class="chart-container">

                        <canvas id="graficoAcessos"></canvas>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- TABELA RESERVAS -->

    <div class="card mb-4">

        <div class="card-body">

            <h5 class="fw-bold mb-3">
                📅 Reservas recentes
            </h5>

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead class="table-light">

                    <tr>

                        <th>Morador</th>
                        <th>Unidade</th>
                        <th>Espaço</th>
                        <th>Início</th>
                        <th>Fim</th>
                        <th>Status</th>
                        <th>Valor</th>

                    </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($reservasRecentes)): ?>

                        <tr>

                            <td
                                colspan="7"
                                class="text-center text-muted py-4"
                            >
                                Nenhuma reserva encontrada.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($reservasRecentes as $reserva): ?>

                            <tr>

                                <td>
                                    <?= e(
                                        $reserva['usuario_nome']
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $reserva['unidade_identificacao']
                                        ?: '-'
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $reserva['espaco_nome']
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        formatarDataRelatorio(
                                            $reserva['data_inicio']
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        formatarDataRelatorio(
                                            $reserva['data_fim']
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= badgeRelatorio(
                                        $reserva['status']
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        moedaRelatorio(
                                            $reserva['valor_taxa']
                                        )
                                    ) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

    <!-- TABELA OCORRÊNCIAS -->

    <div class="card mb-4">

        <div class="card-body">

            <h5 class="fw-bold mb-3">
                ⚠️ Ocorrências recentes
            </h5>

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead class="table-light">

                    <tr>

                        <th>Morador</th>
                        <th>Unidade</th>
                        <th>Tipo</th>
                        <th>Título</th>
                        <th>Abertura</th>
                        <th>Status</th>

                    </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($ocorrenciasRecentes)): ?>

                        <tr>

                            <td
                                colspan="6"
                                class="text-center text-muted py-4"
                            >
                                Nenhuma ocorrência encontrada.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($ocorrenciasRecentes as $ocorrencia): ?>

                            <tr>

                                <td>
                                    <?= e(
                                        $ocorrencia['usuario_nome']
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $ocorrencia['unidade_identificacao']
                                        ?: '-'
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $ocorrencia['tipo']
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $ocorrencia['titulo']
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        formatarDataRelatorio(
                                            $ocorrencia['data_abertura']
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= badgeRelatorio(
                                        $ocorrencia['status']
                                    ) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

    <!-- TABELA ENCOMENDAS -->

    <div class="card mb-4">

        <div class="card-body">

            <h5 class="fw-bold mb-3">
                📦 Encomendas recentes
            </h5>

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead class="table-light">

                    <tr>

                        <th>Unidade</th>
                        <th>Morador</th>
                        <th>Descrição</th>
                        <th>Rastreio</th>
                        <th>Recebimento</th>
                        <th>Retirada</th>
                        <th>Status</th>

                    </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($encomendasRecentes)): ?>

                        <tr>

                            <td
                                colspan="7"
                                class="text-center text-muted py-4"
                            >
                                Nenhuma encomenda encontrada.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($encomendasRecentes as $encomenda): ?>

                            <tr>

                                <td>
                                    <?= e(
                                        $encomenda['unidade_identificacao']
                                        ?: '-'
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $encomenda['usuario_nome']
                                        ?: '-'
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $encomenda['descricao']
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $encomenda['codigo_rastreio']
                                        ?: '-'
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        formatarDataRelatorio(
                                            $encomenda['data_recebimento']
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        formatarDataRelatorio(
                                            $encomenda['data_retirada']
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= badgeRelatorio(
                                        $encomenda['status']
                                    ) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

    <!-- TABELA ACESSOS -->

    <div class="card mb-4">

        <div class="card-body">

            <h5 class="fw-bold mb-3">
                🔐 Acessos recentes
            </h5>

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead class="table-light">

                    <tr>

                        <th>Data/Hora</th>
                        <th>Pessoa</th>
                        <th>Direção</th>
                        <th>Método</th>
                        <th>Observação</th>

                    </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($acessosRecentes)): ?>

                        <tr>

                            <td
                                colspan="5"
                                class="text-center text-muted py-4"
                            >
                                Nenhum acesso encontrado.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($acessosRecentes as $acesso): ?>

                            <?php

                            $pessoa = $acesso['usuario_nome']
                                ?: $acesso['visitante_nome']
                                ?: 'Pessoa não identificada';

                            ?>

                            <tr>

                                <td>
                                    <?= e(
                                        formatarDataRelatorio(
                                            $acesso['data_hora']
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= e($pessoa) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $acesso['direcao']
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $acesso['metodo_leitura']
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $acesso['observacao']
                                        ?: '-'
                                    ) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

<script>

const graficoUsuarios = document.getElementById(
    'graficoUsuarios'
);

if (graficoUsuarios) {

    new Chart(graficoUsuarios, {

        type: 'doughnut',

        data: {

            labels:
                <?= json_encode(
                    $graficoUsuariosLabels,
                    JSON_UNESCAPED_UNICODE
                ) ?>,

            datasets: [{

                data:
                    <?= json_encode(
                        $graficoUsuariosValores
                    ) ?>

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {

                legend: {
                    position: 'bottom'
                }

            }

        }

    });

}


const graficoReservas = document.getElementById(
    'graficoReservas'
);

if (graficoReservas) {

    new Chart(graficoReservas, {

        type: 'bar',

        data: {

            labels:
                <?= json_encode(
                    $graficoReservasLabels,
                    JSON_UNESCAPED_UNICODE
                ) ?>,

            datasets: [{

                label: 'Reservas',

                data:
                    <?= json_encode(
                        $graficoReservasValores
                    ) ?>

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {

                legend: {
                    display: false
                }

            },

            scales: {

                y: {

                    beginAtZero: true,

                    ticks: {
                        precision: 0
                    }

                }

            }

        }

    });

}


const graficoOcorrencias = document.getElementById(
    'graficoOcorrencias'
);

if (graficoOcorrencias) {

    new Chart(graficoOcorrencias, {

        type: 'bar',

        data: {

            labels:
                <?= json_encode(
                    $graficoOcorrenciasLabels,
                    JSON_UNESCAPED_UNICODE
                ) ?>,

            datasets: [{

                label: 'Ocorrências',

                data:
                    <?= json_encode(
                        $graficoOcorrenciasValores
                    ) ?>

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {

                legend: {
                    display: false
                }

            },

            scales: {

                y: {

                    beginAtZero: true,

                    ticks: {
                        precision: 0
                    }

                }

            }

        }

    });

}


const graficoEncomendas = document.getElementById(
    'graficoEncomendas'
);

if (graficoEncomendas) {

    new Chart(graficoEncomendas, {

        type: 'doughnut',

        data: {

            labels:
                <?= json_encode(
                    $graficoEncomendasLabels,
                    JSON_UNESCAPED_UNICODE
                ) ?>,

            datasets: [{

                data:
                    <?= json_encode(
                        $graficoEncomendasValores
                    ) ?>

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {

                legend: {
                    position: 'bottom'
                }

            }

        }

    });

}


const graficoAcessos = document.getElementById(
    'graficoAcessos'
);

if (graficoAcessos) {

    new Chart(graficoAcessos, {

        type: 'bar',

        data: {

            labels:
                <?= json_encode(
                    $graficoAcessosLabels,
                    JSON_UNESCAPED_UNICODE
                ) ?>,

            datasets: [{

                label: 'Acessos',

                data:
                    <?= json_encode(
                        $graficoAcessosValores
                    ) ?>

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {

                legend: {
                    display: false
                }

            },

            scales: {

                y: {

                    beginAtZero: true,

                    ticks: {
                        precision: 0
                    }

                }

            }

        }

    });

}

</script>
<script src="../../assets/js/script.js"></script>

</body>

</html>