<?php

require_once __DIR__ . "/../../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/CobrancaController.php";

PermissaoController::exigirGestao();

/*
 * PRESTAÇÃO DE CONTAS (Código Civil, art. 1.348, VIII)
 * Usa somente a tabela cobranca. Pode ser salva em PDF pelo navegador
 * (botão "Imprimir / Salvar em PDF").
 */

$validaData = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : "";

$dataInicio = $validaData($_GET["data_inicio"] ?? "") ?: date("Y-m-01");
$dataFim    = $validaData($_GET["data_fim"] ?? "") ?: date("Y-m-t");
if ($dataFim < $dataInicio) {
    [$dataInicio, $dataFim] = [$dataFim, $dataInicio];
}

$controller = new CobrancaController();
$hoje = date("Y-m-d");

$itens = [];
foreach ($controller->listar() as $c) {
    $venc = $c->getVencimento();
    if (!$venc || $venc < $dataInicio || $venc > $dataFim) {
        continue;
    }
    $status = $c->getStatus();
    if ($status === "Pendente" && $venc < $hoje) {
        $status = "Vencido";
    }
    $itens[] = ["c" => $c, "status" => $status];
}

$tot = ["Pago" => 0.0, "Pendente" => 0.0, "Vencido" => 0.0, "Cancelado" => 0.0];
$qtd = ["Pago" => 0, "Pendente" => 0, "Vencido" => 0, "Cancelado" => 0];
$porTipo = [];
$inadimplentes = [];

foreach ($itens as $i) {
    $c = $i["c"];
    $st = $i["status"];
    $tot[$st] = ($tot[$st] ?? 0) + $c->getValor();
    $qtd[$st] = ($qtd[$st] ?? 0) + 1;

    $tipo = str_replace("_", " ", $c->getTipoCobranca());
    $porTipo[$tipo]["emitido"] = ($porTipo[$tipo]["emitido"] ?? 0) + ($st === "Cancelado" ? 0 : $c->getValor());
    $porTipo[$tipo]["pago"] = ($porTipo[$tipo]["pago"] ?? 0) + ($st === "Pago" ? $c->getValor() : 0);

    if ($st === "Vencido") {
        $u = $c->getUnidadeIdentificacao() ?: "Unidade";
        $inadimplentes[$u]["valor"] = ($inadimplentes[$u]["valor"] ?? 0) + $c->getValor();
        $inadimplentes[$u]["qtd"] = ($inadimplentes[$u]["qtd"] ?? 0) + 1;
    }
}
arsort($inadimplentes);

$emitido = $tot["Pago"] + $tot["Pendente"] + $tot["Vencido"];
$taxaInad = $emitido > 0 ? ($tot["Vencido"] / $emitido) * 100 : 0;
$taxaRecebimento = $emitido > 0 ? ($tot["Pago"] / $emitido) * 100 : 0;

