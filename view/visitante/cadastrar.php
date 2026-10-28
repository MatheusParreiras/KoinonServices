<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirPortaria();

require_once __DIR__ . "/../../controller/VisitanteController.php";

$controller = new VisitanteController();

$erro = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        $nome = trim($_POST["nome"] ?? "");
        $documento = trim($_POST["documento"] ?? "");
        $documento = Cpf::limpar($documento);
        $tipoVisitante = $_POST["tipo_visitante"] ?? "Visitante";

        // Foto enviada pela webcam
        $fotoBase64 = $_POST["foto_webcam"] ?? "";

        if (empty($nome)) {

            $erro = "Informe o nome do visitante.";

        } elseif (strlen($documento) !== 11) {

            $erro = "Informe o CPF do visitante no padrão 000.000.000-00 (11 números).";

        } elseif (
            !in_array(
                $tipoVisitante,
                [
                    "Visitante",
                    "Prestador_Servico",
                    "Entregador"
                ]
            )
        ) {

            $erro = "Selecione um tipo de visitante válido.";

        } else {

            $fotoUrl = null;

            /*
             * Se uma foto foi tirada pela webcam,
             * salva a imagem no servidor.
             */
            if (!empty($fotoBase64)) {

                if (
                    preg_match(
                        '/^data:image\/(\w+);base64,/',
                        $fotoBase64,
                        $tipoImagem
                    )
                ) {

                    $fotoBase64 = substr(
                        $fotoBase64,
                        strpos($fotoBase64, ",") + 1
                    );

                    $fotoBase64 = base64_decode($fotoBase64);

                    if ($fotoBase64 === false) {

                        throw new Exception(
                            "Não foi possível processar a foto."
                        );
                    }

                    $pastaFotos =
                        __DIR__ .
                        "/../../assets/img/visitantes/";

                    if (!is_dir($pastaFotos)) {

                        mkdir(
                            $pastaFotos,
                            0777,
                            true
                        );
                    }

                    $nomeArquivo =
                        "visitante_" .
                        date("Ymd_His") .
                        "_" .
                        uniqid() .
                        ".jpg";

                    $caminhoCompleto =
                        $pastaFotos .
                        $nomeArquivo;

                    if (
                        !file_put_contents(
                            $caminhoCompleto,
                            $fotoBase64
                        )
                    ) {

                        throw new Exception(
                            "Não foi possível salvar a foto."
                        );
                    }

                    $fotoUrl =
                        "../../assets/img/visitantes/" .
                        $nomeArquivo;
                }
            }

            $visitante = new VisitanteDTO();

            $visitante->setNome($nome);
            $visitante->setDocumento($documento);
            $visitante->setFotoUrl($fotoUrl);
            $visitante->setTipoVisitante($tipoVisitante);

            if ($controller->cadastrar($visitante)) {

                header(
                    "Location: index.php?sucesso=1"
                );

                exit;

            } else {

                $erro =
                    "Não foi possível cadastrar o visitante.";
            }
        }

    } catch (PDOException $e) {

        $erro =
            "Erro no banco de dados: " .
            $e->getMessage();

    } catch (Exception $e) {

        $erro =
            "Erro: " .
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
        Cadastrar Visitante - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        #cameraContainer {
            display: none;
        }

        #camera {
            width: 100%;
            max-width: 500px;
            border-radius: 10px;
            background: #000;
        }

        #fotoCapturada {
            width: 100%;
            max-width: 500px;
            border-radius: 10px;
            display: none;
        }

        .camera-box {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
            background: #f8f9fa;
        }

    </style>


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container mt-5">

    <div class="card shadow">

        <div class="card-header bg-primary text-white">

            <h4 class="mb-0">
                Cadastrar Visitante
            </h4>

        </div>

        <div class="card-body">

            <?php if (!empty($erro)): ?>

                <div class="alert alert-danger">

                    <?= htmlspecialchars($erro) ?>

                </div>

            <?php endif; ?>


            <form method="POST" id="formVisitante">

                <div class="row">

                    <!-- NOME -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Nome completo
                        </label>

                        <input
                            type="text"
                            name="nome"
                            class="form-control"
                            maxlength="150"
                            placeholder="Ex.: João da Silva"
                            value="<?= htmlspecialchars(
                                $_POST["nome"] ?? ""
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- DOCUMENTO -->

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
                            value="<?= htmlspecialchars(
                                $_POST["documento"] ?? ""
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- TIPO -->

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
                                    ($_POST["tipo_visitante"] ?? "")
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


                    <!-- WEBCAM -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Foto do visitante
                        </label>

                        <div class="camera-box">

                            <div class="mb-2">

                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    id="btnAbrirCamera"
                                >
                                    📷 Abrir câmera
                                </button>

                                <button
                                    type="button"
                                    class="btn btn-success"
                                    id="btnTirarFoto"
                                    style="display:none;"
                                >
                                    📸 Tirar foto
                                </button>

                                <button
                                    type="button"
                                    class="btn btn-warning"
                                    id="btnNovaFoto"
                                    style="display:none;"
                                >
                                    🔄 Tirar outra
                                </button>

                            </div>


                            <!-- CÂMERA -->

                            <div
                                id="cameraContainer"
                                class="mt-3"
                            >

                                <video
                                    id="camera"
                                    autoplay
                                    playsinline
                                ></video>

                            </div>


                            <!-- FOTO CAPTURADA -->

                            <div class="mt-3">

                                <img
                                    id="fotoCapturada"
                                    alt="Foto capturada"
                                >

                            </div>


                            <!-- CANVAS ESCONDIDO -->

                            <canvas
                                id="canvas"
                                style="display:none;"
                            ></canvas>


                            <!-- CAMPO OCULTO -->

                            <input
                                type="hidden"
                                name="foto_webcam"
                                id="foto_webcam"
                            >


                            <!-- FOTO DO COMPUTADOR / GALERIA -->

                            <div class="mt-3">
                                <label for="foto_arquivo" class="form-label fw-semibold">
                                    🖼️ Ou anexar uma foto do computador
                                </label>
                                <input
                                    type="file"
                                    id="foto_arquivo"
                                    class="form-control"
                                    accept="image/png,image/jpeg,image/webp"
                                >
                                <div class="form-text">
                                    A imagem é ajustada automaticamente e substitui a foto da câmera.
                                </div>
                                <img
                                    id="fotoArquivoPrevia"
                                    alt="Prévia da foto anexada"
                                    style="display:none;max-width:220px;border-radius:12px;margin-top:10px;"
                                >
                            </div>


                            <div
                                id="mensagemCamera"
                                class="text-muted mt-2"
                            >
                                Clique em "Abrir câmera" para utilizar
                                a webcam.
                            </div>

                        </div>

                    </div>

                </div>


                <!-- BOTÕES -->

                <div class="mt-3">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        Cadastrar visitante
                    </button>

                    <a
                        href="index.php"
                        class="btn btn-secondary"
                    >
                        Voltar
                    </a>

                </div>

            </form>

        </div>

    </div>

