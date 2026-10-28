<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirGestao();

require_once __DIR__ . "/../../controller/OcorrenciaController.php";
require_once __DIR__ . "/../../controller/UsuarioController.php";

$ocorrenciaController = new OcorrenciaController();
$usuarioController = new UsuarioController();

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Ocorrência inválida.");
}

$id = (int) $_GET["id"];

$ocorrencia = $ocorrenciaController->buscarPorId($id);

if (!$ocorrencia) {
    die("Ocorrência não encontrada.");
}

$usuarios = $usuarioController->listar();

$mensagem = "";

$statusPermitidos = [
    "Aberta",
    "Em_Andamento",
    "Resolvida",
    "Cancelada"
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $usuarioId = isset($_POST["usuario_id"])
        ? (int) $_POST["usuario_id"]
        : 0;

    $tipo = trim($_POST["tipo"] ?? "");
    $titulo = trim($_POST["titulo"] ?? "");
    $descricao = trim($_POST["descricao"] ?? "");
    $status = $_POST["status"] ?? "Aberta";
    $mensagemMorador = trim($_POST["mensagem_morador"] ?? "");

    $removerFoto = isset($_POST["remover_foto"]);

    if (
        $usuarioId <= 0 ||
        $tipo === "" ||
        $titulo === "" ||
        $descricao === ""
    ) {
        $mensagem = "Preencha todos os campos obrigatórios.";

    } elseif (!in_array($status, $statusPermitidos, true)) {
        $mensagem = "Status inválido.";

    } else {

        /*
         * FOTO ATUAL
         */
        $fotoAtual = $ocorrencia->getFotoUrl();

        $caminhoFotoAtual = null;

        if (!empty($fotoAtual)) {

            $nomeFotoAtual = basename($fotoAtual);

            $caminhoFotoAtual =
                __DIR__
                . "/../../assets/img/ocorrencias/"
                . $nomeFotoAtual;
        }

        $novaFotoUrl = $fotoAtual;
        $novaFotoCaminho = null;
        $fotoNovaEnviada = false;

        /*
         * NOVA FOTO
         */
        if (
            isset($_FILES["foto"]) &&
            $_FILES["foto"]["error"] !== UPLOAD_ERR_NO_FILE
        ) {

            if ($_FILES["foto"]["error"] !== UPLOAD_ERR_OK) {

                $mensagem = "Erro ao enviar a nova foto.";

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

                if (!in_array(
                    $extensao,
                    $extensoesPermitidas,
                    true
                )) {

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
                        mkdir($pastaFotos, 0777, true);
                    }

                    $nomeArquivo =
                        "ocorrencia_"
                        . date("Ymd_His")
                        . "_"
                        . uniqid()
                        . "."
                        . $extensao;

                    $novaFotoCaminho =
                        $pastaFotos . $nomeArquivo;

                    if (
                        move_uploaded_file(
                            $arquivo["tmp_name"],
                            $novaFotoCaminho
                        )
                    ) {

                        $novaFotoUrl =
                            "../../assets/img/ocorrencias/"
                            . $nomeArquivo;

                        $fotoNovaEnviada = true;

                    } else {

                        $mensagem =
                            "Não foi possível salvar a nova foto.";
                    }
                }
            }
        }

        /*
         * REMOVER FOTO
         *
         * Só remove se não estiver sendo enviada
         * uma nova foto.
         */
        if (
            $mensagem === "" &&
            $removerFoto &&
            !$fotoNovaEnviada
        ) {

            $novaFotoUrl = null;
        }

        /*
         * SALVAR ALTERAÇÕES
         */
        if ($mensagem === "") {

            $ocorrencia->setUsuarioId($usuarioId);
            $ocorrencia->setTipo($tipo);
            $ocorrencia->setTitulo($titulo);
            $ocorrencia->setDescricao($descricao);
            $ocorrencia->setStatus($status);
            $ocorrencia->setFotoUrl($novaFotoUrl);

            $resultado =
                $ocorrenciaController->editar($ocorrencia, $mensagemMorador);

            if ($resultado) {

                /*
                 * Se salvou uma nova foto,
                 * remove a antiga.
                 */
                if (
                    $fotoNovaEnviada &&
                    !empty($caminhoFotoAtual) &&
                    file_exists($caminhoFotoAtual)
                ) {

                    unlink($caminhoFotoAtual);
                }

                /*
                 * Se marcou para remover e não enviou
                 * nova foto, remove o arquivo antigo.
                 */
                elseif (
                    $removerFoto &&
                    !$fotoNovaEnviada &&
                    !empty($caminhoFotoAtual) &&
                    file_exists($caminhoFotoAtual)
                ) {

                    unlink($caminhoFotoAtual);
                }

                header(
                    "Location: index.php?sucesso=2"
                );

                exit;

            } else {

                /*
                 * Se o banco falhar depois de uma nova
                 * foto ter sido enviada, remove a nova
                 * foto para não deixar arquivo perdido.
                 */
                if (
                    $fotoNovaEnviada &&
                    !empty($novaFotoCaminho) &&
                    file_exists($novaFotoCaminho)
                ) {

                    unlink($novaFotoCaminho);
                }

                $mensagem =
                    "Não foi possível atualizar a ocorrência.";
            }
        }
    }
}

