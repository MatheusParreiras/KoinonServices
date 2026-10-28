<?php

require_once __DIR__ . "/../service/Configuracao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$nome = $_SESSION["usuario_nome"] ?? "Usuário";
$perfil = $_SESSION["usuario_perfil"] ?? "Não identificado";
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Acesso Negado - Koinon Services</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../assets/css/estilo.css">

</head>

<body class="bg-light">

<div class="container">

    <div
        class="row justify-content-center align-items-center"
        style="min-height: 100vh;"
    >

        <div class="col-md-6">

            <div class="card shadow border-0">

                <div class="card-body text-center p-5">

                    <div class="mb-4">

                        <span
                            class="d-inline-flex justify-content-center align-items-center bg-danger text-white rounded-circle"
                            style="width: 80px; height: 80px; font-size: 40px;"
                        >
                            🔒
                        </span>

                    </div>

                    <h2 class="mb-3">
                        Acesso Negado
                    </h2>

                    <p class="text-muted mb-2">
                        Olá, <strong><?= htmlspecialchars($nome) ?></strong>.
                    </p>

                    <p class="text-muted">

                        Seu perfil atual é:

                        <strong>
                            <?= htmlspecialchars($perfil) ?>
                        </strong>

                    </p>

                    <div class="alert alert-danger mt-4">

                        Você não possui permissão para acessar esta página.

                    </div>

                    <div class="d-flex justify-content-center gap-2 mt-4">

                        <a
                            href="<?= htmlspecialchars(Configuracao::url("view/dashboard.php")) ?>"
                            class="btn btn-primary"
                        >
                            Voltar ao Dashboard
                        </a>

                        <a
                            href="<?= htmlspecialchars(Configuracao::url("view/login.php")) ?>"
                            class="btn btn-outline-secondary"
                        >
                            Voltar ao Login
                        </a>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>
<script src="../assets/js/script.js"></script>

</body>

</html>