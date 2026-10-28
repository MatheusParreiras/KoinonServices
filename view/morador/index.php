<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../model/dao/MoradorDAO.php";
require_once __DIR__ . "/../../model/dao/PublicacaoDAO.php";

PermissaoController::exigirPerfil(["Morador"]);
$usuarioId = (int)($_SESSION["usuario_id"] ?? 0);
$dao = new MoradorDAO();
$usuario = $dao->buscarUsuario($usuarioId);
$unidade = $dao->buscarUnidade($usuarioId);
$veiculos = $dao->listarVeiculos($usuarioId);

$modulos = [
    ["🏠", "Minha unidade", "Consulte seus dados e a quantidade de vagas.", "unidade.php", "primary"],
    ["🚗", "Meus veículos", "Veja os veículos vinculados à sua unidade.", "veiculos.php", "dark"],
    ["📅", "Reservas", "Consulte disponibilidade e solicite uma nova reserva.", "nova_reserva.php", "success"],
    ["📦", "Encomendas", "Acompanhe recebimentos e retiradas registradas.", "encomendas.php", "info"],
    ["💰", "Financeiro", "Consulte cobranças, vencimentos e pagamentos.", "financeiro.php", "warning"],
    ["📄", "Comprovantes", "Visualize e baixe seus comprovantes.", "comprovantes.php", "secondary"],
    ["👥", "Autorizações", "Gerencie visitantes autorizados e QR Codes.", "autorizacoes.php", "primary"],
    ["⚠️", "Ocorrências", "Registre e acompanhe o andamento das suas ocorrências.", "ocorrencias.php", "warning"],
    ["📢", "Publicações", "Consulte avisos, comunicados e enquetes.", "publicacoes.php", "info"],
    ["🗳️", "Enquetes", "Participe das enquetes disponíveis.", "enquetes.php", "success"],
    ["🔔", "Notificações", "Veja avisos e atualizações do sistema.", "notificacoes.php", "dark"],
    ["📚", "Documentos", "Consulte documentos disponibilizados pelo condomínio.", "documentos.php", "secondary"],
    ["🔎", "Achados e perdidos", "Consulte itens registrados na portaria.", "achados_perdidos.php", "secondary"],
];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Área do Morador - Koinon Services</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../../assets/css/estilo.css">
<style>
.morador-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:18px}
.morador-module{text-decoration:none;color:inherit;height:100%;display:block}
.morador-module .card{height:100%;transition:transform .15s ease,box-shadow .15s ease}
.morador-module:hover .card{transform:translateY(-3px);box-shadow:0 .8rem 1.8rem rgba(0,0,0,.12)!important}
.morador-icon{font-size:2rem;margin-bottom:10px}
.morador-stat{border-radius:16px;padding:20px;background:var(--k-surface);color:var(--k-text);border:1px solid var(--k-border);box-shadow:var(--k-shadow)}
</style>
</head>
<body class="koinon-dashboard-page">
<nav class="navbar bg-white border-bottom">
  <div class="container-fluid px-4">
    <a href="../../view/dashboard.php" class="navbar-brand fw-bold text-primary text-decoration-none">Koinon Services</a>
    <div class="d-flex align-items-center gap-2 koinon-nav-actions">
      <a href="../logout.php" class="btn btn-danger koinon-logout-btn">Sair</a>
    </div>
  </div>
</nav>
<main class="container-fluid px-3 px-lg-4 py-4">
  <div class="koinon-hero p-4 p-lg-5 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-4">
      <div>
        <div class="text-white-50 small mb-2">Área do Morador</div>
        <h1 class="h2 fw-bold text-white mb-2">Olá, <?=htmlspecialchars($usuario['nome'] ?? 'Morador')?> 👋</h1>
        <p class="text-white-50 mb-0">Escolha uma opção para abrir a tela correspondente.</p>
      </div>
      <div class="text-end text-white">
        <div class="small text-white-50">Unidade</div>
        <div class="fw-bold fs-5"><?=htmlspecialchars($unidade['identificacao'] ?? 'Não vinculada')?></div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-4"><div class="morador-stat"><div class="small text-muted">Vagas da unidade</div><div class="h3 mb-0"><?= (int)($unidade['quantidade_vagas'] ?? 0) ?></div></div></div>
    <div class="col-md-4"><div class="morador-stat"><div class="small text-muted">Veículos cadastrados</div><div class="h3 mb-0"><?=count($veiculos)?></div></div></div>
    <div class="col-md-4"><div class="morador-stat"><div class="small text-muted">Publicações</div><div class="h3 mb-0"><?=count((new PublicacaoDAO())->listar())?></div></div></div>
  </div>

  <div class="morador-grid">
    <?php foreach($modulos as [$icone,$titulo,$descricao,$link,$cor]): ?>
      <a class="morador-module" href="<?=htmlspecialchars($link)?>">
        <div class="card shadow-sm border-0">
          <div class="card-body p-4">
            <div class="morador-icon"><?=$icone?></div>
            <h2 class="h5 fw-bold mb-2"><?=htmlspecialchars($titulo)?></h2>
            <p class="text-muted mb-3"><?=htmlspecialchars($descricao)?></p>
            <span class="btn btn-<?=$cor?> btn-sm">Acessar →</span>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</main>
<script src="../../assets/js/script.js"></script>
</body>
</html>
