<?php

require_once __DIR__ . "/../../service/Sessao.php";

Sessao::iniciar();

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/PublicacaoController.php";

PermissaoController::exigirPerfil([
    "Morador"
]);

$usuarioId = $_SESSION["usuario_id"] ?? null;

if (!$usuarioId) {
    header("Location: ../login.php");
    exit;
}

$controller = new PublicacaoController();

$mensagem = "";
$tipoMensagem = "";


// ==========================================================
// VOTAÇÃO
// ==========================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $publicacaoId = filter_input(
        INPUT_POST,
        "publicacao_id",
        FILTER_VALIDATE_INT
    );

    $opcaoId = filter_input(
        INPUT_POST,
        "opcao_id",
        FILTER_VALIDATE_INT
    );

    if (!$publicacaoId || !$opcaoId) {

        $mensagem = "Selecione uma opção para votar.";
        $tipoMensagem = "danger";

    } else {

        $enquete = $controller->buscarPorId(
            $publicacaoId
        );

        if (
            !$enquete ||
            $enquete->getCategoria() !== "Enquete" ||
            $enquete->getStatus() !== "Ativo"
        ) {

            $mensagem = "Esta enquete não está disponível.";
            $tipoMensagem = "danger";

        } else {

            $jaVotou =
                $controller->usuarioJaVotou(
                    $publicacaoId,
                    (int) $usuarioId
                );

            if ($jaVotou) {

                $mensagem =
                    "Você já votou nesta enquete.";

                $tipoMensagem = "warning";

            } else {

                $resultado =
                    $controller->votarEnquete(
                        $publicacaoId,
                        (int) $usuarioId,
                        $opcaoId
                    );

                if ($resultado) {

                    $mensagem =
                        "Seu voto foi registrado com sucesso!";

                    $tipoMensagem = "success";

                } else {

                    $mensagem =
                        "Não foi possível registrar seu voto.";

                    $tipoMensagem = "danger";
                }
            }
        }
    }
}


// ==========================================================
// LISTAR ENQUETES
// ==========================================================

$todasPublicacoes =
    $controller->listar();

$enquetes = [];

foreach ($todasPublicacoes as $publicacao) {

    if ($publicacao->getStatus() !== "Ativo") {
        continue;
    }

    if ($publicacao->getCategoria() !== "Enquete") {
        continue;
    }

    $enquetes[] = $publicacao;
}


// ==========================================================
// MAIS RECENTES PRIMEIRO
// ==========================================================

usort(
    $enquetes,
    function ($a, $b) {

        return strcmp(
            (string) $b->getCreatedAt(),
            (string) $a->getCreatedAt()
        );
    }
);

?>

<!DOCTYPE html>

<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Enquetes - Koinon Services</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body class="bg-light">

