<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../service/Erro.php";

PermissaoController::exigirPortaria();

require_once __DIR__ . "/../../controller/AutorizacaoAcessoController.php";

$controller =
    new AutorizacaoAcessoController();

$erro = "";


/*
|--------------------------------------------------------------------------
| EXCLUIR AUTORIZAÇÃO
|--------------------------------------------------------------------------
*/

if (
    isset($_GET["acao"]) &&
    $_GET["acao"] === "excluir" &&
    isset($_GET["id"]) &&
    is_numeric($_GET["id"])
) {

    $id =
        (int) $_GET["id"];

    try {

        if (
            $controller->excluir($id)
        ) {

            header(
                "Location: index.php?sucesso=2"
            );

            exit;
        }

        header(
            "Location: index.php?erro=1"
        );

        exit;

    } catch (Throwable $e) {

        header(
            "Location: index.php?erro=1"
        );

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| LISTAR AUTORIZAÇÕES
|--------------------------------------------------------------------------
*/

try {

    $autorizacoes =
        $controller->listar();

} catch (Throwable $e) {

    $autorizacoes = [];

    $erro =
        Erro::mensagem($e);
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
        Autorizações de Acesso - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body class="bg-light">

<div class="container py-5">


    <!-- CABEÇALHO -->

    <div
        class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2"
    >

        <div>

            <h2 class="mb-1">

                Autorizações de Acesso

            </h2>

            <p class="text-muted mb-0">

                Gerenciamento das autorizações de entrada
                de visitantes no condomínio.

            </p>

        </div>


        <div class="d-flex gap-2">

            <a
                href="cadastrar.php"
                class="btn btn-primary"
            >

                + Nova Autorização

            </a>


            <a
                href="../dashboard.php"
                class="btn btn-outline-secondary"
            >

                Voltar

            </a>

        </div>

    </div>


    <!-- MENSAGENS -->

    <?php if (isset($_GET["sucesso"])): ?>

        <?php if ($_GET["sucesso"] == "1"): ?>

            <div class="alert alert-success">

                Autorização cadastrada com sucesso!

            </div>

        <?php elseif ($_GET["sucesso"] == "2"): ?>

            <div class="alert alert-success">

                Autorização excluída com sucesso!

            </div>

        <?php endif; ?>

    <?php endif; ?>


    <?php if (
        isset($_GET["erro"])
        ||
        $erro !== ""
    ): ?>

        <div class="alert alert-danger">

            <?php if ($erro !== ""): ?>

                <?= htmlspecialchars($erro) ?>

            <?php else: ?>

                Não foi possível realizar a operação.

            <?php endif; ?>

        </div>

    <?php endif; ?>


    <!-- TABELA -->

    <div class="card shadow-sm border-0">

        <div class="card-header bg-white py-3">

            <div
                class="d-flex justify-content-between align-items-center"
            >

                <strong>

                    Autorizações cadastradas

                </strong>


                <span
                    class="badge text-bg-primary"
                >

                    <?= count($autorizacoes) ?>

                </span>

            </div>

        </div>


        <div class="card-body">

            <div class="table-responsive">

                <table
                    class="table table-hover align-middle mb-0"
                >

                    <thead class="table-dark">

                        <tr>

                            <th>Visitante</th>

                            <th>CPF</th>

                            <th>Unidade</th>

                            <th>Autorizado por</th>

                            <th>Início</th>

                            <th>Fim</th>

                            <th>QR Code</th>

                            <th>Status</th>

                            <th>Ações</th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php if (
                        empty($autorizacoes)
                    ): ?>

                        <tr>

                            <td
                                colspan="9"
                                class="text-center text-muted py-4"
                            >

                                Nenhuma autorização cadastrada.

                            </td>

                        </tr>

                    <?php else: ?>


                        <?php foreach (
                            $autorizacoes
                            as $autorizacao
                        ): ?>


                            <?php

                            $status =
                                $autorizacao->getStatus();


                            switch ($status) {

                                case "Ativo":

                                    $badgeStatus =
                                        "success";

                                    break;


                                case "Expirado":

                                    $badgeStatus =
                                        "secondary";

                                    break;


                                case "Cancelado":

                                    $badgeStatus =
                                        "danger";

                                    break;


                                case "Utilizado":

                                    $badgeStatus =
                                        "primary";

                                    break;


                                default:

                                    $badgeStatus =
                                        "dark";

                                    break;
                            }


                            $dataInicio =
                                $autorizacao->getDataInicio();


                            $dataFim =
                                $autorizacao->getDataFim();

                            ?>


                            <tr>


                                <!-- VISITANTE -->

                                <td>

                                    <strong>

                                        <?= htmlspecialchars(
                                            $autorizacao
                                                ->getVisitanteNome()
                                                ??
                                            "Não informado"
                                        ) ?>

                                    </strong>

                                </td>


                                <!-- DOCUMENTO -->

                                <td>

                                    <?= htmlspecialchars(
                                        $autorizacao
                                            ->getVisitanteDocumento()
                                            ? Cpf::formatar($autorizacao->getVisitanteDocumento())
                                            : "-"
                                    ) ?>

                                </td>


                                <!-- UNIDADE -->

                                <td>

                                    <?= htmlspecialchars(
                                        $autorizacao
                                            ->getUnidadeIdentificacao()
                                            ??
                                        "-"
                                    ) ?>

                                </td>


                                <!-- AUTORIZADO POR -->

                                <td>

                                    <?= htmlspecialchars(
                                        $autorizacao
                                            ->getUsuarioAutorizadorNome()
                                            ??
                                        "Não informado"
                                    ) ?>

                                </td>


                                <!-- INÍCIO -->

                                <td>

                                    <?php if (
                                        $dataInicio
                                    ): ?>

                                        <?= date(
                                            "d/m/Y H:i",
                                            strtotime(
                                                $dataInicio
                                            )
                                        ) ?>

                                    <?php else: ?>

                                        -

                                    <?php endif; ?>

                                </td>


                                <!-- FIM -->

                                <td>

                                    <?php if (
                                        $dataFim
                                    ): ?>

                                        <?= date(
                                            "d/m/Y H:i",
                                            strtotime(
                                                $dataFim
                                            )
                                        ) ?>

                                    <?php else: ?>

                                        -

                                    <?php endif; ?>

                                </td>


                                <!-- QR CODE -->

                                <td>

                                    <?php if (
                                        $autorizacao
                                            ->getQrCode()
                                    ): ?>

                                        <span
                                            class="badge text-bg-success"
                                        >

                                            Gerado

                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="badge text-bg-secondary"
                                        >

                                            Não gerado

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- STATUS -->

                                <td>

                                    <span
                                        class="badge text-bg-<?= $badgeStatus ?>"
                                    >

                                        <?= htmlspecialchars(
                                            $status
                                        ) ?>

                                    </span>

                                </td>


                                <!-- AÇÕES -->

                                <td>

                                    <a
                                        href="index.php?acao=excluir&id=<?= (int) $autorizacao->getId() ?>"
                                        class="btn btn-sm btn-danger"
                                        onclick="return confirm('Tem certeza que deseja excluir esta autorização?');"
                                    >

                                        Excluir

                                    </a>

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