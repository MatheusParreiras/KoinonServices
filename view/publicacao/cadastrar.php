<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/PublicacaoController.php";
require_once __DIR__ . "/../../model/dto/PublicacaoDTO.php";

PermissaoController::exigirGestao();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

$erro = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        $usuarioId = (int) $_SESSION["usuario_id"];

        $categoria =
            trim($_POST["categoria"] ?? "");

        $titulo =
            trim($_POST["titulo"] ?? "");

        $conteudo =
            trim($_POST["conteudo"] ?? "");

        $preco =
            trim($_POST["preco"] ?? "");

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
            "Enquete"
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

        /*
         * ======================================================
         * VALIDAÇÃO DAS OPÇÕES DA ENQUETE
         * ======================================================
         */

        $opcoesValidas = [];

        if ($categoria === "Enquete") {

            if (!is_array($opcoes)) {
                $opcoes = [];
            }

            foreach ($opcoes as $opcao) {

                $opcao = trim((string) $opcao);

                if ($opcao !== "") {
                    $opcoesValidas[] = $opcao;
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

            /*
             * Evita opções repetidas.
             */
            $opcoesComparacao = array_map(
                fn($opcao) => mb_strtolower($opcao),
                $opcoesValidas
            );

            if (
                count($opcoesComparacao) !==
                count(array_unique($opcoesComparacao))
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

            /*
             * Preço só faz sentido para classificados.
             */
            if ($categoria !== "Classificados") {
                $precoFinal = null;
            }
        }

        /*
         * ======================================================
         * UPLOAD
         * ======================================================
         */

        $midiaUrl = null;

        if (
            isset($_FILES["midia"]) &&
            $_FILES["midia"]["error"] !== UPLOAD_ERR_NO_FILE
        ) {

            if (
                $_FILES["midia"]["error"] !==
                UPLOAD_ERR_OK
            ) {
                throw new Exception(
                    "Erro ao enviar a imagem."
                );
            }

            $arquivo = $_FILES["midia"];

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
                    "Não foi possível salvar a imagem."
                );
            }

            $midiaUrl =
                "../../assets/img/publicacoes/" .
                $nomeArquivo;
        }

        /*
         * ======================================================
         * DTO
         * ======================================================
         */

        $publicacao =
            new PublicacaoDTO();

        $publicacao->setUsuarioId(
            $usuarioId
        );

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
            "Ativo"
        );

        $controller =
            new PublicacaoController();

        if (
            $controller->cadastrarComOpcoes(
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
            "Não foi possível cadastrar a publicação.";

    } catch (Throwable $e) {

        $erro =
            $e->getMessage();
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
        Nova Publicação - Koinon Services
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
            Nova Publicação
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

                <option value="">
                    Selecione
                </option>

                <option value="Aviso_Sindico">
                    Aviso do Síndico
                </option>

                <option value="Feed_Social">
                    Feed Social
                </option>

                <option value="Classificados">
                    Classificados
                </option>

                <option value="Enquete">
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
            ></textarea>

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

                <p class="text-muted">
                    Informe pelo menos 2 opções para o morador escolher.
                </p>

                <div id="opcoesContainer">

                    <div class="input-group mb-2 opcao-item">

                        <span class="input-group-text">
                            1
                        </span>

                        <input
                            type="text"
                            name="opcoes[]"
                            class="form-control"
                            maxlength="150"
                            placeholder="Ex.: Sim"
                        >

                    </div>

                    <div class="input-group mb-2 opcao-item">

                        <span class="input-group-text">
                            2
                        </span>

                        <input
                            type="text"
                            name="opcoes[]"
                            class="form-control"
                            maxlength="150"
                            placeholder="Ex.: Não"
                        >

                    </div>

                </div>

                <button
                    type="button"
                    id="btnAdicionarOpcao"
                    class="btn btn-outline-info btn-sm"
                >
                    + Adicionar opção
                </button>

            </div>

        </div>

        <div class="mb-3">

            <label class="form-label">
                Imagem
            </label>

            <input
                type="file"
                name="midia"
                class="form-control"
                accept="image/jpeg,image/png,image/webp"
            >

            <div class="form-text">
                JPG, PNG ou WEBP — máximo 5 MB.
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
                placeholder="Ex.: 150.00"
            >

            <div class="form-text">
                Use somente para anúncios/classificados.
            </div>

        </div>

        <button
            type="submit"
            class="btn btn-primary"
        >
            Publicar
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

        areaEnquete.style.display = "block";

    } else {

        areaEnquete.style.display = "none";
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

        const numero =
            quantidade + 1;

        const div =
            document.createElement("div");

        div.className =
            "input-group mb-2 opcao-item";

        div.innerHTML = `
            <span class="input-group-text">
                ${numero}
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

</script>
<script src="../../assets/js/script.js"></script>

</body>

</html>
