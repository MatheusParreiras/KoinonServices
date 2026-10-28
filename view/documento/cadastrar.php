<?php

require_once __DIR__ . "/../../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../service/Erro.php";

PermissaoController::exigirGestao();

require_once __DIR__ . "/../../controller/DocumentoController.php";

$controller = new DocumentoController();

$erro = "";

$usuarioLogadoId =
    $_SESSION["usuario_id"] ?? null;

if (!$usuarioLogadoId) {

    header(
        "Location: ../login.php"
    );

    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        $titulo = trim(
            $_POST["titulo"] ?? ""
        );

        $categoria = trim(
            $_POST["categoria"] ?? ""
        );

        $descricao = trim(
            $_POST["descricao"] ?? ""
        );

        if ($titulo === "") {
            throw new Exception(
                "Informe o título do documento."
            );
        }

        if ($categoria === "") {
            throw new Exception(
                "Selecione a categoria."
            );
        }

        if (
            !isset($_FILES["arquivo"])
            ||
            $_FILES["arquivo"]["error"]
            !== UPLOAD_ERR_OK
        ) {
            throw new Exception(
                "Selecione um arquivo."
            );
        }

        $arquivo = $_FILES["arquivo"];

        /*
         * Limite de 10 MB.
         */
        $tamanhoMaximo =
            10 * 1024 * 1024;

        if (
            $arquivo["size"]
            > $tamanhoMaximo
        ) {

            throw new Exception(
                "O arquivo não pode ultrapassar 10 MB."
            );
        }

        /*
         * Verificação da extensão.
         */
        $extensao = strtolower(
            pathinfo(
                $arquivo["name"],
                PATHINFO_EXTENSION
            )
        );

        if ($extensao !== "pdf") {

            throw new Exception(
                "Apenas arquivos PDF são permitidos."
            );
        }

        /*
         * Verificação do MIME.
         */
        $finfo = new finfo(
            FILEINFO_MIME_TYPE
        );

        $mime = $finfo->file(
            $arquivo["tmp_name"]
        );

        if ($mime !== "application/pdf") {

            throw new Exception(
                "O arquivo selecionado não é um PDF válido."
            );
        }

        /*
         * Pasta de destino.
         */
        $pastaDestino =
            __DIR__
            . "/../../assets/documentos/";

        if (
            !is_dir($pastaDestino)
        ) {

            mkdir(
                $pastaDestino,
                0775,
                true
            );
        }

        /*
         * Nome seguro e único.
         */
        $nomeArquivo =
            "documento_"
            . date("Ymd_His")
            . "_"
            . uniqid()
            . ".pdf";

        $caminhoCompleto =
            $pastaDestino
            . $nomeArquivo;

        if (
            !move_uploaded_file(
                $arquivo["tmp_name"],
                $caminhoCompleto
            )
        ) {

            throw new Exception(
                "Não foi possível salvar o arquivo."
            );
        }

        /*
         * Caminho que será salvo no banco.
         */
        $arquivoUrl =
            "../../assets/documentos/"
            . $nomeArquivo;

        $documento =
            new DocumentoDTO();

        $documento->setUsuarioUploadId(
            (int) $usuarioLogadoId
        );

        $documento->setTitulo(
            $titulo
        );

        $documento->setCategoria(
            $categoria
        );

        $documento->setDescricao(
            $descricao !== ""
                ? $descricao
                : null
        );

        $documento->setArquivoUrl(
            $arquivoUrl
        );

        if (
            $controller->cadastrar(
                $documento
            )
        ) {

            header(
                "Location: index.php?sucesso=Documento cadastrado com sucesso!"
            );

            exit;
        }

        /*
         * Se o banco falhar, tenta remover
         * o arquivo que acabou de ser enviado.
         */
        if (
            file_exists($caminhoCompleto)
        ) {
            unlink($caminhoCompleto);
        }

        $erro =
            "Não foi possível cadastrar o documento.";

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
        Novo Documento - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
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

            <h2>📄 Novo Documento</h2>

            <p class="text-muted mb-0">
                Cadastre documentos do condomínio.
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

            <?= htmlspecialchars(
                $erro
            ) ?>

        </div>

    <?php endif; ?>


    <div class="card shadow-sm">

        <div class="card-body">

            <form
                method="POST"
                enctype="multipart/form-data"
            >

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
                            $_POST["titulo"] ?? ""
                        ) ?>"
                        required
                    >

                </div>


                <div class="mb-3">

                    <label class="form-label">
                        Categoria *
                    </label>

                    <select
                        name="categoria"
                        class="form-select"
                        required
                    >

                        <option value="">
                            Selecione
                        </option>

                        <option
                            value="Atas"
                            <?= (
                                ($_POST["categoria"] ?? "")
                                === "Atas"
                            )
                                ? "selected"
                                : ""
                            ?>
                        >
                            Atas
                        </option>

                        <option
                            value="Convenção"
                            <?= (
                                ($_POST["categoria"] ?? "")
                                === "Convenção"
                            )
                                ? "selected"
                                : ""
                            ?>
                        >
                            Convenção
                        </option>

                        <option
                            value="Regimento_Interno"
                            <?= (
                                ($_POST["categoria"] ?? "")
                                === "Regimento_Interno"
                            )
                                ? "selected"
                                : ""
                            ?>
                        >
                            Regimento Interno
                        </option>

                        <option
                            value="Prestação_Contas"
                            <?= (
                                ($_POST["categoria"] ?? "")
                                === "Prestação_Contas"
                            )
                                ? "selected"
                                : ""
                            ?>
                        >
                            Prestação de Contas
                        </option>

                        <option
                            value="Contrato"
                            <?= (
                                ($_POST["categoria"] ?? "")
                                === "Contrato"
                            )
                                ? "selected"
                                : ""
                            ?>
                        >
                            Contrato
                        </option>

                        <option
                            value="Outros"
                            <?= (
                                ($_POST["categoria"] ?? "")
                                === "Outros"
                            )
                                ? "selected"
                                : ""
                            ?>
                        >
                            Outros
                        </option>

                    </select>

                </div>


                <div class="mb-3">

                    <label class="form-label">
                        Descrição
                    </label>

                    <textarea
                        name="descricao"
                        class="form-control"
                        rows="4"
                    ><?= htmlspecialchars(
                        $_POST["descricao"] ?? ""
                    ) ?></textarea>

                </div>


                <div class="mb-4">

                    <label class="form-label">
                        Arquivo PDF *
                    </label>

                    <input
                        type="file"
                        name="arquivo"
                        class="form-control"
                        accept=".pdf,application/pdf"
                        required
                    >

                    <div class="form-text">
                        Somente PDF. Tamanho máximo: 10 MB.
                    </div>

                </div>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Salvar documento
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
<script src="../../assets/js/script.js"></script>

</body>

</html>