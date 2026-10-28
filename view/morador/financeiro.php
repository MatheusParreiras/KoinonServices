<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../model/dao/MoradorDAO.php";
require_once __DIR__ . "/../../model/dao/Conexao.php";
require_once __DIR__ . "/../../service/PagamentoService.php";

PermissaoController::exigirPerfil(["Morador"]);

$id        = (int) $_SESSION["usuario_id"];
$dao       = new MoradorDAO();
$cobrancas = $dao->listarCobrancas($id);

// comprovante de cada cobrança paga (para o botão "Comprovante")
$comprovantes = [];
try {
    $ids = array_map(fn($c) => (int) $c["id"], $cobrancas);
    if ($ids) {
        $in = implode(",", $ids);
        foreach (Conexao::conectar()->query("SELECT id, cobranca_id FROM comprovante WHERE cobranca_id IN ($in)") as $r) {
            $comprovantes[(int) $r["cobranca_id"]] = (int) $r["id"];
        }
    }
} catch (Throwable $e) {
}

$totalAberto = 0.0;
$totalPago   = 0.0;
foreach ($cobrancas as $c) {
    if (in_array($c["status"] ?? "", ["Pendente", "Vencido"], true)) {
        $totalAberto += (float) $c["valor"];
    } elseif (($c["status"] ?? "") === "Pago") {
        $totalPago += (float) $c["valor"];
    }
}

function dataBr($d)
{
    $t = $d ? strtotime($d) : false;
    return $t ? date("d/m/Y", $t) : "—";
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meu Financeiro - Koinon Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/estilo.css">
</head>
<body>
<main class="container-fluid py-4">

    <div class="koinon-hero p-4 mb-4">
        <h1 class="h3 text-white">💰 Meu financeiro</h1>
        <p class="text-white-50 mb-0">Consulte e pague suas cobranças com Pix, cartão de crédito, débito ou boleto.</p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="morador-stat"><div class="small text-muted">Em aberto</div>
                <div class="h3 mb-0">R$ <?= number_format($totalAberto, 2, ",", ".") ?></div></div>
        </div>
        <div class="col-md-6">
            <div class="morador-stat"><div class="small text-muted">Já pago</div>
                <div class="h3 mb-0">R$ <?= number_format($totalPago, 2, ",", ".") ?></div></div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Descrição</th><th>Valor</th><th>Vencimento</th><th>Status</th><th>Pagamento</th><th>Ação</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$cobrancas): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Nenhuma cobrança encontrada.</td></tr>
                <?php else: foreach ($cobrancas as $c):
                    $st = (string) ($c["status"] ?? "");
                    $cls = $st === "Pago" ? "success" : ($st === "Vencido" ? "danger" : ($st === "Cancelado" ? "secondary" : "warning"));
                    $cid = (int) $c["id"];
                ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($c["descricao"] ?? "—")) ?></td>
                        <td>R$ <?= number_format((float) ($c["valor"] ?? 0), 2, ",", ".") ?></td>
                        <td><?= dataBr($c["vencimento"] ?? null) ?></td>
                        <td><span class="badge text-bg-<?= $cls ?>"><?= htmlspecialchars($st ?: "—") ?></span></td>
                        <td class="small">
                            <?php if ($st === "Pago"): ?>
                                <?= htmlspecialchars(PagamentoService::rotuloForma($c["forma_pagamento"] ?? null)) ?><br>
                                <span class="text-muted"><?= !empty($c["data_pagamento"]) ? date("d/m/Y H:i", strtotime($c["data_pagamento"])) : "" ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                            <?php if (in_array($st, ["Pendente", "Vencido"], true)): ?>
                                <a href="pagamento.php?id=<?= $cid ?>" class="btn btn-sm btn-success">💳 Pagar</a>
                            <?php endif; ?>
                            <a href="boleto.php?id=<?= $cid ?>" class="btn btn-sm btn-outline-primary">Boleto / detalhes</a>
                            <?php if (!empty($comprovantes[$cid])): ?>
                                <a href="comprovante.php?id=<?= $comprovantes[$cid] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">🧾 Comprovante</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<script src="../../assets/js/script.js"></script>
</body>
</html>
