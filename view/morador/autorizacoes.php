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

$controller = new AutorizacaoAcessoController();

$autorizacoes = [];

$erro = "";

try {

    $autorizacoes = $controller->listarPorMorador(
        $usuarioId
    );

} catch (Throwable $e) {

    $erro = Erro::mensagem($e);

}


/*
 * ============================================================
 * FUNÇÕES AUXILIARES
 * ============================================================
 */

/*
 * Pega um valor de uma autorização.
 *
 * O DAO atual retorna ARRAY.
 *
 * Esta função também aceita objeto caso algum outro
 * ponto do sistema futuramente retorne um DTO.
 */
function valorAutorizacao(
    mixed $autorizacao,
    array $chaves,
    mixed $padrao = null
): mixed {

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


    if (is_object($autorizacao)) {

        foreach ($chaves as $chave) {

            /*
             * Se existir getter correspondente.
             */
            $nomeGetter =
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
                    $nomeGetter
                )
            ) {

                return $autorizacao->$nomeGetter();

            }


            /*
             * Tenta propriedade pública.
             */
            if (
                isset(
                    $autorizacao->$chave
                )
            ) {

                return $autorizacao->$chave;

            }

        }

    }


    return $padrao;
}


/*
 * Converte o caminho salvo no banco em uma URL
 * que funcione em qualquer página do sistema.
 */
function urlFotoVisitante(
    ?string $foto
): ?string {

    if (
        empty($foto)
    ) {

        return null;
    }


    $foto = trim($foto);


    if (
        $foto === ""
    ) {

        return null;
    }


    /*
     * Se já for uma URL completa.
     */
    if (
        str_starts_with(
            $foto,
            "http://"
        )
        ||
        str_starts_with(
            $foto,
            "https://"
        )
    ) {

        return $foto;

    }


    /*
     * Se já estiver como:
     *
     * /koinon-service/assets/...
     */
    if (
        str_starts_with(
            $foto,
            Configuracao::url()
        )
    ) {

        return $foto;

    }


    /*
     * Remove barras iniciais.
     */
    $foto = ltrim(
        $foto,
        "/"
    );


    /*
     * Remove caminhos relativos.
     *
     * Exemplo:
     *
     * ../../assets/img/visitantes/foto.jpg
     *
     * vira:
     *
     * assets/img/visitantes/foto.jpg
     */
    while (
        str_starts_with(
            $foto,
            "../"
        )
    ) {

        $foto = substr(
            $foto,
            3
        );

    }


    return Configuracao::url()
        . ltrim(
            $foto,
            "/"
        );
}


/*
 * Formata data/hora.
 */
