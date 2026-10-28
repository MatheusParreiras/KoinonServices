<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirGestao();

require_once __DIR__ . "/../../controller/DocumentoController.php";

$controller = new DocumentoController();

$id = isset($_GET["id"])
    ? (int) $_GET["id"]
    : 0;

if ($id <= 0) {

    header("Location: index.php");

    exit;
}

$documento =
    $controller->buscarPorId($id);

if (!$documento) {

    header(
        "Location: index.php?erro=Documento não encontrado."
    );

    exit;
}

$erro = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $arquivoAnteriorUrl =
        $documento->getArquivoUrl();

    $novoCaminhoCompleto = null;

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

        $arquivoUrl =
            $arquivoAnteriorUrl;

        $novoArquivoEnviado = false;

        /*
         * Verifica se foi enviado um novo PDF.
         */
        if (
            isset($_FILES["arquivo"])
            &&
            $_FILES["arquivo"]["error"]
            !== UPLOAD_ERR_NO_FILE
        ) {

            if (
                $_FILES["arquivo"]["error"]
                !== UPLOAD_ERR_OK
            ) {

                throw new Exception(
                    "Erro ao enviar o novo arquivo."
                );
            }

            $arquivo =
                $_FILES["arquivo"];

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

            $finfo = new finfo(
                FILEINFO_MIME_TYPE
            );

            $mime = $finfo->file(
                $arquivo["tmp_name"]
            );

            if (
                $mime !== "application/pdf"
            ) {

                throw new Exception(
                    "O arquivo selecionado não é um PDF válido."
                );
            }

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

            $nomeArquivo =
                "documento_"
                . date("Ymd_His")
                . "_"
                . uniqid()
                . ".pdf";

            $novoCaminhoCompleto =
                $pastaDestino
                . $nomeArquivo;

            if (
                !move_uploaded_file(
                    $arquivo["tmp_name"],
                    $novoCaminhoCompleto
                )
            ) {

                throw new Exception(
                    "Não foi possível salvar o novo arquivo."
                );
            }

            $arquivoUrl =
                "../../assets/documentos/"
                . $nomeArquivo;

            $novoArquivoEnviado = true;
        }

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
            !$controller->editar(
                $documento
            )
        ) {

            throw new Exception(
                "Não foi possível atualizar o documento."
            );
        }

        /*
         * Se tudo deu certo e houve substituição,
         * exclui o arquivo antigo.
         */
        if (
            $novoArquivoEnviado
            &&
            $arquivoAnteriorUrl !== ""
        ) {

            $arquivoAnterior =
                __DIR__
                . "/"
                . $arquivoAnteriorUrl;

            /*
             * Resolve corretamente:
             * ../../assets/documentos/arquivo.pdf
             */
            $arquivoAnterior =
                realpath(
                    __DIR__
                    . "/"
                    . $arquivoAnteriorUrl
                );

            if (
                $arquivoAnterior
                &&
                file_exists($arquivoAnterior)
                &&
                $arquivoAnterior
                !== realpath(
                    $novoCaminhoCompleto
                )
            ) {

                unlink(
                    $arquivoAnterior
                );
            }
        }

        header(
            "Location: index.php?sucesso=Documento atualizado com sucesso!"
        );

        exit;

    } catch (Throwable $e) {

        /*
         * Se o banco falhou depois de salvar
         * um novo arquivo, remove o novo arquivo.
         */
        if (
            $novoCaminhoCompleto !== null
            &&
            file_exists($novoCaminhoCompleto)
        ) {

            unlink(
                $novoCaminhoCompleto
            );
        }

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
        Editar Documento - Koinon Services
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

            <h2>✏️ Editar Documento</h2>

            <p class="text-muted mb-0">
                Atualize as informações do documento.
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
                            $documento->getTitulo()
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

                        <option
                            value="Atas"
                            <?= $documento->getCategoria()
                                === "Atas"
                                ? "selected"
                                : ""
                            ?>
                        >
                            Atas
                        </option>

                        <option
                            value="Convenção"
                            <?= $documento->getCategoria()
                                === "Convenção"
                                ? "selected"
                                : ""
                            ?>
                        >
                            Convenção
                        </option>

                        <option
                            value="Regimento_Interno"
                            <?= $documento->getCategoria()
                                === "Regimento_Interno"
                                ? "selected"
                                : ""
                            ?>
                        >
                            Regimento Interno
                        </option>

                        <option
                            value="Prestação_Contas"
                            <?= $documento->getCategoria()
                                === "Prestação_Contas"
                                ? "selected"
                                : ""
                            ?>
                        >
                            Prestação de Contas
                        </option>

                        <option
                            value="Contrato"
                            <?= $documento->getCategoria()
                                === "Contrato"
                                ? "selected"
                                : ""
                            ?>
                        >
                            Contrato
                        </option>

                        <option
                            value="Outros"
                            <?= $documento->getCategoria()
                                === "Outros"
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
                        $documento->getDescricao()
                        ?? ""
                    ) ?></textarea>

                </div>


                <div class="mb-3">

                    <label class="form-label">
                        Arquivo atual
                    </label>

                    <div>

                        <a
                            href="<?= htmlspecialchars(
                                $documento->getArquivoUrl()
                            ) ?>"
                            target="_blank"
                            class="btn btn-outline-primary"
                        >
                            📄 Abrir PDF atual
                        </a>

                    </div>

                </div>


                <div class="mb-4">

                    <label class="form-label">
                        Substituir arquivo
                    </label>

                    <input
                        type="file"
                        name="arquivo"
                        class="form-control"
                        accept=".pdf,application/pdf"
                    >

                    <div class="form-text">
                        Deixe vazio para manter o PDF atual.
                        Somente PDF até 10 MB.
                    </div>

                </div>


                <div class="mb-3">

                    <strong>
                        Enviado por:
                    </strong>

                    <?= htmlspecialchars(
                        $documento->getUsuarioNome()
                        ?? "-"
                    ) ?>

                </div>


                <div class="mb-4">

                    <strong>
                        Data do upload:
                    </strong>

                    <?= $documento->getDataUpload()
                        ? date(
                            "d/m/Y H:i",
                            strtotime(
                                $documento->getDataUpload()
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
<script src="../../assets/js/script.js"></script>

</body>

</html>