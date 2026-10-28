<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirGestao();

require_once __DIR__ . "/../../controller/OcorrenciaController.php";

$controller = new OcorrenciaController();

$ocorrencias = $controller->listar();

$mensagem = "";
$tipoMensagem = "success";

/*
|--------------------------------------------------------------------------
| EXCLUSÃO
|--------------------------------------------------------------------------
*/

if (
    isset($_GET["excluir"]) &&
    is_numeric($_GET["excluir"])
) {

    $id = (int) $_GET["excluir"];

    if ($controller->excluir($id)) {

        $mensagem = "Ocorrência excluída com sucesso.";
        $tipoMensagem = "success";

    } else {

        $mensagem = "Não foi possível excluir a ocorrência.";
        $tipoMensagem = "danger";
    }

    $ocorrencias = $controller->listar();
}


/*
|--------------------------------------------------------------------------
| ALTERAÇÃO DE STATUS
|--------------------------------------------------------------------------
*/

if (
    isset($_GET["status"]) &&
    isset($_GET["id"]) &&
    is_numeric($_GET["id"])
) {

    $id = (int) $_GET["id"];
    $status = $_GET["status"];

    $statusPermitidos = [
        "Aberta",
        "Em_Andamento",
        "Resolvida",
        "Cancelada"
    ];

    if (
        in_array(
            $status,
            $statusPermitidos,
            true
        )
    ) {

        if (
            $controller->alterarStatus(
                $id,
                $status
            )
        ) {

            $mensagem = "Status da ocorrência atualizado com sucesso.";
            $tipoMensagem = "success";

        } else {

            $mensagem = "Não foi possível atualizar o status.";
            $tipoMensagem = "danger";
        }

        $ocorrencias = $controller->listar();
    }
}


/*
|--------------------------------------------------------------------------
| MENSAGENS DE CADASTRO / EDIÇÃO
|--------------------------------------------------------------------------
*/

