<?php

require_once __DIR__ . "/../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../controller/PermissaoController.php";
require_once __DIR__ . "/../controller/UsuarioController.php";
require_once __DIR__ . "/../model/dao/Conexao.php";

PermissaoController::exigirLogin(false);

$usuarioId = (int) PermissaoController::usuarioId();
$nome = PermissaoController::usuarioNome() ?? "Usuário";
$erro = null;
$sucesso = null;

try {
    $pdo = Conexao::conectar();
    $stmt = $pdo->prepare(
        "SELECT senha_definida, termo_lgpd, data_aceite_lgpd
         FROM usuario WHERE id = :id LIMIT 1"
    );
    $stmt->bindValue(":id", $usuarioId, PDO::PARAM_INT);
    $stmt->execute();
    $dados = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dados || (int)$dados["termo_lgpd"] !== 1 || empty($dados["data_aceite_lgpd"])) {
        header("Location: lgpd.php");
        exit;
    }

    if ((int)$dados["senha_definida"] === 1) {
        $perfil = PermissaoController::usuarioPerfil();
        header($perfil === "Morador" ? "Location: morador/index.php" : "Location: dashboard.php");
        exit;
    }
} catch (Throwable $e) {
    $erro = "Não foi possível validar seu primeiro acesso.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $erro === null) {
    $senha = (string)($_POST["senha"] ?? "");
    $confirmacao = (string)($_POST["confirmacao"] ?? "");

    if (strlen($senha) < 8) {
        $erro = "A senha deve possuir pelo menos 8 caracteres.";
    } elseif ($senha !== $confirmacao) {
        $erro = "A confirmação da senha não confere.";
    } else {
        try {
            $controller = new UsuarioController();
            if ($controller->alterarSenha($usuarioId, password_hash($senha, PASSWORD_DEFAULT))) {
                $_SESSION["primeiro_acesso"] = false;
                $sucesso = "Senha cadastrada com sucesso. Seu próximo acesso exigirá CPF e senha.";
            } else {
                $erro = "Não foi possível salvar sua senha.";
            }
        } catch (Throwable $e) {
            $erro = "Não foi possível salvar sua senha. Verifique o banco de dados.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Definir senha - Koinon Services</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/estilo.css">
</head>
<body class="koinon-auth-page">
<div class="container py-5">
    <div class="card koinon-auth-card mx-auto">
        <div class="card-body p-4 p-md-5">
            <span class="koinon-eyebrow">Primeiro acesso</span>
            <h1 class="h3 fw-bold mt-2">Crie sua senha</h1>
            <p class="text-muted">Olá, <?= htmlspecialchars($nome) ?>. O aceite da LGPD foi registrado. Agora defina sua senha pessoal para os próximos logins.</p>

            <?php if ($erro): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
            <?php endif; ?>

            <?php if ($sucesso): ?>
                <div class="alert alert-success"><?= htmlspecialchars($sucesso) ?></div>
                <a href="login.php" class="btn btn-primary w-100">Ir para o login</a>
            <?php else: ?>
                <form method="POST" autocomplete="off">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nova senha</label>
                        <input type="password" name="senha" class="form-control form-control-lg" minlength="8" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Confirmar senha</label>
                        <input type="password" name="confirmacao" class="form-control form-control-lg" minlength="8" required>
                    </div>
                    <button class="btn btn-primary btn-lg w-100">Salvar senha e continuar</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="../assets/js/script.js"></script>
</body>
</html>
