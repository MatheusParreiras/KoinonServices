<?php

require_once __DIR__ . "/../../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/PublicacaoController.php";
require_once __DIR__ . "/../../model/dto/PublicacaoDTO.php";

PermissaoController::exigirPerfil(["Morador"]);

$usuarioId = $_SESSION["usuario_id"] ?? null;

if (!$usuarioId) {
    header("Location: ../login.php");
    exit;
}

$controller = new PublicacaoController();

$mensagem = "";
$tipoMensagem = "";


// ==========================================================
// PROCESSAMENTO DOS FORMULÁRIOS
// ==========================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $acao = $_POST["acao"] ?? "";


    // ======================================================
    // CADASTRAR ACHADO / PERDIDO
    // ======================================================

    if ($acao === "cadastrar") {

        $tipo = trim($_POST["tipo_achado"] ?? "");
        $titulo = trim($_POST["titulo"] ?? "");
        $descricao = trim($_POST["conteudo"] ?? "");
        $local = trim($_POST["local_ocorrencia"] ?? "");
        $data = trim($_POST["data_ocorrencia"] ?? "");

        $erros = [];

        if (
            $tipo !== "Achado" &&
            $tipo !== "Perdido"
        ) {
            $erros[] =
                "Selecione se é um item achado ou perdido.";
        }

        if ($titulo === "") {
            $erros[] =
                "Informe um título.";
        }

        if ($descricao === "") {
            $erros[] =
                "Informe a descrição.";
        }

        if ($local === "") {
            $erros[] =
                "Informe o local da ocorrência.";
        }

        if ($data === "") {
            $erros[] =
                "Informe a data da ocorrência.";
        }


        // ==================================================
        // UPLOAD DA IMAGEM
        // ==================================================

        $midiaUrl = null;

        if (
            isset($_FILES["imagem"]) &&
            $_FILES["imagem"]["error"] !== UPLOAD_ERR_NO_FILE
        ) {

            $arquivo = $_FILES["imagem"];

            if ($arquivo["error"] !== UPLOAD_ERR_OK) {

                $erros[] =
                    "Não foi possível enviar a imagem.";

            } else {

                $tamanhoMaximo =
                    5 * 1024 * 1024;

                if (
                    $arquivo["size"] >
                    $tamanhoMaximo
                ) {

                    $erros[] =
                        "A imagem deve ter no máximo 5 MB.";

                } else {

                    $extensao =
                        strtolower(
                            pathinfo(
                                $arquivo["name"],
                                PATHINFO_EXTENSION
                            )
                        );

                    $extensoesPermitidas = [
                        "jpg",
                        "jpeg",
                        "png",
                        "webp"
                    ];

                    if (
                        !in_array(
                            $extensao,
                            $extensoesPermitidas,
                            true
                        )
                    ) {

                        $erros[] =
                            "Formato de imagem inválido. Use JPG, JPEG, PNG ou WEBP.";

                    } else {

                        $imagemInfo =
                            @getimagesize(
                                $arquivo["tmp_name"]
                            );

                        if ($imagemInfo === false) {

                            $erros[] =
                                "O arquivo enviado não é uma imagem válida.";

                        } else {

                            $pastaDestino =
                                __DIR__ .
                                "/../../assets/img/";

                            if (
                                !is_dir(
                                    $pastaDestino
                                )
                            ) {

                                $erros[] =
                                    "A pasta de imagens não foi encontrada.";

                            } else {

                                $nomeArquivo =
                                    "achado_perdido_" .
                                    uniqid() .
                                    "." .
                                    $extensao;

                                $caminhoCompleto =
                                    $pastaDestino .
                                    $nomeArquivo;

                                if (
                                    move_uploaded_file(
                                        $arquivo["tmp_name"],
                                        $caminhoCompleto
                                    )
                                ) {

                                    $midiaUrl =
                                        "assets/img/" .
                                        $nomeArquivo;

                                } else {

                                    $erros[] =
                                        "Não foi possível salvar a imagem.";
                                }
                            }
                        }
                    }
                }
            }
        }


        // ==================================================
        // CADASTRAR
        // ==================================================

        if (empty($erros)) {

            $publicacao =
                new PublicacaoDTO();

            $publicacao->setUsuarioId(
                (int) $usuarioId
            );

            $publicacao->setCategoria(
                "Achados_Perdidos"
            );

            $publicacao->setTitulo(
                $titulo
            );

            $publicacao->setConteudo(
                $descricao
            );

            $publicacao->setTipoAchado(
                $tipo
            );

            $publicacao->setLocalOcorrencia(
                $local
            );

            $publicacao->setDataOcorrencia(
                $data
            );

            $publicacao->setStatus(
                "Ativo"
            );

            $publicacao->setMidiaUrl(
                $midiaUrl
            );

            $publicacao->setPreco(
                null
            );


            if (
                $controller->cadastrar(
                    $publicacao
                )
            ) {

                header(
                    "Location: achados_perdidos.php?sucesso=1"
                );

                exit;

            } else {

                $mensagem =
                    "Não foi possível cadastrar o anúncio.";

                $tipoMensagem =
                    "danger";
            }

        } else {

            $mensagem =
                implode(
                    "<br>",
                    $erros
                );

            $tipoMensagem =
                "danger";
        }
    }


    // ======================================================
    // MARCAR COMO RESOLVIDO
    // ======================================================

    elseif ($acao === "resolver") {

        $id =
            (int) ($_POST["id"] ?? 0);

        if ($id <= 0) {

            $mensagem =
                "Anúncio inválido.";

            $tipoMensagem =
                "danger";

        } else {

            $publicacao =
                $controller->buscarPorId(
                    $id
                );

            if (!$publicacao) {

                $mensagem =
                    "Anúncio não encontrado.";

                $tipoMensagem =
                    "danger";

            } elseif (
                $publicacao->getCategoria() !==
                "Achados_Perdidos"
            ) {

                $mensagem =
                    "Este registro não pertence a Achados e Perdidos.";

                $tipoMensagem =
                    "danger";

            } elseif (
                $publicacao->getUsuarioId() !==
                (int) $usuarioId
            ) {

                $mensagem =
                    "Você não pode encerrar este anúncio.";

                $tipoMensagem =
                    "danger";

            } elseif (
                $publicacao->getStatus() !==
                "Ativo"
            ) {

                $mensagem =
                    "Este anúncio já foi encerrado.";

                $tipoMensagem =
                    "danger";

            } else {

                if (
                    $controller->alterarStatus(
                        $id,
                        "Encerrado"
                    )
                ) {

                    header(
                        "Location: achados_perdidos.php?resolvido=1"
                    );

                    exit;

                } else {

                    $mensagem =
                        "Não foi possível encerrar o anúncio.";

                    $tipoMensagem =
                        "danger";
                }
            }
        }
    }
}


