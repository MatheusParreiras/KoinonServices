<?php

require_once __DIR__ . "/../../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/MoradorController.php";
require_once __DIR__ . "/../../model/dao/Conexao.php";
require_once __DIR__ . "/../../service/PagamentoService.php";

PermissaoController::exigirPerfil(["Morador"]);

$usuarioId  = (int) $_SESSION["usuario_id"];
$cobrancaId = (int) ($_GET["id"] ?? 0);

$controller = new MoradorController();
$cobranca   = $cobrancaId > 0 ? $controller->buscarCobrancaUsuario($usuarioId, $cobrancaId) : null;

if (!$cobranca) {
    header("Location: financeiro.php");
    exit;
}

$pdo    = Conexao::conectar();
$boleto = PagamentoService::garantirBoleto($pdo, $cobranca);

$status = (string) ($cobranca["status"] ?? "Pendente");
$statusClass = match ($status) {
    "Pago"      => "bg-success",
    "Pendente"  => "bg-warning text-dark",
    "Vencido"   => "bg-danger",
    "Cancelado" => "bg-secondary",
    default     => "bg-dark",
};

$comprovanteId = 0;
if ($status === "Pago") {
    $st = $pdo->prepare("SELECT id FROM comprovante WHERE cobranca_id = :c LIMIT 1");
    $st->execute([":c" => $cobrancaId]);
    $comprovanteId = (int) ($st->fetchColumn() ?: 0);
}

$podePagar = in_array($status, ["Pendente", "Vencido"], true);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boleto - Koinon Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/estilo.css">
    <style>
        .pay-code { font-family: ui-monospace, Consolas, monospace; font-size: .9rem; word-break: break-all;
                    background: rgba(99, 102, 241, .08); border: 1px dashed rgba(99, 102, 241, .45);
                    border-radius: 12px; padding: .75rem 1rem; }
    </style>
</head>
<body>
<div class="container py-4">

    <div class="koinon-hero p-4 p-lg-5 mb-4">
        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center">
            <div>
                <div class="text-white-50 small mb-2">Financeiro</div>
                <h1 class="h3 mb-2">Detalhes da cobrança</h1>
                <p class="mb-0 text-white-50">Consulte o boleto e escolha como pagar: Pix, cartão de crédito, débito ou boleto.</p>
            </div>
            <span class="badge <?= $statusClass ?> fs-6 px-3 py-2"><?= htmlspecialchars($status) ?></span>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-body p-4">
                    <h2 class="h5 mb-4">Informações do boleto</h2>
                    <div class="row g-4">
                        <div class="col-md-8">
                            <div class="text-muted small">Descrição</div>
                            <div class="fw-semibold"><?= htmlspecialchars((string) ($cobranca["descricao"] ?? "Cobrança")) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Valor</div>
                            <div class="fw-bold fs-5">R$ <?= number_format((float) ($cobranca["valor"] ?? 0), 2, ",", ".") ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Vencimento</div>
                            <div class="fw-semibold"><?= !empty($cobranca["vencimento"]) ? date("d/m/Y", strtotime($cobranca["vencimento"])) : "—" ?></div>
                        </div>
                        <?php if ($status === "Pago"): ?>
                        <div class="col-md-4">
                            <div class="text-muted small">Pago em</div>
                            <div class="fw-semibold"><?= !empty($cobranca["data_pagamento"]) ? date("d/m/Y H:i", strtotime($cobranca["data_pagamento"])) : "—" ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Forma de pagamento</div>
                            <div class="fw-semibold"><?= htmlspecialchars(PagamentoService::rotuloForma($cobranca["forma_pagamento"] ?? null)) ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <div class="text-muted small mb-1">Código de barras</div>
                            <div class="mb-2"><?= PagamentoService::barrasSvg($boleto["barras"]) ?></div>
                            <div class="text-muted small mb-1">Linha digitável</div>
                            <div class="pay-code" id="linhaDigitavel"><?= htmlspecialchars($boleto["linha"]) ?></div>
                            <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="btnCopiar">📋 Copiar linha digitável</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body p-4">
                    <h2 class="h5">Pagamento</h2>
                    <p class="text-muted">
                        <?= $podePagar
                            ? "Ao pagar, a baixa é automática: a cobrança vira Pago, o comprovante é emitido e, se for uma reserva, ela é confirmada na hora."
                            : ($status === "Pago" ? "Esta cobrança já foi paga." : "Esta cobrança não está disponível para pagamento.") ?>
                    </p>
                    <div class="d-grid gap-2">
                        <?php if ($podePagar): ?>
                            <a href="pagamento.php?id=<?= $cobrancaId ?>" class="btn btn-success btn-lg">💳 Pagar agora</a>
                        <?php endif; ?>
                        <?php if ($comprovanteId > 0): ?>
                            <a href="comprovante.php?id=<?= $comprovanteId ?>" target="_blank" class="btn btn-primary">🧾 Ver comprovante</a>
                        <?php endif; ?>
                        <a href="financeiro.php" class="btn btn-outline-primary">Voltar ao financeiro</a>
                        <a href="notificacoes.php" class="btn btn-outline-secondary">Ver notificações</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../../assets/js/script.js"></script>
<script>
document.getElementById('btnCopiar').addEventListener('click', function () {
    var t = document.getElementById('linhaDigitavel').textContent.trim();
    if (navigator.clipboard) { navigator.clipboard.writeText(t); }
    var b = this, o = b.textContent; b.textContent = '✔ Copiado!';
    setTimeout(function () { b.textContent = o; }, 1500);
});
</script>
</body>
</html>
