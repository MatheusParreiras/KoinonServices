<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/PublicacaoController.php";
require_once __DIR__ . "/../../model/dto/PublicacaoDTO.php";

// Portaria, síndico e administrador registram e acompanham os itens.
PermissaoController::exigirPortaria();

$controller = new PublicacaoController();
$usuarioId = (int) ($_SESSION["usuario_id"] ?? 0);

$mensagem = "";
$tipoMensagem = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $acao = $_POST["acao"] ?? "";

    // ------------------------------------------------------ CADASTRAR
    if ($acao === "cadastrar") {

        $tipo      = trim($_POST["tipo_achado"] ?? "");
        $titulo    = trim($_POST["titulo"] ?? "");
        $descricao = trim($_POST["conteudo"] ?? "");
        $local     = trim($_POST["local_ocorrencia"] ?? "");
        $data      = trim($_POST["data_ocorrencia"] ?? "");
        $erros     = [];

        if ($tipo !== "Achado" && $tipo !== "Perdido") {
            $erros[] = "Selecione se o item foi achado ou perdido.";
        }
        if ($titulo === "") {
            $erros[] = "Informe um título.";
        }
        if ($descricao === "") {
            $erros[] = "Informe a descrição.";
        }
        if ($local === "") {
            $erros[] = "Informe o local.";
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            $erros[] = "Informe a data.";
        }

        $midiaUrl = null;

        if (isset($_FILES["imagem"]) && $_FILES["imagem"]["error"] !== UPLOAD_ERR_NO_FILE) {

            $arq = $_FILES["imagem"];
            $ext = strtolower(pathinfo($arq["name"], PATHINFO_EXTENSION));

            if ($arq["error"] !== UPLOAD_ERR_OK) {
                $erros[] = "Não foi possível enviar a imagem.";
            } elseif ($arq["size"] > 5 * 1024 * 1024) {
                $erros[] = "A imagem deve ter no máximo 5 MB.";
            } elseif (!in_array($ext, ["jpg", "jpeg", "png", "webp"], true)) {
                $erros[] = "Formato inválido. Use JPG, PNG ou WEBP.";
            } elseif (@getimagesize($arq["tmp_name"]) === false) {
                $erros[] = "O arquivo enviado não é uma imagem válida.";
            } else {
                $pasta = __DIR__ . "/../../assets/img/";
                $nome  = "achado_perdido_" . uniqid() . "." . $ext;

                if (is_dir($pasta) && move_uploaded_file($arq["tmp_name"], $pasta . $nome)) {
                    $midiaUrl = "assets/img/" . $nome;
                } else {
                    $erros[] = "Não foi possível salvar a imagem.";
                }
            }
        }

        if (empty($erros)) {

            $publicacao = new PublicacaoDTO();
            $publicacao->setUsuarioId($usuarioId);
            $publicacao->setCategoria("Achados_Perdidos");
            $publicacao->setTitulo($titulo);
            $publicacao->setConteudo($descricao);
            $publicacao->setTipoAchado($tipo);
            $publicacao->setLocalOcorrencia($local);
            $publicacao->setDataOcorrencia($data);
            $publicacao->setStatus("Ativo");
            $publicacao->setMidiaUrl($midiaUrl);
            $publicacao->setPreco(null);

            if ($controller->cadastrar($publicacao)) {
                header("Location: achados_perdidos.php?sucesso=1");
                exit;
            }

            $mensagem = "Não foi possível registrar o item.";
            $tipoMensagem = "danger";

        } else {
            $mensagem = implode("<br>", $erros);
            $tipoMensagem = "danger";
        }
    }

    // ------------------------------------------- MARCAR COMO DEVOLVIDO
    elseif ($acao === "resolver") {

        $id = (int) ($_POST["id"] ?? 0);
        $item = $id > 0 ? $controller->buscarPorId($id) : null;

        if ($item && $item->getCategoria() === "Achados_Perdidos") {
            $controller->alterarStatus($id, "Encerrado");
            header("Location: achados_perdidos.php?sucesso=2");
            exit;
        }

        $mensagem = "Item não encontrado.";
        $tipoMensagem = "danger";
    }
}

if (($_GET["sucesso"] ?? "") === "1") {
    $mensagem = "Item registrado! Os moradores foram avisados.";
    $tipoMensagem = "success";
} elseif (($_GET["sucesso"] ?? "") === "2") {
    $mensagem = "Item marcado como devolvido / resolvido.";
    $tipoMensagem = "success";
}

