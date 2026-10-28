<?php

require_once __DIR__ . "/../../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/PublicacaoController.php";
require_once __DIR__ . "/../../service/Erro.php";

PermissaoController::exigirPerfil([
    "Morador"
]);

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

$usuarioId = (int) $_SESSION["usuario_id"];

$publicacaoController = new PublicacaoController();

$erro = "";
$publicacoes = [];

/*
 * ============================================================
 * FUNÇÕES AUXILIARES
 * ============================================================
 */

function textoCategoriaMorador(string $categoria): string
{
    return match ($categoria) {

        "Aviso_Sindico"
            => "Aviso do Síndico",

        "Aviso_Administrador"
            => "Aviso da Administração",

        default
            => "Aviso"
    };
}

function classeCategoriaMorador(string $categoria): string
{
    return match ($categoria) {

        "Aviso_Sindico"
            => "primary",

        "Aviso_Administrador"
            => "dark",

        default
            => "secondary"
    };
}

/*
 * ============================================================
 * LISTAGEM
 * ============================================================
 *
 * Esta tela mostra SOMENTE avisos oficiais.
 *
 * Feed Social       -> tela própria
 * Achados e Perdidos -> tela própria
 * Classificados     -> removido
 * Enquetes          -> tela própria
 *
 * ============================================================
 */

try {

    $todasPublicacoes = $publicacaoController->listar();

    foreach ($todasPublicacoes as $publicacao) {

        if ($publicacao->getStatus() !== "Ativo") {
            continue;
        }

        $categoria = $publicacao->getCategoria();

        if (
            $categoria !== "Aviso_Sindico" &&
            $categoria !== "Aviso_Administrador"
        ) {
            continue;
        }

        $publicacoes[] = $publicacao;
    }

} catch (Throwable $e) {

    $erro = Erro::mensagem($e);
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

    <title>
        Avisos - Área do Morador
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >

    <style>

        .post-card {
            border-radius: 15px;
            overflow: hidden;
        }

        .post-image {
            width: 100%;
            max-height: 400px;
            object-fit: cover;
        }

        .data-publicacao {
            font-size: 0.85rem;
        }

    </style>


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body class="bg-light">

<nav class="navbar navbar-dark bg-primary">

    <div class="container">

        <a
            href="index.php"
            class="navbar-brand fw-bold"
        >
            Koinon Services
        </a>


    </div>

</nav>

<div class="container py-4">

    <?php if ($erro !== ""): ?>

        <div class="alert alert-danger">
            <?= htmlspecialchars($erro) ?>
        </div>

    <?php endif; ?>

    <div class="mb-4">

        <h2>
            📢 Avisos do Condomínio
        </h2>

        <p class="text-muted mb-0">
            Consulte os avisos e comunicados oficiais
            publicados pelo síndico e pela administração.
        </p>

    </div>

    <?php if (empty($publicacoes)): ?>

        <div class="alert alert-info">

            Nenhum aviso oficial ativo no momento.

        </div>

    <?php else: ?>

        <?php foreach ($publicacoes as $publicacao): ?>

            <?php

            $categoria = $publicacao->getCategoria();
            $titulo = $publicacao->getTitulo();
            $conteudo = $publicacao->getConteudo();
            $autor = $publicacao->getUsuarioNome();
            $midia = $publicacao->getMidiaUrl();
            $createdAt = $publicacao->getCreatedAt();

            ?>

            <div class="card shadow-sm post-card mb-4">

                <div class="card-body">

                    <div
                        class="d-flex justify-content-between align-items-start"
                    >

                        <div>

                            <span
                                class="badge bg-<?= htmlspecialchars(
                                    classeCategoriaMorador(
                                        $categoria
                                    )
                                ) ?>"
                            >

                                <?= htmlspecialchars(
                                    textoCategoriaMorador(
                                        $categoria
                                    )
                                ) ?>

                            </span>

                            <h4 class="mt-2 mb-1">

                                <?= htmlspecialchars(
                                    $titulo
                                ) ?>

                            </h4>

                            <small class="text-muted">

                                Publicado por

                                <strong>

                                    <?= htmlspecialchars(
                                        $autor ?? "Administração"
                                    ) ?>

                                </strong>

                                <?php if ($createdAt): ?>

                                    <span
                                        class="data-publicacao"
                                    >

                                        em

                                        <?= date(
                                            "d/m/Y H:i",
                                            strtotime($createdAt)
                                        ) ?>

                                    </span>

                                <?php endif; ?>

                            </small>

                        </div>

                    </div>

                    <?php if ($midia): ?>

                        <div class="mt-3">

                            <img
                                src="<?= htmlspecialchars($midia) ?>"
                                class="post-image rounded"
                                alt="Imagem do aviso"
                            >

                        </div>

                    <?php endif; ?>

                    <div class="mt-3">

                        <?= nl2br(
                            htmlspecialchars($conteudo)
                        ) ?>

                    </div>

                    <?php if (
                        $publicacao->getPreco() !== null
                    ): ?>

                        <div
                            class="alert alert-success mt-3 mb-0"
                        >

                            <strong>
                                💰 Preço:
                            </strong>

                            R$

                            <?= number_format(
                                $publicacao->getPreco(),
                                2,
                                ",",
                                "."
                            ) ?>

                        </div>

                    <?php endif; ?>

                    <div
                        class="alert alert-light border mt-4 mb-0"
                    >

                        <small class="text-muted">

                            👁️ Este é um comunicado oficial
                            da administração do condomínio.

                            Os moradores podem visualizar
                            o conteúdo, mas não podem
                            comentar, curtir ou interagir.

                        </small>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"
></script>
<script src="../../assets/js/script.js"></script>

</body>

</html>