</div>


<script>

let stream = null;

const btnAbrirCamera =
    document.getElementById("btnAbrirCamera");

const btnTirarFoto =
    document.getElementById("btnTirarFoto");

const btnNovaFoto =
    document.getElementById("btnNovaFoto");

const cameraContainer =
    document.getElementById("cameraContainer");

const camera =
    document.getElementById("camera");

const canvas =
    document.getElementById("canvas");

const fotoCapturada =
    document.getElementById("fotoCapturada");

const fotoWebcam =
    document.getElementById("foto_webcam");

const mensagemCamera =
    document.getElementById("mensagemCamera");


// ABRIR CÂMERA

btnAbrirCamera.addEventListener(
    "click",
    async function () {

        try {

            stream =
                await navigator.mediaDevices.getUserMedia({
                    video: true,
                    audio: false
                });

            camera.srcObject = stream;

            cameraContainer.style.display = "block";

            btnAbrirCamera.style.display = "none";

            btnTirarFoto.style.display = "inline-block";

            mensagemCamera.innerText =
                "Câmera ativada. Posicione o visitante e clique em 'Tirar foto'.";

        } catch (erro) {

            console.error(erro);

            mensagemCamera.innerText =
                "Não foi possível acessar a câmera. Verifique a permissão do navegador.";

            mensagemCamera.classList.remove(
                "text-muted"
            );

            mensagemCamera.classList.add(
                "text-danger"
            );
        }

    }
);


