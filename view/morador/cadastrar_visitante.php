<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../model/dao/Conexao.php";
require_once __DIR__ . "/../../service/Erro.php";

PermissaoController::exigirPerfil([
    "Morador"
]);

$mensagem = "";
$tipoMensagem = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $nome = trim($_POST["nome"] ?? "");

    $documento = trim(
        $_POST["documento"] ?? ""
    );
        $documento = Cpf::limpar($documento);

    $tipoVisitante = trim(
        $_POST["tipo_visitante"] ?? "Visitante"
    );

    /*
     * Foto capturada pela câmera.
     */
    $fotoBase64 = trim(
        $_POST["foto_base64"] ?? ""
    );

    /*
     * Foto anexada pelo computador/celular.
     */
    $fotoAnexo = $_FILES["foto_anexo"] ?? null;

    if ($nome === "") {

        $mensagem =
            "Informe o nome do visitante.";

        $tipoMensagem = "danger";

    } elseif (strlen($documento) !== 11) {

        $mensagem =
            "Informe o CPF do visitante no padrão 000.000.000-00 (11 números).";

        $tipoMensagem = "danger";

    } elseif (
        !in_array(
            $tipoVisitante,
            [
                "Visitante",
                "Prestador_Servico",
                "Entregador"
            ],
            true
        )
    ) {

        $mensagem =
            "Tipo de visitante inválido.";

        $tipoMensagem = "danger";

    } else {

        /*
         * Verifica se existe pelo menos uma das
         * duas formas de foto:
         *
         * 1. Câmera
         * 2. Arquivo anexado
         */
        $temFotoCamera =
            $fotoBase64 !== "";

        $temFotoAnexo =
            is_array($fotoAnexo)
            && isset($fotoAnexo["error"])
            && $fotoAnexo["error"] !== UPLOAD_ERR_NO_FILE;

        if (
            !$temFotoCamera
            && !$temFotoAnexo
        ) {

            $mensagem =
                "Tire uma foto pela câmera ou "
                . "anexe uma foto do visitante.";

            $tipoMensagem = "warning";

        } else {

            try {

                $conexao =
                    Conexao::conectar();

                /*
                 * Verifica se já existe visitante
                 * com o mesmo documento.
                 */
                $consulta = $conexao->prepare("
                    SELECT id
                    FROM visitante
                    WHERE documento = :documento
                    LIMIT 1
                ");

                $consulta->bindValue(
                    ":documento",
                    $documento,
                    PDO::PARAM_STR
                );

                $consulta->execute();

                if ($consulta->fetch()) {

                    $mensagem =
                        "Já existe um visitante cadastrado "
                        . "com este CPF.";

                    $tipoMensagem = "warning";

                } else {

                    /*
                     * Pasta física das fotos.
                     */
                    $pastaFisica =
                        __DIR__
                        . "/../../assets/img/visitantes/";

                    /*
                     * Cria a pasta caso não exista.
                     */
                    if (!is_dir($pastaFisica)) {

                        if (
                            !mkdir(
                                $pastaFisica,
                                0755,
                                true
                            )
                        ) {

                            throw new Exception(
                                "Não foi possível criar a pasta "
                                . "de fotos dos visitantes."
                            );
                        }
                    }

                    /*
                     * Variáveis que receberão os
                     * dados finais da imagem.
                     */
                    $dadosImagem = false;
                    $extensao = "jpg";

                    /*
                     * =====================================================
                     * OPÇÃO 1 - FOTO DA CÂMERA
                     * =====================================================
                     */
                    if ($temFotoCamera) {

                        /*
                         * Aceita JPEG, PNG e WEBP.
                         */
                        if (
                            !preg_match(
                                '/^data:image\/(jpeg|jpg|png|webp);base64,/i',
                                $fotoBase64,
                                $matches
                            )
                        ) {

                            throw new Exception(
                                "Formato da foto da câmera inválido."
                            );
                        }

                        /*
                         * Identifica o formato informado pelo navegador.
                         */
                        $formato =
                            strtolower(
                                $matches[1] ?? ""
                            );

                        /*
                         * Remove o cabeçalho Base64.
                         */
                        $dadosBase64 =
                            preg_replace(
                                '/^data:image\/(jpeg|jpg|png|webp);base64,/i',
                                "",
                                $fotoBase64
                            );

                        /*
                         * Corrige espaços.
                         */
                        $dadosBase64 =
                            str_replace(
                                " ",
                                "+",
                                $dadosBase64
                            );

                        /*
                         * Decodifica.
                         */
                        $dadosImagem =
                            base64_decode(
                                $dadosBase64,
                                true
                            );

                        if (
                            $dadosImagem === false
                        ) {

                            throw new Exception(
                                "Não foi possível processar "
                                . "a foto da câmera."
                            );
                        }

                        /*
                         * Limite de 5 MB.
                         */
                        if (
                            strlen($dadosImagem)
                            > 5 * 1024 * 1024
                        ) {

                            throw new Exception(
                                "A foto é muito grande. "
                                . "Utilize uma imagem de até 5 MB."
                            );
                        }

                        /*
                         * Detecta o formato através dos
                         * bytes reais da imagem.
                         *
                         * Não utiliza GD.
                         */
                        if (
                            substr(
                                $dadosImagem,
                                0,
                                3
                            ) === "\xFF\xD8\xFF"
                        ) {

                            $extensao = "jpg";

                        } elseif (
                            substr(
                                $dadosImagem,
                                0,
                                8
                            ) === "\x89PNG\x0D\x0A\x1A\x0A"
                        ) {

                            $extensao = "png";

                        } elseif (
                            substr(
                                $dadosImagem,
                                0,
                                4
                            ) === "RIFF"
                            && substr(
                                $dadosImagem,
                                8,
                                4
                            ) === "WEBP"
                        ) {

                            $extensao = "webp";

                        } else {

                            throw new Exception(
                                "A foto da câmera não é "
                                . "uma imagem válida."
                            );
                        }

                    /*
                     * =====================================================
                     * OPÇÃO 2 - FOTO ANEXADA
                     * =====================================================
                     */
                    } else {

                        /*
                         * Verifica erro do upload.
                         */
                        if (
                            !isset(
                                $fotoAnexo["error"]
                            )
                            || $fotoAnexo["error"]
                            !== UPLOAD_ERR_OK
                        ) {

                            throw new Exception(
                                "Não foi possível enviar "
                                . "a foto selecionada."
                            );
                        }

                        /*
                         * Verifica tamanho.
                         */
                        if (
                            !isset(
                                $fotoAnexo["size"]
                            )
                            || $fotoAnexo["size"]
                            <= 0
                        ) {

                            throw new Exception(
                                "O arquivo de foto está vazio."
                            );
                        }

                        if (
                            $fotoAnexo["size"]
                            > 5 * 1024 * 1024
                        ) {

                            throw new Exception(
                                "A foto é muito grande. "
                                . "Utilize uma imagem de até 5 MB."
                            );
                        }

                        /*
                         * Lê os bytes do arquivo temporário.
                         */
                        $dadosImagem =
                            file_get_contents(
                                $fotoAnexo["tmp_name"]
                            );

                        if (
                            $dadosImagem === false
                        ) {

                            throw new Exception(
                                "Não foi possível ler "
                                . "a foto enviada."
                            );
                        }

                        /*
                         * Verifica o conteúdo real da imagem.
                         *
                         * JPEG
                         */
                        if (
                            substr(
                                $dadosImagem,
                                0,
                                3
                            ) === "\xFF\xD8\xFF"
                        ) {

                            $extensao = "jpg";

                        /*
                         * PNG
                         */
                        } elseif (
                            substr(
                                $dadosImagem,
                                0,
                                8
                            ) === "\x89PNG\x0D\x0A\x1A\x0A"
                        ) {

                            $extensao = "png";

                        /*
                         * WEBP
                         */
                        } elseif (
                            substr(
                                $dadosImagem,
                                0,
                                4
                            ) === "RIFF"
                            && substr(
                                $dadosImagem,
                                8,
                                4
                            ) === "WEBP"
                        ) {

                            $extensao = "webp";

                        } else {

                            throw new Exception(
                                "O arquivo selecionado "
                                . "não é uma imagem válida."
                            );
                        }
                    }

                    /*
                     * Nome único da imagem.
                     */
                    $nomeArquivo =
                        "visitante_"
                        . date("Ymd_His")
                        . "_"
                        . bin2hex(
                            random_bytes(4)
                        )
                        . "."
                        . $extensao;

                    /*
                     * Caminho físico.
                     */
                    $caminhoArquivo =
                        $pastaFisica
                        . $nomeArquivo;

                    /*
                     * Salva diretamente os bytes.
                     *
                     * Não utiliza GD.
                     */
                    $salvou =
                        file_put_contents(
                            $caminhoArquivo,
                            $dadosImagem
                        );

                    if (
                        $salvou === false
                    ) {

                        throw new Exception(
                            "Não foi possível salvar "
                            . "a foto no servidor."
                        );
                    }

                    /*
                     * Caminho armazenado no banco.
                     */
                    $fotoUrl =
                        "../../assets/img/visitantes/"
                        . $nomeArquivo;

                    /*
                     * Cadastra o visitante.
                     */
                    $sql = $conexao->prepare("
                        INSERT INTO visitante (
                            nome,
                            documento,
                            foto_url,
                            tipo_visitante
                        )
                        VALUES (
                            :nome,
                            :documento,
                            :foto_url,
                            :tipo_visitante
                        )
                    ");

                    $sql->bindValue(
                        ":nome",
                        $nome,
                        PDO::PARAM_STR
                    );

                    $sql->bindValue(
                        ":documento",
                        $documento,
                        PDO::PARAM_STR
                    );

                    $sql->bindValue(
                        ":foto_url",
                        $fotoUrl,
                        PDO::PARAM_STR
                    );

                    $sql->bindValue(
                        ":tipo_visitante",
                        $tipoVisitante,
                        PDO::PARAM_STR
                    );

                    $sql->execute();

                    /*
                     * Cadastro concluído.
                     *
                     * Volta para a tela de autorização.
                     */
                    header(
                        "Location: nova_autorizacao.php"
                        . "?visitante_cadastrado=1"
                    );

                    exit;
                }

            } catch (Throwable $e) {

                $mensagem =
                    "Erro ao cadastrar visitante: "
                    . Erro::mensagem($e);

                $tipoMensagem = "danger";
            }
        }
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
        Cadastrar Visitante - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body {
            min-height: 100vh;
        }

        #camera {

            width: 100%;

            max-width: 500px;

            margin: 0 auto;

            background: #000;

            border-radius: 10px;

            overflow: hidden;

        }

        #video {

            width: 100%;

            display: block;

        }

        #preview {

            width: 100%;

            max-width: 500px;

            max-height: 500px;

            object-fit: contain;

            border-radius: 10px;

            display: none;

            margin: 0 auto;

            background: #000;

        }

        #canvas {

            display: none;

        }

        #previewAnexo {

            width: 100%;

            max-width: 500px;

            max-height: 500px;

            object-fit: contain;

            border-radius: 10px;

            display: none;

            margin: 0 auto;

            background: #000;

        }

        .opcao-foto {

            border: 2px solid #dee2e6;

            border-radius: 10px;

            padding: 15px;

            height: 100%;

        }

        .opcao-foto:hover {

            border-color: #0d6efd;

        }

    </style>


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body class="bg-light">


