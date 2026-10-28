<?php

require_once __DIR__ . "/../../service/Sessao.php";
if (session_status() !== PHP_SESSION_ACTIVE) Sessao::iniciar();
require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../service/ComprovanteDAO.php";
PermissaoController::exigirPerfil(["Morador"]);
$id=(int)$_SESSION['usuario_id'];
$comprovantes=(new ComprovanteDAO())->listarDoMorador($id);
function tipoComprovante($tipo){return $tipo==='Retirada_Encomenda'?'Retirada de encomenda':'Pagamento';}
function dataComprovante($d){$t=$d?strtotime($d):false;return $t?date('d/m/Y H:i:s',$t):'—';}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Comprovantes - Koinon Services</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../../assets/css/estilo.css"></head><body><main class="container-fluid py-4"><a href="index.php" class="btn btn-outline-secondary mb-3">← Voltar</a><div class="koinon-hero p-4 mb-4"><h1 class="h3 text-white">📄 Comprovantes</h1><p class="text-white-50 mb-0">Registros de retiradas de encomendas e pagamentos. A exclusão não é disponibilizada pela interface do sistema.</p></div><div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Nº do comprovante</th><th>Tipo</th><th>Data</th><th>Detalhe</th><th></th></tr></thead><tbody><?php if(!$comprovantes): ?><tr><td colspan="5" class="text-center text-muted py-5">Nenhum comprovante registrado.</td></tr><?php else: foreach($comprovantes as $c): ?><tr><td><strong><?=htmlspecialchars($c['numero_comprovante']??'—')?></strong></td><td><span class="badge text-bg-secondary"><?=tipoComprovante($c['tipo']??'')?></span></td><td><?=dataComprovante($c['data_emissao']??null)?></td><td><?=htmlspecialchars($c['dados']['descricao']??'Registro' )?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" target="_blank" href="comprovante.php?id=<?=(int)$c['id']?>&modo=visualizar">Visualizar</a> <a class="btn btn-sm btn-primary" href="comprovante.php?id=<?=(int)$c['id']?>&download=1">Baixar PDF</a></td></tr><?php endforeach; endif; ?></tbody></table></div></div></main><script src="../../assets/js/script.js"></script></body></html>