function formatarDataHora(?string $data): string
{
    if (empty($data)) {
        return "-";
    }

    $timestamp = strtotime($data);

    if ($timestamp === false) {
        return $data;
    }

    return date("d/m/Y H:i", $timestamp);
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

    <title>Editar Ocorrência - Koinon Services</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container mt-5">

    <div class="row justify-content-center">

        <div class="col-md-9 col-lg-8">

            <div class="card shadow">

                <div class="card-header bg-primary text-white">

                    <h4 class="mb-0">
                        Editar Ocorrência
                    </h4>

                </div>

                <div class="card-body">

                    <?php if ($mensagem !== ""): ?>

                        <div class="alert alert-danger">
                            <?= htmlspecialchars($mensagem) ?>
                        </div>

                    <?php endif; ?>


                    <form
                        method="POST"
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
                                        <?= $usuario->getId() == $ocorrencia->getUsuarioId() ? "selected" : "" ?>
                                    >

                                        <?= htmlspecialchars(
                                            $usuario->getNome()
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
                                Tipo *
                            </label>

                            <input
                                type="text"
                                name="tipo"
                                id="tipo"
                                class="form-control"
                                value="<?= htmlspecialchars(
                                    $ocorrencia->getTipo()
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
                                value="<?= htmlspecialchars(
                                    $ocorrencia->getTitulo()
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
                                rows="5"
                                required
                            ><?= htmlspecialchars(
                                $ocorrencia->getDescricao()
                            ) ?></textarea>

                        </div>


                        <!-- STATUS -->

                        <div class="mb-3">

                            <label
                                for="status"
                                class="form-label"
                            >
                                Status *
                            </label>

                            <select
                                name="status"
                                id="status"
                                class="form-select"
                                required
                            >

                                <?php foreach (
                                    $statusPermitidos
                                    as $status
                                ): ?>

                                    <option
                                        value="<?= $status ?>"
                                        <?= $ocorrencia->getStatus() === $status
                                            ? "selected"
                                            : "" ?>
                                    >

                                        <?php
                                        if ($status === "Em_Andamento") {
                                            echo "Em Andamento";
                                        } else {
                                            echo $status;
                                        }
                                        ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- MENSAGEM AO MORADOR (aparece no histórico) -->

                        <div class="mb-3">

                            <label
                                for="mensagem_morador"
                                class="form-label"
                            >
                                Mensagem ao morador (opcional)
                            </label>

                            <textarea
                                name="mensagem_morador"
                                id="mensagem_morador"
                                class="form-control"
                                rows="2"
                                maxlength="500"
                                placeholder="Ex.: A equipe de manutenção visitará o local amanhã."
                            ></textarea>

                            <div class="form-text">
                                Este texto aparece no andamento da ocorrência
                                para o morador. Se ficar em branco, o sistema
                                registra apenas a mudança de status.
                            </div>

                        </div>


                        <!-- FOTO ATUAL -->

                        <div class="mb-3">

                            <label class="form-label">
                                Foto da ocorrência
                            </label>

                            <?php if (
                                !empty($ocorrencia->getFotoUrl())
                            ): ?>

                                <div class="mb-3">

                                    <div class="card">

                                        <div class="card-body text-center">

                                            <p class="mb-2">
                                                <strong>
                                                    Foto atual
                                                </strong>
                                            </p>

                                            <img
                                                src="<?= htmlspecialchars(
                                                    $ocorrencia->getFotoUrl()
                                                ) ?>"
                                                alt="Foto da ocorrência"
                                                class="img-fluid rounded"
                                                style="
                                                    max-width: 400px;
                                                    max-height: 300px;
                                                    object-fit: contain;
                                                "
                                            >

                                            <div class="mt-3">

                                                <a
                                                    href="<?= htmlspecialchars(
                                                        $ocorrencia->getFotoUrl()
                                                    ) ?>"
                                                    target="_blank"
                                                    class="btn btn-sm btn-outline-primary"
                                                >
                                                    Ver foto em tamanho maior
                                                </a>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            <?php else: ?>

                                <div class="alert alert-secondary">

                                    Esta ocorrência ainda não possui foto.

                                </div>

                            <?php endif; ?>


                            <!-- NOVA FOTO -->

                            <label
                                for="foto"
                                class="form-label"
                            >
                                <?= !empty($ocorrencia->getFotoUrl())
                                    ? "Substituir foto"
                                    : "Adicionar foto" ?>
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


                        <!-- REMOVER FOTO -->

                        <?php if (
                            !empty($ocorrencia->getFotoUrl())
                        ): ?>

                            <div class="mb-3">

                                <div class="form-check">

                                    <input
                                        type="checkbox"
                                        name="remover_foto"
                                        id="remover_foto"
                                        class="form-check-input"
                                        value="1"
                                    >

                                    <label
                                        for="remover_foto"
                                        class="form-check-label text-danger"
                                    >
                                        Remover foto atual
                                    </label>

                                </div>

                                <div class="form-text">
                                    Se você selecionar uma nova foto,
                                    ela substituirá automaticamente
                                    a foto atual.
                                </div>

                            </div>

                        <?php endif; ?>


                        <!-- DATAS -->

                        <div class="row">

                            <div class="col-md-6 mb-3">

                                <label class="form-label">
                                    Data de abertura
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    value="<?= htmlspecialchars(
                                        formatarDataHora(
                                            $ocorrencia->getDataAbertura()
                                        )
                                    ) ?>"
                                    disabled
                                >

                            </div>


                            <div class="col-md-6 mb-3">

                                <label class="form-label">
                                    Data de fechamento
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    value="<?= htmlspecialchars(
                                        formatarDataHora(
                                            $ocorrencia->getDataFechamento()
                                        )
                                    ) ?>"
                                    disabled
                                >

                            </div>

                        </div>


                        <!-- BOTÕES -->

                        <div class="d-flex gap-2 mt-4">

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                Salvar Alterações
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
<script src="../../assets/js/script.js"></script>

</body>

</html>