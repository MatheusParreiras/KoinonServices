<?php
require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/LocalReservaController.php";

PermissaoController::exigirGestao();

$controller = new LocalReservaController();
$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;
$local = $id > 0 ? $controller->buscarPorId($id) : null;

if (!$local) {
    header("Location: locais.php?erro=local_nao_encontrado");
    exit;
}

$mensagem = '';
$tipoMensagem = 'success';
$fotos = $local->getFotosArray();
$baseDir = __DIR__ . "/../../uploads/locais/" . $id;
$baseUrl = "../../uploads/locais/" . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'excluir') {
        $arquivo = basename((string)($_POST['arquivo'] ?? ''));
        $fotos = array_values(array_filter($fotos, fn($foto) => basename($foto) !== $arquivo));
        $caminho = $baseDir . DIRECTORY_SEPARATOR . $arquivo;
        if (is_file($caminho)) { @unlink($caminho); }
        $controller->atualizarFotos($id, $fotos);
        $mensagem = 'Foto removida do álbum.';
    } elseif ($acao === 'upload') {
        if (!isset($_FILES['fotos']) || !is_array($_FILES['fotos']['name'] ?? null)) {
            $mensagem = 'Selecione pelo menos uma imagem.';
            $tipoMensagem = 'danger';
        } else {
            if (!is_dir($baseDir)) { @mkdir($baseDir, 0755, true); }
            $permitidos = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            $adicionadas = 0;
            $limite = 8;
            foreach ($_FILES['fotos']['tmp_name'] as $i => $tmp) {
                if ($adicionadas >= $limite) break;
                if (($_FILES['fotos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                if (!is_uploaded_file($tmp)) continue;
                if ((int)($_FILES['fotos']['size'][$i] ?? 0) > 5 * 1024 * 1024) continue;
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                if (!isset($permitidos[$mime])) continue;
                $ext = $permitidos[$mime];
                $nome = bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($tmp, $baseDir . DIRECTORY_SEPARATOR . $nome)) {
                    $fotos[] = 'uploads/locais/' . $id . '/' . $nome;
                    $adicionadas++;
                }
            }
            $controller->atualizarFotos($id, $fotos);
            $mensagem = $adicionadas > 0 ? "$adicionadas foto(s) adicionada(s) ao álbum." : 'Nenhuma imagem válida foi adicionada.';
            $tipoMensagem = $adicionadas > 0 ? 'success' : 'warning';
        }
    }
    $local = $controller->buscarPorId($id);
    $fotos = $local ? $local->getFotosArray() : [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Álbum - <?= htmlspecialchars($local->getNome()) ?> - Koinon Services</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../../assets/css/estilo.css">
<style>.album-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:18px}.album-card img{width:100%;height:180px;object-fit:cover;border-radius:12px}.album-card{border:1px solid #dee2e6;border-radius:14px;padding:10px;background:#fff}</style>
</head>
<body class="bg-light">
<div class="container py-4">
<div class="d-flex justify-content-between align-items-center mb-4"><div><h2>📷 Álbum de fotos</h2><p class="text-muted mb-0"><?= htmlspecialchars($local->getNome()) ?></p></div><a href="locais.php" class="btn btn-secondary">Voltar aos locais</a></div>
<?php if ($mensagem): ?><div class="alert alert-<?= $tipoMensagem ?>"><?= htmlspecialchars($mensagem) ?></div><?php endif; ?>
<div class="card shadow-sm mb-4"><div class="card-body"><form method="POST" enctype="multipart/form-data"><input type="hidden" name="acao" value="upload"><label class="form-label fw-semibold">Adicionar fotos</label><input type="file" class="form-control" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple required><div class="form-text">JPG, PNG ou WEBP. Até 5 MB por foto e 8 fotos por envio.</div><button class="btn btn-primary mt-3">Adicionar ao álbum</button></form></div></div>
<div class="album-grid">
<?php if (!$fotos): ?><div class="alert alert-info">Nenhuma foto cadastrada para este local.</div><?php else: foreach ($fotos as $foto): $arquivo=basename($foto); $src='../../'.$foto; ?>
<div class="album-card"><img src="<?= htmlspecialchars($src) ?>" alt="Foto de <?= htmlspecialchars($local->getNome()) ?>"><form method="POST" class="mt-2" onsubmit="return confirm('Excluir esta foto do álbum?')"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="arquivo" value="<?= htmlspecialchars($arquivo) ?>"><button class="btn btn-outline-danger btn-sm w-100">Excluir foto</button></form></div>
<?php endforeach; endif; ?>
</div></div></body></html>
