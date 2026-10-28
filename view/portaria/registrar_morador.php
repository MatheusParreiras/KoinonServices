<?php
require_once __DIR__ . "/../../controller/PermissaoController.php";
PermissaoController::exigirPortaria();
require_once __DIR__ . "/../../controller/UsuarioController.php";
require_once __DIR__ . "/../../controller/VeiculoController.php";
require_once __DIR__ . "/../../controller/RegistroAcessoController.php";
require_once __DIR__ . "/../../model/dto/RegistroAcessoDTO.php";

$uc = new UsuarioController();
$vc = new VeiculoController();
$rc = new RegistroAcessoController();
$usuarios = array_filter($uc->listar(), fn($u) => $u->getPerfil() === 'Morador' && (int)$u->getAtivo() === 1);
$veiculos = $vc->listar();
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = (int)($_POST['usuario_id'] ?? 0);
    $vid = (int)($_POST['veiculo_id'] ?? 0);
    $tipo = $_POST['tipo_acesso'] ?? 'A_pe';
    $dir = $_POST['direcao'] ?? '';
    // Acesso de morador é sempre acesso próprio, tanto na entrada quanto na saída.
    $autorizadoPorUsuarioId = null;

    if ($uid <= 0) {
        $erro = 'Selecione o morador.';
    } elseif (!in_array($dir, ['Entrada', 'Saida'], true)) {
        $erro = 'Selecione uma direção válida.';
    } elseif (!in_array($tipo, ['A_pe', 'Veiculo'], true)) {
        $erro = 'Selecione um tipo de acesso válido.';
    } elseif ($tipo === 'Veiculo') {
        $sel = null;
        foreach ($veiculos as $v) {
            if ((int)$v->getId() === $vid && (int)$v->getUsuarioId() === $uid) {
                $sel = $v;
                break;
            }
        }
        if (!$sel) {
            $erro = 'Selecione um veículo válido.';
        } elseif ($vc->status($vid) !== 'Aprovado') {
            $erro = 'Este veículo ainda não foi aprovado pela administração.';
        }
    }

    if ($erro === '') {
        $r = new RegistroAcessoDTO();
        $r->setUsuarioId($uid);
        // Para saída de morador não existe autorizador: o histórico exibirá Acesso próprio.
        $r->setAutorizadoPorUsuarioId($autorizadoPorUsuarioId);
        $r->setDirecao($dir);
        $r->setMetodoLeitura($tipo === 'Veiculo' ? 'Placa_LPR' : 'Manual');
        $r->setObservacao($tipo === 'Veiculo'
            ? 'Morador em veículo: ' . $sel->getPlaca() . ' — ' . $sel->getModelo()
            : 'Morador a pé.'
        );
        if ($rc->cadastrar($r)) {
            header('Location: index.php?sucesso=3');
            exit;
        }
        $erro = 'Não foi possível registrar o acesso.';
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Entrada e saída de moradores</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../../assets/css/estilo.css">
</head>
<body>
<div class="container py-4">
<div class="koinon-hero p-4 p-lg-5 mb-4"><div class="text-white-50 small">Controle de acesso</div><h1 class="h2 fw-bold text-white">Entrada e saída de moradores</h1><p class="text-white-50 mb-0">A pé ou em veículo autorizado.</p></div>
<style>.busca-pessoa{margin-bottom:8px}</style>
<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<div class="card"><div class="card-body p-4"><form method="post">
<div class="row g-3">
<div class="col-md-6"><label class="form-label fw-semibold" for="morador_busca">Morador</label>
<input type="search" id="morador_busca" class="form-control" list="moradores_lista" placeholder="🔎 Digite o nome ou CPF..." autocomplete="off" required>
<input type="hidden" name="usuario_id" id="usuario_id" value="<?= htmlspecialchars($_POST['usuario_id'] ?? '') ?>">
<datalist id="moradores_lista"><?php foreach ($usuarios as $u): ?>
<option value="<?= htmlspecialchars($u->getNome() . ($u->getUnidadeIdentificacao() ? ' — ' . $u->getUnidadeIdentificacao() : '') . ' — CPF ' . $u->getCpf()) ?>" data-id="<?= (int)$u->getId() ?>"></option>
<?php endforeach; ?></datalist>
<div class="form-text">Digite e selecione o morador. A lista filtra automaticamente conforme você escreve.</div></div>
<div class="col-md-6"><label class="form-label fw-semibold">Direção</label><select name="direcao" id="direcao" class="form-select" required><option value="">Selecione...</option><option value="Entrada">Entrada</option><option value="Saida">Saída</option></select></div>
<div class="col-md-6"><label class="form-label fw-semibold">Tipo</label><select name="tipo_acesso" id="tipo" class="form-select"><option value="A_pe">A pé</option><option value="Veiculo">Veículo</option></select></div>
<div class="col-md-6"><label class="form-label fw-semibold">Autorização</label><input type="text" class="form-control" value="Acesso próprio" readonly><div class="form-text">Morador não precisa de autorizador para entrada ou saída.</div></div>
<div class="col-md-6" id="vw"><label class="form-label fw-semibold">Veículo aprovado</label><select name="veiculo_id" id="veiculo" class="form-select"><option value="">Selecione o morador...</option></select></div>
</div>
<div class="d-flex gap-2 mt-4"><button class="btn btn-primary">Registrar acesso</button><a href="index.php" class="btn btn-outline-secondary">Voltar à Portaria</a></div>
</form></div></div></div>
<script>
const V = <?= json_encode(array_map(fn($v) => ['id'=>$v->getId(),'uid'=>$v->getUsuarioId(),'label'=>$v->getPlaca().' — '.$v->getModelo(),'status'=>$vc->status((int)$v->getId())], $veiculos), JSON_UNESCAPED_UNICODE) ?>;
const U=document.getElementById('usuario_id'), M=document.getElementById('morador_busca'), T=document.getElementById('tipo'),W=document.getElementById('vw'),S=document.getElementById('veiculo'),D=document.getElementById('direcao');
const moradores=[...document.querySelectorAll('#moradores_lista option')].map(o=>({id:o.dataset.id,label:o.value}));
function syncMorador(){
 const val=(M.value||'').trim().toLowerCase();
 const found=moradores.find(x=>x.label.toLowerCase()===val);
 U.value=found?found.id:'';
 f();
}
M.addEventListener('input',syncMorador); M.addEventListener('change',syncMorador);
function f(){W.style.display=T.value==='Veiculo'?'block':'none';S.innerHTML='<option value="">Selecione...</option>';V.filter(x=>String(x.uid)===String(U.value)&&x.status==='Aprovado').forEach(x=>S.innerHTML+=`<option value="${x.id}">${x.label}</option>`);}
U.onchange=f;T.onchange=f;D.onchange=f;f();
</script>
<script src="../../assets/js/script.js"></script>


</body></html>
