<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../service/Erro.php";

PermissaoController::exigirGestao();

require_once __DIR__ . "/../../controller/OcorrenciaController.php";
require_once __DIR__ . "/../../controller/UsuarioController.php";

$ocorrenciaController = new OcorrenciaController();
$usuarioController = new UsuarioController();

$usuarios = $usuarioController->listar();

$mensagem = "";
$tipoMensagem = "danger";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $usuarioId = $_POST["usuario_id"] ?? "";
    $tipo = trim($_POST["tipo"] ?? "");
    $titulo = trim($_POST["titulo"] ?? "");
    $descricao = trim($_POST["descricao"] ?? "");
    $status = $_POST["status"] ?? "Aberta";

    $fotoUrl = null;

    if (
        $usuarioId === "" ||
        $tipo === "" ||
        $titulo === "" ||
        $descricao === ""
    ) {

        $mensagem = "Preencha todos os campos obrigatórios.";

    } else {

        $statusPermitidos = [
            "Aberta",
            "Em_Andamento",
            "Resolvida",
            "Cancelada"
        ];

        if (!in_array($status, $statusPermitidos, true)) {

            $mensagem = "Status da ocorrência inválido.";

        } else {

            /*
             * UPLOAD DA FOTO
             */

            if (
                isset($_FILES["foto"]) &&
                $_FILES["foto"]["error"] !== UPLOAD_ERR_NO_FILE
            ) {

                if (
                    $_FILES["foto"]["error"] !== UPLOAD_ERR_OK
                ) {

                    $mensagem = "Erro ao enviar a foto.";

                } else {

                    $arquivo = $_FILES["foto"];

                    $extensoesPermitidas = [
                        "jpg",
                        "jpeg",
                        "png",
                        "webp"
                    ];

                    $extensao = strtolower(
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

                        $mensagem =
                            "Formato de foto não permitido. "
                            . "Use JPG, JPEG, PNG ou WEBP.";

                    } elseif ($arquivo["size"] > 5 * 1024 * 1024) {

                        $mensagem =
                            "A foto não pode ultrapassar 5 MB.";

                    } else {

                        $pastaFotos =
                            __DIR__
                            . "/../../assets/img/ocorrencias/";

                        if (!is_dir($pastaFotos)) {

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

                            $mensagem =
                                "Não foi possível salvar a foto.";
                        }
                    }
                }
            }


            /*
             * CADASTRO DA OCORRÊNCIA
             */

            if (empty($mensagem)) {

                $ocorrencia = new OcorrenciaDTO();

                $ocorrencia->setUsuarioId(
                    (int) $usuarioId
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

                $ocorrencia->setStatus(
                    $status
                );

                /*
                 * O banco registra automaticamente
                 * a data de abertura.
                 */

                $ocorrencia->setDataAbertura(
                    null
                );

                $ocorrencia->setDataFechamento(
                    null
                );

                $ocorrencia->setFotoUrl(
                    $fotoUrl
                );

                try {

                    if (
                        $ocorrenciaController->cadastrar(
                            $ocorrencia
                        )
                    ) {

                        header(
                            "Location: index.php?sucesso=1"
                        );

                        exit;

                    } else {

                        $mensagem =
                            "Não foi possível cadastrar a ocorrência.";
                    }

                } catch (Exception $e) {

                    $mensagem =
                        "Erro ao cadastrar ocorrência: "
                        . Erro::mensagem($e);
                }
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
        Cadastrar Ocorrência - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container mt-4">

    <div class="row justify-content-center">

        <div class="col-md-9">

            <div class="card shadow">

                <div class="card-header bg-primary text-white">

                    <h4 class="mb-0">
                        Cadastrar Ocorrência
                    </h4>

                </div>

                <div class="card-body">

                    <?php if (!empty($mensagem)): ?>

                        <div
                            class="alert alert-<?= htmlspecialchars($tipoMensagem) ?>"
                        >

                            <?= htmlspecialchars($mensagem) ?>

                        </div>

                    <?php endif; ?>


                    <form
                        method="POST"
                        action=""
                        enctype="multipart/form-data"
                    >

                        <!-- USUÁRIO -->

                        <div class="mb-3">

                            <label
                                for="usuario_id"
                                class="form-label"
                            >
                                Usuário *
                            </label>

                            <select
                                name="usuario_id"
                                id="usuario_id"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Selecione o usuário
                                </option>

                                <?php foreach ($usuarios as $usuario): ?>

                                    <option
                                        value="<?= $usuario->getId() ?>"
                                        <?= (
                                            isset($_POST["usuario_id"]) &&
                                            $_POST["usuario_id"] == $usuario->getId()
                                        )
                                            ? "selected"
                                            : ""
                                        ?>
                                    >

                                        <?= htmlspecialchars(
                                            $usuario->getNome()
                                        ) ?>

                                        -
                                        <?= htmlspecialchars(
                                            $usuario->getPerfil()
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- TIPO -->

                        <div class="mb-3">

                            <label
                                for="tipo"
                                class="form-label"
                            >
                                Tipo da ocorrência *
                            </label>

                            <input
                                type="text"
                                name="tipo"
                                id="tipo"
                                class="form-control"
                                maxlength="50"
                                placeholder="Ex.: Manutenção, Segurança, Barulho..."
                                value="<?= htmlspecialchars(
                                    $_POST["tipo"] ?? ""
                                ) ?>"
                                required
                            >

                        </div>


                        <!-- TÍTULO -->

                        <div class="mb-3">

                            <label
                                for="titulo"
                                class="form-label"
                            >
                                Título *
                            </label>

                            <input
                                type="text"
                                name="titulo"
                                id="titulo"
                                class="form-control"
                                maxlength="150"
                                placeholder="Informe o título da ocorrência"
                                value="<?= htmlspecialchars(
                                    $_POST["titulo"] ?? ""
                                ) ?>"
                                required
                            >

                        </div>


                        <!-- DESCRIÇÃO -->

                        <div class="mb-3">

                            <label
                                for="descricao"
                                class="form-label"
                            >
                                Descrição *
                            </label>

                            <textarea
                                name="descricao"
                                id="descricao"
                                class="form-control"
                                rows="6"
                                placeholder="Descreva detalhadamente a ocorrência..."
                                required
                            ><?= htmlspecialchars(
                                $_POST["descricao"] ?? ""
                            ) ?></textarea>

                        </div>


                        <!-- STATUS -->

                        <div class="mb-3">

                            <label
                                for="status"
                                class="form-label"
                            >
                                Status
                            </label>

                            <select
                                name="status"
                                id="status"
                                class="form-select"
                            >

                                <option
                                    value="Aberta"
                                    <?= (
                                        ($_POST["status"] ?? "Aberta")
                                        === "Aberta"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >
                                    Aberta
                                </option>

                                <option
                                    value="Em_Andamento"
                                    <?= (
                                        ($_POST["status"] ?? "")
                                        === "Em_Andamento"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >
                                    Em andamento
                                </option>

                                <option
                                    value="Resolvida"
                                    <?= (
                                        ($_POST["status"] ?? "")
                                        === "Resolvida"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >
                                    Resolvida
                                </option>

                                <option
                                    value="Cancelada"
                                    <?= (
                                        ($_POST["status"] ?? "")
                                        === "Cancelada"
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >
                                    Cancelada
                                </option>

                            </select>

                        </div>


                        <!-- FOTO -->

                        <div class="mb-3">

                            <label
                                for="foto"
                                class="form-label"
                            >
                                Foto da ocorrência
                            </label>

                            <input
                                type="file"
                                name="foto"
                                id="foto"
                                class="form-control"
                                accept=".jpg,.jpeg,.png,.webp"
                            >

                            <div class="form-text">

                                Formatos permitidos:
                                JPG, JPEG, PNG e WEBP.
                                Tamanho máximo: 5 MB.

                            </div>

                        </div>


                        <!-- AVISO -->

                        <div class="alert alert-info">

                            <strong>Data de abertura:</strong>

                            será registrada automaticamente pelo banco
                            de dados no momento do cadastro.

                        </div>


                        <!-- BOTÕES -->

                        <div class="d-flex gap-2">

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                Cadastrar Ocorrência
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

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"
></script>
<script src="../../assets/js/script.js"></script>

</body>

</html>