<div class="container py-4">


    <!-- =====================================================
         CABEÇALHO
    ====================================================== -->

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2 class="mb-1">
                📊 Enquetes
            </h2>

            <p class="text-muted mb-0">
                Participe das decisões do condomínio.
            </p>

        </div>

        <a
            href="index.php"
            class="btn btn-outline-secondary"
        >
            ← Voltar
        </a>

    </div>


    <!-- =====================================================
         MENSAGEM
    ====================================================== -->

    <?php if ($mensagem !== ""): ?>

        <div
            class="alert alert-<?= htmlspecialchars($tipoMensagem) ?>"
            role="alert"
        >

            <?= htmlspecialchars($mensagem) ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         ENQUETES
    ====================================================== -->

    <?php if (empty($enquetes)): ?>

        <div class="card shadow-sm">

            <div class="card-body text-center py-5">

                <div class="fs-1 mb-3">
                    📊
                </div>

                <h5>
                    Nenhuma enquete disponível
                </h5>

                <p class="text-muted mb-0">
                    No momento não existem enquetes ativas.
                </p>

            </div>

        </div>

    <?php else: ?>


        <?php foreach ($enquetes as $enquete): ?>

            <?php

            $publicacaoId =
                $enquete->getId();

            $opcoes =
                $controller->listarOpcoesEnquete(
                    $publicacaoId
                );

            $jaVotou =
                $controller->usuarioJaVotou(
                    $publicacaoId,
                    (int) $usuarioId
                );

            $totalVotos =
                $controller->totalVotosEnquete(
                    $publicacaoId
                );

            ?>


            <div class="card shadow-sm mb-4">


                <!-- =================================================
                     TÍTULO
                ================================================== -->

                <div class="card-header">

                    <h5 class="mb-1">

                        <?= htmlspecialchars(
                            $enquete->getTitulo()
                        ) ?>

                    </h5>


                    <?php if ($enquete->getCreatedAt()): ?>

                        <small class="text-muted">

                            Publicada em

                            <?= date(
                                "d/m/Y H:i",
                                strtotime(
                                    $enquete->getCreatedAt()
                                )
                            ) ?>

                        </small>

                    <?php endif; ?>

                </div>


                <!-- =================================================
                     CONTEÚDO
                ================================================== -->

                <div class="card-body">

                    <?php if (
                        trim(
                            $enquete->getConteudo()
                        ) !== ""
                    ): ?>

                        <p>

                            <?= nl2br(
                                htmlspecialchars(
                                    $enquete->getConteudo()
                                )
                            ) ?>

                        </p>

                    <?php endif; ?>


                    <?php if (empty($opcoes)): ?>

                        <div class="alert alert-warning mb-0">

                            Esta enquete não possui opções cadastradas.

                        </div>

                    <?php else: ?>


                        <!-- =================================================
                             FORMULÁRIO DE VOTO
                        ================================================== -->

                        <?php if (!$jaVotou): ?>

                            <form method="POST">

                                <input
                                    type="hidden"
                                    name="publicacao_id"
                                    value="<?= (int) $publicacaoId ?>"
                                >


                                <div class="mb-3">

                                    <?php foreach (
                                        $opcoes
                                        as $opcao
                                    ): ?>

                                        <div class="form-check mb-2">

                                            <input
                                                class="form-check-input"
                                                type="radio"
                                                name="opcao_id"
                                                id="opcao_<?= (int) $opcao["id"] ?>_<?= (int) $publicacaoId ?>"
                                                value="<?= (int) $opcao["id"] ?>"
                                                required
                                            >

                                            <label
                                                class="form-check-label"
                                                for="opcao_<?= (int) $opcao["id"] ?>_<?= (int) $publicacaoId ?>"
                                            >

                                                <?= htmlspecialchars(
                                                    $opcao["descricao"]
                                                ) ?>

                                            </label>

                                        </div>

                                    <?php endforeach; ?>

                                </div>


                                <button
                                    type="submit"
                                    class="btn btn-primary"
                                >

                                    🗳️ Votar

                                </button>

                            </form>

                        <?php else: ?>

                            <div class="alert alert-success">

                                <strong>
                                    ✓ Você já votou nesta enquete.
                                </strong>

                                <br>

                                Confira abaixo o resultado atual.

                            </div>

                        <?php endif; ?>


                        <!-- =================================================
                             RESULTADOS
                        ================================================== -->

                        <hr class="my-4">


                        <h6 class="mb-3">

                            📈 Resultado da enquete

                        </h6>


                        <?php foreach (
                            $opcoes
                            as $opcao
                        ): ?>

                            <?php

                            $votos =
                                $controller->contarVotosOpcao(
                                    (int) $opcao["id"]
                                );

                            $percentual = 0;

                            if ($totalVotos > 0) {

                                $percentual =
                                    ($votos / $totalVotos) * 100;
                            }

                            ?>


                            <div class="mb-3">


                                <div class="d-flex justify-content-between">

                                    <span>

                                        <?= htmlspecialchars(
                                            $opcao["descricao"]
                                        ) ?>

                                    </span>


                                    <span class="text-muted">

                                        <?= $votos ?>
                                        voto(s)

                                    </span>

                                </div>


                                <div
                                    class="progress"
                                    role="progressbar"
                                    aria-valuenow="<?= round($percentual) ?>"
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                >

                                    <div
                                        class="progress-bar"
                                        style="width: <?= $percentual ?>%;"
                                    >

                                        <?= round($percentual) ?>%

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>


                        <small class="text-muted">

                            Total de votos:

                            <strong>
                                <?= $totalVotos ?>
                            </strong>

                        </small>


                    <?php endif; ?>

                </div>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>


</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>
<script src="../../assets/js/script.js"></script>

</body>

</html>