<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/MoradorController.php";
require_once __DIR__ . "/../../model/dto/OcorrenciaDTO.php";
require_once __DIR__ . "/../../service/Erro.php";

PermissaoController::exigirPerfil([
    "Morador"
]);

$usuarioId =
    (int) $_SESSION["usuario_id"];

$erro = "";


if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $tipo =
        trim(
            $_POST["tipo"] ?? ""
        );

    $titulo =
        trim(
            $_POST["titulo"] ?? ""
        );

    $descricao =
        trim(
            $_POST["descricao"] ?? ""
        );

    $fotoUrl = null;


    /*
     * ==========================================================
     * VALIDAÇÃO
     * ==========================================================
     */

    if (
        $tipo === "" ||
        $titulo === "" ||
        $descricao === ""
    ) {

        $erro =
            "Preencha todos os campos obrigatórios.";

    } else {

        /*
         * ======================================================
         * FOTO
         * ======================================================
         */

        if (
            isset($_FILES["foto"]) &&
            $_FILES["foto"]["error"]
                !== UPLOAD_ERR_NO_FILE
        ) {

            if (
                $_FILES["foto"]["error"]
                !== UPLOAD_ERR_OK
            ) {

                $erro =
                    "Erro ao enviar a foto.";

            } else {

                $arquivo =
                    $_FILES["foto"];

                $extensoesPermitidas = [
                    "jpg",
                    "jpeg",
                    "png",
                    "webp"
                ];

                $extensao =
                    strtolower(
                        pathinfo(
                            $arquivo["name"],
                            PATHINFO_EXTENSION
                        )
                    );


                if (
                    !in_array(
                        $extensao,
                        $extensoesPermitidas,
                        true
                    )
                ) {

                    $erro =
                        "Formato de foto não permitido. "
                        . "Use JPG, JPEG, PNG ou WEBP.";

                } elseif (
                    $arquivo["size"]
                    > 5 * 1024 * 1024
                ) {

                    $erro =
                        "A foto não pode ultrapassar 5 MB.";

                } else {

                    $pastaFotos =
                        __DIR__
                        . "/../../assets/img/ocorrencias/";

                    if (
                        !is_dir(
                            $pastaFotos
                        )
                    ) {

                        mkdir(
                            $pastaFotos,
                            0777,
                            true
                        );
                    }


                    $nomeArquivo =
                        "ocorrencia_"
                        . date("Ymd_His")
                        . "_"
                        . uniqid()
                        . "."
                        . $extensao;


                    $caminhoCompleto =
                        $pastaFotos
                        . $nomeArquivo;


                    if (
                        move_uploaded_file(
                            $arquivo["tmp_name"],
                            $caminhoCompleto
                        )
                    ) {

                        $fotoUrl =
                            "../../assets/img/ocorrencias/"
                            . $nomeArquivo;

                    } else {

                        $erro =
                            "Não foi possível salvar a foto.";
                    }
                }
            }
        }


        /*
         * ======================================================
         * CADASTRO
         * ======================================================
         */

        if (
            $erro === ""
        ) {

            $ocorrencia =
                new OcorrenciaDTO();

            /*
             * O usuário vem da sessão.
             * O morador não consegue escolher outro usuário.
             */

            $ocorrencia->setUsuarioId(
                $usuarioId
            );

            $ocorrencia->setTipo(
                $tipo
            );

            $ocorrencia->setTitulo(
                $titulo
            );

            $ocorrencia->setDescricao(
                $descricao
            );

            /*
             * Sempre começa como Aberta.
             */

            $ocorrencia->setStatus(
                "Aberta"
            );

            $ocorrencia->setFotoUrl(
                $fotoUrl
            );

            $ocorrencia->setDataAbertura(
                null
            );

            $ocorrencia->setDataFechamento(
                null
            );


            try {

                $controller =
                    new MoradorController();

                if (
                    $controller->cadastrarOcorrencia(
                        $ocorrencia
                    )
                ) {

                    header(
                        "Location: ocorrencias.php?sucesso=1&id="
                        . $controller->ultimaOcorrenciaId()
                    );

                    exit;
                }

                $erro =
                    "Não foi possível registrar a ocorrência.";

            } catch (Exception $e) {

                $erro =
                    "Erro ao registrar ocorrência: "
                    . Erro::mensagem($e);
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
        Nova Ocorrência - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body class="bg-light">

<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-md-8">

            <div class="card shadow">

                <div class="card-header bg-warning">

                    <h4 class="mb-0">

                        ⚠️ Registrar ocorrência

                    </h4>

                </div>


                <div class="card-body">


                    <div class="alert alert-info">

                        Informe o problema ocorrido no condomínio.
                        A ocorrência será enviada para a administração
                        com status <strong>Aberta</strong> e você poderá
                        acompanhar o andamento em
                        <a href="ocorrencias.php">Minhas ocorrências</a>.

                    </div>


                    <?php if (
                        $erro !== ""
                    ): ?>

                        <div class="alert alert-danger">

                            <?= htmlspecialchars(
                                $erro
                            ) ?>

                        </div>

                    <?php endif; ?>


                    <form
                        method="POST"
                        enctype="multipart/form-data"
                    >


                        <!-- TIPO -->

                        <div class="mb-3">

                            <label class="form-label">

                                Tipo da ocorrência *

                            </label>

                            <select
                                name="tipo"
                                class="form-select"
                                required
                            >

                                <option value="">

                                    Selecione...

                                </option>

                                <option
                                    value="Manutenção"
                                    <?= (
                                        ($_POST["tipo"] ?? "")
                                        === "Manutenção"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >

                                    Manutenção

                                </option>

                                <option
                                    value="Segurança"
                                    <?= (
                                        ($_POST["tipo"] ?? "")
                                        === "Segurança"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >

                                    Segurança

                                </option>

                                <option
                                    value="Barulho"
                                    <?= (
                                        ($_POST["tipo"] ?? "")
                                        === "Barulho"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >

                                    Barulho

                                </option>

                                <option
                                    value="Vazamento"
                                    <?= (
                                        ($_POST["tipo"] ?? "")
                                        === "Vazamento"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >

                                    Vazamento

                                </option>

                                <option
                                    value="Iluminação"
                                    <?= (
                                        ($_POST["tipo"] ?? "")
                                        === "Iluminação"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >

                                    Iluminação

                                </option>

                                <option
                                    value="Limpeza"
                                    <?= (
                                        ($_POST["tipo"] ?? "")
                                        === "Limpeza"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >

                                    Limpeza

                                </option>

                                <option
                                    value="Outros"
                                    <?= (
                                        ($_POST["tipo"] ?? "")
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


                        <!-- TÍTULO -->

                        <div class="mb-3">

                            <label class="form-label">

                                Título *

                            </label>

                            <input
                                type="text"
                                name="titulo"
                                class="form-control"
                                maxlength="150"
                                placeholder="Ex.: Vazamento no corredor"
                                value="<?= htmlspecialchars(
                                    $_POST["titulo"] ?? ""
                                ) ?>"
                                required
                            >

                        </div>


                        <!-- DESCRIÇÃO -->

                        <div class="mb-3">

                            <label class="form-label">

                                Descrição *

                            </label>

                            <textarea
                                name="descricao"
                                class="form-control"
                                rows="6"
                                placeholder="Descreva detalhadamente o problema..."
                                required
                            ><?= htmlspecialchars(
                                $_POST["descricao"] ?? ""
                            ) ?></textarea>

                        </div>


                        <!-- FOTO -->

                        <div class="mb-4">

                            <label class="form-label">

                                Foto da ocorrência

                            </label>

                            <input
                                type="file"
                                name="foto"
                                class="form-control"
                                accept=".jpg,.jpeg,.png,.webp"
                            >

                            <small class="text-muted">

                                Opcional. JPG, JPEG, PNG ou WEBP.
                                Máximo de 5 MB.

                            </small>

                        </div>


                        <!-- BOTÕES -->

                        <div class="d-flex gap-2">

                            <a
                                href="index.php"
                                class="btn btn-secondary"
                            >

                                Voltar

                            </a>

                            <button
                                type="submit"
                                class="btn btn-warning"
                            >

                                Registrar ocorrência

                            </button>

                        </div>


                    </form>

                </div>

            </div>

        </div>

    </div>

</div>
<script src="../../assets/js/script.js"></script>

</body>

</html>