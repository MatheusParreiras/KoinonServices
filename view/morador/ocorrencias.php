<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../model/dao/MoradorDAO.php";
require_once __DIR__ . "/../../service/OcorrenciaHistorico.php";

PermissaoController::exigirPerfil([
    "Morador"
]);

$usuarioId = (int) $_SESSION["usuario_id"];

$dao = new MoradorDAO();

$ocorrencias = $dao->listarOcorrencias($usuarioId);

/*
 * Ocorrência em destaque (vinda da notificação ou do cadastro recém-feito).
 * Sem parâmetro, a mais recente fica aberta.
 */
$destaqueId = isset($_GET["id"]) && is_numeric($_GET["id"])
    ? (int) $_GET["id"]
    : (!empty($ocorrencias) ? (int) $ocorrencias[0]["id"] : 0);

$totais = [
    "Aberta"       => 0,
    "Em_Andamento" => 0,
    "Resolvida"    => 0,
    "Cancelada"    => 0,
];

foreach ($ocorrencias as $o) {
    $st = $o["status"] ?? "Aberta";
    if (isset($totais[$st])) {
        $totais[$st]++;
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

    <title>Minhas ocorrências - Koinon Services</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../../assets/css/estilo.css">

    <style>
        .oc-card { margin-bottom: 18px; }
        .oc-head { cursor: pointer; list-style: none; }
        .oc-head::-webkit-details-marker { display: none; }
        .oc-chevron { transition: transform .2s; display: inline-block; }
        details[open] .oc-chevron { transform: rotate(90deg); }

        .oc-timeline { list-style: none; margin: 0; padding: 0 0 0 6px; }
        .oc-timeline li {
            position: relative;
            padding: 0 0 18px 28px;
            border-left: 2px solid var(--k-border);
            margin-left: 8px;
        }
        .oc-timeline li:last-child { border-left-color: transparent; padding-bottom: 0; }
        .oc-timeline li::before {
            content: "";
            position: absolute;
            left: -8px;
            top: 2px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: var(--k-primary);
            border: 3px solid var(--k-surface);
            box-shadow: 0 0 0 2px var(--k-primary);
        }
        .oc-timeline li.st-Aberta::before       { background: #f59e0b; box-shadow: 0 0 0 2px #f59e0b; }
        .oc-timeline li.st-Em_Andamento::before { background: #3b82f6; box-shadow: 0 0 0 2px #3b82f6; }
        .oc-timeline li.st-Resolvida::before    { background: #22c55e; box-shadow: 0 0 0 2px #22c55e; }
        .oc-timeline li.st-Cancelada::before    { background: #ef4444; box-shadow: 0 0 0 2px #ef4444; }
        .oc-when { font-size: .8rem; color: var(--k-muted); }
        .oc-photo { max-width: 220px; border-radius: 12px; border: 1px solid var(--k-border); }
    </style>

</head>

<body class="bg-light">

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">

        <div>

            <h2 class="mb-1">⚠️ Minhas ocorrências</h2>

            <p class="text-muted mb-0">
                Acompanhe o andamento de cada ocorrência registrada por você.
            </p>

        </div>

        <a href="nova_ocorrencia.php" class="btn btn-warning">
            + Nova ocorrência
        </a>

    </div>


    <?php if (isset($_GET["sucesso"])): ?>

        <div class="alert alert-success">
            Ocorrência registrada com sucesso! A administração foi avisada
            e você pode acompanhar o andamento logo abaixo.
        </div>

    <?php endif; ?>


    <?php if (empty($ocorrencias)): ?>

        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <div style="font-size:2.4rem">📭</div>
                <h5 class="mt-2">Você ainda não registrou nenhuma ocorrência</h5>
                <p class="text-muted">
                    Quando registrar uma, o andamento aparecerá aqui.
                </p>
                <a href="nova_ocorrencia.php" class="btn btn-warning">
                    Registrar ocorrência
                </a>
            </div>
        </div>

    <?php else: ?>

        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="morador-stat">
                    <div class="small text-muted">Abertas</div>
                    <div class="h3 mb-0"><?= $totais["Aberta"] ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="morador-stat">
                    <div class="small text-muted">Em andamento</div>
                    <div class="h3 mb-0"><?= $totais["Em_Andamento"] ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="morador-stat">
                    <div class="small text-muted">Resolvidas</div>
                    <div class="h3 mb-0"><?= $totais["Resolvida"] ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="morador-stat">
                    <div class="small text-muted">Canceladas</div>
                    <div class="h3 mb-0"><?= $totais["Cancelada"] ?></div>
                </div>
            </div>
        </div>


        <?php foreach ($ocorrencias as $o): ?>

            <?php
            $status = $o["status"] ?? "Aberta";
            $classe = OcorrenciaHistorico::classeStatus($status);

            $linhaDoTempo = OcorrenciaHistorico::montar(
                $o["historico"] ?? null,
                $status,
                $o["data_abertura"] ?? null,
                $o["data_fechamento"] ?? null
            );

            $aberta = ((int) $o["id"] === $destaqueId);
            ?>

            <div class="card shadow-sm oc-card" id="ocorrencia-<?= (int) $o["id"] ?>">

                <details <?= $aberta ? "open" : "" ?>>

                    <summary class="oc-head card-header d-flex justify-content-between align-items-center flex-wrap gap-2">

                        <div>
                            <span class="oc-chevron" aria-hidden="true">▶</span>
                            <strong>
                                <?= htmlspecialchars($o["titulo"] ?? "") ?>
                            </strong>
                            <span class="text-muted small ms-2">
                                <?= htmlspecialchars($o["tipo"] ?? "") ?>
                            </span>
                        </div>

                        <div class="d-flex align-items-center gap-2">
                            <span class="oc-when">
                                <?= !empty($o["data_abertura"])
                                    ? date("d/m/Y H:i", strtotime($o["data_abertura"]))
                                    : "" ?>
                            </span>
                            <span class="badge bg-<?= $classe ?>">
                                <?= htmlspecialchars(
                                    OcorrenciaHistorico::rotuloStatus($status)
                                ) ?>
                            </span>
                        </div>

                    </summary>

                    <div class="card-body">

                        <div class="row g-4">

                            <div class="col-lg-6">

                                <h6 class="text-muted text-uppercase small mb-2">
                                    Descrição
                                </h6>

                                <p>
                                    <?= nl2br(htmlspecialchars($o["descricao"] ?? "")) ?>
                                </p>

                                <?php if (!empty($o["foto_url"])): ?>
                                    <a
                                        href="<?= htmlspecialchars($o["foto_url"]) ?>"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        <img
                                            src="<?= htmlspecialchars($o["foto_url"]) ?>"
                                            alt="Foto da ocorrência"
                                            class="oc-photo"
                                        >
                                    </a>
                                <?php endif; ?>

                                <?php if (!empty($o["data_fechamento"])): ?>
                                    <p class="small text-muted mt-3 mb-0">
                                        Encerrada em
                                        <?= date("d/m/Y H:i", strtotime($o["data_fechamento"])) ?>
                                    </p>
                                <?php endif; ?>

                            </div>

                            <div class="col-lg-6">

                                <h6 class="text-muted text-uppercase small mb-3">
                                    Andamento
                                </h6>

                                <ol class="oc-timeline">

                                    <?php foreach ($linhaDoTempo as $evento): ?>

                                        <li class="st-<?= htmlspecialchars($evento["status"] ?? "Aberta") ?>">

                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <span class="badge bg-<?= OcorrenciaHistorico::classeStatus($evento["status"] ?? "Aberta") ?>">
                                                    <?= htmlspecialchars($evento["status_rotulo"]) ?>
                                                </span>
                                                <span class="oc-when">
                                                    <?= htmlspecialchars($evento["data_formatada"]) ?>
                                                </span>
                                            </div>

                                            <?php if (!empty($evento["mensagem"])): ?>
                                                <div class="mt-1">
                                                    <?= nl2br(htmlspecialchars($evento["mensagem"])) ?>
                                                </div>
                                            <?php endif; ?>

                                            <div class="oc-when">
                                                por <?= htmlspecialchars($evento["perfil_rotulo"]) ?>
                                            </div>

                                        </li>

                                    <?php endforeach; ?>

                                </ol>

                            </div>

                        </div>

                    </div>

                </details>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

<script src="../../assets/js/script.js"></script>

</body>

</html>
