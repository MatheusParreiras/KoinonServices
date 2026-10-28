<?php

require_once __DIR__ . "/../../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../../service/PagamentoService.php";
require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/CobrancaController.php";

PermissaoController::exigirGestao();

$controller = new CobrancaController();

$perfil = $_SESSION["usuario_perfil"] ?? "";

$podeAdministrar = in_array(
    $perfil,
    [
        "Administrador",
        "Sindico"
    ],
    true
);

$mensagem = "";
$tipoMensagem = "success";

/*
 * Atualiza vencimentos somente para Administrador ou Síndico.
 */
if (
    isset($_GET["acao"]) &&
    $_GET["acao"] === "atualizar_vencimentos"
) {

    if (!$podeAdministrar) {

        header(
            "Location: index.php?erro=sem_permissao"
        );

        exit;
    }

    try {

        $controller->atualizarVencimentos();

        header(
            "Location: index.php?sucesso=vencimentos"
        );

        exit;

    } catch (Throwable $e) {

        header(
            "Location: index.php?erro=operacao"
        );

        exit;
    }
}

/*
 * Registrar pagamento.
 */
if (
    isset($_GET["acao"]) &&
    $_GET["acao"] === "pagar" &&
    isset($_GET["id"])
) {

    if (!$podeAdministrar) {

        header(
            "Location: index.php?erro=sem_permissao"
        );

        exit;
    }

    $id = (int) $_GET["id"];

    if ($id > 0) {

        try {

            $resultado =
                $controller->registrarPagamento($id);

            if ($resultado) {

                header(
                    "Location: index.php?sucesso=pago"
                );

                exit;

            } else {

                header(
                    "Location: index.php?erro=operacao"
                );

                exit;
            }

        } catch (Throwable $e) {

            header(
                "Location: index.php?erro=operacao"
            );

            exit;
        }
    }
}

/*
 * Exclusão.
 */
if (
    isset($_GET["acao"]) &&
    $_GET["acao"] === "excluir" &&
    isset($_GET["id"])
) {

    if (!$podeAdministrar) {

        header(
            "Location: index.php?erro=sem_permissao"
        );

        exit;
    }

    $id = (int) $_GET["id"];

    if ($id > 0) {

        try {

            $resultado =
                $controller->excluir($id);

            if ($resultado) {

                header(
                    "Location: index.php?sucesso=excluido"
                );

                exit;

            } else {

                header(
                    "Location: index.php?erro=operacao"
                );

                exit;
            }

        } catch (Throwable $e) {

            header(
                "Location: index.php?erro=operacao"
            );

            exit;
        }
    }
}

/*
 * Mensagens.
 */
if (isset($_GET["sucesso"])) {

    switch ($_GET["sucesso"]) {

        case "pago":
            $mensagem =
                "Pagamento registrado com sucesso.";
            break;

        case "excluido":
            $mensagem =
                "Cobrança excluída com sucesso.";
            break;

        case "vencimentos":
            $mensagem =
                "Vencimentos atualizados com sucesso.";
            break;
    }
}

if (isset($_GET["erro"])) {

    switch ($_GET["erro"]) {

        case "sem_permissao":
            $mensagem =
                "Você não possui permissão para realizar esta operação.";
            $tipoMensagem = "danger";
            break;

        default:
            $mensagem =
                "Não foi possível realizar a operação.";
            $tipoMensagem = "danger";
            break;
    }
}

