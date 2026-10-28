<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../../model/dao/Conexao.php";
require_once __DIR__ . "/../../controller/RegistroAcessoController.php";
require_once __DIR__ . "/../../controller/VisitanteController.php";
require_once __DIR__ . "/../../model/dto/RegistroAcessoDTO.php";
require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../service/Erro.php";
require_once __DIR__ . "/../../service/Configuracao.php";

PermissaoController::exigirPortaria();

$registroController = new RegistroAcessoController();
$visitanteController = new VisitanteController();

$mensagem = "";
$tipoMensagem = "";

$visitante = null;
$autorizacao = null;


/*
 * ============================================================
 * FUNÇÕES AUXILIARES
 * ============================================================
 */

function valorVisitante(
    mixed $visitante,
    array $chaves,
    mixed $padrao = null
): mixed {

    if (is_array($visitante)) {

        foreach ($chaves as $chave) {

            if (array_key_exists($chave, $visitante)) {
                return $visitante[$chave];
            }

        }

        return $padrao;
    }


    if (is_object($visitante)) {

        foreach ($chaves as $chave) {

            $getter =
                "get"
                . str_replace(
                    " ",
                    "",
                    ucwords(
                        str_replace(
                            "_",
                            " ",
                            $chave
                        )
                    )
                );


            if (method_exists($visitante, $getter)) {
                return $visitante->$getter();
            }


            if (isset($visitante->$chave)) {
                return $visitante->$chave;
            }

        }

    }


    return $padrao;
}


function urlFotoVisitante(
    ?string $foto
): ?string {

    if (empty($foto)) {
        return null;
    }


    $foto = trim($foto);


    if ($foto === "") {
        return null;
    }


    if (
        str_starts_with($foto, "http://")
        ||
        str_starts_with($foto, "https://")
    ) {

        return $foto;
    }


    if (
        str_starts_with(
            $foto,
            Configuracao::url()
        )
    ) {

        return $foto;
    }


    $foto = ltrim($foto, "/");


    while (
        str_starts_with(
            $foto,
            "../"
        )
    ) {

        $foto = substr($foto, 3);
    }


    return Configuracao::url()
        . ltrim($foto, "/");
}


