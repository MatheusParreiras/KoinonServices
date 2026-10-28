<?php

require_once __DIR__ . "/../../service/Sessao.php";
if (session_status() !== PHP_SESSION_ACTIVE) Sessao::iniciar();
require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../model/dao/MoradorDAO.php";
PermissaoController::exigirPerfil(["Morador"]);
$id=(int)$_SESSION['usuario_id']; $dao=new MoradorDAO(); $u=$dao->buscarUnidade($id); $usuario=$dao->buscarUsuario($id); $veiculos=$dao->listarVeiculos($id);
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Minha Unidade</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../../assets/css/estilo.css"></head><body><main class="container py-4"><a href="index.php" class="btn btn-outline-secondary mb-3">← Voltar</a><div class="koinon-hero p-4 mb-4"><h1 class="h3 text-white">🏠 Minha unidade</h1><p class="text-white-50 mb-0">Dados da unidade e vagas disponíveis.</p></div><div class="row g-4"><div class="col-md-6"><div class="card h-100"><div class="card-body"><h2 class="h5">Unidade</h2><p><strong>Identificação:</strong> <?=htmlspecialchars($u['identificacao']??'—')?></p><p><strong>Tipo:</strong> <?=htmlspecialchars($u['tipo_unidade']??'—')?></p><p><strong>Vagas:</strong> <?= (int)($u['quantidade_vagas']??0) ?></p></div></div></div><div class="col-md-6"><div class="card h-100"><div class="card-body"><h2 class="h5">Morador</h2><p><strong>Nome:</strong> <?=htmlspecialchars($usuario['nome']??'—')?></p><p><strong>CPF:</strong> <?=htmlspecialchars($usuario['cpf']??'—')?></p><p><strong>E-mail:</strong> <?=htmlspecialchars($usuario['email']??'—')?></p></div></div></div></div></main><script src="../../assets/js/script.js"></script></body></html>