$itens = array_values(array_filter(
    $controller->listar(),
    fn($p) => $p->getCategoria() === "Achados_Perdidos"
));

$ativos = array_filter($itens, fn($p) => $p->getStatus() === "Ativo");

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Achados e Perdidos - Koinon Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/estilo.css">
</head>
<body class="bg-light">

<div class="container py-4">

    <div class="mb-4">
        <h2 class="mb-1">🔎 Achados e Perdidos</h2>
        <p class="text-muted mb-0">
            Registre itens encontrados na portaria ou áreas comuns. Os moradores são avisados
            e acompanham pela aba deles. <strong><?= count($ativos) ?></strong> item(ns) em aberto.
        </p>
    </div>

    <?php if ($mensagem !== ""): ?>
        <div class="alert alert-<?= $tipoMensagem ?>"><?= $mensagem ?></div>
    <?php endif; ?>

    <div class="row g-4">

        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header"><strong>Registrar item</strong></div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="acao" value="cadastrar">

                        <div class="mb-3">
                            <label class="form-label">Situação *</label>
                            <select name="tipo_achado" class="form-select" required>
                                <option value="Achado">Achado (encontrado no condomínio)</option>
                                <option value="Perdido">Perdido (morador procura)</option>
                            </select>
                        </div>

                        <div class="mb-3"><label class="form-label">Título *</label>
                            <input type="text" name="titulo" class="form-control" maxlength="120" placeholder="Ex.: Chave com chaveiro azul" required></div>

                        <div class="mb-3"><label class="form-label">Descrição *</label>
                            <textarea name="conteudo" class="form-control" rows="3" required></textarea></div>

                        <div class="mb-3"><label class="form-label">Local *</label>
                            <input type="text" name="local_ocorrencia" class="form-control" maxlength="120" placeholder="Ex.: Hall do bloco A" required></div>

                        <div class="mb-3"><label class="form-label">Data *</label>
                            <input type="date" name="data_ocorrencia" class="form-control" value="<?= date("Y-m-d") ?>" max="<?= date("Y-m-d") ?>" required></div>

                        <div class="mb-3"><label class="form-label">Foto (opcional)</label>
                            <input type="file" name="imagem" class="form-control" accept="image/jpeg,image/png,image/webp"></div>

                        <button class="btn btn-primary w-100">Registrar e avisar moradores</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="row g-3">
                <?php if (empty($itens)): ?>
                    <div class="col-12"><div class="card shadow-sm"><div class="card-body text-center text-muted py-5">Nenhum item registrado ainda.</div></div></div>
                <?php endif; ?>

                <?php foreach ($itens as $p): ?>
                    <?php $ativo = $p->getStatus() === "Ativo"; ?>
                    <div class="col-md-6">
                        <div class="card shadow-sm h-100">
                            <?php if ($p->getMidiaUrl()): ?>
                                <img src="../../<?= htmlspecialchars($p->getMidiaUrl()) ?>" class="card-img-top" alt="Foto do item" style="max-height:180px;object-fit:cover">
                            <?php endif; ?>
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <span class="badge bg-<?= $p->getTipoAchado() === "Achado" ? "success" : "warning" ?>">
                                        <?= htmlspecialchars($p->getTipoAchado() ?? "") ?>
                                    </span>
                                    <span class="badge bg-<?= $ativo ? "primary" : "secondary" ?>"><?= $ativo ? "Em aberto" : "Resolvido" ?></span>
                                </div>
                                <h6 class="fw-bold"><?= htmlspecialchars($p->getTitulo()) ?></h6>
                                <p class="small mb-2"><?= nl2br(htmlspecialchars($p->getConteudo())) ?></p>
                                <div class="small text-muted">
                                    📍 <?= htmlspecialchars($p->getLocalOcorrencia() ?? "") ?><br>
                                    📅 <?= $p->getDataOcorrencia() ? date("d/m/Y", strtotime($p->getDataOcorrencia())) : "" ?>
                                    · por <?= htmlspecialchars($p->getUsuarioNome() ?? "") ?>
                                </div>
                            </div>
                            <?php if ($ativo): ?>
                                <div class="card-footer bg-transparent">
                                    <form method="post" class="m-0" onsubmit="return confirm('Marcar este item como devolvido / resolvido?');">
                                        <input type="hidden" name="acao" value="resolver">
                                        <input type="hidden" name="id" value="<?= (int) $p->getId() ?>">
                                        <button class="btn btn-sm btn-success w-100">✔ Marcar como devolvido</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</div>

<script src="../../assets/js/script.js"></script>
</body>
</html>
