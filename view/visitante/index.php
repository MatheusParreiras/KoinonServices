<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirPortaria();

require_once __DIR__ . "/../../controller/VisitanteController.php";

$controller = new VisitanteController();


/*
|--------------------------------------------------------------------------
| EXCLUIR VISITANTE
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

        header("Location: index.php?erro=1");
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| LISTAR VISITANTES
|--------------------------------------------------------------------------
*/

$visitantes = $controller->listar();

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Visitantes - Koinon Services</title>

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

            <h2>Visitantes</h2>

            <p class="text-muted mb-0">
                Gerenciamento de visitantes e prestadores externos
            </p>

        </div>

        <a
            href="cadastrar.php"
            class="btn btn-primary"
        >
            + Cadastrar Visitante
        </a>

    </div>


    <!-- MENSAGENS -->

    <?php if (isset($_GET["sucesso"])): ?>

        <?php if ($_GET["sucesso"] == 1): ?>

            <div class="alert alert-success">
                Visitante cadastrado com sucesso!
            </div>

        <?php elseif ($_GET["sucesso"] == 2): ?>

            <div class="alert alert-success">
                Visitante excluído com sucesso!
            </div>

        <?php elseif ($_GET["sucesso"] == 3): ?>

            <div class="alert alert-success">
                Visitante atualizado com sucesso!
            </div>

        <?php endif; ?>

    <?php endif; ?>


    <?php if (isset($_GET["erro"])): ?>

        <div class="alert alert-danger">
            Não foi possível realizar a operação.
        </div>

    <?php endif; ?>


    <!-- TABELA -->

    <div class="card shadow">

        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead class="table-dark">

                        <tr>

                            <th>Nome</th>

                            <th>CPF</th>

                            <th>Tipo</th>

                            <th>Foto</th>

                            <th>Data de Cadastro</th>

                            <th>Ações</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($visitantes)): ?>

                        <tr>

                            <td
                                colspan="6"
                                class="text-center text-muted"
                            >
                                Nenhum visitante cadastrado.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($visitantes as $visitante): ?>

                            <tr>

                                <!-- NOME -->

                                <td>

                                    <strong>

                                        <?= htmlspecialchars(
                                            $visitante->getNome()
                                        ) ?>

                                    </strong>

                                </td>


                                <!-- DOCUMENTO -->

                                <td>

                                    <?= htmlspecialchars(
                                        Cpf::formatar($visitante->getDocumento())
                                    ) ?>

                                </td>


                                <!-- TIPO -->

                                <td>

                                    <?php

                                    $tipo =
                                        $visitante->getTipoVisitante();

                                    if ($tipo === "Visitante") {

                                        $badge = "primary";

                                    } elseif (
                                        $tipo === "Prestador_Servico"
                                    ) {

                                        $badge = "warning";

                                    } else {

                                        $badge = "info";

                                    }

                                    ?>

                                    <span
                                        class="badge bg-<?= $badge ?>"
                                    >

                                        <?= htmlspecialchars(
                                            $tipo
                                        ) ?>

                                    </span>

                                </td>


                                <!-- FOTO -->

                                <td>

                                    <?php if (
                                        !empty(
                                            $visitante->getFotoUrl()
                                        )
                                    ): ?>

                                        <a
                                            href="<?= htmlspecialchars(
                                                $visitante->getFotoUrl()
                                            ) ?>"
                                            target="_blank"
                                            class="btn btn-sm btn-outline-primary"
                                        >
                                            Ver foto
                                        </a>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            Sem foto
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- DATA -->

                                <td>

                                    <?php

                                    $data =
                                        $visitante->getCreatedAt();

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


                                <!-- AÇÕES -->

                                <td>

                                    <div class="d-flex gap-1">

                                        <a
                                            href="editar.php?id=<?= $visitante->getId() ?>"
                                            class="btn btn-sm btn-warning"
                                        >
                                            Editar
                                        </a>


                                        <a
                                            href="index.php?acao=excluir&id=<?= $visitante->getId() ?>"
                                            class="btn btn-sm btn-danger"
                                            onclick="return confirm('Tem certeza que deseja excluir este visitante?');"
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