$brl = fn($v) => "R$ " . number_format((float) $v, 2, ",", ".");
$br = fn($d) => $d ? date("d/m/Y", strtotime($d)) : "—";

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prestação de contas - Koinon Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/estilo.css">
    <style>
        .kpi { border-radius: 16px; padding: 18px; background: var(--k-surface); border: 1px solid var(--k-border); }
        .kpi .valor { font-size: 1.5rem; font-weight: 800; }
        .print-only { display: none; }
        @media print {
            .no-print, .koinon-topbar, .koinon-theme-floating { display: none !important; }
            .print-only { display: block !important; }
            body { background: #fff !important; color: #000 !important; }
            .card, .kpi { box-shadow: none !important; border: 1px solid #999 !important; animation: none !important; }
            body::before { display: none !important; }
        }
    </style>
</head>
<body class="bg-light">

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div>
            <h2 class="mb-1">🧾 Prestação de contas</h2>
            <p class="text-muted mb-0">Resumo financeiro do período para apresentar à assembleia (Código Civil, art. 1.348, VIII).</p>
        </div>
        <button class="btn btn-primary no-print" onclick="window.print()">🖨️ Imprimir / Salvar em PDF</button>
    </div>

    <form method="get" class="card shadow-sm mb-4 no-print">
        <div class="card-body row g-3 align-items-end">
            <div class="col-md-3"><label class="form-label">Vencimento de</label>
                <input type="date" name="data_inicio" class="form-control" value="<?= $dataInicio ?>"></div>
            <div class="col-md-3"><label class="form-label">até</label>
                <input type="date" name="data_fim" class="form-control" value="<?= $dataFim ?>"></div>
            <div class="col-md-6 d-flex gap-2 flex-wrap">
                <button class="btn btn-primary">Gerar</button>
                <a class="btn btn-secondary" href="?data_inicio=<?= date("Y-m-01") ?>&data_fim=<?= date("Y-m-t") ?>">Mês atual</a>
                <a class="btn btn-secondary" href="?data_inicio=<?= date("Y-01-01") ?>&data_fim=<?= date("Y-12-31") ?>">Ano atual</a>
            </div>
        </div>
    </form>

    <div class="print-only mb-3">
        <h3>Koinon Services — Prestação de contas</h3>
        <p>Período: <?= $br($dataInicio) ?> a <?= $br($dataFim) ?> · Emitido em <?= date("d/m/Y H:i") ?></p>
    </div>

    <div class="alert alert-secondary py-2">
        Período: <strong><?= $br($dataInicio) ?></strong> a <strong><?= $br($dataFim) ?></strong> — cobranças com vencimento neste intervalo.
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><div class="kpi"><div class="small text-muted">Total emitido</div><div class="valor"><?= $brl($emitido) ?></div></div></div>
        <div class="col-6 col-lg-3"><div class="kpi"><div class="small text-muted">Recebido (<?= $qtd["Pago"] ?>)</div><div class="valor text-success"><?= $brl($tot["Pago"]) ?></div></div></div>
        <div class="col-6 col-lg-3"><div class="kpi"><div class="small text-muted">A vencer (<?= $qtd["Pendente"] ?>)</div><div class="valor text-primary"><?= $brl($tot["Pendente"]) ?></div></div></div>
        <div class="col-6 col-lg-3"><div class="kpi"><div class="small text-muted">Em atraso (<?= $qtd["Vencido"] ?>)</div><div class="valor text-danger"><?= $brl($tot["Vencido"]) ?></div></div></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6"><div class="kpi">
            <div class="small text-muted">Taxa de recebimento</div>
            <div class="valor"><?= number_format($taxaRecebimento, 1, ",", ".") ?>%</div>
            <div class="progress mt-2" style="height:8px"><div class="progress-bar bg-success" style="width:<?= min(100, $taxaRecebimento) ?>%"></div></div>
        </div></div>
        <div class="col-md-6"><div class="kpi">
            <div class="small text-muted">Taxa de inadimplência</div>
            <div class="valor"><?= number_format($taxaInad, 1, ",", ".") ?>%</div>
            <div class="progress mt-2" style="height:8px"><div class="progress-bar bg-danger" style="width:<?= min(100, $taxaInad) ?>%"></div></div>
        </div></div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header"><strong>Por tipo de cobrança</strong></div>
        <div class="table-responsive"><table class="table mb-0">
            <thead><tr><th>Tipo</th><th class="text-end">Emitido</th><th class="text-end">Recebido</th><th class="text-end">Em aberto</th></tr></thead>
            <tbody>
            <?php if (empty($porTipo)): ?><tr><td colspan="4" class="text-center text-muted py-3">Sem cobranças no período.</td></tr><?php endif; ?>
            <?php foreach ($porTipo as $tipo => $v): ?>
                <tr><td><?= htmlspecialchars($tipo) ?></td><td class="text-end"><?= $brl($v["emitido"]) ?></td>
                    <td class="text-end"><?= $brl($v["pago"]) ?></td><td class="text-end"><?= $brl($v["emitido"] - $v["pago"]) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header"><strong>Unidades em atraso</strong> <span class="text-muted small">(somente a identificação da unidade, sem nomes de moradores)</span></div>
        <div class="table-responsive"><table class="table mb-0">
            <thead><tr><th>Unidade</th><th class="text-end">Cobranças</th><th class="text-end">Valor em atraso</th></tr></thead>
            <tbody>
            <?php if (empty($inadimplentes)): ?><tr><td colspan="3" class="text-center text-muted py-3">Nenhuma unidade em atraso no período. 🎉</td></tr><?php endif; ?>
            <?php foreach ($inadimplentes as $u => $v): ?>
                <tr><td><?= htmlspecialchars($u) ?></td><td class="text-end"><?= (int) $v["qtd"] ?></td><td class="text-end text-danger"><?= $brl($v["valor"]) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header"><strong>Detalhamento das cobranças</strong></div>
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>Unidade</th><th>Tipo</th><th>Descrição</th><th>Vencimento</th><th>Pagamento</th><th>Status</th><th class="text-end">Valor</th></tr></thead>
            <tbody>
            <?php foreach ($itens as $i): $c = $i["c"]; ?>
                <tr>
                    <td><?= htmlspecialchars($c->getUnidadeIdentificacao() ?? "") ?></td>
                    <td><?= htmlspecialchars(str_replace("_", " ", $c->getTipoCobranca())) ?></td>
                    <td><?= htmlspecialchars($c->getDescricao()) ?></td>
                    <td><?= $br($c->getVencimento()) ?></td>
                    <td><?= $br($c->getDataPagamento()) ?></td>
                    <td><?= htmlspecialchars($i["status"]) ?></td>
                    <td class="text-end"><?= $brl($c->getValor()) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

    <div class="print-only mt-5">
        <p>Responsável: <?= htmlspecialchars($_SESSION["usuario_nome"] ?? "") ?></p>
        <p class="mt-5">______________________________________<br>Assinatura do Síndico</p>
    </div>

</div>

<script src="../../assets/js/script.js"></script>
</body>
</html>