<nav class="navbar navbar-dark bg-primary">

    <div class="container">

        <span class="navbar-brand fw-bold">

            🏢 Koinon Services

        </span>

        <a
            href="index.php"
            class="btn btn-outline-light btn-sm"
        >

            ← Voltar

        </a>

    </div>

</nav>


<div class="container py-4">

    <div class="row justify-content-center">

        <div class="col-lg-8">

            <div class="card shadow">

                <div class="card-header bg-primary text-white">

                    <h4 class="mb-0">

                        👤 Cadastrar visitante

                    </h4>

                </div>


                <div class="card-body">


                    <?php if (!empty($mensagem)): ?>

                        <div
                            class="alert alert-<?= htmlspecialchars(
                                $tipoMensagem
                            ) ?>"
                        >

                            <?= htmlspecialchars(
                                $mensagem
                            ) ?>

                        </div>

                    <?php endif; ?>


                    <div class="alert alert-info">

                        <strong>
                            📸 Foto facial do visitante
                        </strong>

                        <br>

                        Você pode tirar uma foto usando
                        a câmera ou anexar uma foto
                        que já esteja salva no dispositivo.

                        <br>

                        Prefira uma foto frontal,
                        nítida e com boa iluminação.

                    </div>


                    <form
                        method="POST"
                        id="formVisitante"
                        enctype="multipart/form-data"
                    >


                        <div class="row">


                            <div class="col-md-6 mb-3">

                                <label class="form-label">

                                    Nome completo

                                </label>

                                <input
                                    type="text"
                                    name="nome"
                                    class="form-control"
                                    maxlength="150"
                                    required
                                    value="<?= htmlspecialchars(
                                        $_POST["nome"] ?? ""
                                    ) ?>"
                                >

                            </div>


                            <div class="col-md-6 mb-3">

                                <label class="form-label">

                                    CPF

                                </label>

                                <input
                                    type="text"
                                    name="documento"
                                    class="form-control"
                                    maxlength="14"
                            inputmode="numeric"
                            autocomplete="off"
                            placeholder="000.000.000-00"
                            pattern="\d{3}\.\d{3}\.\d{3}-\d{2}"
                            title="Informe o CPF no padrão 000.000.000-00"
                            data-cpf
                            required
                                    value="<?= htmlspecialchars(
                                        $_POST["documento"] ?? ""
                                    ) ?>"
                                >

                            </div>


                            <div class="col-md-6 mb-3">

                                <label class="form-label">

                                    Tipo de visitante

                                </label>

                                <select
                                    name="tipo_visitante"
                                    class="form-select"
                                    required
                                >

                                    <option
                                        value="Visitante"
                                        <?= (
                                            ($_POST["tipo_visitante"] ?? "Visitante")
                                            === "Visitante"
                                        )
                                            ? "selected"
                                            : ""
                                        ?>
                                    >

                                        Visitante

                                    </option>


                                    <option
                                        value="Prestador_Servico"
                                        <?= (
                                            ($_POST["tipo_visitante"] ?? "")
                                            === "Prestador_Servico"
                                        )
                                            ? "selected"
                                            : ""
                                        ?>
                                    >

                                        Prestador de Serviço

                                    </option>


                                    <option
                                        value="Entregador"
                                        <?= (
                                            ($_POST["tipo_visitante"] ?? "")
                                            === "Entregador"
                                        )
                                            ? "selected"
                                            : ""
                                        ?>
                                    >

                                        Entregador

                                    </option>

                                </select>

                            </div>

                        </div>


                        <hr class="my-4">


                        <h5 class="mb-3 text-center">

                            📷 Foto facial

                        </h5>


                        <!-- =====================================================
                             CÂMERA
                        ====================================================== -->

                        <div
                            id="camera"
                            class="mb-3"
                        >

                            <video
                                id="video"
                                autoplay
                                playsinline
                            ></video>

                        </div>


                        <canvas
                            id="canvas"
                        ></canvas>


                        <!-- Foto da câmera -->

                        <img
                            id="preview"
                            alt="Pré-visualização da foto tirada"
                        >


                        <!-- Foto anexada -->

                        <img
                            id="previewAnexo"
                            alt="Pré-visualização da foto anexada"
                        >


                        <input
                            type="hidden"
                            name="foto_base64"
                            id="foto_base64"
                        >


                        <!-- =====================================================
                             OPÇÕES DE FOTO
                        ====================================================== -->

                        <div class="row g-3 mb-4">


                            <div class="col-md-6">

                                <div class="opcao-foto">

                                    <h6 class="text-center">

                                        📷 Usar câmera

                                    </h6>

                                    <p class="text-muted small text-center">

                                        Tire uma nova foto
                                        do visitante.

                                    </p>

                                    <div
                                        class="d-flex flex-wrap gap-2 justify-content-center"
                                    >

                                        <button
                                            type="button"
                                            class="btn btn-primary"
                                            id="btnCamera"
                                        >

                                            📷 Abrir câmera

                                        </button>


                                        <button
                                            type="button"
                                            class="btn btn-success"
                                            id="btnCapturar"
                                            disabled
                                        >

                                            📸 Tirar foto

                                        </button>

                                    </div>

                                </div>

                            </div>


                            <div class="col-md-6">

                                <div class="opcao-foto">

                                    <h6 class="text-center">

                                        📁 Anexar foto

                                    </h6>

                                    <p class="text-muted small text-center">

                                        Escolha uma foto
                                        já salva no dispositivo.

                                    </p>

                                    <div class="text-center">

                                        <label
                                            for="foto_anexo"
                                            class="btn btn-warning"
                                        >

                                            📁 Escolher foto

                                        </label>

                                        <input
                                            type="file"
                                            name="foto_anexo"
                                            id="foto_anexo"
                                            accept="image/jpeg,image/png,image/webp"
                                            class="d-none"
                                        >

                                    </div>

                                </div>

                            </div>

                        </div>


                        <!-- =====================================================
                             BOTÕES DA FOTO
                        ====================================================== -->

                        <div
                            class="d-flex flex-wrap gap-2 justify-content-center mb-4"
                        >

                            <button
                                type="button"
                                class="btn btn-warning"
                                id="btnNovaFoto"
                                style="display:none;"
                            >

                                🔄 Tirar outra foto

                            </button>


                            <button
                                type="button"
                                class="btn btn-outline-danger"
                                id="btnRemoverFoto"
                                style="display:none;"
                            >

                                🗑️ Remover foto

                            </button>

                        </div>


                        <div class="text-center mb-4">

                            <small class="text-muted">

                                Posicione o rosto de frente,
                                mantenha uma boa iluminação
                                e evite objetos cobrindo o rosto.

                            </small>

                        </div>


                        <div class="d-flex justify-content-between">


                            <a
                                href="index.php"
                                class="btn btn-secondary"
                            >

                                Cancelar

                            </a>


                            <button
                                type="submit"
                                class="btn btn-primary"
                            >

                                💾 Salvar visitante

                            </button>


                        </div>


                    </form>

                </div>

            </div>

        </div>

    </div>

