<?php

require_once __DIR__ . "/../../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/EncomendaController.php";
require_once __DIR__ . "/../../service/Configuracao.php";

PermissaoController::exigirPortaria();

$controller = new EncomendaController();

$id = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

if (!$id) {
    header(
        "Location: index.php?erro=id_invalido"
    );
    exit;
}

$encomenda = $controller->buscarPorId(
    (int) $id
);

if (!$encomenda) {
    header(
        "Location: index.php?erro=encomenda_nao_encontrada"
    );
    exit;
}

$unidades = $controller->listarUnidades();

$moradores = [];

if ($encomenda->getUnidadeId()) {
    $moradores =
        $controller->listarMoradoresPorUnidade(
            (int) $encomenda->getUnidadeId()
        );
}

$erro = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $unidadeId = filter_input(
        INPUT_POST,
        "unidade_id",
        FILTER_VALIDATE_INT
    );

    $usuarioRecebedorId = filter_input(
        INPUT_POST,
        "usuario_recebedor_id",
        FILTER_VALIDATE_INT
    );

    $descricao = trim(
        $_POST["descricao"] ?? ""
    );

    $codigoRastreio = trim(
        $_POST["codigo_rastreio"] ?? ""
    );

    if (!$unidadeId) {
        $erro = "Selecione a unidade.";
    } elseif (!$usuarioRecebedorId) {
        $erro = "Selecione o morador responsável pelo recebimento.";
    } elseif ($descricao === "") {
        $erro = "Informe a descrição da encomenda.";
    } else {

        /*
         * Verifica se o morador realmente pertence
         * à unidade selecionada.
         */
        $moradores =
            $controller->listarMoradoresPorUnidade(
                (int) $unidadeId
            );

        $moradorValido = false;

        foreach ($moradores as $morador) {

            if (
                (int) $morador["id"]
                === (int) $usuarioRecebedorId
            ) {
                $moradorValido = true;
                break;
            }
        }

        if (!$moradorValido) {
            $erro =
                "O morador selecionado não pertence à unidade informada.";
        }
    }

    /*
     * ==========================================================
     * FOTO
     * ==========================================================
     */

    $fotoUrl = $encomenda->getFotoUrl();

    if (
        $erro === null
        && isset($_FILES["foto"])
        && $_FILES["foto"]["error"] !== UPLOAD_ERR_NO_FILE
    ) {

        if (
            $_FILES["foto"]["error"]
            !== UPLOAD_ERR_OK
        ) {

            $erro =
                "Não foi possível enviar a nova foto.";

        } elseif (
            $_FILES["foto"]["size"]
            > 5 * 1024 * 1024
        ) {

            $erro =
                "A foto deve ter no máximo 5 MB.";

        } else {

            $arquivoTemporario =
                $_FILES["foto"]["tmp_name"];

            $bytes =
                file_get_contents(
                    $arquivoTemporario
                );

            if ($bytes === false) {

                $erro =
                    "Não foi possível ler a foto enviada.";

            } else {

                $valido = false;

                /*
                 * JPEG
                 */
                if (
                    substr(
                        $bytes,
                        0,
                        3
                    ) === "\xFF\xD8\xFF"
                ) {
                    $valido = true;
                }

                /*
                 * PNG
                 */
                if (
                    substr(
                        $bytes,
                        0,
                        8
                    ) === "\x89PNG\x0D\x0A\x1A\x0A"
                ) {
                    $valido = true;
                }

                /*
                 * WEBP
                 */
                if (
                    substr(
                        $bytes,
                        0,
                        4
                    ) === "RIFF"
                    &&
                    substr(
                        $bytes,
                        8,
                        4
                    ) === "WEBP"
                ) {
                    $valido = true;
                }

                if (!$valido) {

                    $erro =
                        "Envie uma imagem JPG, PNG ou WEBP válida.";

                } else {

                    $diretorio =
                        __DIR__
                        . "/../../assets/img/encomendas";

                    if (
                        !is_dir($diretorio)
                    ) {
                        mkdir(
                            $diretorio,
                            0777,
                            true
                        );
                    }

                    $extensao =
                        strtolower(
                            pathinfo(
                                $_FILES["foto"]["name"],
                                PATHINFO_EXTENSION
                            )
                        );

                    if (
                        !in_array(
                            $extensao,
                            [
                                "jpg",
                                "jpeg",
                                "png",
                                "webp"
                            ],
                            true
                        )
                    ) {
                        $extensao = "jpg";
                    }

                    $nomeArquivo =
                        "encomenda_"
                        . date("Ymd_His")
                        . "_"
                        . bin2hex(
                            random_bytes(8)
                        )
                        . "."
                        . $extensao;

                    $caminhoCompleto =
                        $diretorio
                        . "/"
                        . $nomeArquivo;

                    if (
                        file_put_contents(
                            $caminhoCompleto,
                            $bytes
                        ) === false
                    ) {

                        $erro =
                            "Não foi possível salvar a nova foto.";

                    } else {

                        $fotoUrl =
                            "../../assets/img/encomendas/"
                            . $nomeArquivo;
                    }
                }
            }
        }
    }

    /*
     * ==========================================================
     * SALVAR
     * ==========================================================
     */

    if ($erro === null) {

        $dto = new EncomendaDTO();

        $dto->setId(
            (int) $id
        );

        $dto->setUnidadeId(
            (int) $unidadeId
        );

        $dto->setUsuarioRecebedorId(
            (int) $usuarioRecebedorId
        );

        $dto->setDescricao(
            $descricao
        );

        $dto->setCodigoRastreio(
            $codigoRastreio !== ""
                ? $codigoRastreio
                : null
        );

        $dto->setFotoUrl(
            $fotoUrl
        );

        $dto->setStatus(
            $encomenda->getStatus()
        );

        $dto->setDataRecebimento(
            $encomenda->getDataRecebimento()
        );

        $dto->setDataRetirada(
            $encomenda->getDataRetirada()
        );

        if (
            $controller->editar($dto)
        ) {

            header(
                "Location: index.php?sucesso=editada"
            );

            exit;
        }

        $erro =
            "Não foi possível atualizar a encomenda.";
    }
}

