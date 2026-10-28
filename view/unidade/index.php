<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirGestao();

require_once __DIR__ . "/../../controller/UnidadeController.php";
require_once __DIR__ . "/../../service/CondominioConfigService.php";

$controller = new UnidadeController();

/*
|--------------------------------------------------------------------------
| EXCLUSÃO DE UNIDADE
|--------------------------------------------------------------------------
*/
if (
    isset($_GET["acao"]) &&
    $_GET["acao"] === "excluir" &&
    isset($_GET["id"]) &&
    is_numeric($_GET["id"])
) {
    $id = (int) $_GET["id"];

    try {
        if ($controller->excluir($id)) {
            header("Location: index.php?sucesso=2");
            exit;
        } else {
            header("Location: index.php?erro=1");
            exit;
        }
    } catch (PDOException $e) {
        header("Location: index.php?erro=2");
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| LISTAGEM
|--------------------------------------------------------------------------
*/
$unidades = $controller->listar();

?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Unidades - Koinon Services</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container mt-5">

    <!-- CABEÇALHO -->

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2>Unidades</h2>

            <p class="text-muted mb-0">
                Gerenciamento das unidades do condomínio
            </p>

        </div>

        <a
            href="cadastrar.php"
            class="btn btn-primary"
        >
            + Cadastrar Unidade
        </a>

    </div>


    <!-- MENSAGENS -->

    <?php if (isset($_GET["sucesso"])): ?>

        <?php if ($_GET["sucesso"] == 1): ?>

            <div class="alert alert-success">
                Unidade cadastrada com sucesso!
            </div>

        <?php elseif ($_GET["sucesso"] == 2): ?>

            <div class="alert alert-success">
                Unidade excluída com sucesso!
            </div>

        <?php elseif ($_GET["sucesso"] == 3): ?>

            <div class="alert alert-success">
                Unidade atualizada com sucesso!
            </div>

        <?php endif; ?>

    <?php endif; ?>


    <?php if (isset($_GET["erro"])): ?>

        <?php if ($_GET["erro"] == 1): ?>

            <div class="alert alert-danger">
                Não foi possível excluir a unidade.
            </div>

        <?php elseif ($_GET["erro"] == 2): ?>

            <div class="alert alert-danger">
                Não é possível excluir esta unidade porque existem
                registros relacionados a ela.
            </div>

        <?php else: ?>

            <div class="alert alert-danger">
                Não foi possível realizar a operação.
            </div>

        <?php endif; ?>

    <?php endif; ?>


    <!-- TABELA -->

    <div class="card shadow">

        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead class="table-dark">

                        <tr>

                            <th>Identificação</th>

                            <th>Tipo</th><th>Garagem</th>

                            <th>Data de Cadastro</th>

                            <th>Ações</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($unidades)): ?>

                        <tr>

                            <td
                                colspan="5"
                                class="text-center text-muted"
                            >
                                Nenhuma unidade cadastrada.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($unidades as $unidade): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars(
                                        $unidade->getIdentificacao()
                                    ) ?>
                                </td>

                                <td>

                                    <?php
                                    $tipo = $unidade->getTipoUnidade();

                                    if ($tipo === "Residencial") {
                                        $badge = "primary";
                                    } elseif ($tipo === "Comercial") {
                                        $badge = "success";
                                    } elseif ($tipo === "Vaga") {
                                        $badge = "warning";
                                    } else {
                                        $badge = "secondary";
                                    }
                                    ?>

                                    <span class="badge bg-<?= $badge ?>">
                                        <?= htmlspecialchars($tipo) ?>
                                    </span>

                                </td>

                                <td><span class="badge bg-primary-subtle text-primary"><?=CondominioConfigService::vagasUnidade((int)$unidade->getId())?> vaga(s)</span></td>

                                <td>

                                    <?php
                                    $data = $unidade->getCreatedAt();

                                    if ($data) {
                                        echo date(
                                            "d/m/Y H:i",
                                            strtotime($data)
                                        );
                                    } else {
                                        echo "-";
                                    }
                                    ?>

                                </td>

                                <td>

                                    <div class="d-flex gap-1">

                                        <a
                                            href="editar.php?id=<?= $unidade->getId() ?>"
                                            class="btn btn-sm btn-warning"
                                        >
                                            Editar
                                        </a>

                                        <a
                                            href="index.php?acao=excluir&id=<?= $unidade->getId() ?>"
                                            class="btn btn-sm btn-danger"
                                            onclick="return confirm('Tem certeza que deseja excluir esta unidade? Esta ação pode afetar registros relacionados ao condomínio.');"
                                        >
                                            Excluir
                                        </a>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>
<script src="../../assets/js/script.js"></script>

</body>

</html>