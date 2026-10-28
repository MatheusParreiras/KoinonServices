```php
<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/PublicacaoController.php";
require_once __DIR__ . "/../../service/Erro.php";

PermissaoController::exigirGestao();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

$id =
    isset($_GET["id"])
        ? (int) $_GET["id"]
        : 0;

if ($id <= 0) {
    header("Location: index.php");
    exit;
}

$controller =
    new PublicacaoController();

$publicacao =
    $controller->buscarPorId($id);

if (!$publicacao) {
    header("Location: index.php");
    exit;
}

$erro = "";

$opcoesExistentes = [];

if (
    $publicacao->getCategoria() === "Enquete"
) {
    $opcoesExistentes =
        $controller->listarOpcoesEnquete(
            $id
        );
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        $categoria =
            trim($_POST["categoria"] ?? "");

        $titulo =
            trim($_POST["titulo"] ?? "");

        $conteudo =
            trim($_POST["conteudo"] ?? "");

        $preco =
            trim($_POST["preco"] ?? "");

        $status =
            trim(
                $_POST["status"] ?? "Ativo"
            );

        $opcoes =
            $_POST["opcoes"] ?? [];

        if (
            $categoria === "" ||
            $titulo === "" ||
            $conteudo === ""
        ) {
            throw new Exception(
                "Preencha todos os campos obrigatórios."
            );
        }

        $categoriasPermitidas = [
            "Aviso_Sindico",
            "Feed_Social",
            "Classificados",
            "Achados_Perdidos",
            "Enquete"
        ];

        $statusPermitidos = [
            "Ativo",
            "Encerrado",
            "Arquivado",
            "Removido"
        ];

        if (
            !in_array(
                $categoria,
                $categoriasPermitidas,
                true
            )
        ) {
            throw new Exception(
                "Categoria inválida."
            );
        }

        if (
            !in_array(
                $status,
                $statusPermitidos,
                true
            )
        ) {
            throw new Exception(
                "Status inválido."
            );
        }

        /*
         * ======================================================
         * OPÇÕES
         * ======================================================
         */

        $opcoesValidas = [];

        if ($categoria === "Enquete") {

            foreach ($opcoes as $opcao) {

                $opcao =
                    trim((string) $opcao);

                if ($opcao !== "") {
                    $opcoesValidas[] = $opcao;
                }
            }

            /*
             * Se for uma enquete que já tinha opções,
             * mantém as opções existentes caso o formulário
             * não as envie.
             */
            if (
                count($opcoesValidas) < 2 &&
                !empty($opcoesExistentes)
            ) {
                foreach (
                    $opcoesExistentes
                    as $opcaoExistente
                ) {
                    $opcoesValidas[] =
                        $opcaoExistente["descricao"];
                }
            }

            if (count($opcoesValidas) < 2) {
                throw new Exception(
                    "Uma enquete precisa ter pelo menos 2 opções."
                );
            }

            if (count($opcoesValidas) > 10) {
                throw new Exception(
                    "Uma enquete pode ter no máximo 10 opções."
                );
            }

            $comparacao =
                array_map(
                    fn($opcao) =>
                        mb_strtolower($opcao),
                    $opcoesValidas
                );

            if (
                count($comparacao) !==
                count(array_unique($comparacao))
            ) {
                throw new Exception(
                    "As opções da enquete não podem ser repetidas."
                );
            }

        } else {

            $opcoesValidas = [];
        }

        /*
         * ======================================================
         * PREÇO
         * ======================================================
         */

        $precoFinal = null;

        if ($preco !== "") {

            $precoFinal =
                (float) str_replace(
                    ",",
                    ".",
                    $preco
                );

            if ($precoFinal < 0) {
                throw new Exception(
                    "O preço não pode ser negativo."
                );
            }

            if ($categoria !== "Classificados") {
                $precoFinal = null;
            }
        }

        /*
         * ======================================================
         * IMAGEM
         * ======================================================
         */

        $midiaUrl =
            $publicacao->getMidiaUrl();

        if (
            isset($_FILES["midia"]) &&
            $_FILES["midia"]["error"] !==
                UPLOAD_ERR_NO_FILE
        ) {

            if (
                $_FILES["midia"]["error"] !==
                UPLOAD_ERR_OK
            ) {
                throw new Exception(
                    "Erro ao enviar a nova imagem."
                );
            }

            $arquivo =
                $_FILES["midia"];

            $tiposPermitidos = [
                "image/jpeg",
                "image/png",
                "image/webp"
            ];

            if (
                !in_array(
                    $arquivo["type"],
                    $tiposPermitidos,
                    true
                )
            ) {
                throw new Exception(
                    "Formato inválido. Use JPG, PNG ou WEBP."
                );
            }

            if (
                $arquivo["size"] >
                5 * 1024 * 1024
            ) {
                throw new Exception(
                    "A imagem deve ter no máximo 5 MB."
                );
            }

            $pasta =
                __DIR__ .
                "/../../assets/img/publicacoes/";

            if (!is_dir($pasta)) {
                mkdir(
                    $pasta,
                    0777,
                    true
                );
            }

            $extensao =
                strtolower(
                    pathinfo(
                        $arquivo["name"],
                        PATHINFO_EXTENSION
                    )
                );

            $nomeArquivo =
                "publicacao_" .
                date("Ymd_His") .
                "_" .
                uniqid() .
                "." .
                $extensao;

            $destino =
                $pasta .
                $nomeArquivo;

            if (
                !move_uploaded_file(
                    $arquivo["tmp_name"],
                    $destino
                )
            ) {
                throw new Exception(
                    "Não foi possível salvar a nova imagem."
                );
            }

            $midiaUrl =
                "../../assets/img/publicacoes/" .
                $nomeArquivo;
        }

        /*
         * ======================================================
         * ATUALIZA DTO
         * ======================================================
         */

        $publicacao->setCategoria(
            $categoria
        );

        $publicacao->setTitulo(
            $titulo
        );

        $publicacao->setConteudo(
            $conteudo
        );

        $publicacao->setMidiaUrl(
            $midiaUrl
        );

        $publicacao->setPreco(
            $precoFinal
        );

        $publicacao->setStatus(
            $status
        );

        if (
            $controller->editarComOpcoes(
                $publicacao,
                $opcoesValidas
            )
        ) {

            header(
                "Location: index.php"
            );

            exit;
        }

        $erro =
            "Não foi possível atualizar a publicação.";

    } catch (Throwable $e) {

        $erro =
            Erro::mensagem($e);
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
        Editar Publicação - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container mt-4">

    <div class="d-flex justify-content-between align-items-center mb-4">

        <h2>
            Editar Publicação
        </h2>

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

    <form
        method="POST"
        enctype="multipart/form-data"
    >

        <div class="mb-3">

            <label class="form-label">
                Categoria *
            </label>

            <select
                name="categoria"
                id="categoria"
                class="form-select"
                required
            >

                <option
                    value="Aviso_Sindico"
                    <?= $publicacao->getCategoria() === "Aviso_Sindico"
                        ? "selected"
                        : "" ?>
                >
                    Aviso do Síndico
                </option>

                <option
                    value="Feed_Social"
                    <?= $publicacao->getCategoria() === "Feed_Social"
                        ? "selected"
                        : "" ?>
                >
                    Feed Social
                </option>

                <option
                    value="Classificados"
                    <?= $publicacao->getCategoria() === "Classificados"
                        ? "selected"
                        : "" ?>
                >
                    Classificados
                </option>

                <?php if ($publicacao->getCategoria() === "Achados_Perdidos"): ?>
                    <option value="Achados_Perdidos" selected>
                        Achados e Perdidos
                    </option>
                <?php endif; ?>

                <option
                    value="Enquete"
                    <?= $publicacao->getCategoria() === "Enquete"
                        ? "selected"
                        : "" ?>
                >
                    Enquete
                </option>

            </select>

        </div>

        <div class="mb-3">

            <label class="form-label">
                Título *
            </label>

            <input
                type="text"
                name="titulo"
                class="form-control"
                maxlength="150"
                value="<?= htmlspecialchars(
                    $publicacao->getTitulo()
                ) ?>"
                required
            >

        </div>

        <div class="mb-3">

            <label class="form-label">
                Conteúdo *
            </label>

            <textarea
                name="conteudo"
                class="form-control"
                rows="5"
                required
            ><?= htmlspecialchars(
                $publicacao->getConteudo()
            ) ?></textarea>

        </div>

        <!-- OPÇÕES DA ENQUETE -->

        <div
            id="areaEnquete"
            class="card border-info mb-4"
            style="display:none;"
        >

            <div class="card-header bg-info text-white">

                <strong>
                    🗳️ Opções da Enquete
                </strong>

            </div>

            <div class="card-body">

                <?php if (!empty($opcoesExistentes)): ?>

                    <div id="opcoesContainer">

                        <?php foreach (
                            $opcoesExistentes
                            as $index => $opcao
                        ): ?>

                            <div
                                class="input-group mb-2 opcao-item"
                            >

                                <span class="input-group-text">
                                    <?= $index + 1 ?>
                                </span>

                                <input
                                    type="text"
                                    name="opcoes[]"
                                    class="form-control"
                                    maxlength="150"
                                    value="<?= htmlspecialchars(
                                        $opcao["descricao"]
                                    ) ?>"
                                    required
                                >

                                <button
                                    type="button"
                                    class="btn btn-outline-danger remover-opcao"
                                >
                                    Remover
                                </button>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div id="opcoesContainer">

                        <div
                            class="input-group mb-2 opcao-item"
                        >

                            <span class="input-group-text">
                                1
                            </span>

                            <input
                                type="text"
                                name="opcoes[]"
                                class="form-control"
                                maxlength="150"
                            >

                        </div>

                        <div
                            class="input-group mb-2 opcao-item"
                        >

                            <span class="input-group-text">
                                2
                            </span>

                            <input
                                type="text"
                                name="opcoes[]"
                                class="form-control"
                                maxlength="150"
                            >

                        </div>

                    </div>

                <?php endif; ?>

                <button
                    type="button"
                    id="btnAdicionarOpcao"
                    class="btn btn-outline-info btn-sm"
                >
                    + Adicionar opção
                </button>

                <div class="form-text mt-2">
                    Se esta enquete já possuir votos,
                    as opções existentes serão preservadas.
                </div>

            </div>

        </div>

        <?php if ($publicacao->getMidiaUrl()): ?>

            <div class="mb-3">

                <label class="form-label">
                    Imagem atual
                </label>

                <br>

                <img
                    src="<?= htmlspecialchars(
                        $publicacao->getMidiaUrl()
                    ) ?>"
                    alt="Imagem da publicação"
                    style="
                        max-width:300px;
                        max-height:200px;
                        object-fit:cover;
                    "
                    class="img-thumbnail"
                >

            </div>

        <?php endif; ?>

        <div class="mb-3">

            <label class="form-label">
                Trocar imagem
            </label>

            <input
                type="file"
                name="midia"
                class="form-control"
                accept="image/jpeg,image/png,image/webp"
            >

            <div class="form-text">
                Se não escolher uma nova imagem,
                a atual será mantida.
            </div>

        </div>

        <div class="mb-3">

            <label class="form-label">
                Preço
            </label>

            <input
                type="number"
                name="preco"
                class="form-control"
                step="0.01"
                min="0"
                value="<?= $publicacao->getPreco() !== null
                    ? htmlspecialchars(
                        (string) $publicacao->getPreco()
                    )
                    : "" ?>"
            >

            <div class="form-text">
                Use somente para classificados.
            </div>

        </div>

        <div class="mb-3">

            <label class="form-label">
                Status
            </label>

            <select
                name="status"
                class="form-select"
            >

                <option
                    value="Ativo"
                    <?= $publicacao->getStatus() === "Ativo"
                        ? "selected"
                        : "" ?>
                >
                    Ativo
                </option>

                <option
                    value="Encerrado"
                    <?= $publicacao->getStatus() === "Encerrado"
                        ? "selected"
                        : "" ?>
                >
                    Encerrado
                </option>

                <option
                    value="Arquivado"
                    <?= $publicacao->getStatus() === "Arquivado"
                        ? "selected"
                        : "" ?>
                >
                    Arquivado
                </option>

                <option
                    value="Removido"
                    <?= $publicacao->getStatus() === "Removido"
                        ? "selected"
                        : "" ?>
                >
                    Removido
                </option>

            </select>

        </div>

        <button
            type="submit"
            class="btn btn-primary"
        >
            Salvar Alterações
        </button>

    </form>

</div>

<script>

const categoria =
    document.getElementById("categoria");

const areaEnquete =
    document.getElementById("areaEnquete");

const opcoesContainer =
    document.getElementById("opcoesContainer");

const btnAdicionarOpcao =
    document.getElementById("btnAdicionarOpcao");

function atualizarEnquete() {

    if (categoria.value === "Enquete") {

        areaEnquete.style.display =
            "block";

    } else {

        areaEnquete.style.display =
            "none";
    }
}

categoria.addEventListener(
    "change",
    atualizarEnquete
);

btnAdicionarOpcao.addEventListener(
    "click",
    function () {

        const quantidade =
            opcoesContainer.querySelectorAll(
                ".opcao-item"
            ).length;

        if (quantidade >= 10) {

            alert(
                "Uma enquete pode ter no máximo 10 opções."
            );

            return;
        }

        const div =
            document.createElement("div");

        div.className =
            "input-group mb-2 opcao-item";

        div.innerHTML = `
            <span class="input-group-text">
                ${quantidade + 1}
            </span>

            <input
                type="text"
                name="opcoes[]"
                class="form-control"
                maxlength="150"
                placeholder="Digite a opção"
            >

            <button
                type="button"
                class="btn btn-outline-danger remover-opcao"
            >
                Remover
            </button>
        `;

        opcoesContainer.appendChild(div);

        atualizarNumeracao();
    }
);

opcoesContainer.addEventListener(
    "click",
    function (event) {

        if (
            event.target.classList.contains(
                "remover-opcao"
            )
        ) {

            const itens =
                opcoesContainer.querySelectorAll(
                    ".opcao-item"
                );

            if (itens.length <= 2) {

                alert(
                    "A enquete precisa ter pelo menos 2 opções."
                );

                return;
            }

            event.target
                .closest(".opcao-item")
                .remove();

            atualizarNumeracao();
        }
    }
);

function atualizarNumeracao() {

    const itens =
        opcoesContainer.querySelectorAll(
            ".opcao-item"
        );

    itens.forEach(
        function (item, index) {

            item.querySelector(
                ".input-group-text"
            ).textContent =
                index + 1;
        }
    );
}

atualizarEnquete();

</script>
<script src="../../assets/js/script.js"></script>

</body>

</html>
```