if (isset($_GET["sucesso"])) {

    if ($_GET["sucesso"] == "1") {

        $mensagem = "Ocorrência cadastrada com sucesso.";
        $tipoMensagem = "success";
    }

    if ($_GET["sucesso"] == "2") {

        $mensagem = "Ocorrência editada com sucesso.";
        $tipoMensagem = "success";
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

    <title>Ocorrências - Koinon Services</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container-fluid mt-4">


    <!-- CABEÇALHO -->

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2>
                Ocorrências
            </h2>

            <p class="text-muted mb-0">
                Gerenciamento de ocorrências do condomínio.
            </p>

        </div>


        <div>

            <a
                href="cadastrar.php"
                class="btn btn-primary"
            >
                + Nova Ocorrência
            </a>

            <a
                href="../dashboard.php"
                class="btn btn-secondary"
            >
                Voltar
            </a>

        </div>

    </div>


    <!-- MENSAGEM -->

    <?php if (!empty($mensagem)): ?>

        <div
            class="alert alert-<?= htmlspecialchars($tipoMensagem) ?> alert-dismissible fade show"
            role="alert"
        >

            <?= htmlspecialchars($mensagem) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- TABELA -->

    <div class="card shadow-sm">

        <div class="card-header">

            <strong>
                Lista de Ocorrências
            </strong>

        </div>


        <div class="card-body">

            <?php if (empty($ocorrencias)): ?>

                <div class="alert alert-info mb-0">

                    Nenhuma ocorrência cadastrada.

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table
                        class="table table-bordered table-hover align-middle"
                    >

                        <thead class="table-dark">

                            <tr>

                                <th>Usuário</th>

                                <th>Tipo</th>

                                <th>Título</th>

                                <th>Descrição</th>

                                <th>Status</th>

                                <th>Foto</th>

                                <th>Abertura</th>

                                <th>Fechamento</th>

                                <th>Ações</th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($ocorrencias as $ocorrencia): ?>

                            <?php

                            $status =
                                $ocorrencia->getStatus();

                            switch ($status) {

                                case "Resolvida":
                                    $classeStatus = "success";
                                    break;

                                case "Cancelada":
                                    $classeStatus = "danger";
                                    break;

                                case "Em_Andamento":
                                    $classeStatus = "primary";
                                    break;

                                default:
                                    $classeStatus = "warning";
                                    break;
                            }

                            ?>


                            <tr>


                                <!-- USUÁRIO -->

                                <td>

                                    <?= htmlspecialchars(
                                        $ocorrencia->getUsuarioNome()
                                        ?? "Não informado"
                                    ) ?>

                                </td>


                                <!-- TIPO -->

                                <td>

                                    <?= htmlspecialchars(
                                        $ocorrencia->getTipo()
                                    ) ?>

                                </td>


                                <!-- TÍTULO -->

                                <td>

                                    <?= htmlspecialchars(
                                        $ocorrencia->getTitulo()
                                    ) ?>

                                </td>


                                <!-- DESCRIÇÃO -->

                                <td style="min-width: 250px;">

                                    <?= nl2br(
                                        htmlspecialchars(
                                            $ocorrencia->getDescricao()
                                        )
                                    ) ?>

                                </td>


                                <!-- STATUS -->

                                <td>

                                    <span
                                        class="badge bg-<?= $classeStatus ?>"
                                    >

                                        <?= htmlspecialchars(
                                            $status
                                        ) ?>

                                    </span>

                                </td>


                                <!-- FOTO -->

                                <td>

                                    <?php if (
                                        !empty(
                                            $ocorrencia->getFotoUrl()
                                        )
                                    ): ?>

                                        <a
                                            href="<?= htmlspecialchars(
                                                $ocorrencia->getFotoUrl()
                                            ) ?>"
                                            target="_blank"
                                            class="btn btn-sm btn-info"
                                        >
                                            Ver foto
                                        </a>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            Sem foto
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- DATA ABERTURA -->

                                <td>

                                    <?php

                                    $dataAbertura =
                                        $ocorrencia->getDataAbertura();

                                    if ($dataAbertura) {

                                        echo date(
                                            "d/m/Y H:i",
                                            strtotime(
                                                $dataAbertura
                                            )
                                        );
                                    }

                                    ?>

                                </td>


                                <!-- DATA FECHAMENTO -->

                                <td>

                                    <?php

                                    $dataFechamento =
                                        $ocorrencia->getDataFechamento();

                                    if ($dataFechamento) {

                                        echo date(
                                            "d/m/Y H:i",
                                            strtotime(
                                                $dataFechamento
                                            )
                                        );

                                    } else {

                                        echo '<span class="text-muted">-</span>';

                                    }

                                    ?>

                                </td>


                                <!-- AÇÕES -->

                                <td>

                                    <div
                                        class="d-flex gap-1 flex-wrap"
                                    >


                                        <a
                                            href="editar.php?id=<?= $ocorrencia->getId() ?>"
                                            class="btn btn-sm btn-warning"
                                        >
                                            Editar
                                        </a>


                                        <?php if ($status === "Aberta"): ?>

                                            <a
                                                href="index.php?id=<?= $ocorrencia->getId() ?>&status=Em_Andamento"
                                                class="btn btn-sm btn-primary"
                                                onclick="return confirm('Colocar esta ocorrência em andamento?')"
                                            >
                                                Em andamento
                                            </a>

                                            <a
                                                href="index.php?id=<?= $ocorrencia->getId() ?>&status=Cancelada"
                                                class="btn btn-sm btn-danger"
                                                onclick="return confirm('Cancelar esta ocorrência?')"
                                            >
                                                Cancelar
                                            </a>


                                        <?php elseif ($status === "Em_Andamento"): ?>

                                            <a
                                                href="index.php?id=<?= $ocorrencia->getId() ?>&status=Resolvida"
                                                class="btn btn-sm btn-success"
                                                onclick="return confirm('Marcar esta ocorrência como resolvida?')"
                                            >
                                                Resolver
                                            </a>

                                            <a
                                                href="index.php?id=<?= $ocorrencia->getId() ?>&status=Cancelada"
                                                class="btn btn-sm btn-danger"
                                                onclick="return confirm('Cancelar esta ocorrência?')"
                                            >
                                                Cancelar
                                            </a>

                                        <?php endif; ?>


                                        <a
                                            href="index.php?excluir=<?= $ocorrencia->getId() ?>"
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('Tem certeza que deseja excluir esta ocorrência?')"
                                        >
                                            Excluir
                                        </a>


                                    </div>

                                </td>


                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>
<script src="../../assets/js/script.js"></script>

</body>

</html>