// ==========================================================
// MENSAGENS
// ==========================================================

if (isset($_GET["sucesso"])) {

    $mensagem =
        "Achado/Perdido cadastrado com sucesso!";

    $tipoMensagem =
        "success";
}

if (isset($_GET["resolvido"])) {

    $mensagem =
        "Anúncio marcado como resolvido!";

    $tipoMensagem =
        "success";
}


// ==========================================================
// LISTAR ACHADOS E PERDIDOS
// ==========================================================

$todasPublicacoes =
    $controller->listar();

$publicacoes = [];

foreach (
    $todasPublicacoes as $publicacao
) {

    if (
        $publicacao->getCategoria() !==
        "Achados_Perdidos"
    ) {
        continue;
    }

    if (
        $publicacao->getStatus() !== "Ativo" &&
        $publicacao->getStatus() !== "Encerrado"
    ) {
        continue;
    }

    $publicacoes[] =
        $publicacao;
}


// ==========================================================
// ORDENAR
// ==========================================================

usort(
    $publicacoes,
    function (
        PublicacaoDTO $a,
        PublicacaoDTO $b
    ) {

        return strtotime(
            $b->getCreatedAt() ??
            "1970-01-01"
        ) <=> strtotime(
            $a->getCreatedAt() ??
            "1970-01-01"
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

    <title>
        Achados e Perdidos - Koinon Services
    </title>

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

    <div
        class="d-flex justify-content-between align-items-center mb-4"
    >

        <div>

            <h2 class="mb-1">
                🔎 Achados e Perdidos
            </h2>

            <p class="text-muted mb-0">
                Encontre ou comunique objetos perdidos
                no condomínio.
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
            class="alert alert-<?php
                echo htmlspecialchars(
                    $tipoMensagem
                );
            ?>"
        >

            <?php
            echo $mensagem;
            ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         FORMULÁRIO DE CADASTRO
    ====================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-header bg-primary text-white">

            <strong>
                📢 Publicar Achado ou Perdido
            </strong>

        </div>

        <div class="card-body">

            <form
                method="POST"
                action="achados_perdidos.php"
                enctype="multipart/form-data"
            >

                <input
                    type="hidden"
                    name="acao"
                    value="cadastrar"
                >

                <div class="row g-3">


                    <!-- TIPO -->

                    <div class="col-md-4">

                        <label class="form-label">
                            Tipo
                        </label>

                        <select
                            name="tipo_achado"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Selecione
                            </option>

                            <option value="Achado">
                                🔎 Achei um objeto
                            </option>

                            <option value="Perdido">
                                ❓ Perdi um objeto
                            </option>

                        </select>

                    </div>


                    <!-- TÍTULO -->

                    <div class="col-md-8">

                        <label class="form-label">
                            Título
                        </label>

                        <input
                            type="text"
                            name="titulo"
                            class="form-control"
                            maxlength="150"
                            placeholder="Ex.: Chave encontrada na área da piscina"
                            required
                        >

                    </div>


                    <!-- DESCRIÇÃO -->

                    <div class="col-12">

                        <label class="form-label">
                            Descrição
                        </label>

                        <textarea
                            name="conteudo"
                            class="form-control"
                            rows="4"
                            placeholder="Descreva o objeto, cor, marca, características etc."
                            required
                        ></textarea>

                    </div>


                    <!-- LOCAL -->

                    <div class="col-md-8">

                        <label class="form-label">
                            Local da ocorrência
                        </label>

                        <input
                            type="text"
                            name="local_ocorrencia"
                            class="form-control"
                            maxlength="200"
                            placeholder="Ex.: Área da piscina"
                            required
                        >

                    </div>


                    <!-- DATA -->

                    <div class="col-md-4">

                        <label class="form-label">
                            Data da ocorrência
                        </label>

                        <input
                            type="date"
                            name="data_ocorrencia"
                            class="form-control"
                            required
                        >

                    </div>


                    <!-- IMAGEM -->

                    <div class="col-12">

                        <label class="form-label">
                            📷 Foto do objeto
                        </label>

                        <input
                            type="file"
                            name="imagem"
                            class="form-control"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        >

                        <div class="form-text">
                            Opcional. Formatos: JPG, JPEG, PNG ou WEBP. Máximo 5 MB.
                        </div>

                    </div>


                    <!-- BOTÃO PUBLICAR -->

                    <div class="col-12">

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            📢 Publicar
                        </button>

                    </div>

                </div>

            </form>

        </div>

    </div>


    <!-- =====================================================
         LISTAGEM
    ====================================================== -->

    <div
        class="d-flex justify-content-between align-items-center mb-3"
    >

        <h4 class="mb-0">
            📋 Achados e Perdidos
        </h4>

        <span class="badge text-bg-secondary">

            <?php
            echo count($publicacoes);
            ?>

        </span>

    </div>


    <?php if (empty($publicacoes)): ?>

        <div class="alert alert-info">

            Ainda não existem registros de
            Achados e Perdidos.

        </div>

    <?php else: ?>

        <div class="row g-4">

            <?php foreach (
                $publicacoes as $publicacao
            ): ?>

                <?php

                $tipo =
                    $publicacao->getTipoAchado();

                $isAchado =
                    $tipo === "Achado";

                $classeTipo =
                    $isAchado
                        ? "success"
                        : "warning";

                $textoTipo =
                    $isAchado
                        ? "ACHADO"
                        : "PERDIDO";

                $status =
                    $publicacao->getStatus();

                ?>

                <div class="col-md-6 col-lg-4">

                    <div class="card h-100 shadow-sm">


                        <!-- IMAGEM -->

                        <?php

                        $imagem =
                            $publicacao->getMidiaUrl();

                        ?>

                        <?php if (
                            !empty($imagem)
                        ): ?>

                            <img
                                src="../../<?php
                                    echo htmlspecialchars(
                                        $imagem
                                    );
                                ?>"
                                class="card-img-top"
                                alt="Imagem do objeto"
                                style="
                                    height: 220px;
                                    object-fit: cover;
                                "
                            >

                        <?php else: ?>

                            <div
                                class="bg-light border-bottom d-flex align-items-center justify-content-center"
                                style="height: 220px;"
                            >

                                <span
                                    class="text-muted"
                                >
                                    📷 Sem imagem
                                </span>

                            </div>

                        <?php endif; ?>


                        <div class="card-body">


                            <!-- BADGES -->

                            <div
                                class="d-flex justify-content-between align-items-center mb-3"
                            >

                                <span
                                    class="badge text-bg-<?php
                                        echo $classeTipo;
                                    ?>"
                                >

                                    <?php
                                    echo $textoTipo;
                                    ?>

                                </span>


                                <?php if (
                                    $status ===
                                    "Encerrado"
                                ): ?>

                                    <span
                                        class="badge text-bg-secondary"
                                    >
                                        Resolvido
                                    </span>

                                <?php else: ?>

                                    <span
                                        class="badge text-bg-primary"
                                    >
                                        Ativo
                                    </span>

                                <?php endif; ?>

                            </div>


                            <!-- TÍTULO -->

                            <h5 class="card-title">

                                <?php
                                echo htmlspecialchars(
                                    $publicacao->getTitulo()
                                );
                                ?>

                            </h5>


                            <!-- DESCRIÇÃO -->

                            <p class="card-text">

                                <?php
                                echo nl2br(
                                    htmlspecialchars(
                                        $publicacao->getConteudo()
                                    )
                                );
                                ?>

                            </p>


                            <hr>


                            <!-- LOCAL -->

                            <p class="mb-2">

                                <strong>
                                    📍 Local:
                                </strong>

                                <?php
                                echo htmlspecialchars(
                                    $publicacao->getLocalOcorrencia()
                                );
                                ?>

                            </p>


                            <!-- DATA -->

                            <p class="mb-2">

                                <strong>
                                    📅 Data:
                                </strong>

                                <?php

                                $dataOcorrencia =
                                    $publicacao->getDataOcorrencia();

                                if ($dataOcorrencia) {

                                    echo date(
                                        "d/m/Y",
                                        strtotime(
                                            $dataOcorrencia
                                        )
                                    );
                                }

                                ?>

                            </p>


                            <!-- AUTOR -->

                            <p class="mb-3">

                                <strong>
                                    👤 Publicado por:
                                </strong>

                                <?php
                                echo htmlspecialchars(
                                    $publicacao->getUsuarioNome()
                                    ?? "Morador"
                                );
                                ?>

                            </p>


                            <!-- BOTÃO RESOLVER -->

                            <?php if (
                                $status === "Ativo" &&
                                $publicacao->getUsuarioId() ===
                                    (int) $usuarioId
                            ): ?>

                                <form
                                    method="POST"
                                    action="achados_perdidos.php"
                                    onsubmit="return confirm('Deseja marcar este anúncio como resolvido?');"
                                >

                                    <input
                                        type="hidden"
                                        name="acao"
                                        value="resolver"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?php
                                            echo (int) $publicacao->getId();
                                        ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="btn btn-outline-success w-100"
                                    >
                                        ✅ Marcar como resolvido
                                    </button>

                                </form>

                            <?php endif; ?>


                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>
<script src="../../assets/js/script.js"></script>

</body>

</html>