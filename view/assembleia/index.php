<?php

require_once __DIR__ . "/../../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/PublicacaoController.php";
require_once __DIR__ . "/../../controller/DocumentoController.php";
require_once __DIR__ . "/../../model/dto/PublicacaoDTO.php";

PermissaoController::exigirGestao();

/*
 * ASSEMBLEIAS — sem tabela nova:
 *  - a convocação é uma Publicação da categoria Aviso_Sindico (todos os moradores são notificados);
 *  - as atas são Documentos da categoria "Atas".
 */

const PREFIXO_CONVOCACAO = "📣 Convocação de Assembleia";

$publicacaoController = new PublicacaoController();
$documentoController = new DocumentoController();

$mensagem = "";
$tipoMensagem = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $acao = $_POST["acao"] ?? "";

    if ($acao === "convocar") {

        $tipo   = ($_POST["tipo"] ?? "") === "Extraordinária" ? "Extraordinária" : "Ordinária";
        $data   = trim($_POST["data"] ?? "");
        $hora   = trim($_POST["hora"] ?? "");
        $local  = trim($_POST["local"] ?? "");
        $pauta  = trim($_POST["pauta"] ?? "");
        $erros  = [];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $data < date("Y-m-d")) {
            $erros[] = "Informe uma data futura para a assembleia.";
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $hora)) {
            $erros[] = "Informe o horário.";
        }
        if ($local === "") {
            $erros[] = "Informe o local.";
        }
        if ($pauta === "") {
            $erros[] = "Informe a pauta.";
        }

        if (empty($erros)) {

            $dataBr = date("d/m/Y", strtotime($data));

            $publicacao = new PublicacaoDTO();
            $publicacao->setUsuarioId((int) $_SESSION["usuario_id"]);
            $publicacao->setCategoria("Aviso_Sindico");
            $publicacao->setTitulo(PREFIXO_CONVOCACAO . " {$tipo} — {$dataBr} às {$hora}");
            $publicacao->setConteudo(
                "Ficam convocados todos os condôminos para a Assembleia Geral {$tipo}, "
                . "a realizar-se em {$dataBr}, às {$hora}, no local: {$local}.\n\n"
                . "PAUTA:\n{$pauta}\n\n"
                . "A presença é muito importante. Em caso de dúvida, procure o síndico."
            );
            $publicacao->setStatus("Ativo");
            $publicacao->setMidiaUrl(null);
            $publicacao->setPreco(null);

            if ($publicacaoController->cadastrar($publicacao)) {
                header("Location: index.php?sucesso=1");
                exit;
            }

            $mensagem = "Não foi possível publicar a convocação.";
            $tipoMensagem = "danger";

        } else {
            $mensagem = implode("<br>", $erros);
            $tipoMensagem = "danger";
        }

    } elseif ($acao === "encerrar") {

        $id = (int) ($_POST["id"] ?? 0);
        $pub = $id > 0 ? $publicacaoController->buscarPorId($id) : null;

        if ($pub && strpos($pub->getTitulo(), PREFIXO_CONVOCACAO) === 0) {
            $publicacaoController->alterarStatus($id, "Encerrado");
            header("Location: index.php?sucesso=2");
            exit;
        }

        $mensagem = "Convocação inválida.";
        $tipoMensagem = "danger";
    }
}

if (($_GET["sucesso"] ?? "") === "1") {
    $mensagem = "Convocação publicada! Todos os moradores foram notificados.";
    $tipoMensagem = "success";
} elseif (($_GET["sucesso"] ?? "") === "2") {
    $mensagem = "Convocação encerrada.";
    $tipoMensagem = "success";
}

$convocacoes = array_values(array_filter(
    $publicacaoController->listar(),
    fn($p) => strpos($p->getTitulo(), PREFIXO_CONVOCACAO) === 0
));

$atas = array_values(array_filter(
    $documentoController->listar(),
    fn($d) => $d->getCategoria() === "Atas"
));

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assembleias - Koinon Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/estilo.css">
</head>
<body class="bg-light">

<div class="container py-4">

    <div class="mb-4">
        <h2 class="mb-1">🏛️ Assembleias</h2>
        <p class="text-muted mb-0">Convoque os condôminos e mantenha as atas organizadas (Código Civil, art. 1.348, I).</p>
    </div>

    <?php if ($mensagem !== ""): ?>
        <div class="alert alert-<?= $tipoMensagem ?>"><?= $mensagem ?></div>
    <?php endif; ?>

    <div class="row g-4">

        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-header"><strong>Convocar assembleia</strong></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="acao" value="convocar">

                        <div class="mb-3">
                            <label class="form-label">Tipo</label>
                            <select name="tipo" class="form-select">
                                <option>Ordinária</option>
                                <option>Extraordinária</option>
                            </select>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-7"><label class="form-label">Data *</label>
                                <input type="date" name="data" class="form-control" min="<?= date("Y-m-d") ?>" required></div>
                            <div class="col-5"><label class="form-label">Hora *</label>
                                <input type="time" name="hora" class="form-control" required></div>
                        </div>

                        <div class="mb-3"><label class="form-label">Local *</label>
                            <input type="text" name="local" class="form-control" maxlength="120" placeholder="Ex.: Salão de festas" required></div>

                        <div class="mb-3"><label class="form-label">Pauta *</label>
                            <textarea name="pauta" class="form-control" rows="5" placeholder="1. Prestação de contas&#10;2. Orçamento do próximo ano&#10;3. Assuntos gerais" required></textarea></div>

                        <button class="btn btn-primary w-100">Publicar convocação e notificar moradores</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">

            <div class="card shadow-sm mb-4">
                <div class="card-header"><strong>Convocações publicadas</strong></div>
                <div class="list-group list-group-flush">
                    <?php if (empty($convocacoes)): ?>
                        <div class="list-group-item text-muted">Nenhuma convocação publicada.</div>
                    <?php endif; ?>
                    <?php foreach ($convocacoes as $c): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($c->getTitulo()) ?></div>
                                <small class="text-muted">Publicada em <?= $c->getCreatedAt() ? date("d/m/Y H:i", strtotime($c->getCreatedAt())) : "" ?></small>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-<?= $c->getStatus() === "Ativo" ? "success" : "secondary" ?>"><?= htmlspecialchars($c->getStatus()) ?></span>
                                <?php if ($c->getStatus() === "Ativo"): ?>
                                    <form method="post" class="m-0" onsubmit="return confirm('Encerrar esta convocação?');">
                                        <input type="hidden" name="acao" value="encerrar">
                                        <input type="hidden" name="id" value="<?= (int) $c->getId() ?>">
                                        <button class="btn btn-sm btn-outline-danger">Encerrar</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Atas de assembleia</strong>
                    <a href="../documento/cadastrar.php" class="btn btn-sm btn-success">+ Enviar ata</a>
                </div>
                <div class="list-group list-group-flush">
                    <?php if (empty($atas)): ?>
                        <div class="list-group-item text-muted">Nenhuma ata enviada. Use "Enviar ata" e escolha a categoria Atas.</div>
                    <?php endif; ?>
                    <?php foreach ($atas as $a): ?>
                        <a class="list-group-item list-group-item-action d-flex justify-content-between"
                           href="<?= htmlspecialchars($a->getArquivoUrl()) ?>" target="_blank" rel="noopener">
                            <span>📄 <?= htmlspecialchars($a->getTitulo()) ?></span>
                            <small class="text-muted"><?= $a->getDataUpload() ? date("d/m/Y", strtotime($a->getDataUpload())) : "" ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="../../assets/js/script.js"></script>
</body>
</html>
