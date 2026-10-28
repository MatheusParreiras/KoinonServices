<?php

require_once __DIR__ . "/../../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/AutorizacaoAcessoController.php";
require_once __DIR__ . "/../../service/Erro.php";
require_once __DIR__ . "/../../service/Configuracao.php";

PermissaoController::exigirPerfil([
    "Morador"
]);

$usuarioId = (int) ($_SESSION["usuario_id"] ?? 0);

$id = (int) ($_GET["id"] ?? 0);

if ($id <= 0) {

    header(
        "Location: " . Configuracao::url("view/morador/autorizacoes.php")
    );

    exit;
}


$controller =
    new AutorizacaoAcessoController();


$autorizacao = null;

$erro = "";


try {

    /*
     * O método atual precisa dos dois parâmetros:
     *
     * ID da autorização
     * ID do morador logado
     */
    $autorizacao =
        $controller->buscarPorId(
            $id,
            $usuarioId
        );

} catch (Throwable $e) {

    $erro =
        Erro::mensagem($e);

}


/*
 * ============================================================
 * FUNÇÃO PARA PEGAR DADOS DO ARRAY
 * ============================================================
 */

function valorAutorizacao(
    mixed $autorizacao,
    array $chaves,
    mixed $padrao = null
): mixed {

    /*
     * Seu DAO atual retorna ARRAY.
     */
    if (is_array($autorizacao)) {

        foreach ($chaves as $chave) {

            if (
                array_key_exists(
                    $chave,
                    $autorizacao
                )
            ) {

                return $autorizacao[$chave];

            }

        }

        return $padrao;
    }


    /*
     * Deixamos compatibilidade caso futuramente
     * o DAO passe a retornar DTO.
     */
    if (is_object($autorizacao)) {

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


            if (
                method_exists(
                    $autorizacao,
                    $getter
                )
            ) {

                return $autorizacao->$getter();

            }

        }

    }


    return $padrao;
}


/*
 * ============================================================
 * SE NÃO ENCONTROU A AUTORIZAÇÃO
 * ============================================================
 */

if (
    !$autorizacao
    ||
    !is_array($autorizacao)
) {

    if ($erro === "") {

        $erro =
            "Autorização não encontrada.";

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
            QR Code - Koinon Services
        </title>

        <link
            href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
            rel="stylesheet"
        >

    
    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

    <body class="bg-light">


    <nav class="navbar navbar-dark bg-primary">

        <div class="container">

            <span class="navbar-brand fw-bold">

                🏢 Koinon Services

            </span>

        </div>

    </nav>


    <div class="container py-5">

        <div class="row justify-content-center">

            <div class="col-md-6">

                <div class="card shadow">

                    <div class="card-body text-center">


                        <div
                            style="font-size:60px;"
                        >

                            ⚠️

                        </div>


                        <h4 class="mt-3">

                            Não foi possível carregar
                            a autorização

                        </h4>


                        <div class="alert alert-danger mt-3">

                            <?= htmlspecialchars(
                                $erro
                            ) ?>

                        </div>


                        <a
                            href="<?= htmlspecialchars(Configuracao::url("view/morador/autorizacoes.php")) ?>"
                            class="btn btn-primary"
                        >

                            ← Voltar para autorizações

                        </a>


                    </div>

                </div>

            </div>

        </div>

    </div>
<script src="../../assets/js/script.js"></script>

</body>

    </html>

    <?php

    exit;
}


/*
 * ============================================================
 * DADOS DA AUTORIZAÇÃO
 * ============================================================
 */

$status =
    valorAutorizacao(
        $autorizacao,
        [
            "status"
        ],
        "Ativo"
    );


$qrCode =
    valorAutorizacao(
        $autorizacao,
        [
            "qr_code"
        ],
        ""
    );


$visitanteNome =
    valorAutorizacao(
        $autorizacao,
        [
            "visitante_nome",
            "nome_visitante"
        ],
        "Visitante"
    );


$visitanteDocumento =
    valorAutorizacao(
        $autorizacao,
        [
            "visitante_documento",
            "documento_visitante"
        ],
        "-"
    );


$tipoVisitante =
    valorAutorizacao(
        $autorizacao,
        [
            "tipo_visitante"
        ],
        "Visitante"
    );


$unidade =
    valorAutorizacao(
        $autorizacao,
        [
            "unidade_identificacao",
            "identificacao_unidade"
        ],
        "-"
    );


$dataInicio =
    valorAutorizacao(
        $autorizacao,
        [
            "data_inicio"
        ],
        null
    );


$dataFim =
    valorAutorizacao(
        $autorizacao,
        [
            "data_fim"
        ],
        null
    );


/*
 * ============================================================
 * FORMATAÇÃO DAS DATAS
 * ============================================================
 */

function formatarDataHora(
    mixed $data
): string {

    if (
        empty($data)
    ) {

        return "-";
    }


    $timestamp =
        strtotime(
            (string) $data
        );


    if (
        $timestamp === false
    ) {

        return htmlspecialchars(
            (string) $data
        );
    }


    return date(
        "d/m/Y H:i",
        $timestamp
    );
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
        QR Code - Koinon Services
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >


    <!-- Biblioteca para gerar QR Code -->

    <script
        src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous"
    ></script>


    <style>

        body {

            min-height: 100vh;

        }


        #qrcode {

            min-height: 260px;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 20px;

        }


        #qrcode img {

            margin: 0 auto;

        }


        .codigo {

            word-break: break-all;

            font-family: monospace;

            font-size: 13px;

        }

    </style>

