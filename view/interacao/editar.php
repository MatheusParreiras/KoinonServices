<?php

require_once __DIR__ . "/../../controller/InteracaoController.php";
require_once __DIR__ . "/../../controller/PublicacaoController.php";
require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirGestao();

$controller = new InteracaoController();
$publicacaoController = new PublicacaoController();

$id = isset($_GET["id"])
    ? (int) $_GET["id"]
    : 0;

if ($id <= 0) {

    header(
        "Location: index.php"
    );

    exit;
}

$interacao =
    $controller->buscarPorId($id);

if (!$interacao) {

    header(
        "Location: index.php?erro=Interação não encontrada."
    );

    exit;
}

$publicacoes =
    $publicacaoController->listar();

$erro = "";

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
                "Tipo de interação inválido."
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

        $interacao->setPublicacaoId(
            $publicacaoId
        );

        $interacao->setTipoInteracao(
            $tipo
        );

        $interacao->setConteudoTexto(
            $conteudo
        );

        if (
            $controller->editar(
                $interacao
            )
        ) {

            header(
                "Location: index.php?sucesso=Interação atualizada com sucesso!"
            );

            exit;
        }

        $erro =
            "Não foi possível atualizar a interação.";

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
        Editar Interação - Koinon Services
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

        <h2>✏️ Editar Interação</h2>

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
                        class="form-select"
                        required
                    >

                        <option value="">
                            Selecione
                        </option>

                        <?php foreach (
                            $publicacoes
                            as $publicacao
                        ): ?>

                            <option
                                value="<?= $publicacao->getId() ?>"
                                <?= (
                                    $publicacao->getId()
                                    === $interacao->getPublicacaoId()
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

                        <option
                            value="Curtida"
                            <?= $interacao->getTipoInteracao()
                                === "Curtida"
                                ? "selected"
                                : ""
                            ?>
                        >
                            ❤️ Curtida
                        </option>

                        <option
                            value="Comentario"
                            <?= $interacao->getTipoInteracao()
                                === "Comentario"
                                ? "selected"
                                : ""
                            ?>
                        >
                            💬 Comentário
                        </option>

                        <option
                            value="Voto_Enquete"
                            <?= $interacao->getTipoInteracao()
                                === "Voto_Enquete"
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
                    ><?= htmlspecialchars(
                        $interacao->getConteudoTexto()
                        ?? ""
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

                    🗳️ O voto só pode ser utilizado em uma
                    publicação da categoria <strong>Enquete</strong>.

                </div>


                <div class="mb-3">

                    <strong>
                        Usuário:
                    </strong>

                    <?= htmlspecialchars(
                        $interacao->getUsuarioNome()
                        ?? "-"
                    ) ?>

                </div>


                <div class="mb-3">

                    <strong>
                        Data:
                    </strong>

                    <?= $interacao->getCreatedAt()
                        ? date(
                            "d/m/Y H:i",
                            strtotime(
                                $interacao->getCreatedAt()
                            )
                        )
                        : "-"
                    ?>

                </div>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Salvar alterações
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