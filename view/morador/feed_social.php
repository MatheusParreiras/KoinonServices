<?php

require_once __DIR__ . "/../../service/Sessao.php";

Sessao::iniciar();

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/PublicacaoController.php";
require_once __DIR__ . "/../../model/dto/PublicacaoDTO.php";

PermissaoController::exigirPerfil(["Morador"]);

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

$usuarioId = (int) $_SESSION["usuario_id"];

$controller = new PublicacaoController();

$mensagem = "";
$tipoMensagem = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $titulo = trim($_POST["titulo"] ?? "");
    $conteudo = trim($_POST["conteudo"] ?? "");

    if ($titulo === "" || $conteudo === "") {

        $mensagem = "Preencha o título e o conteúdo da publicação.";
        $tipoMensagem = "danger";

    } else {

        $publicacao = new PublicacaoDTO();

        $publicacao->setUsuarioId($usuarioId);
        $publicacao->setCategoria("Feed_Social");
        $publicacao->setTitulo($titulo);
        $publicacao->setConteudo($conteudo);
        $publicacao->setStatus("Ativo");

        if ($controller->cadastrar($publicacao)) {

            header("Location: feed_social.php?sucesso=1");
            exit;

        } else {

            $mensagem = "Não foi possível publicar. Tente novamente.";
            $tipoMensagem = "danger";
        }
    }
}

if (isset($_GET["sucesso"])) {
    $mensagem = "Publicação criada com sucesso!";
    $tipoMensagem = "success";
}

$publicacoes = $controller->listar();

$feed = [];

foreach ($publicacoes as $publicacao) {

    if (
        $publicacao->getCategoria() === "Feed_Social" &&
        $publicacao->getStatus() === "Ativo"
    ) {
        $feed[] = $publicacao;
    }
}

usort($feed, function ($a, $b) {

    $dataA = $a->getCreatedAt() ?? "";
    $dataB = $b->getCreatedAt() ?? "";

    return strtotime($dataB) <=> strtotime($dataA);
});

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Feed Social - Koinon Services</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >

    <style>

        body {
            background-color: #f5f6f8;
        }

        .feed-container {
            max-width: 850px;
            margin: 30px auto;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .publicacao {
            margin-bottom: 20px;
        }

        .avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background-color: #0d6efd;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
        }

        .autor {
            font-weight: 600;
        }

        .data-publicacao {
            font-size: 13px;
            color: #6c757d;
        }

        .conteudo {
            white-space: pre-line;
            line-height: 1.6;
        }

    </style>


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container feed-container">

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2 class="mb-1">📰 Feed Social</h2>

            <p class="text-muted mb-0">
                Compartilhe informações com os moradores do condomínio.
            </p>

        </div>

        <a
            href="index.php"
            class="btn btn-outline-secondary"
        >
            Voltar
        </a>

    </div>

    <?php if ($mensagem !== ""): ?>

        <div class="alert alert-<?= htmlspecialchars($tipoMensagem) ?> alert-dismissible fade show">

            <?= htmlspecialchars($mensagem) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- NOVA PUBLICAÇÃO -->

    <div class="card p-4 mb-4">

        <h5 class="mb-3">Criar publicação</h5>

        <form method="POST">

            <div class="mb-3">

                <label class="form-label">
                    Título
                </label>

                <input
                    type="text"
                    name="titulo"
                    class="form-control"
                    maxlength="255"
                    placeholder="Digite o título da publicação"
                    required
                >

            </div>


            <div class="mb-3">

                <label class="form-label">
                    Conteúdo
                </label>

                <textarea
                    name="conteudo"
                    class="form-control"
                    rows="4"
                    placeholder="O que você deseja compartilhar?"
                    required
                ></textarea>

            </div>


            <div class="text-end">

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Publicar
                </button>

            </div>

        </form>

    </div>


    <!-- FEED -->

    <?php if (empty($feed)): ?>

        <div class="card p-5 text-center">

            <div style="font-size: 45px;">
                📰
            </div>

            <h5 class="mt-3">
                Nenhuma publicação ainda
            </h5>

            <p class="text-muted mb-0">
                Seja o primeiro morador a publicar algo no feed.
            </p>

        </div>

    <?php else: ?>

        <?php foreach ($feed as $publicacao): ?>

            <?php

            $nomeAutor = $publicacao->getUsuarioNome();

            if (!$nomeAutor) {
                $nomeAutor = "Morador";
            }

            $primeiraLetra = strtoupper(
                substr(
                    $nomeAutor,
                    0,
                    1
                )
            );

            $data = $publicacao->getCreatedAt();

            if ($data) {

                $dataFormatada = date(
                    "d/m/Y H:i",
                    strtotime($data)
                );

            } else {

                $dataFormatada = "";
            }

            ?>

            <div class="card p-4 publicacao">

                <div class="d-flex align-items-center mb-3">

                    <div class="avatar me-3">

                        <?= htmlspecialchars($primeiraLetra) ?>

                    </div>

                    <div>

                        <div class="autor">

                            <?= htmlspecialchars($nomeAutor) ?>

                        </div>

                        <div class="data-publicacao">

                            <?= htmlspecialchars($dataFormatada) ?>

                        </div>

                    </div>

                </div>


                <h5 class="mb-2">

                    <?= htmlspecialchars(
                        $publicacao->getTitulo()
                    ) ?>

                </h5>


                <div class="conteudo text-secondary">

                    <?= htmlspecialchars(
                        $publicacao->getConteudo()
                    ) ?>

                </div>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous">
</script>
<script src="../../assets/js/script.js"></script>

</body>

</html>