/*
 * ============================================================
 * PROCESSAMENTO DO QR CODE
 * ============================================================
 */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $qrCode =
        trim(
            $_POST["qr_code"] ?? ""
        );


    if ($qrCode === "") {

        $mensagem =
            "Informe ou escaneie um QR Code.";

        $tipoMensagem =
            "danger";

    } else {

        try {

            $conexao =
                Conexao::conectar();


            $sql = "
                SELECT
                    a.id,
                    a.visitante_id,
                    a.unidade_id,
                    a.usuario_autorizador_id,
                    a.data_inicio,
                    a.data_fim,
                    a.qr_code,
                    a.status,

                    u.identificacao AS unidade_identificacao

                FROM autorizacao_acesso a

                LEFT JOIN unidade u
                    ON u.id = a.unidade_id

                WHERE a.qr_code = :qr_code

                LIMIT 1
            ";


            $stmt =
                $conexao->prepare(
                    $sql
                );


            $stmt->bindValue(
                ":qr_code",
                $qrCode,
                PDO::PARAM_STR
            );


            $stmt->execute();


            $autorizacao =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$autorizacao) {

                $mensagem =
                    "QR Code inválido ou não encontrado.";

                $tipoMensagem =
                    "danger";

            } else {

                if (
                    $autorizacao["status"]
                    !==
                    "Ativo"
                ) {

                    $mensagem =
                        "Este QR Code não está disponível "
                        . "para acesso. Status atual: "
                        . $autorizacao["status"]
                        . ".";

                    $tipoMensagem =
                        "danger";

                } else {

                    $agora =
                        new DateTime();


                    $inicio =
                        new DateTime(
                            $autorizacao["data_inicio"]
                        );


                    $fim =
                        new DateTime(
                            $autorizacao["data_fim"]
                        );


                    if ($agora < $inicio) {

                        $mensagem =
                            "Este QR Code ainda não está válido. "
                            . "O acesso começa em "
                            . $inicio->format(
                                "d/m/Y H:i"
                            )
                            . ".";

                        $tipoMensagem =
                            "warning";

                    } elseif ($agora > $fim) {

                        $update =
                            $conexao->prepare("
                                UPDATE autorizacao_acesso
                                SET status = 'Expirado'
                                WHERE id = :id
                            ");


                        $update->bindValue(
                            ":id",
                            $autorizacao["id"],
                            PDO::PARAM_INT
                        );


                        $update->execute();


                        $mensagem =
                            "Este QR Code está expirado.";

                        $tipoMensagem =
                            "danger";

                    } else {

                        $visitante =
                            $visitanteController->buscarPorId(
                                (int) $autorizacao["visitante_id"]
                            );


                        if (!$visitante) {

                            $mensagem =
                                "O visitante associado ao QR Code "
                                . "não foi encontrado.";

                            $tipoMensagem =
                                "danger";

                        } else {

                            $registro =
                                new RegistroAcessoDTO();


                            $registro->setVisitanteId(
                                (int) $autorizacao["visitante_id"]
                            );


                            if (
                                isset(
                                    $_SESSION["usuario_id"]
                                )
                            ) {

                                $registro->setUsuarioId(
                                    (int) $_SESSION["usuario_id"]
                                );
                            }


                            $registro->setDataHora(
                                date("Y-m-d H:i:s")
                            );


                            $registro->setDirecao(
                                "Entrada"
                            );


                            $registro->setMetodoLeitura(
                                "QR_Code"
                            );


                            $registro->setObservacao(
                                "Entrada autorizada através de QR Code."
                            );


                            if (
                                $registroController->cadastrar(
                                    $registro
                                )
                            ) {

                                $update =
                                    $conexao->prepare("
                                        UPDATE autorizacao_acesso
                                        SET status = 'Utilizado'
                                        WHERE id = :id
                                    ");


                                $update->bindValue(
                                    ":id",
                                    $autorizacao["id"],
                                    PDO::PARAM_INT
                                );


                                $update->execute();


                                $mensagem =
                                    "Acesso autorizado! "
                                    . "Entrada registrada com sucesso.";

                                $tipoMensagem =
                                    "success";

                            } else {

                                $mensagem =
                                    "O QR Code é válido, "
                                    . "mas não foi possível "
                                    . "registrar a entrada.";

                                $tipoMensagem =
                                    "danger";
                            }

                        }

                    }

                }

            }

        } catch (Throwable $e) {

            $mensagem =
                "Erro ao validar o QR Code: "
                . Erro::mensagem($e);

            $tipoMensagem =
                "danger";
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
        Validar QR Code - Koinon Services
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <script
        src="https://unpkg.com/html5-qrcode"
        type="text/javascript"
    ></script>


    <style>

        .foto-visitante-portaria {

            width: 180px;
            height: 180px;

            object-fit: cover;

            border-radius: 12px;

            border: 4px solid #fff;

            box-shadow:
                0 4px 15px
                rgba(0, 0, 0, 0.20);

            background: #f1f1f1;
        }


        .sem-foto-portaria {

            width: 180px;
            height: 180px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 12px;

            background: #e9ecef;

            color: #6c757d;

            font-size: 18px;

            text-align: center;

            border: 4px solid #fff;

            box-shadow:
                0 4px 15px
                rgba(0, 0, 0, 0.15);
        }


        .dados-acesso {
            font-size: 16px;
        }


        .acesso-autorizado {
            border: 2px solid #198754;
        }


        #reader {

            width: 100%;

            max-width: 500px;

            margin: 0 auto;

            overflow: hidden;

            border-radius: 12px;
        }


        #reader video {

            width: 100% !important;

            height: auto !important;

            border-radius: 12px;
        }


        .camera-box {

            background: #f8f9fa;

            border: 1px solid #dee2e6;

            border-radius: 12px;

            padding: 15px;
        }


        @media (max-width: 576px) {

            .foto-visitante-portaria,
            .sem-foto-portaria {

                width: 140px;
                height: 140px;
            }

        }

    </style>


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>


<body class="bg-light">


<div class="container mt-5">


    <div class="row justify-content-center">


        <div class="col-md-8">


            <div class="card shadow">


                <div
                    class="card-header bg-primary text-white text-center"
                >

                    <h4 class="mb-0">
                        📱 Validar QR Code
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


                    <?php if ($visitante): ?>

                        <?php

                        $visitanteNome =
                            valorVisitante(
                                $visitante,
                                ["nome"],
                                "Visitante"
                            );


                        $visitanteDocumento =
                            valorVisitante(
                                $visitante,
                                ["documento"],
                                "-"
                            );


                        $visitanteTipo =
                            valorVisitante(
                                $visitante,
                                ["tipo_visitante"],
                                "Visitante"
                            );


                        $visitanteFoto =
                            valorVisitante(
                                $visitante,
                                [
                                    "foto_url",
                                    "foto",
                                    "foto_visitante"
                                ],
                                null
                            );


                        $fotoUrl =
                            urlFotoVisitante(
                                $visitanteFoto
                            );


                        $unidadeIdentificacao =
                            $autorizacao[
                                "unidade_identificacao"
                            ]
                            ??
                            "-";

                        ?>


                        <div
                            class="card acesso-autorizado border-success mb-4"
                        >

                            <div
                                class="card-header bg-success text-white text-center"
                            >

                                <h4 class="mb-0">
                                    ✅ ACESSO AUTORIZADO
                                </h4>

                            </div>


                            <div class="card-body">


                                <div class="row align-items-center">


                                    <div
                                        class="col-md-5 text-center mb-3 mb-md-0"
                                    >

                                        <?php if (!empty($fotoUrl)): ?>

                                            <img
                                                src="<?= htmlspecialchars(
                                                    $fotoUrl
                                                ) ?>"
                                                alt="Foto do visitante"
                                                class="foto-visitante-portaria"
                                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                            >


                                            <div
                                                class="sem-foto-portaria"
                                                style="display:none; margin:0 auto;"
                                            >

                                                📷<br>
                                                Foto não encontrada

                                            </div>

                                        <?php else: ?>

                                            <div
                                                class="sem-foto-portaria"
                                                style="margin:0 auto;"
                                            >

                                                📷<br>
                                                Sem foto cadastrada

                                            </div>

                                        <?php endif; ?>

                                    </div>


                                    <div
                                        class="col-md-7 dados-acesso"
                                    >

                                        <h4 class="mb-3">

                                            <?= htmlspecialchars(
                                                (string) $visitanteNome
                                            ) ?>

                                        </h4>


                                        <p class="mb-2">

                                            <strong>
                                                CPF:
                                            </strong>

                                            <?= htmlspecialchars(
                                                Cpf::formatar($visitanteDocumento)
                                            ) ?>

                                        </p>


                                        <p class="mb-2">

                                            <strong>
                                                Tipo:
                                            </strong>

                                            <?= htmlspecialchars(
                                                (string) $visitanteTipo
                                            ) ?>

                                        </p>


                                        <p class="mb-2">

                                            <strong>
                                                Unidade:
                                            </strong>

                                            <?= htmlspecialchars(
                                                (string) $unidadeIdentificacao
                                            ) ?>

                                        </p>


                                        <p class="mb-2">

                                            <strong>
                                                Entrada:
                                            </strong>

                                            <?= date(
                                                "d/m/Y H:i:s"
                                            ) ?>

                                        </p>


                                        <p class="mb-0">

                                            <strong>
                                                Método:
                                            </strong>

                                            QR Code

                                        </p>

                                    </div>

                                </div>

                            </div>

                        </div>

                    <?php endif; ?>


                    <!-- ==================================================
                         LEITOR QR CODE
                    ================================================== -->

                    <div class="text-center mb-4">

                        <h5>
                            📷 Escanear pela câmera
                        </h5>


                        <p class="text-muted">

                            Clique em iniciar câmera e permita
                            o acesso quando o navegador solicitar.

                        </p>

                    </div>


                    <div class="camera-box">

                        <div
                            id="reader"
                        ></div>

                    </div>


                    <div
                        id="cameraMensagem"
                        class="alert alert-info mt-3"
                    >

                        Clique em
                        <strong>"Iniciar câmera"</strong>
                        para começar.

                    </div>


                    <div class="d-grid mb-4">

                        <button
                            type="button"
                            id="btnCamera"
                            class="btn btn-primary btn-lg"
                        >

                            📷 Iniciar câmera

                        </button>

                    </div>


                    <hr>


                    <!-- ==================================================
                         DIGITAÇÃO MANUAL
                    ================================================== -->

                    <div class="text-center mb-3">

                        <h5>
                            ⌨️ Digitação manual
                        </h5>


                        <p class="text-muted">

                            Caso não seja possível utilizar
                            a câmera, informe o código manualmente.

                        </p>

                    </div>


                    <form
                        method="POST"
                        action=""
                        id="formQr"
                    >

                        <div class="mb-3">

                            <label
                                for="qr_code"
                                class="form-label fw-bold"
                            >

                                Código do QR Code

                            </label>


                            <input
                                type="text"
                                name="qr_code"
                                id="qr_code"
                                class="form-control form-control-lg text-center"
                                placeholder="Código do QR Code"
                                autocomplete="off"
                                required
                            >

                        </div>


                        <div class="d-grid">

                            <button
                                type="submit"
                                class="btn btn-success btn-lg"
                            >

                                🔎 Validar Acesso

                            </button>

                        </div>

                    </form>


                    <div class="text-center mt-4">

                        <a
                            href="index.php"
                            class="btn btn-secondary"
                        >

                            Voltar para Portaria

                        </a>

                    </div>


                </div>

            </div>

        </div>

    </div>

