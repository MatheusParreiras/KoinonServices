<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../controller/PermissaoController.php";
require_once __DIR__ . "/../service/Auditoria.php";

// Somente o administrador global enxerga a auditoria (ele supervisiona a gestão).
PermissaoController::exigirAdministrador();

$filtros = [
    "usuario"     => trim($_GET["usuario"] ?? ""),
    "perfil"      => trim($_GET["perfil"] ?? ""),
    "data_inicio" => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET["data_inicio"] ?? "") ? $_GET["data_inicio"] : "",
    "data_fim"    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET["data_fim"] ?? "") ? $_GET["data_fim"] : "",
];

$registros = Auditoria::ler(300, $filtros);

$classePerfil = [
    "Administrador" => "primary", "Sindico" => "info", "Porteiro" => "success", "Morador" => "warning",
];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoria - Koinon Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/estilo.css">
</head>
<body class="bg-light">

<div class="container-fluid py-4 px-4">

    <div class="mb-4">
        <h2 class="mb-1">🕵️ Auditoria do sistema</h2>
        <p class="text-muted mb-0">
            Registro de quem executou ações que alteram dados (cadastrar, alterar, excluir, pagar, mudar status).
            Por privacidade (LGPD), valores digitados e senhas nunca são gravados.
        </p>
    </div>

    <form method="get" class="card shadow-sm mb-4">
        <div class="card-body row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Usuário</label>
                <input type="text" name="usuario" class="form-control" value="<?= htmlspecialchars($filtros["usuario"]) ?>" placeholder="Nome">
            </div>
            <div class="col-md-2">
                <label class="form-label">Perfil</label>
                <select name="perfil" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach (["Administrador", "Sindico", "Porteiro", "Morador"] as $p): ?>
                        <option value="<?= $p ?>" <?= $filtros["perfil"] === $p ? "selected" : "" ?>><?= $p === "Sindico" ? "Síndico" : $p ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">De</label>
                <input type="date" name="data_inicio" class="form-control" value="<?= htmlspecialchars($filtros["data_inicio"]) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Até</label>
                <input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars($filtros["data_fim"]) ?>">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary">Filtrar</button>
                <a href="auditoria.php" class="btn btn-secondary">Limpar</a>
            </div>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="card-header"><strong><?= count($registros) ?></strong> registro(s) mais recentes</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr><th>Data e hora</th><th>Usuário</th><th>Perfil</th><th>Ação</th><th>Registro</th><th>Tela</th><th>IP</th></tr>
                </thead>
                <tbody>
                <?php if (empty($registros)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma ação registrada ainda.</td></tr>
                <?php endif; ?>
                <?php foreach ($registros as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars(date("d/m/Y H:i:s", strtotime($r["data"] ?? "now"))) ?></td>
                        <td><?= htmlspecialchars($r["usuario"] ?? "") ?></td>
                        <td>
                            <span class="badge bg-<?= $classePerfil[$r["perfil"] ?? ""] ?? "secondary" ?>">
                                <?= htmlspecialchars(($r["perfil"] ?? "") === "Sindico" ? "Síndico" : ($r["perfil"] ?? "")) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($r["acao"] ?? "") ?></td>
                        <td><?= isset($r["registro"]) ? "#" . (int) $r["registro"] : "—" ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($r["tela"] ?? "") ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($r["ip"] ?? "") ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script src="../assets/js/script.js"></script>
</body>
</html>
