<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../model/dao/Conexao.php";
require_once __DIR__ . "/../../model/dao/MoradorDAO.php";
require_once __DIR__ . "/../../model/dao/CobrancaDAO.php";
require_once __DIR__ . "/../../service/PagamentoService.php";

PermissaoController::exigirPerfil(["Morador"]);

$usuarioId  = (int) $_SESSION["usuario_id"];
$cobrancaId = (int) ($_GET["id"] ?? 0);

$moradorDao = new MoradorDAO();
$cobranca   = $cobrancaId > 0 ? $moradorDao->buscarCobrancaUsuario($usuarioId, $cobrancaId) : null;

if (!$cobranca) {
    header("Location: financeiro.php");
    exit;
}

$pdo    = Conexao::conectar();
$boleto = PagamentoService::garantirBoleto($pdo, $cobranca);
$pix    = PagamentoService::pixCopiaECola($cobranca);

$erro = "";

/* ------------------------------------------------------------------
 * PROCESSAR O PAGAMENTO (simulado)
 * ------------------------------------------------------------------ */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    in_array($cobranca["status"], ["Pendente", "Vencido"], true)
) {
    $metodo = $_POST["metodo"] ?? "";
    $forma  = null;

    if ($metodo === "pix") {
        $forma = "Pix";
    } elseif ($metodo === "boleto") {
        $forma = "Boleto";
    } elseif ($metodo === "credito" || $metodo === "debito") {

        $numero   = (string) ($_POST["numero"] ?? "");
        $nome     = trim((string) ($_POST["nome"] ?? ""));
        $validade = (string) ($_POST["validade"] ?? "");
        $cvv      = preg_replace('/\D/', '', (string) ($_POST["cvv"] ?? ""));

        if (!PagamentoService::numeroCartaoValido($numero)) {
            $erro = "Número de cartão inválido. Para a demonstração use, por exemplo, 4111 1111 1111 1111.";
        } elseif (mb_strlen($nome) < 3) {
            $erro = "Informe o nome impresso no cartão.";
        } elseif (!PagamentoService::validadeCartaoValida($validade)) {
            $erro = "Validade inválida ou vencida (use MM/AA).";
        } elseif (strlen($cvv) < 3 || strlen($cvv) > 4) {
            $erro = "Código de segurança (CVV) inválido.";
        } else {
            $forma = $metodo === "credito" ? "Cartao_Credito" : "Cartao_Debito";
        }
    } else {
        $erro = "Escolha uma forma de pagamento.";
    }

    if ($forma !== null && $erro === "") {

        $transacao = PagamentoService::novaTransacao($cobrancaId);

        if ((new CobrancaDAO())->registrarPagamento($cobrancaId, date("Y-m-d H:i:s"), $forma, $transacao)) {
            header("Location: pagamento.php?id=" . $cobrancaId . "&ok=1");
            exit;
        }

        $erro = "Não foi possível processar o pagamento. Tente novamente.";
    }

    // recarrega os dados após eventual falha
    $cobranca = $moradorDao->buscarCobrancaUsuario($usuarioId, $cobrancaId) ?? $cobranca;
}

$cobranca = $moradorDao->buscarCobrancaUsuario($usuarioId, $cobrancaId) ?? $cobranca;
$status   = (string) $cobranca["status"];
$pago     = $status === "Pago";
$valorBr  = "R$ " . number_format((float) $cobranca["valor"], 2, ",", ".");
$ehReserva = ($cobranca["tipo_cobranca"] ?? "") === "Reserva";

$comprovanteId = 0;
if ($pago) {
    $st = $pdo->prepare("SELECT id FROM comprovante WHERE cobranca_id = :c LIMIT 1");
    $st->execute([":c" => $cobrancaId]);
    $comprovanteId = (int) ($st->fetchColumn() ?: 0);
}

