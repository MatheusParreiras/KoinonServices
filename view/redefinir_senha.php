<?php

require_once __DIR__ . "/../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../service/Csrf.php";
Csrf::proteger();

require_once __DIR__ . "/../model/dao/Conexao.php";
require_once __DIR__ . "/../controller/UsuarioController.php";

$token =
    trim($_GET["token"] ?? $_POST["token"] ?? "");

$erro = null;
$sucesso = null;
$usuarioId = null;

$arquivoToken =
    __DIR__
    . "/../storage/password_resets/"
    . hash("sha256", $token)
    . ".json";

if ($token === "" || !is_file($arquivoToken)) {

    $erro =
        "O link de redefinição é inválido ou já expirou.";

} else {

    $dadosToken =
        json_decode(
            file_get_contents($arquivoToken),
            true
        );

    if (
        !is_array($dadosToken)
        || (int) ($dadosToken["expira_em"] ?? 0) < time()
    ) {

        @unlink($arquivoToken);

        $erro =
            "O link de redefinição é inválido ou já expirou.";

    } else {

        $usuarioId =
            (int) ($dadosToken["usuario_id"] ?? 0);
    }
}

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && $usuarioId !== null
) {

    $senha =
        $_POST["senha"] ?? "";

    $confirmacao =
        $_POST["confirmacao"] ?? "";

    if (strlen($senha) < 8) {

        $erro =
            "A nova senha deve possuir pelo menos 8 caracteres.";

    } elseif ($senha !== $confirmacao) {

        $erro =
            "A confirmação da senha não confere.";

    } else {

        try {

            $controller =
                new UsuarioController();

            $sucessoAlteracao =
                $controller->alterarSenha(
                    $usuarioId,
                    password_hash(
                        $senha,
                        PASSWORD_DEFAULT
                    )
                );

            if ($sucessoAlteracao) {

                @unlink($arquivoToken);

                $sucesso =
                    "Senha redefinida com sucesso. "
                    . "Você já pode entrar no sistema com a nova senha.";

                $usuarioId = null;

            } else {

                $erro =
                    "Não foi possível alterar a senha.";
            }

        } catch (Throwable $e) {

            $erro =
                "Não foi possível alterar a senha. "
                . "Verifique a conexão com o banco de dados.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir senha - Koinon Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/estilo.css">

</head>
<body class="bg-light">

<div class="container py-5">

    <div class="card shadow-sm border-0 mx-auto" style="max-width: 520px;">

        <div class="card-body p-4 p-md-5">

            <h1 class="h4 fw-bold mb-2">
                Redefinir senha
            </h1>

            <?php if ($erro !== null): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($erro) ?>
                </div>
            <?php endif; ?>

            <?php if ($sucesso !== null): ?>

                <div class="alert alert-success">
                    <?= htmlspecialchars($sucesso) ?>
                </div>

                <a
                    href="login.php"
                    class="btn btn-primary w-100"
                >
                    Ir para o login
                </a>

            <?php elseif ($usuarioId !== null): ?>

                <p class="text-muted">
                    Escolha uma nova senha para sua conta.
                </p>

                <form method="POST">

                    <input
                        type="hidden"
                        name="token"
                        value="<?= htmlspecialchars($token) ?>"
                    >

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="senha">
                            Nova senha
                        </label>
                        <input
                            type="password"
                            class="form-control"
                            id="senha"
                            name="senha"
                            minlength="8"
                            required
                            autocomplete="new-password"
                        >
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="confirmacao">
                            Confirmar nova senha
                        </label>
                        <input
                            type="password"
                            class="form-control"
                            id="confirmacao"
                            name="confirmacao"
                            minlength="8"
                            required
                            autocomplete="new-password"
                        >
                    </div>

                    <button class="btn btn-primary w-100" type="submit">
                        Salvar nova senha
                    </button>

                </form>

            <?php endif; ?>

        </div>

    </div>

</div>
<script src="../assets/js/script.js"></script>

</body>
</html>