</div>


<script>

let stream = null;


/*
 * Elementos da página.
 */
const video =
    document.getElementById("video");

const canvas =
    document.getElementById("canvas");

const preview =
    document.getElementById("preview");

const previewAnexo =
    document.getElementById("previewAnexo");

const fotoBase64 =
    document.getElementById("foto_base64");

const fotoAnexo =
    document.getElementById("foto_anexo");

const btnCamera =
    document.getElementById("btnCamera");

const btnCapturar =
    document.getElementById("btnCapturar");

const btnNovaFoto =
    document.getElementById("btnNovaFoto");

const btnRemoverFoto =
    document.getElementById("btnRemoverFoto");


/*
 * Verifica se existe alguma foto selecionada.
 */
function possuiFoto() {

    return (
        fotoBase64.value !== ""
        ||
        (
            fotoAnexo.files
            &&
            fotoAnexo.files.length > 0
        )
    );
}


/*
 * Abre a câmera.
 */
async function abrirCamera() {

    try {

        /*
         * Remove uma eventual foto anexada,
         * pois estamos escolhendo a câmera.
         */
        fotoAnexo.value = "";

        previewAnexo.src = "";

        previewAnexo.style.display =
            "none";


        /*
         * Se já existir uma câmera aberta,
         * encerra antes de abrir novamente.
         */
        if (stream) {

            stream
                .getTracks()
                .forEach(
                    track => track.stop()
                );

            stream = null;
        }


        /*
         * Verifica suporte do navegador.
         */
        if (
            !navigator.mediaDevices
            ||
            !navigator.mediaDevices.getUserMedia
        ) {

            alert(
                "Seu navegador não suporta acesso à câmera."
            );

            return;
        }


        /*
         * Solicita acesso à câmera frontal.
         */
        stream =
            await navigator.mediaDevices.getUserMedia({

                video: {

                    facingMode: "user",

                    width: {
                        ideal: 1280
                    },

                    height: {
                        ideal: 720
                    }

                },

                audio: false

            });


        /*
         * Coloca a câmera no vídeo.
         */
        video.srcObject =
            stream;


        video.style.display =
            "block";


        preview.style.display =
            "none";


        btnCapturar.disabled =
            false;


        btnNovaFoto.style.display =
            "none";


        btnRemoverFoto.style.display =
            "none";

    } catch (erro) {

        console.error(
            "Erro ao abrir câmera:",
            erro
        );

        alert(
            "Não foi possível acessar a câmera.\n\n"
            + "Verifique se o navegador possui "
            + "permissão para utilizar a câmera."
        );
    }
}