/*
 * ==========================================================
 * VALORES DO FORMULÁRIO
 * ==========================================================
 */

$unidadeSelecionada =
    $_POST["unidade_id"]
    ?? $encomenda->getUnidadeId();

$moradorSelecionado =
    $_POST["usuario_recebedor_id"]
    ?? $encomenda->getUsuarioRecebedorId();

$descricaoValor =
    $_POST["descricao"]
    ?? $encomenda->getDescricao();

$rastreioValor =
    $_POST["codigo_rastreio"]
    ?? $encomenda->getCodigoRastreio();

$fotoAtual =
    $encomenda->getFotoUrl();

function urlFotoEncomenda(
    ?string $foto
): ?string {

    if (
        empty($foto)
    ) {
        return null;
    }

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

    $foto = str_replace(
        "\\",
        "/",
        $foto
    );

    $foto = preg_replace(
        "#^(\.\./)+#",
        "",
        $foto
    );

    $foto = ltrim(
        $foto,
        "/"
    );

    if (
        str_starts_with(
            $foto,
            "koinon-service/"
        )
    ) {
        return "/" . $foto;
    }

    if (
        str_starts_with(
            $foto,
            "assets/"
        )
    ) {
        return Configuracao::url() . $foto;
    }

    return Configuracao::url() . $foto;
}