</head>


<body class="bg-light">


<!-- ==========================================================
     NAVBAR
=========================================================== -->

<nav class="navbar navbar-dark bg-primary">

    <div class="container">

        <span class="navbar-brand fw-bold">

            🏢 Koinon Services

        </span>


        <a
            href="<?= htmlspecialchars(Configuracao::url("view/morador/autorizacoes.php")) ?>"
            class="btn btn-outline-light btn-sm"
        >

            ← Voltar

        </a>

    </div>

</nav>


<!-- ==========================================================
     CONTEÚDO
=========================================================== -->

<div class="container py-5">


    <div class="row justify-content-center">


        <div class="col-md-7 col-lg-6">


            <div class="card shadow">


                <div
                    class="card-header bg-success text-white text-center"
                >

                    <h4 class="mb-0">

                        📱 QR Code de acesso

                    </h4>

                </div>


                <div class="card-body text-center">


                    <!-- STATUS -->

                    <?php if (
                        $status === "Ativo"
                    ): ?>


                        <div class="alert alert-success">

                            <strong>

                                ✅ QR Code ativo

                            </strong>

                            <br>

                            Apresente este código
                            na portaria.

                        </div>


                    <?php elseif (
                        $status === "Utilizado"
                    ): ?>


                        <div class="alert alert-info">

                            <strong>

                                ℹ️ QR Code já utilizado

                            </strong>

                            <br>

                            Este QR Code já foi utilizado
                            para registrar uma entrada.

                        </div>


                    <?php elseif (
                        $status === "Expirado"
                    ): ?>


                        <div class="alert alert-warning">

                            <strong>

                                ⚠️ QR Code expirado

                            </strong>

                        </div>


                    <?php elseif (
                        $status === "Cancelado"
                    ): ?>


                        <div class="alert alert-danger">

                            <strong>

                                ❌ QR Code cancelado

                            </strong>

                        </div>


                    <?php endif; ?>


                    <!-- VISITANTE -->

                    <div class="mt-4">


                        <h4>

                            <?= htmlspecialchars(
                                (string) $visitanteNome
                            ) ?>

                        </h4>


                        <p class="mb-1">

                            <strong>
                                CPF:
                            </strong>

                            <?= htmlspecialchars(
                                Cpf::formatar($visitanteDocumento)
                            ) ?>

                        </p>


                        <p class="mb-1">

                            <strong>
                                Tipo:
                            </strong>

                            <?= htmlspecialchars(
                                (string) $tipoVisitante
                            ) ?>

                        </p>


                        <p class="mb-1">

                            <strong>
                                Unidade:
                            </strong>

                            <?= htmlspecialchars(
                                (string) $unidade
                            ) ?>

                        </p>


                        <p class="mb-1">

                            <strong>
                                Início:
                            </strong>

                            <?= formatarDataHora(
                                $dataInicio
                            ) ?>

                        </p>


                        <p class="mb-3">

                            <strong>
                                Fim:
                            </strong>

                            <?= formatarDataHora(
                                $dataFim
                            ) ?>

                        </p>


                    </div>


                    <hr>


                    <!-- QR CODE -->

                    <h5 class="mb-3">

                        Código de acesso

                    </h5>


                    <div
                        id="qrcode"
                        class="mb-3"
                    ></div>


                    <?php if (
                        empty($qrCode)
                    ): ?>


                        <div class="alert alert-danger">

                            O QR Code desta autorização
                            não foi encontrado.

                        </div>


                    <?php else: ?>


                        <div
                            class="alert alert-light border"
                        >

                            <strong>
                                Código:
                            </strong>

                            <br>

                            <span class="codigo">

                                <?= htmlspecialchars(
                                    (string) $qrCode
                                ) ?>

                            </span>

                        </div>


                    <?php endif; ?>


                    <!-- BOTÕES -->

                    <div
                        class="d-grid gap-2 mt-4"
                    >


                        <?php if (
                            $status === "Ativo"
                            &&
                            !empty($qrCode)
                        ): ?>

                            <button
                                type="button"
                                class="btn btn-primary"
                                onclick="window.print();"
                            >

                                🖨️ Imprimir / Salvar QR Code

                            </button>

                        <?php endif; ?>


                        <a
                            href="<?= htmlspecialchars(Configuracao::url("view/morador/autorizacoes.php")) ?>"
                            class="btn btn-outline-secondary"
                        >

                            ← Voltar para autorizações

                        </a>


                    </div>


                </div>

            </div>


        </div>


    </div>


</div>


<script>

/*
 * QR Code vindo do banco.
 */
const codigoQr =
    <?= json_encode(
        (string) $qrCode,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ) ?>;


/*
 * Gera o QR Code.
 */
if (
    codigoQr !== ""
) {

    const elemento =
        document.getElementById(
            "qrcode"
        );


    new QRCode(
        elemento,
        {
            text: codigoQr,
            width: 260,
            height: 260,
            correctLevel:
                QRCode.CorrectLevel.H
        }
    );

}

</script>


</body>

</html>