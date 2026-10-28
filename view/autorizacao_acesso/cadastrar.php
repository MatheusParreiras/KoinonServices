<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirPortaria();

require_once __DIR__ . "/../../controller/AutorizacaoAcessoController.php";
require_once __DIR__ . "/../../controller/VisitanteController.php";
require_once __DIR__ . "/../../controller/UnidadeController.php";
require_once __DIR__ . "/../../controller/UsuarioController.php";

$controller = new AutorizacaoAcessoController();

$visitanteController = new VisitanteController();
$unidadeController = new UnidadeController();
$usuarioController = new UsuarioController();

$erro = "";


// ======================================
// CARREGAR DADOS PARA OS SELECTS
// ======================================

$visitantes = $visitanteController->listar();

$unidades = $unidadeController->listar();

$usuarios = $usuarioController->listar();


// ======================================
// CADASTRAR
// ======================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        $visitanteId = $_POST["visitante_id"] ?? "";

        $unidadeId = $_POST["unidade_id"] ?? "";

        $usuarioAutorizadorId =
            $_POST["usuario_autorizador_id"] ?? "";

        $dataInicio =
            $_POST["data_inicio"] ?? "";

        $dataFim =
            $_POST["data_fim"] ?? "";

        $status =
            $_POST["status"] ?? "Ativo";


        // ==================================
        // VALIDAÇÕES
        // ==================================

        if (
            empty($visitanteId) ||
            !is_numeric($visitanteId)
        ) {

            $erro =
                "Selecione um visitante.";

        } elseif (
            empty($unidadeId) ||
            !is_numeric($unidadeId)
        ) {

            $erro =
                "Selecione uma unidade.";

        } elseif (
            empty($dataInicio)
        ) {

            $erro =
                "Informe a data e hora de início.";

        } elseif (
            empty($dataFim)
        ) {

            $erro =
                "Informe a data e hora de fim.";

        } elseif (
            strtotime($dataFim)
            <= strtotime($dataInicio)
        ) {

            $erro =
                "A data/hora de fim deve ser posterior à data/hora de início.";

        } elseif (
            !in_array(
                $status,
                [
                    "Ativo",
                    "Expirado",
                    "Cancelado",
                    "Utilizado"
                ]
            )
        ) {

            $erro =
                "Selecione um status válido.";

        } else {


            // ==================================
            // CRIAR DTO
            // ==================================

            $autorizacao =
                new AutorizacaoAcessoDTO();


            $autorizacao->setVisitanteId(
                (int) $visitanteId
            );


            $autorizacao->setUnidadeId(
                (int) $unidadeId
            );


            // Usuário autorizador é opcional

            if (
                !empty($usuarioAutorizadorId) &&
                is_numeric($usuarioAutorizadorId)
            ) {

                $autorizacao->setUsuarioAutorizadorId(
                    (int) $usuarioAutorizadorId
                );

            } else {

                $autorizacao->setUsuarioAutorizadorId(
                    null
                );
            }


            // ==================================
            // DATAS
            // ==================================

            /*
             * O input datetime-local envia:
             *
             * 2026-09-18T18:30
             *
             * O MySQL espera:
             *
             * 2026-09-18 18:30:00
             */

            $dataInicioMysql =
                str_replace(
                    "T",
                    " ",
                    $dataInicio
                );

            $dataFimMysql =
                str_replace(
                    "T",
                    " ",
                    $dataFim
                );


            if (strlen($dataInicioMysql) === 16) {

                $dataInicioMysql .= ":00";
            }


            if (strlen($dataFimMysql) === 16) {

                $dataFimMysql .= ":00";
            }


            $autorizacao->setDataInicio(
                $dataInicioMysql
            );


            $autorizacao->setDataFim(
                $dataFimMysql
            );


            // ==================================
            // STATUS
            // ==================================

            $autorizacao->setStatus(
                $status
            );


            // ==================================
            // QR CODE
            // ==================================

            /*
             * Nesta primeira versão,
             * o QR Code será gerado automaticamente
             * com um código único.
             */

            $qrCode =
                "KOINON-" .
                strtoupper(
                    bin2hex(
                        random_bytes(8)
                    )
                );


            $autorizacao->setQrCode(
                $qrCode
            );


            // ==================================
            // SALVAR
            // ==================================

            if (
                $controller->cadastrar(
                    $autorizacao
                )
            ) {

                header(
                    "Location: index.php?sucesso=1"
                );

                exit;

            } else {

                $erro =
                    "Não foi possível cadastrar a autorização.";
            }
        }

    } catch (PDOException $e) {

        $erro =
            "Erro no banco de dados: " .
            $e->getMessage();

    } catch (Exception $e) {

        $erro =
            "Erro: " .
            $e->getMessage();
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
        Nova Autorização - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container mt-5">

    <div class="card shadow">

        <div class="card-header bg-primary text-white">

            <h4 class="mb-0">
                Nova Autorização de Acesso
            </h4>

        </div>


        <div class="card-body">


            <!-- ==================================
                 ERRO
            =================================== -->

            <?php if (!empty($erro)): ?>

                <div class="alert alert-danger">

                    <?= htmlspecialchars($erro) ?>

                </div>

            <?php endif; ?>


            <form
                method="POST"
            >


                <div class="row">


                    <!-- ==================================
                         VISITANTE
                    =================================== -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">

                            Visitante

                        </label>


                        <select
                            name="visitante_id"
                            class="form-select"
                            required
                        >

                            <option value="">

                                Selecione o visitante

                            </option>


                            <?php foreach (
                                $visitantes
                                as $visitante
                            ): ?>

                                <option
                                    value="<?= $visitante->getId() ?>"
                                    <?= (
                                        ($_POST["visitante_id"] ?? "")
                                        == $visitante->getId()
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >

                                    <?= htmlspecialchars(
                                        $visitante->getNome()
                                    ) ?>

                                    -
                                    <?= htmlspecialchars(
                                        Cpf::formatar($visitante->getDocumento())
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- ==================================
                         UNIDADE
                    =================================== -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">

                            Unidade

                        </label>


                        <select
                            name="unidade_id"
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
                                    value="<?= $unidade->getId() ?>"
                                    <?= (
                                        ($_POST["unidade_id"] ?? "")
                                        == $unidade->getId()
                                    )
                                        ? "selected"
                                        : ""
                                    ?>
                                >

                                    <?= htmlspecialchars(
                                        $unidade->getIdentificacao()
                                    ) ?>

                                    -
                                    <?= htmlspecialchars(
                                        $unidade->getTipoUnidade()
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- ==================================
                         USUÁRIO AUTORIZADOR
                    =================================== -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">

                            Autorizado por

                        </label>


                        <select
                            name="usuario_autorizador_id"
                            class="form-select"
                        >

                            <option value="">

                                Não informado

                            </option>


                            <?php foreach (
                                $usuarios
                                as $usuario
                            ): ?>


                                <?php if (
                                    $usuario->getAtivo()
                                ): ?>

                                    <option
                                        value="<?= $usuario->getId() ?>"
                                        <?= (
                                            ($_POST["usuario_autorizador_id"] ?? "")
                                            == $usuario->getId()
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

                                <?php endif; ?>

                            <?php endforeach; ?>

                        </select>


                        <small class="text-muted">

                            Usuário responsável pela autorização.

                        </small>

                    </div>


                    <!-- ==================================
                         STATUS
                    =================================== -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">

                            Status

                        </label>


                        <select
                            name="status"
                            class="form-select"
                            required
                        >

                            <option
                                value="Ativo"
                                <?= (
                                    ($_POST["status"] ?? "Ativo")
                                    === "Ativo"
                                )
                                    ? "selected"
                                    : ""
                                ?>
                            >

                                Ativo

                            </option>


                            <option
                                value="Expirado"
                                <?= (
                                    ($_POST["status"] ?? "")
                                    === "Expirado"
                                )
                                    ? "selected"
                                    : ""
                                ?>
                            >

                                Expirado

                            </option>


                            <option
                                value="Cancelado"
                                <?= (
                                    ($_POST["status"] ?? "")
                                    === "Cancelado"
                                )
                                    ? "selected"
                                    : ""
                                ?>
                            >

                                Cancelado

                            </option>


                            <option
                                value="Utilizado"
                                <?= (
                                    ($_POST["status"] ?? "")
                                    === "Utilizado"
                                )
                                    ? "selected"
                                    : ""
                                ?>
                            >

                                Utilizado

                            </option>

                        </select>

                    </div>


                    <!-- ==================================
                         DATA INÍCIO
                    =================================== -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">

                            Data e hora de início

                        </label>


                        <input
                            type="datetime-local"
                            name="data_inicio"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $_POST["data_inicio"] ?? ""
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- ==================================
                         DATA FIM
                    =================================== -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">

                            Data e hora de fim

                        </label>


                        <input
                            type="datetime-local"
                            name="data_fim"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $_POST["data_fim"] ?? ""
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- ==================================
                         QR CODE
                    =================================== -->

                    <div class="col-12 mb-3">

                        <div class="alert alert-info">

                            <strong>
                                QR Code
                            </strong>

                            <br>

                            Um código exclusivo será gerado
                            automaticamente ao cadastrar a autorização.

                            <br>

                            Esse código poderá ser utilizado
                            futuramente no controle de acesso da portaria.

                        </div>

                    </div>


                </div>


                <!-- ==================================
                     BOTÕES
                =================================== -->

                <div class="mt-3">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >

                        Cadastrar autorização

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


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>
<script src="../../assets/js/script.js"></script>

</body>

</html>