/*
 * Captura a foto atual da câmera.
 */
function capturarFoto() {

    if (!stream) {

        alert(
            "Abra a câmera primeiro."
        );

        return;
    }


    const largura =
        video.videoWidth;

    const altura =
        video.videoHeight;


    if (
        largura === 0
        ||
        altura === 0
    ) {

        alert(
            "A câmera ainda não está pronta. "
            + "Aguarde alguns segundos."
        );

        return;
    }


    /*
     * Define o tamanho do canvas.
     */
    canvas.width =
        largura;

    canvas.height =
        altura;


    /*
     * Desenha o frame atual.
     */
    const contexto =
        canvas.getContext("2d");


    contexto.drawImage(
        video,
        0,
        0,
        largura,
        altura
    );


    /*
     * Converte para JPEG Base64.
     */
    const imagem =
        canvas.toDataURL(
            "image/jpeg",
            0.90
        );


    /*
     * Guarda a foto no formulário.
     */
    fotoBase64.value =
        imagem;


    /*
     * Remove eventual arquivo anexado.
     */
    fotoAnexo.value =
        "";


    previewAnexo.src =
        "";

    previewAnexo.style.display =
        "none";


    /*
     * Mostra a prévia da câmera.
     */
    preview.src =
        imagem;


    preview.style.display =
        "block";


    video.style.display =
        "none";


    btnCapturar.disabled =
        true;


    btnNovaFoto.style.display =
        "inline-block";


    btnRemoverFoto.style.display =
        "inline-block";


    /*
     * Encerra a câmera.
     */
    if (stream) {

        stream
            .getTracks()
            .forEach(
                track => track.stop()
            );

        stream = null;
    }
}