</div>


<script>

let scanner = null;

let cameraAtiva = false;

let iniciandoCamera = false;

let processandoQr = false;


const botaoCamera =
    document.getElementById(
        "btnCamera"
    );


const mensagemCamera =
    document.getElementById(
        "cameraMensagem"
    );


const campoQr =
    document.getElementById(
        "qr_code"
    );


const formulario =
    document.getElementById(
        "formQr"
    );


/*
 * ============================================================
 * MENSAGEM DA CÂMERA
 * ============================================================
 */

function mostrarMensagemCamera(
    texto,
    tipo = "info"
) {

    mensagemCamera.className =
        "alert alert-" + tipo + " mt-3";

    mensagemCamera.innerHTML =
        texto;
}


/*
 * ============================================================
 * BOTÃO DA CÂMERA
 * ============================================================
 */

botaoCamera.addEventListener(
    "click",
    function () {

        if (iniciandoCamera) {
            return;
        }


        if (cameraAtiva) {

            pararCamera();

        } else {

            iniciarCamera();

        }

    }
);


/*
 * ============================================================
 * INICIAR CÂMERA
 * ============================================================
 */

async function iniciarCamera() {

    if (iniciandoCamera) {
        return;
    }


    iniciandoCamera = true;

    botaoCamera.disabled = true;


    mostrarMensagemCamera(
        "⏳ Solicitando acesso à câmera...",
        "info"
    );


    try {

        /*
         * Primeiro verificamos se o navegador
         * permite acesso à câmera.
         */

        if (
            !navigator.mediaDevices
            ||
            !navigator.mediaDevices.getUserMedia
        ) {

            throw new Error(
                "Este navegador não disponibiliza acesso à câmera."
            );
        }


        /*
         * Solicita a câmera diretamente.
         *
         * Isso força o navegador a mostrar
         * a solicitação de permissão.
         */

        const stream =
            await navigator.mediaDevices.getUserMedia(
                {
                    video: {
                        facingMode: {
                            ideal: "environment"
                        }
                    },
                    audio: false
                }
            );


        /*
         * Paramos o stream de teste.
         *
         * O Html5Qrcode assumirá a câmera
         * logo depois.
         */

        stream.getTracks().forEach(
            function (track) {
                track.stop();
            }
        );


        /*
         * Cria o leitor.
         */

        scanner =
            new Html5Qrcode(
                "reader"
            );


        /*
         * Configuração do leitor.
         */

        const configuracao = {

            fps: 10,

            qrbox: function (
                largura,
                altura
            ) {

                const tamanho =
                    Math.min(
                        largura,
                        altura,
                        280
                    );

                return {
                    width: tamanho,
                    height: tamanho
                };

            },

            aspectRatio: 1.0

        };


        /*
         * Tenta iniciar usando a câmera traseira.
         */

        await scanner.start(

            {
                facingMode: "environment"
            },

            configuracao,

            function (
                codigoLido
            ) {

                if (processandoQr) {
                    return;
                }


                processandoQr = true;


                campoQr.value =
                    codigoLido;


                mostrarMensagemCamera(
                    "✅ QR Code identificado! Validando acesso...",
                    "success"
                );


                pararCamera(
                    true
                );


                /*
                 * Pequeno intervalo para garantir
                 * que a câmera seja encerrada antes
                 * do envio do formulário.
                 */

                setTimeout(
                    function () {

                        formulario.submit();

                    },
                    300
                );

            },

            function () {

                /*
                 * Erros de leitura são normais enquanto
                 * o leitor procura um QR Code.
                 */

            }

        );


        cameraAtiva = true;


        botaoCamera.disabled = false;

        botaoCamera.innerText =
            "⛔ Parar câmera";


        botaoCamera.classList.remove(
            "btn-primary"
        );


        botaoCamera.classList.add(
            "btn-danger"
        );


        mostrarMensagemCamera(
            "🟢 Câmera ativa. Aponte para o QR Code do visitante.",
            "success"
        );


    } catch (erro) {

        console.error(
            "Erro ao iniciar câmera:",
            erro
        );


        cameraAtiva = false;


        if (scanner) {

            try {

                await scanner.clear();

            } catch (e) {

                console.error(e);

            }

        }


        scanner = null;


        botaoCamera.disabled = false;


        botaoCamera.innerText =
            "📷 Iniciar câmera";


        botaoCamera.classList.remove(
            "btn-danger"
        );


        botaoCamera.classList.add(
            "btn-primary"
        );


        let mensagem =
            "❌ Não foi possível iniciar a câmera.";


        /*
         * Permissão negada.
         */

        if (
            erro.name === "NotAllowedError"
            ||
            erro.name === "PermissionDeniedError"
        ) {

            mensagem =
                "🔒 O acesso à câmera foi bloqueado. "
                + "Clique no ícone de câmera/cadeado na barra de endereço "
                + "do navegador, permita a câmera e tente novamente.";

        }


        /*
         * Nenhuma câmera encontrada.
         */

        else if (
            erro.name === "NotFoundError"
            ||
            erro.name === "DevicesNotFoundError"
        ) {

            mensagem =
                "📷 Nenhuma câmera foi encontrada neste computador.";

        }


        /*
         * Câmera ocupada por outro programa.
         */

        else if (
            erro.name === "NotReadableError"
            ||
            erro.name === "TrackStartError"
        ) {

            mensagem =
                "⚠️ A câmera está sendo usada por outro programa. "
                + "Feche aplicativos como Teams, Zoom, WhatsApp ou outro leitor "
                + "de câmera e tente novamente.";

        }


        /*
         * Página sem permissão para câmera.
         */

        else if (
            erro.name === "SecurityError"
        ) {

            mensagem =
                "🔒 O navegador bloqueou o acesso à câmera "
                + "por segurança. Verifique as permissões do site.";

        }


        /*
         * Página em origem insegura.
         */

        else if (
            window.isSecureContext === false
        ) {

            mensagem =
                "🔐 O navegador não considerou esta página segura "
                + "para utilizar a câmera. Tente acessar pelo endereço "
                + "localhost do XAMPP.";

        }


        mostrarMensagemCamera(
            mensagem,
            "danger"
        );


    } finally {

        iniciandoCamera = false;

        botaoCamera.disabled = false;

    }

}


/*
 * ============================================================
 * PARAR CÂMERA
 * ============================================================
 */

async function pararCamera(
    mantendoMensagem = false
) {

    if (!scanner) {

        cameraAtiva = false;

        return;

    }


    try {

        if (cameraAtiva) {

            await scanner.stop();

        }


        await scanner.clear();


    } catch (erro) {

        console.error(
            "Erro ao parar câmera:",
            erro
        );

    }


    scanner = null;

    cameraAtiva = false;


    botaoCamera.innerText =
        "📷 Iniciar câmera";


    botaoCamera.classList.remove(
        "btn-danger"
    );


    botaoCamera.classList.add(
        "btn-primary"
    );


    if (!mantendoMensagem) {

        mostrarMensagemCamera(
            "Câmera desligada. Clique em "
            + "\"Iniciar câmera\" para utilizar novamente.",
            "info"
        );

    }

}


/*
 * ============================================================
 * AO SAIR DA PÁGINA
 * ============================================================
 */

window.addEventListener(
    "beforeunload",
    function () {

        if (scanner && cameraAtiva) {

            scanner.stop().catch(
                function () {}
            );

        }

    }
);

</script>
<script src="../../assets/js/script.js"></script>

</body>

</html>