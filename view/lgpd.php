<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../controller/PermissaoController.php";
require_once __DIR__ . "/../controller/UsuarioController.php";
require_once __DIR__ . "/../model/dao/Conexao.php";

PermissaoController::exigirLogin(false);

$usuarioId = PermissaoController::usuarioId();
$usuarioNome = PermissaoController::usuarioNome();
$usuarioPerfil = PermissaoController::usuarioPerfil();

/*
 * A tela de LGPD só deve aparecer para quem ainda não aceitou.
 * A verificação é feita no banco para evitar inconsistências de sessão.
 */
try {

    $conexao = Conexao::conectar();

    $stmt = $conexao->prepare(
        "SELECT termo_lgpd, data_aceite_lgpd
         FROM usuario
         WHERE id = :id
         LIMIT 1"
    );

    $stmt->bindValue(
        ":id",
        (int) $usuarioId,
        PDO::PARAM_INT
    );

    $stmt->execute();

    $dadosLgpd = $stmt->fetch(PDO::FETCH_ASSOC);

    if (
        $dadosLgpd
        && (int) ($dadosLgpd["termo_lgpd"] ?? 0) === 1
        && !empty($dadosLgpd["data_aceite_lgpd"])
    ) {

        $_SESSION["lgpd_aceito"] = true;

        $stmtSenha = $conexao->prepare("SELECT senha_definida FROM usuario WHERE id = :id LIMIT 1");
        $stmtSenha->bindValue(":id", (int) $usuarioId, PDO::PARAM_INT);
        $stmtSenha->execute();
        $senhaDefinida = (int) ($stmtSenha->fetchColumn() ?? 0) === 1;

        header(
            !$senhaDefinida
                ? "Location: definir_senha.php"
                : ($usuarioPerfil === "Morador"
                    ? "Location: morador/index.php"
                    : "Location: dashboard.php")
        );

        exit;
    }

    $_SESSION["lgpd_aceito"] = false;

} catch (Throwable $e) {

    $_SESSION["lgpd_aceito"] = false;
}

$erro = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (($_POST["aceitar_lgpd"] ?? "") !== "1") {

        $erro =
            "Para continuar utilizando o Koinon Services, "
            . "é necessário aceitar o termo de privacidade e LGPD.";

    } else {

        try {

            $controller = new UsuarioController();

            if ($controller->aceitarLgpd((int) $usuarioId)) {

                $_SESSION["lgpd_aceito"] = true;

                $conexao = Conexao::conectar();
                $stmtSenha = $conexao->prepare("SELECT senha_definida FROM usuario WHERE id = :id LIMIT 1");
                $stmtSenha->bindValue(":id", (int) $usuarioId, PDO::PARAM_INT);
                $stmtSenha->execute();
                $senhaDefinida = (int) ($stmtSenha->fetchColumn() ?? 0) === 1;

                if (!$senhaDefinida) {
                    header("Location: definir_senha.php");
                    exit;
                }

                if ($usuarioPerfil === "Morador") {
                    header("Location: morador/index.php");
                    exit;
                }

                header("Location: dashboard.php");

                exit;
            }

            $erro =
                "Não foi possível registrar o aceite. Tente novamente.";

        } catch (Throwable $e) {

            $erro =
                "Não foi possível registrar o aceite. "
                . "Verifique a conexão com o banco de dados.";
        }
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
    <title>Privacidade e LGPD - Koinon Services</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <style>
        body {
            min-height: 100vh;
            background: #f5f7fa;
        }

        .lgpd-card {
            max-width: 850px;
            margin: 50px auto;
            border: 0;
            border-radius: 18px;
            box-shadow: 0 12px 35px rgba(0, 0, 0, .08);
        }
    </style>

    <link rel="stylesheet" href="../assets/css/estilo.css">

</head>
<body>

<div class="container py-4">

    <div class="card lgpd-card">

        <div class="card-body p-4 p-md-5">

            <div class="mb-4">
                <span class="badge text-bg-primary mb-2">
                    Primeiro acesso
                </span>

                <h1 class="h3 fw-bold">
                    Privacidade e proteção de dados
                </h1>

                <p class="text-muted mb-0">
                    Olá, <?= htmlspecialchars($usuarioNome ?? "Usuário") ?>.
                    Antes de continuar, leia as informações abaixo e confirme
                    sua concordância com o tratamento de dados realizado pelo
                    Koinon Services.
                </p>
            </div>

            <?php if ($erro !== null): ?>

                <div class="alert alert-danger">
                    <?= htmlspecialchars($erro) ?>
                </div>

            <?php endif; ?>

            <div class="border rounded-3 p-4 bg-light mb-4">

                <h2 class="h5 fw-bold">
                    Termo de privacidade e LGPD
                </h2>

                <p>
                    O Koinon Services utiliza dados pessoais necessários para
                    identificar usuários, administrar unidades, controlar
                    acessos, gerenciar reservas, ocorrências, encomendas,
                    documentos, comunicações e demais serviços do condomínio.
                </p>

                <p>
                    Os dados devem ser utilizados somente para finalidades
                    legítimas relacionadas à administração e segurança do
                    condomínio, respeitando os princípios da Lei Geral de
                    Proteção de Dados Pessoais (Lei nº 13.709/2018).
                </p>

                <p>
                    O sistema poderá registrar informações de acesso, dados
                    cadastrais e operações realizadas pelo usuário para fins
                    de segurança, auditoria e funcionamento dos serviços.
                </p>

                <p class="mb-0">
                    O aceite fica registrado com data e hora no cadastro do
                    usuário. Em caso de dúvidas sobre o tratamento de dados,
                    procure a administração do condomínio.
                </p>

            </div>

            <form method="POST">

                <div class="form-check mb-4">

                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="aceitar_lgpd"
                        name="aceitar_lgpd"
                        value="1"
                        required
                    >

                    <label
                        class="form-check-label"
                        for="aceitar_lgpd"
                    >
                        Li as informações acima e concordo com o tratamento
                        dos meus dados pessoais conforme as finalidades
                        apresentadas e com os termos de privacidade do sistema.
                    </label>

                </div>

                <button
                    type="submit"
                    class="btn btn-primary w-100"
                >
                    Aceitar e continuar
                </button>

            </form>

        </div>

    </div>

</div>
<script src="../assets/js/script.js"></script>

</body>
</html>