/*
 * Permite tirar uma nova foto.
 */
function novaFoto() {

    fotoBase64.value =
        "";

    preview.src =
        "";

    preview.style.display =
        "none";

    btnNovaFoto.style.display =
        "none";

    btnRemoverFoto.style.display =
        "none";

    abrirCamera();
}


/*
 * Quando o usuário seleciona
 * uma foto do computador/celular.
 */
fotoAnexo.addEventListener(
    "change",
    function() {

        /*
         * Se nenhum arquivo foi escolhido.
         */
        if (
            !this.files
            ||
            this.files.length === 0
        ) {

            return;
        }


        const arquivo =
            this.files[0];


        /*
         * Verifica tipo.
         */
        const tiposPermitidos = [
            "image/jpeg",
            "image/png",
            "image/webp"
        ];


        if (
            !tiposPermitidos.includes(
                arquivo.type
            )
        ) {

            alert(
                "Formato de imagem inválido.\n\n"
                + "Utilize JPG, PNG ou WEBP."
            );

            this.value = "";

            return;
        }


        /*
         * Limite de 5 MB.
         */
        if (
            arquivo.size
            > 5 * 1024 * 1024
        ) {

            alert(
                "A foto é muito grande.\n\n"
                + "Utilize uma imagem de até 5 MB."
            );

            this.value = "";

            return;
        }


        /*
         * Remove a foto da câmera.
         */
        fotoBase64.value =
            "";

        preview.src =
            "";

        preview.style.display =
            "none";


        /*
         * Para a câmera caso esteja aberta.
         */
        if (stream) {

            stream
                .getTracks()
                .forEach(
                    track => track.stop()
                );

            stream = null;
        }


        video.style.display =
            "none";

        btnCapturar.disabled =
            true;


        /*
         * Mostra a prévia do arquivo.
         */
        const leitor =
            new FileReader();


        leitor.onload =
            function(evento) {

                previewAnexo.src =
                    evento.target.result;

                previewAnexo.style.display =
                    "block";

                btnRemoverFoto.style.display =
                    "inline-block";

                btnNovaFoto.style.display =
                    "inline-block";
            };


        leitor.readAsDataURL(
            arquivo
        );
    }
);


