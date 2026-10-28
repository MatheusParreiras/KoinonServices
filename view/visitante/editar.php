<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../service/Erro.php";

PermissaoController::exigirPortaria();

require_once __DIR__ . "/../../controller/VisitanteController.php";

$controller = new VisitanteController();

$erro = "";

if (
    !isset($_GET["id"]) ||
    !is_numeric($_GET["id"])
) {
    header("Location: index.php?erro=1");
    exit;
}

$id = (int) $_GET["id"];

$visitante = $controller->buscarPorId($id);

if (!$visitante) {
    header("Location: index.php?erro=1");
    exit;
}


// ==============================
// PROCESSAMENTO DO FORMULÁRIO
// ==============================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        $nome = trim($_POST["nome"] ?? "");

        $documento = trim(
            $_POST["documento"] ?? ""
        );
        $documento = Cpf::limpar($documento);

        $tipoVisitante =
            $_POST["tipo_visitante"] ?? "Visitante";

        $fotoBase64 =
            $_POST["foto_webcam"] ?? "";


        // ==============================
        // VALIDAÇÕES
        // ==============================

        if (empty($nome)) {

            $erro =
                "Informe o nome do visitante.";

        } elseif (strlen($documento) !== 11) {

            $erro =
                "Informe o CPF do visitante no padrão 000.000.000-00 (11 números).";

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

            $erro =
                "Selecione um tipo de visitante válido.";

        } else {


            // ==============================
            // MANTÉM A FOTO ATUAL
            // ==============================

            $fotoUrl =
                $visitante->getFotoUrl();


            // ==============================
            // NOVA FOTO PELA WEBCAM
            // ==============================

            if (!empty($fotoBase64)) {

                if (
                    preg_match(
                        '/^data:image\/(\w+);base64,/',
                        $fotoBase64,
                        $tipoImagem
                    )
                ) {

                    $fotoBase64 =
                        substr(
                            $fotoBase64,
                            strpos($fotoBase64, ",") + 1
                        );

                    $fotoBase64 =
                        base64_decode($fotoBase64);


                    if ($fotoBase64 === false) {

                        throw new Exception(
                            "Não foi possível processar a foto."
                        );
                    }


                    // ==============================
                    // PASTA DAS FOTOS
                    // ==============================

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


                    // ==============================
                    // NOME DA NOVA FOTO
                    // ==============================

                    $nomeArquivo =
                        "visitante_" .
                        $id .
                        "_" .
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
                            "Não foi possível salvar a nova foto."
                        );
                    }


                    $fotoUrl =
                        "../../assets/img/visitantes/" .
                        $nomeArquivo;
                }
            }


            // ==============================
            // ATUALIZA DTO
            // ==============================

            $visitante->setNome($nome);

            $visitante->setDocumento(
                $documento
            );

            $visitante->setTipoVisitante(
                $tipoVisitante
            );

            $visitante->setFotoUrl(
                $fotoUrl
            );


            // ==============================
            // SALVA NO BANCO
            // ==============================

            if (
                $controller->editar(
                    $visitante
                )
            ) {

                header(
                    "Location: index.php?sucesso=3"
                );

                exit;

            } else {

                $erro =
                    "Não foi possível atualizar o visitante.";
            }
        }

    } catch (PDOException $e) {

        $erro =
            "Erro no banco de dados: " .
            Erro::mensagem($e);

    } catch (Exception $e) {

        $erro =
            "Erro: " .
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
        Editar Visitante - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
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

        .foto-atual {
            width: 180px;
            height: 180px;
            object-fit: cover;
            border-radius: 10px;
            border: 2px solid #ddd;
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

        <div class="card-header bg-warning">

            <h4 class="mb-0">
                Editar Visitante
            </h4>

        </div>

        <div class="card-body">

            <?php if (!empty($erro)): ?>

                <div class="alert alert-danger">

                    <?= htmlspecialchars($erro) ?>

                </div>

            <?php endif; ?>


            <form
                method="POST"
                id="formVisitante"
            >

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
                            value="<?= htmlspecialchars(
                                $_POST["nome"]
                                ?? $visitante->getNome()
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
                                $_POST["documento"]
                                ?? Cpf::formatar($visitante->getDocumento())
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- TIPO -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Tipo de visitante
                        </label>

                        <?php

                        $tipoAtual =
                            $_POST["tipo_visitante"]
                            ?? $visitante->getTipoVisitante();

                        ?>

                        <select
                            name="tipo_visitante"
                            class="form-select"
                            required
                        >

                            <option
                                value="Visitante"
                                <?= $tipoAtual === "Visitante"
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Visitante
                            </option>

                            <option
                                value="Prestador_Servico"
                                <?= $tipoAtual === "Prestador_Servico"
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Prestador de Serviço
                            </option>

                            <option
                                value="Entregador"
                                <?= $tipoAtual === "Entregador"
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Entregador
                            </option>

                        </select>

                    </div>


                    <!-- FOTO -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Foto do visitante
                        </label>

                        <div class="camera-box">


                            <!-- FOTO ATUAL -->

                            <?php if (
                                !empty(
                                    $visitante->getFotoUrl()
                                )
                            ): ?>

                                <div
                                    id="fotoAtualContainer"
                                    class="mb-3"
                                >

                                    <p class="mb-2">
                                        Foto atual:
                                    </p>

                                    <img
                                        src="<?= htmlspecialchars(
                                            $visitante->getFotoUrl()
                                        ) ?>"
                                        class="foto-atual"
                                        alt="Foto atual do visitante"
                                    >

                                </div>

                            <?php endif; ?>


                            <!-- BOTÕES -->

                            <div class="mb-2">

                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    id="btnAbrirCamera"
                                >
                                    📷 Nova foto
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
                                    alt="Nova foto capturada"
                                >

                            </div>


                            <canvas
                                id="canvas"
                                style="display:none;"
                            ></canvas>


                            <!-- FOTO BASE64 -->

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
                                Se quiser alterar a foto,
                                clique em "Nova foto".
                            </div>

                        </div>

                    </div>

                </div>


                <!-- BOTÕES -->

                <div class="mt-3">

                    <button
                        type="submit"
                        class="btn btn-warning"
                    >
                        Salvar alterações
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
    document.getElementById(
        "btnAbrirCamera"
    );

const btnTirarFoto =
    document.getElementById(
        "btnTirarFoto"
    );

const btnNovaFoto =
    document.getElementById(
        "btnNovaFoto"
    );

const cameraContainer =
    document.getElementById(
        "cameraContainer"
    );

const camera =
    document.getElementById(
        "camera"
    );

const canvas =
    document.getElementById(
        "canvas"
    );

const fotoCapturada =
    document.getElementById(
        "fotoCapturada"
    );

const fotoWebcam =
    document.getElementById(
        "foto_webcam"
    );

const mensagemCamera =
    document.getElementById(
        "mensagemCamera"
    );


// ==============================
// ABRIR CÂMERA
// ==============================

btnAbrirCamera.addEventListener(
    "click",
    async function () {

        try {

            stream =
                await navigator.mediaDevices
                    .getUserMedia({
                        video: true,
                        audio: false
                    });


            camera.srcObject =
                stream;


            cameraContainer.style.display =
                "block";


            btnAbrirCamera.style.display =
                "none";


            btnTirarFoto.style.display =
                "inline-block";


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


// ==============================
// TIRAR FOTO
// ==============================

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
            "Nova foto capturada! Ao salvar, ela substituirá a foto atual.";


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


// ==============================
// TIRAR OUTRA FOTO
// ==============================

btnNovaFoto.addEventListener(
    "click",
    function () {

        fotoCapturada.style.display =
            "none";


        fotoWebcam.value =
            "";


        btnNovaFoto.style.display =
            "none";


        btnAbrirCamera.style.display =
            "inline-block";


        mensagemCamera.innerText =
            "Clique em 'Nova foto' para tirar outra.";

        mensagemCamera.classList.remove(
            "text-success"
        );

        mensagemCamera.classList.add(
            "text-muted"
        );

    }
);


// ==============================
// DESLIGA CÂMERA AO SAIR
// ==============================

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
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"
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