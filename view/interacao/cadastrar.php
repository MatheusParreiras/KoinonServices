<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../../controller/InteracaoController.php";
require_once __DIR__ . "/../../controller/PublicacaoController.php";
require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirGestao();

$controller = new InteracaoController();
$publicacaoController = new PublicacaoController();

$erro = "";

$usuarioLogadoId =
    $_SESSION["usuario_id"] ?? null;

if (!$usuarioLogadoId) {

    header(
        "Location: ../login.php"
    );

    exit;
}

$publicacoes =
    $publicacaoController->listar();

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        $publicacaoId = (int)(
            $_POST["publicacao_id"] ?? 0
        );

        $tipo = trim(
            $_POST["tipo_interacao"] ?? ""
        );

        $conteudo = trim(
            $_POST["conteudo_texto"] ?? ""
        );

        $tiposPermitidos = [
            "Curtida",
            "Comentario",
            "Voto_Enquete"
        ];

        if (
            !in_array(
                $tipo,
                $tiposPermitidos,
                true
            )
        ) {

            throw new Exception(
                "Selecione um tipo de interação válido."
            );
        }

        if ($publicacaoId <= 0) {

            throw new Exception(
                "Selecione uma publicação."
            );
        }

        if (
            $tipo !== "Curtida"
            && $conteudo === ""
        ) {

            throw new Exception(
                $tipo === "Comentario"
                    ? "Digite o comentário."
                    : "Informe a opção do voto."
            );
        }

        if ($tipo === "Curtida") {
            $conteudo = null;
        }

        $interacao = new InteracaoDTO();

        $interacao->setPublicacaoId(
            $publicacaoId
        );

        $interacao->setUsuarioId(
            (int) $usuarioLogadoId
        );

        $interacao->setTipoInteracao(
            $tipo
        );

        $interacao->setConteudoTexto(
            $conteudo
        );

        if (
            $controller->cadastrar(
                $interacao
            )
        ) {

            header(
                "Location: index.php?sucesso=Interação cadastrada com sucesso!"
            );

            exit;
        }

        $erro =
            "Não foi possível cadastrar a interação.";

    } catch (Throwable $e) {

        $erro = $e->getMessage();
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

    <title>
        Nova Interação - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body class="bg-light">

<div class="container py-4">

    <div
        class="d-flex justify-content-between align-items-center mb-4"
    >

        <div>

            <h2>💬 Nova Interação</h2>

            <p class="text-muted mb-0">
                Registre curtidas, comentários ou votos.
            </p>

        </div>

        <a
            href="index.php"
            class="btn btn-secondary"
        >
            Voltar
        </a>

    </div>


    <?php if ($erro !== ""): ?>

        <div class="alert alert-danger">

            <?= htmlspecialchars($erro) ?>

        </div>

    <?php endif; ?>


    <div class="card shadow-sm">

        <div class="card-body">

            <form method="POST">

                <div class="mb-3">

                    <label class="form-label">
                        Publicação *
                    </label>

                    <select
                        name="publicacao_id"
                        id="publicacao_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            Selecione a publicação
                        </option>

                        <?php foreach (
                            $publicacoes
                            as $publicacao
                        ): ?>

                            <option
                                value="<?= $publicacao->getId() ?>"
                                data-categoria="<?= htmlspecialchars(
                                    $publicacao->getCategoria()
                                ) ?>"
                                <?= (
                                    (int)(
                                        $_POST["publicacao_id"]
                                        ?? 0
                                    )
                                    === $publicacao->getId()
                                )
                                    ? "selected"
                                    : ""
                                ?>
                            >

                                <?= htmlspecialchars(
                                    $publicacao->getTitulo()
                                ) ?>

                                —
                                <?= htmlspecialchars(
                                    $publicacao->getCategoria()
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="mb-3">

                    <label class="form-label">
                        Tipo de interação *
                    </label>

                    <select
                        name="tipo_interacao"
                        id="tipo_interacao"
                        class="form-select"
                        required
                    >

                        <option value="">
                            Selecione
                        </option>

                        <option
                            value="Curtida"
                            <?= (
                                ($_POST["tipo_interacao"] ?? "")
                                === "Curtida"
                            )
                                ? "selected"
                                : ""
                            ?>
                        >
                            ❤️ Curtida
                        </option>

                        <option
                            value="Comentario"
                            <?= (
                                ($_POST["tipo_interacao"] ?? "")
                                === "Comentario"
                            )
                                ? "selected"
                                : ""
                            ?>
                        >
                            💬 Comentário
                        </option>

                        <option
                            value="Voto_Enquete"
                            <?= (
                                ($_POST["tipo_interacao"] ?? "")
                                === "Voto_Enquete"
                            )
                                ? "selected"
                                : ""
                            ?>
                        >
                            🗳️ Voto em Enquete
                        </option>

                    </select>

                </div>


                <div
                    class="mb-3"
                    id="conteudoContainer"
                >

                    <label
                        class="form-label"
                        id="conteudoLabel"
                    >
                        Conteúdo
                    </label>

                    <textarea
                        name="conteudo_texto"
                        id="conteudo_texto"
                        class="form-control"
                        rows="5"
                        placeholder="Digite o comentário ou a opção do voto..."
                    ><?= htmlspecialchars(
                        $_POST["conteudo_texto"] ?? ""
                    ) ?></textarea>

                </div>


                <div
                    id="curtidaAviso"
                    class="alert alert-info d-none"
                >

                    ❤️ Uma curtida não precisa de texto.

                </div>


                <div
                    id="votoAviso"
                    class="alert alert-warning d-none"
                >

                    🗳️ O voto só pode ser registrado em uma
                    publicação da categoria <strong>Enquete</strong>.

                </div>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Salvar interação
                </button>

                <a
                    href="index.php"
                    class="btn btn-secondary"
                >
                    Cancelar
                </a>

            </form>

        </div>

    </div>

</div>


<script>

const tipo =
    document.getElementById("tipo_interacao");

const conteudoContainer =
    document.getElementById("conteudoContainer");

const conteudo =
    document.getElementById("conteudo_texto");

const conteudoLabel =
    document.getElementById("conteudoLabel");

const curtidaAviso =
    document.getElementById("curtidaAviso");

const votoAviso =
    document.getElementById("votoAviso");

function atualizarFormulario() {

    const valor = tipo.value;

    curtidaAviso.classList.add("d-none");
    votoAviso.classList.add("d-none");

    if (valor === "Curtida") {

        conteudoContainer.classList.add("d-none");

        conteudo.required = false;

        curtidaAviso.classList.remove("d-none");

    } else {

        conteudoContainer.classList.remove("d-none");

        conteudo.required = true;

        if (valor === "Comentario") {

            conteudoLabel.textContent =
                "Comentário *";

            conteudo.placeholder =
                "Digite o comentário...";

        } else if (
            valor === "Voto_Enquete"
        ) {

            conteudoLabel.textContent =
                "Opção do voto *";

            conteudo.placeholder =
                "Digite a opção escolhida...";

            votoAviso.classList.remove(
                "d-none"
            );

        } else {

            conteudoLabel.textContent =
                "Conteúdo";

            conteudo.placeholder =
                "Digite o conteúdo...";
        }
    }
}

tipo.addEventListener(
    "change",
    atualizarFormulario
);

atualizarFormulario();

</script>
<script src="../../assets/js/script.js"></script>

</body>

</html>