function formatarDataHora(
    mixed $data
): string {

    if (
        empty($data)
    ) {

        return "-";
    }


    $timestamp = strtotime(
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
        Minhas Autorizações - Koinon Services
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <style>

        body {

            min-height: 100vh;

        }


        .foto-visitante {

            width: 100%;

            height: 230px;

            object-fit: cover;

            display: block;

            background: #f1f1f1;

            border-radius: 8px 8px 0 0;

        }


        .sem-foto {

            width: 100%;

            height: 230px;

            display: flex;

            align-items: center;

            justify-content: center;

            background: #f1f1f1;

            color: #777;

            font-size: 18px;

            border-radius: 8px 8px 0 0;

        }


        .card-autorizacao {

            transition: transform 0.15s ease;

        }


        .card-autorizacao:hover {

            transform: translateY(-2px);

        }


        .codigo-documento {

            word-break: break-word;

        }

    </style>


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>


<body class="bg-light">


<!-- ==========================================================
     NAVBAR
=========================================================== -->

<nav class="navbar navbar-dark bg-primary">

    <div class="container">

        <a
            href="index.php"
            class="navbar-brand fw-bold text-decoration-none"
        >

            🏢 Koinon Services

        </a>


        <a
            href="index.php"
            class="btn btn-outline-light btn-sm"
        >

            ← Voltar

        </a>

    </div>

</nav>


<!-- ==========================================================
     CONTEÚDO
=========================================================== -->

<div class="container py-4">


    <!-- CABEÇALHO -->

    <div
        class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"
    >

        <div>

            <h2 class="mb-1">

                👥 Minhas autorizações

            </h2>

            <p class="text-muted mb-0">

                Gerencie os acessos autorizados para sua unidade.

            </p>

        </div>


        <a
            href="nova_autorizacao.php"
            class="btn btn-primary"
        >

            + Nova autorização

        </a>

    </div>


    <!-- ======================================================
         MENSAGEM DE ERRO
    ======================================================= -->

    <?php if ($erro !== ""): ?>

        <div class="alert alert-danger">

            <strong>
                Erro:
            </strong>

            <?= htmlspecialchars(
                $erro
            ) ?>

        </div>

    <?php endif; ?>


    <!-- ======================================================
         MENSAGEM DE SUCESSO
    ======================================================= -->

    <?php if (
        isset($_GET["sucesso"])
        &&
        $_GET["sucesso"] === "1"
    ): ?>

        <div class="alert alert-success">

            ✅ Autorização criada com sucesso!

        </div>

    <?php endif; ?>


    <?php if (
        isset($_GET["visitante_cadastrado"])
        &&
        $_GET["visitante_cadastrado"] === "1"
    ): ?>

        <div class="alert alert-success">

            ✅ Visitante cadastrado com sucesso!

            <br>

            Agora você pode criar uma autorização
            para esse visitante.

        </div>

    <?php endif; ?>


    <?php if (
        isset($_GET["cancelado"])
        &&
        $_GET["cancelado"] === "1"
    ): ?>

        <div class="alert alert-success">

            ✅ Autorização cancelada com sucesso!

        </div>

    <?php endif; ?>


    <!-- ======================================================
         NENHUMA AUTORIZAÇÃO
    ======================================================= -->

    <?php if (
        empty($autorizacoes)
    ): ?>


        <div class="card shadow-sm">

            <div class="card-body text-center py-5">


                <div
                    style="font-size: 60px;"
                >

                    👥

                </div>


                <h4 class="mt-3">

                    Nenhuma autorização cadastrada

                </h4>


                <p class="text-muted">

                    Você ainda não possui visitantes
                    autorizados para sua unidade.

                </p>


                <a
                    href="nova_autorizacao.php"
                    class="btn btn-primary"
                >

                    + Cadastrar autorização

                </a>


            </div>

        </div>


    <?php else: ?>


        <!-- ==================================================
             CARDS DAS AUTORIZAÇÕES
        =================================================== -->

        <div class="row g-4">


            <?php foreach (
                $autorizacoes
                as $autorizacao
            ): ?>


                <?php

                /*
                 * ==================================================
                 * DADOS DA AUTORIZAÇÃO
                 * ==================================================
                 */

                $id = valorAutorizacao(
                    $autorizacao,
                    [
                        "id",
                        "autorizacao_id"
                    ],
                    0
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


                $visitanteFoto =
                    valorAutorizacao(
                        $autorizacao,
                        [
                            "visitante_foto",
                            "foto_url",
                            "foto_visitante"
                        ],
                        null
                    );


                $unidade =
                    valorAutorizacao(
                        $autorizacao,
                        [
                            "unidade_identificacao",
                            "identificacao_unidade",
                            "unidade"
                        ],
                        "-"
                    );


                $dataInicio =
                    valorAutorizacao(
                        $autorizacao,
                        [
                            "data_inicio",
                            "inicio"
                        ],
                        null
                    );


                $dataFim =
                    valorAutorizacao(
                        $autorizacao,
                        [
                            "data_fim",
                            "fim"
                        ],
                        null
                    );


                $status =
                    valorAutorizacao(
                        $autorizacao,
                        [
                            "status"
                        ],
                        "Ativo"
                    );


                /*
                 * URL da foto.
                 */
                $fotoUrl =
                    urlFotoVisitante(
                        $visitanteFoto
                    );


                /*
                 * Classe visual do status.
                 */
                $classeStatus = "bg-secondary";


                if (
                    $status === "Ativo"
                ) {

                    $classeStatus =
                        "bg-success";

                } elseif (
                    $status === "Expirado"
                ) {

                    $classeStatus =
                        "bg-secondary";

                } elseif (
                    $status === "Cancelado"
                ) {

                    $classeStatus =
                        "bg-danger";

                } elseif (
                    $status === "Utilizado"
                ) {

                    $classeStatus =
                        "bg-primary";

                }

                ?>


                <div class="col-md-6 col-lg-4">


                    <div
                        class="card shadow-sm h-100 card-autorizacao"
                    >


                        <!-- FOTO -->

                        <?php if (
                            !empty($fotoUrl)
                        ): ?>


                            <img
                                src="<?= htmlspecialchars(
                                    $fotoUrl
                                ) ?>"
                                alt="Foto do visitante"
                                class="foto-visitante"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                            >


                            <div
                                class="sem-foto"
                                style="display:none;"
                            >

                                📷 Foto não encontrada

                            </div>


                        <?php else: ?>


                            <div class="sem-foto">

                                📷 Sem foto cadastrada

                            </div>


                        <?php endif; ?>


                        <!-- INFORMAÇÕES -->

                        <div class="card-body">


                            <h5 class="card-title">

                                <?= htmlspecialchars(
                                    (string) $visitanteNome
                                ) ?>

                            </h5>


                            <p class="mb-2">

                                <strong>
                                    CPF:
                                </strong>

                                <span class="codigo-documento">

                                    <?= htmlspecialchars(
                                        Cpf::formatar($visitanteDocumento)
                                    ) ?>

                                </span>

                            </p>


                            <p class="mb-2">

                                <strong>
                                    Unidade:
                                </strong>

                                <?= htmlspecialchars(
                                    (string) $unidade
                                ) ?>

                            </p>


                            <p class="mb-2">

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


                            <span
                                class="badge <?= $classeStatus ?>"
                            >

                                <?= htmlspecialchars(
                                    (string) $status
                                ) ?>

                            </span>


                        </div>


                        <!-- AÇÕES -->

                        <div class="card-footer bg-white">


                            <?php if (
                                $status === "Ativo"
                            ): ?>


                                <div
                                    class="d-grid gap-2"
                                >


                                    <a
                                        href="../autorizacao_acesso/qrcode.php?id=<?= (int) $id ?>"
                                        class="btn btn-success"
                                    >

                                        📱 Ver QR Code

                                    </a>


                                    <a
                                        href="cancelar_autorizacao.php?id=<?= (int) $id ?>"
                                        class="btn btn-outline-danger"
                                        onclick="return confirm('Deseja realmente cancelar esta autorização?');"
                                    >

                                        Cancelar

                                    </a>


                                </div>


                            <?php elseif (
                                $status === "Utilizado"
                            ): ?>


                                <a
                                    href="../autorizacao_acesso/qrcode.php?id=<?= (int) $id ?>"
                                    class="btn btn-outline-primary w-100"
                                >

                                    📱 Ver QR Code

                                </a>


                            <?php else: ?>


                                <div
                                    class="text-center text-muted"
                                >

                                    Sem ações disponíveis.

                                </div>


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