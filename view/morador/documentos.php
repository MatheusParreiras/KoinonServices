<?php

require_once __DIR__ . "/../../service/Sessao.php";
if (session_status() !== PHP_SESSION_ACTIVE) Sessao::iniciar();
require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../model/dao/MoradorDAO.php";
PermissaoController::exigirPerfil(["Morador"]);
$documentos=(new MoradorDAO())->listarDocumentos();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Documentos do Condomínio</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../../assets/css/estilo.css"></head><body><main class="container py-4"><a href="index.php" class="btn btn-outline-secondary mb-3">← Voltar</a><div class="koinon-hero p-4 mb-4"><h1 class="h3 text-white">📚 Documentos do condomínio</h1><p class="text-white-50 mb-0">Arquivos disponibilizados pela administração.</p></div><div class="row g-3"><?php if(!$documentos): ?><div class="col-12"><div class="card"><div class="card-body text-muted">Nenhum documento disponível.</div></div></div><?php else: foreach($documentos as $d): ?><div class="col-md-6"><div class="card h-100"><div class="card-body"><h2 class="h5"><?=htmlspecialchars($d['titulo']??'Documento')?></h2><p class="text-muted"><?=htmlspecialchars($d['descricao']??'')?></p><a href="<?=htmlspecialchars($d['arquivo_url']??'#')?>" target="_blank" class="btn btn-outline-primary">Abrir documento</a></div></div></div><?php endforeach; endif; ?></div></main><script src="../../assets/js/script.js"></script></body></html>