$cobrancas = $controller->listar();

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Financeiro - Koinon Services</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container-fluid mt-4">

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2>
                💰 Financeiro
            </h2>

            <p class="text-muted mb-0">

                Controle de cobranças do condomínio.

            </p>

        </div>

        <div>

            <a
                href="../dashboard.php"
                class="btn btn-secondary"
            >
                ← Voltar
            </a>

            <?php if ($podeAdministrar): ?>

                <a
                    href="cadastrar.php"
                    class="btn btn-success"
                >
                    + Nova Cobrança
                </a>

                <a
                    href="index.php?acao=atualizar_vencimentos"
                    class="btn btn-warning"
                    onclick="return confirm('Deseja atualizar os vencimentos das cobranças?');"
                >
                    🔄 Atualizar Vencimentos
                </a>

            <?php endif; ?>

        </div>

    </div>


    <?php if (!empty($mensagem)): ?>

        <div class="alert alert-<?= htmlspecialchars($tipoMensagem) ?>">

            <?= htmlspecialchars($mensagem) ?>

        </div>

    <?php endif; ?>


    <?php if (!$podeAdministrar): ?>

        <div class="alert alert-info">

            <strong>Consulta financeira:</strong>

            você possui acesso somente para visualização.
            O registro e alteração de pagamentos são realizados pelo administrador.

        </div>

    <?php endif; ?>


    <div class="card shadow">

        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-bordered table-hover align-middle">

                    <thead class="table-dark">

                        <tr>

                            <th>Unidade</th>

                            <th>Tipo</th>

                            <th>Mês</th>

                            <th>Descrição</th>

                            <th>Valor</th>

                            <th>Vencimento</th>

                            <th>Status</th>

                            <th>Pagamento</th>

                            <?php if ($podeAdministrar): ?>

                                <th>Ações</th>

                            <?php endif; ?>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($cobrancas)): ?>

                        <tr>

                            <td
                                colspan="<?= $podeAdministrar ? '9' : '8' ?>"
                                class="text-center"
                            >

                                Nenhuma cobrança encontrada.

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($cobrancas as $cobranca): ?>

                            <?php

                            $status =
                                $cobranca->getStatus();

                            $classeStatus = "secondary";

                            if ($status === "Pago") {
                                $classeStatus = "success";
                            } elseif ($status === "Pendente") {
                                $classeStatus = "warning";
                            } elseif ($status === "Vencido") {
                                $classeStatus = "danger";
                            } elseif ($status === "Cancelado") {
                                $classeStatus = "dark";
                            }

                            ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars(
                                        $cobranca->getUnidadeIdentificacao()
                                        ?? "Não vinculada"
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $cobranca->getTipoCobranca()
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $cobranca->getMesReferencia()
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $cobranca->getDescricao()
                                    ) ?>
                                </td>

                                <td>

                                    R$

                                    <?= number_format(
                                        (float) $cobranca->getValor(),
                                        2,
                                        ",",
                                        "."
                                    ) ?>

                                </td>

                                <td>

                                    <?= date(
                                        "d/m/Y",
                                        strtotime(
                                            $cobranca->getVencimento()
                                        )
                                    ) ?>

                                </td>

                                <td>

                                    <span
                                        class="badge bg-<?= $classeStatus ?>"
                                    >

                                        <?= htmlspecialchars(
                                            $status
                                        ) ?>

                                    </span>

                                </td>

                                <td>

                                    <?php if (
                                        $cobranca->getDataPagamento()
                                    ): ?>

                                        <?= date(
                                            "d/m/Y H:i",
                                            strtotime(
                                                $cobranca->getDataPagamento()
                                            )
                                        ) ?>

                                        <div class="small text-muted">
                                            <?= htmlspecialchars(PagamentoService::rotuloForma($cobranca->getFormaPagamento())) ?>
                                        </div>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            Não registrado
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <?php if ($podeAdministrar): ?>

                                    <td>

                                        <div class="d-flex gap-1 flex-wrap">

                                            <a
                                                href="editar.php?id=<?= $cobranca->getId() ?>"
                                                class="btn btn-sm btn-primary"
                                            >
                                                Editar
                                            </a>


                                            <?php if (
                                                $status !== "Pago" &&
                                                $status !== "Cancelado"
                                            ): ?>

                                                <a
                                                    href="index.php?acao=pagar&id=<?= $cobranca->getId() ?>"
                                                    class="btn btn-sm btn-success"
                                                    onclick="return confirm('Confirmar o pagamento desta cobrança?');"
                                                >
                                                    💰 Marcar Pago
                                                </a>

                                            <?php endif; ?>


                                            <?php if ($status === "Pago"): ?>
                                                <span class="badge text-bg-secondary align-self-center">🧾 Comprovante emitido</span>
                                            <?php else: ?>
                                                <a
                                                    href="index.php?acao=excluir&id=<?= $cobranca->getId() ?>"
                                                    class="btn btn-sm btn-danger"
                                                    onclick="return confirm('Tem certeza que deseja excluir esta cobrança?');"
                                                >
                                                    Excluir
                                                </a>
                                            <?php endif; ?>

                                        </div>

                                    </td>

                                <?php endif; ?>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>
<script src="../../assets/js/script.js"></script>

</body>

</html>