$fotoAtualUrl =
    urlFotoEncomenda(
        $fotoAtual
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
        Editar Encomenda - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body class="bg-light">

<nav class="navbar navbar-dark bg-primary">

    <div class="container">

        <span class="navbar-brand fw-bold">
            📦 Koinon Services
        </span>

        <div>

            <span class="text-white me-3">

                <?= htmlspecialchars(
                    $_SESSION["usuario_nome"]
                    ?? "Usuário"
                ) ?>

            </span>

            <a
                href="<?= htmlspecialchars(Configuracao::url("logout.php")) ?>"
                class="btn btn-outline-light btn-sm"
            >
                Sair
            </a>

        </div>

    </div>

</nav>


<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2>
                📦 Editar Encomenda
            </h2>

            <p class="text-muted mb-0">
                Atualize os dados da encomenda.
            </p>

        </div>

        <a
            href="index.php"
            class="btn btn-secondary"
        >
            ← Voltar
        </a>

    </div>


    <?php if ($erro !== null): ?>

        <div class="alert alert-danger">

            <?= htmlspecialchars($erro) ?>

        </div>

    <?php endif; ?>


    <div class="card shadow-sm">

        <div class="card-body">

            <form
                method="POST"
                enctype="multipart/form-data"
            >

                <div class="row">

                    <!-- UNIDADE -->

                    <div class="col-md-6 mb-3">

                        <label
                            for="unidade_id"
                            class="form-label fw-bold"
                        >
                            Unidade
                        </label>

                        <select
                            name="unidade_id"
                            id="unidade_id"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Selecione a unidade
                            </option>

                            <?php foreach (
                                $unidades
                                as $unidade
                            ): ?>

                                <option
                                    value="<?= (int) $unidade["id"] ?>"
                                    <?= (
                                        (int) $unidade["id"]
                                        === (int) $unidadeSelecionada
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >

                                    <?= htmlspecialchars(
                                        $unidade["identificacao"]
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- MORADOR -->

                    <div class="col-md-6 mb-3">

                        <label
                            for="usuario_recebedor_id"
                            class="form-label fw-bold"
                        >
                            Morador responsável
                        </label>

                        <select
                            name="usuario_recebedor_id"
                            id="usuario_recebedor_id"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Selecione o morador
                            </option>

                            <?php foreach (
                                $moradores
                                as $morador
                            ): ?>

                                <option
                                    value="<?= (int) $morador["id"] ?>"
                                    <?= (
                                        (int) $morador["id"]
                                        === (int) $moradorSelecionado
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >

                                    <?= htmlspecialchars(
                                        $morador["nome"]
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- DESCRIÇÃO -->

                    <div class="col-12 mb-3">

                        <label
                            for="descricao"
                            class="form-label fw-bold"
                        >
                            Descrição da encomenda
                        </label>

                        <textarea
                            name="descricao"
                            id="descricao"
                            class="form-control"
                            rows="3"
                            required
                        ><?= htmlspecialchars(
                            $descricaoValor ?? ""
                        ) ?></textarea>

                    </div>


                    <!-- RASTREIO -->

                    <div class="col-md-6 mb-3">

                        <label
                            for="codigo_rastreio"
                            class="form-label fw-bold"
                        >
                            Código de rastreio
                        </label>

                        <input
                            type="text"
                            name="codigo_rastreio"
                            id="codigo_rastreio"
                            class="form-control"
                            maxlength="50"
                            value="<?= htmlspecialchars(
                                $rastreioValor ?? ""
                            ) ?>"
                        >

                    </div>


                    <!-- FOTO -->

                    <div class="col-md-6 mb-3">

                        <label
                            for="foto"
                            class="form-label fw-bold"
                        >
                            Nova foto
                        </label>

                        <input
                            type="file"
                            name="foto"
                            id="foto"
                            class="form-control"
                            accept="image/jpeg,image/png,image/webp"
                        >

                        <small class="text-muted">
                            JPG, PNG ou WEBP — máximo 5 MB.
                        </small>

                    </div>


                    <!-- FOTO ATUAL -->

                    <?php if ($fotoAtualUrl): ?>

                        <div class="col-12 mb-4">

                            <label class="form-label fw-bold d-block">
                                Foto atual
                            </label>

                            <img
                                src="<?= htmlspecialchars(
                                    $fotoAtualUrl
                                ) ?>"
                                alt="Foto da encomenda"
                                class="img-thumbnail"
                                style="
                                    max-width: 250px;
                                    max-height: 250px;
                                    object-fit: cover;
                                "
                            >

                        </div>

                    <?php endif; ?>


                    <!-- STATUS -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label fw-bold">
                            Status atual
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $encomenda->getStatus()
                            ) ?>"
                            disabled
                        >

                    </div>


                    <!-- RECEBIMENTO -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label fw-bold">
                            Data de recebimento
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $encomenda->getDataRecebimento()
                                ?? "-"
                            ) ?>"
                            disabled
                        >

                    </div>

                </div>


                <hr>


                <div class="d-flex gap-2">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        💾 Salvar alterações
                    </button>

                    <a
                        href="index.php"
                        class="btn btn-secondary"
                    >
                        Cancelar
                    </a>

                </div>

            </form>

        </div>

    </div>

</div>


<script>

const unidadeSelect =
    document.getElementById("unidade_id");

const moradorSelect =
    document.getElementById(
        "usuario_recebedor_id"
    );

unidadeSelect.addEventListener(
    "change",
    async function () {

        const unidadeId =
            this.value;

        moradorSelect.innerHTML =
            '<option value="">Carregando moradores...</option>';

        if (!unidadeId) {

            moradorSelect.innerHTML =
                '<option value="">Selecione o morador</option>';

            return;
        }

        try {

            const resposta =
                await fetch(
                    "moradores.php?unidade_id="
                    + encodeURIComponent(
                        unidadeId
                    )
                );

            if (!resposta.ok) {
                throw new Error(
                    "Erro HTTP"
                );
            }

            const moradores =
                await resposta.json();

            moradorSelect.innerHTML =
                '<option value="">Selecione o morador</option>';

            moradores.forEach(
                function (morador) {

                    const option =
                        document.createElement(
                            "option"
                        );

                    option.value =
                        morador.id;

                    option.textContent =
                        morador.nome;

                    moradorSelect.appendChild(
                        option
                    );

                }
            );

        } catch (erro) {

            console.error(
                erro
            );

            moradorSelect.innerHTML =
                '<option value="">Erro ao carregar moradores</option>';
        }
    }
);

</script>
<script src="../../assets/js/script.js"></script>

</body>

</html>