// TIRAR FOTO

btnTirarFoto.addEventListener(
    "click",
    function () {

        if (!stream) {

            return;
        }


        canvas.width =
            camera.videoWidth;

        canvas.height =
            camera.videoHeight;


        const contexto =
            canvas.getContext("2d");


        contexto.drawImage(
            camera,
            0,
            0,
            canvas.width,
            canvas.height
        );


        const imagem =
            canvas.toDataURL(
                "image/jpeg",
                0.85
            );


        fotoWebcam.value =
            imagem;


        fotoCapturada.src =
            imagem;

        fotoCapturada.style.display =
            "block";


        cameraContainer.style.display =
            "none";


        btnTirarFoto.style.display =
            "none";

        btnNovaFoto.style.display =
            "inline-block";


        mensagemCamera.innerText =
            "Foto capturada com sucesso!";


        mensagemCamera.classList.remove(
            "text-muted"
        );

        mensagemCamera.classList.add(
            "text-success"
        );


        // Desliga a câmera

        stream.getTracks().forEach(
            function (track) {

                track.stop();

            }
        );

        stream = null;

    }
);


// TIRAR OUTRA FOTO

btnNovaFoto.addEventListener(
    "click",
    function () {

        fotoCapturada.style.display =
            "none";

        fotoWebcam.value = "";

        btnNovaFoto.style.display =
            "none";

        btnAbrirCamera.style.display =
            "inline-block";


        mensagemCamera.innerText =
            "Clique em 'Abrir câmera' para tirar uma nova foto.";

        mensagemCamera.classList.remove(
            "text-success"
        );

        mensagemCamera.classList.add(
            "text-muted"
        );

    }
);


// AO SAIR DA PÁGINA, DESLIGA A CÂMERA

window.addEventListener(
    "beforeunload",
    function () {

        if (stream) {

            stream.getTracks().forEach(
                function (track) {

                    track.stop();

                }
            );

        }

    }
);

</script>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>
<script src="../../assets/js/script.js">
/* Foto anexada do computador: redimensiona e envia no mesmo campo da câmera */
(function () {
    var arq = document.getElementById("foto_arquivo");
    var campo = document.getElementById("foto_webcam");
    var previa = document.getElementById("fotoArquivoPrevia");
    if (!arq || !campo) { return; }
    arq.addEventListener("change", function () {
        var f = arq.files && arq.files[0];
        if (!f) { return; }
        if (!/^image\//.test(f.type)) { alert("Escolha um arquivo de imagem."); arq.value = ""; return; }
        var leitor = new FileReader();
        leitor.onload = function () {
            var img = new Image();
            img.onload = function () {
                var max = 900, w = img.width, h = img.height;
                if (w > max || h > max) { var r = Math.min(max / w, max / h); w = Math.round(w * r); h = Math.round(h * r); }
                var c = document.createElement("canvas");
                c.width = w; c.height = h;
                c.getContext("2d").drawImage(img, 0, 0, w, h);
                var data = c.toDataURL("image/jpeg", 0.85);
                campo.value = data;
                if (previa) { previa.src = data; previa.style.display = "block"; }
            };
            img.src = leitor.result;
        };
        leitor.readAsDataURL(f);
    });
})();
</script>

</body>

</html>