$podePagar = in_array($status, ["Pendente", "Vencido"], true);
$aba = $_POST["metodo"] ?? "pix";

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamento - Koinon Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/estilo.css">
    <style>
        .pay-card { border-radius: 20px; }
        .pay-tabs .nav-link { border-radius: 14px; font-weight: 700; padding: .7rem 1rem; color: var(--k-text); }
        .pay-tabs .nav-link.active { background: linear-gradient(135deg, #4f46e5, #0ea5e9); color: #fff; }
        .pay-code { font-family: ui-monospace, Consolas, monospace; font-size: .86rem; word-break: break-all;
                    background: rgba(99, 102, 241, .08); border: 1px dashed rgba(99, 102, 241, .45);
                    border-radius: 12px; padding: .75rem 1rem; }
        #pixQr { display: inline-block; padding: 12px; background: #fff; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, .12); }
        .pay-overlay { position: fixed; inset: 0; z-index: 3000; display: none; align-items: center; justify-content: center;
                       background: rgba(15, 23, 42, .72); backdrop-filter: blur(6px); }
        .pay-overlay.show { display: flex; }
        .pay-overlay .box { background: var(--k-surface, #fff); color: var(--k-text, #111); border-radius: 24px; padding: 2rem 2.4rem; text-align: center; max-width: 360px; }
        .pay-spinner { width: 54px; height: 54px; border-radius: 50%; border: 5px solid rgba(99, 102, 241, .25);
                       border-top-color: #4f46e5; animation: payspin 1s linear infinite; margin: 0 auto 1rem; }
        @keyframes payspin { to { transform: rotate(360deg); } }
        .pay-ok { width: 92px; height: 92px; border-radius: 50%; margin: 0 auto 1rem; display: grid; place-items: center;
                  font-size: 2.8rem; color: #fff; background: linear-gradient(135deg, #16a34a, #10b981);
                  animation: payPop .6s cubic-bezier(.2, 1.4, .3, 1) both, payRing 1.8s .6s ease-out infinite; }
        @keyframes payPop { from { transform: scale(0); } to { transform: scale(1); } }
        @keyframes payRing { 0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, .5); } 100% { box-shadow: 0 0 0 28px rgba(16, 185, 129, 0); } }
    </style>
</head>
<body>

<div class="container py-4" style="max-width: 980px">

    <div class="koinon-hero p-4 p-lg-5 mb-4">
        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center">
            <div>
                <div class="text-white-50 small mb-2">Financeiro › Pagamento</div>
                <h1 class="h3 mb-1">💳 <?= $pago ? "Pagamento concluído" : "Pagar cobrança" ?></h1>
                <p class="mb-0 text-white-50"><?= htmlspecialchars((string) $cobranca["descricao"]) ?></p>
            </div>
            <div class="text-end">
                <div class="text-white-50 small">Valor</div>
                <div class="h2 mb-0"><?= $valorBr ?></div>
            </div>
        </div>
    </div>

<?php if ($pago): ?>

    <div class="card pay-card shadow-sm" id="pagamentoConcluido"
         data-push-titulo="✅ Pagamento aprovado"
         data-push-msg="<?= htmlspecialchars($valorBr . " pagos via " . PagamentoService::rotuloForma($cobranca["forma_pagamento"] ?? null)
            . ($ehReserva ? ". Sua reserva foi confirmada." : ".")) ?>">
        <div class="card-body p-4 p-lg-5 text-center">

            <div class="pay-ok">✔</div>
            <h2 class="h3 mb-1">Pagamento aprovado!</h2>
            <p class="text-muted mb-4">
                <?= $ehReserva
                    ? "O status da sua reserva foi atualizado automaticamente para <strong>Confirmada</strong>."
                    : "A cobrança foi baixada automaticamente." ?>
            </p>

            <div class="row g-3 text-start mb-4">
                <div class="col-md-4">
                    <div class="text-muted small">Forma de pagamento</div>
                    <div class="fw-semibold"><?= htmlspecialchars(PagamentoService::rotuloForma($cobranca["forma_pagamento"] ?? null)) ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Data e hora</div>
                    <div class="fw-semibold"><?= !empty($cobranca["data_pagamento"]) ? date("d/m/Y H:i", strtotime($cobranca["data_pagamento"])) : "—" ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Código da transação</div>
                    <div class="fw-semibold"><?= htmlspecialchars((string) ($cobranca["codigo_transacao"] ?? "—")) ?></div>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 justify-content-center">
                <?php if ($comprovanteId > 0): ?>
                    <a class="btn btn-primary" target="_blank" href="comprovante.php?id=<?= $comprovanteId ?>">🧾 Ver comprovante</a>
                <?php endif; ?>
                <?php if ($ehReserva): ?>
                    <a class="btn btn-success" href="nova_reserva.php">📅 Minhas reservas</a>
                <?php endif; ?>
                <a class="btn btn-outline-secondary" href="financeiro.php">Voltar ao financeiro</a>
            </div>

        </div>
    </div>

<?php elseif (!$podePagar): ?>

    <div class="alert alert-secondary">Esta cobrança está <strong><?= htmlspecialchars($status) ?></strong> e não pode ser paga.</div>
    <a href="financeiro.php" class="btn btn-outline-secondary">← Voltar ao financeiro</a>

<?php else: ?>

    <div class="alert alert-info small">
        <strong>Ambiente de demonstração (TCC):</strong> nenhum valor é cobrado de verdade e dados de cartão
        <strong>não são armazenados</strong>. Ao confirmar, o sistema simula a resposta do banco e atualiza tudo
        automaticamente (cobrança, comprovante, reserva e notificações).
    </div>

    <?php if ($erro !== ""): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <div class="card pay-card shadow-sm">
        <div class="card-body p-4">

            <ul class="nav nav-pills pay-tabs gap-2 mb-4" role="tablist">
                <li class="nav-item"><button class="nav-link <?= $aba === "pix" ? "active" : "" ?>" data-bs-toggle="pill" data-bs-target="#abaPix" type="button">⚡ Pix</button></li>
                <li class="nav-item"><button class="nav-link <?= $aba === "credito" ? "active" : "" ?>" data-bs-toggle="pill" data-bs-target="#abaCredito" type="button">💳 Cartão de crédito</button></li>
                <li class="nav-item"><button class="nav-link <?= $aba === "debito" ? "active" : "" ?>" data-bs-toggle="pill" data-bs-target="#abaDebito" type="button">🏧 Cartão de débito</button></li>
                <li class="nav-item"><button class="nav-link <?= $aba === "boleto" ? "active" : "" ?>" data-bs-toggle="pill" data-bs-target="#abaBoleto" type="button">📄 Boleto</button></li>
            </ul>

            <div class="tab-content">

                <!-- PIX -->
                <div class="tab-pane fade <?= $aba === "pix" ? "show active" : "" ?>" id="abaPix">
                    <div class="row g-4 align-items-center">
                        <div class="col-md-5 text-center">
                            <div id="pixQr"></div>
                            <div class="small text-muted mt-2">Aponte a câmera do app do banco</div>
                        </div>
                        <div class="col-md-7">
                            <h2 class="h5">Pague com Pix</h2>
                            <p class="text-muted">Escaneie o QR Code ou use o código "copia e cola". A confirmação é imediata.</p>
                            <div class="pay-code mb-2" id="pixCodigo"><?= htmlspecialchars($pix) ?></div>
                            <button type="button" class="btn btn-outline-primary btn-sm mb-3" data-copiar="#pixCodigo">📋 Copiar código</button>
                            <form method="post" class="form-simular">
                                <input type="hidden" name="metodo" value="pix">
                                <button class="btn btn-success w-100 py-2" type="submit">⚡ Simular pagamento do Pix</button>
                                <div class="form-text">Simula o aviso do banco informando que o Pix foi pago.</div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- CRÉDITO / DÉBITO -->
                <?php foreach (["credito" => "Cartão de crédito", "debito" => "Cartão de débito"] as $chave => $titulo): ?>
                <div class="tab-pane fade <?= $aba === $chave ? "show active" : "" ?>" id="aba<?= ucfirst($chave) ?>">
                    <h2 class="h5 mb-3"><?= $titulo ?></h2>
                    <form method="post" class="form-simular" autocomplete="off">
                        <input type="hidden" name="metodo" value="<?= $chave ?>">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Número do cartão</label>
                                <input class="form-control" name="numero" inputmode="numeric" maxlength="23"
                                       placeholder="4111 1111 1111 1111" data-mask="cartao" required
                                       value="<?= $aba === $chave ? htmlspecialchars((string) ($_POST["numero"] ?? "")) : "" ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Validade</label>
                                <input class="form-control" name="validade" maxlength="5" placeholder="MM/AA" data-mask="validade" required
                                       value="<?= $aba === $chave ? htmlspecialchars((string) ($_POST["validade"] ?? "")) : "" ?>">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Nome impresso no cartão</label>
                                <input class="form-control" name="nome" placeholder="NOME COMO NO CARTÃO" required
                                       value="<?= $aba === $chave ? htmlspecialchars((string) ($_POST["nome"] ?? "")) : "" ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">CVV</label>
                                <input class="form-control" name="cvv" inputmode="numeric" maxlength="4" placeholder="123" required type="password">
                            </div>
                            <?php if ($chave === "credito"): ?>
                            <div class="col-12">
                                <label class="form-label">Parcelamento</label>
                                <select class="form-select" name="parcelas">
                                    <?php foreach ([1, 2, 3] as $p): ?>
                                        <option value="<?= $p ?>"><?= $p ?>x de R$ <?= number_format(((float) $cobranca["valor"]) / $p, 2, ",", ".") ?> sem juros</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                        </div>
                        <button class="btn btn-success w-100 py-2 mt-4" type="submit">🔒 Pagar <?= $valorBr ?></button>
                        <div class="form-text">Cartão de teste: 4111 1111 1111 1111, validade futura e qualquer CVV.</div>
                    </form>
                </div>
                <?php endforeach; ?>

                <!-- BOLETO -->
                <div class="tab-pane fade <?= $aba === "boleto" ? "show active" : "" ?>" id="abaBoleto">
                    <h2 class="h5">Boleto bancário</h2>
                    <p class="text-muted">Vencimento em <strong><?= date("d/m/Y", strtotime((string) $cobranca["vencimento"])) ?></strong>.</p>
                    <div class="mb-2"><?= PagamentoService::barrasSvg($boleto["barras"]) ?></div>
                    <div class="pay-code mb-2" id="boletoLinha"><?= htmlspecialchars($boleto["linha"]) ?></div>
                    <button type="button" class="btn btn-outline-primary btn-sm mb-3" data-copiar="#boletoLinha">📋 Copiar linha digitável</button>
                    <form method="post" class="form-simular">
                        <input type="hidden" name="metodo" value="boleto">
                        <button class="btn btn-success w-100 py-2" type="submit">📄 Simular compensação do boleto</button>
                        <div class="form-text">Simula a baixa do boleto pelo banco (na vida real leva até 3 dias úteis).</div>
                    </form>
                </div>

            </div>
        </div>
    </div>

    <a href="financeiro.php" class="btn btn-outline-secondary mt-3">← Voltar ao financeiro</a>

<?php endif; ?>

</div>

<div class="pay-overlay" id="payOverlay">
    <div class="box">
        <div class="pay-spinner"></div>
        <h2 class="h5 mb-1">Aguardando confirmação do banco…</h2>
        <p class="text-muted small mb-0">Não feche esta página.</p>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/script.js"></script>
<script>
(function () {
    var qr = document.getElementById('pixQr');
    if (qr && window.QRCode) {
        new QRCode(qr, { text: document.getElementById('pixCodigo').textContent.trim(), width: 190, height: 190 });
    }

    document.querySelectorAll('[data-copiar]').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = document.querySelector(b.getAttribute('data-copiar')).textContent.trim();
            if (navigator.clipboard) { navigator.clipboard.writeText(t); }
            var o = b.textContent; b.textContent = '✔ Copiado!';
            setTimeout(function () { b.textContent = o; }, 1500);
        });
    });

    document.querySelectorAll('[data-mask="cartao"]').forEach(function (i) {
        i.addEventListener('input', function () {
            i.value = i.value.replace(/\D/g, '').slice(0, 19).replace(/(.{4})/g, '$1 ').trim();
        });
    });
    document.querySelectorAll('[data-mask="validade"]').forEach(function (i) {
        i.addEventListener('input', function () {
            var v = i.value.replace(/\D/g, '').slice(0, 4);
            i.value = v.length > 2 ? v.slice(0, 2) + '/' + v.slice(2) : v;
        });
    });

    // Simulação: mostra "aguardando o banco" por ~2,5 s antes de enviar.
    document.querySelectorAll('.form-simular').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            if (f.dataset.enviado === '1') return;
            if (!f.checkValidity()) return;
            e.preventDefault();
            f.dataset.enviado = '1';
            document.getElementById('payOverlay').classList.add('show');
            setTimeout(function () { f.submit(); }, 2500);
        });
    });

    // Push de "pago com sucesso" na tela de conclusão.
    var ok = document.getElementById('pagamentoConcluido');
    if (ok && window.koinonToast && /[?&]ok=1/.test(location.search)) {
        window.koinonToast(ok.dataset.pushTitulo, ok.dataset.pushMsg, 'success');
    }
})();
</script>
</body>
</html>