/*
 * Remove a foto atual.
 */
function removerFoto() {

    fotoBase64.value =
        "";

    fotoAnexo.value =
        "";

    preview.src =
        "";

    previewAnexo.src =
        "";

    preview.style.display =
        "none";

    previewAnexo.style.display =
        "none";

    btnNovaFoto.style.display =
        "none";

    btnRemoverFoto.style.display =
        "none";

    btnCapturar.disabled =
        true;

    video.style.display =
        "none";


    /*
     * Encerra a câmera.
     */
    if (stream) {

        stream
            .getTracks()
            .forEach(
                track => track.stop()
            );

        stream = null;
    }
}


/*
 * Eventos dos botões.
 */
btnCamera.addEventListener(
    "click",
    abrirCamera
);


btnCapturar.addEventListener(
    "click",
    capturarFoto
);


btnNovaFoto.addEventListener(
    "click",
    novaFoto
);


btnRemoverFoto.addEventListener(
    "click",
    removerFoto
);


/*
 * Antes de enviar o formulário,
 * exige uma foto.
 */
document
    .getElementById("formVisitante")
    .addEventListener(
        "submit",
        function(event) {

            if (!possuiFoto()) {

                event.preventDefault();

                alert(
                    "Tire uma foto pela câmera "
                    + "ou anexe uma foto do visitante."
                );

                return;
            }
        }
    );


/*
 * Encerra a câmera se o usuário
 * sair da página.
 */
window.addEventListener(
    "beforeunload",
    function() {

        if (stream) {

            stream
                .getTracks()
                .forEach(
                    track => track.stop()
                );
        }

    }
);

</script>
<script src="../../assets/js/script.js"></script>

</body>

</html>