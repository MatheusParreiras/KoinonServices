<?php


require_once __DIR__ . "/../../controller/UsuarioController.php";
require_once __DIR__ . "/../../controller/UnidadeController.php";
require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirAdministrador();

$controller = new UsuarioController();
$unidadeController = new UnidadeController();

$unidades = $unidadeController->listar();

if (
    !isset($_GET["id"]) ||
    !is_numeric($_GET["id"])
) {

    header("Location: index.php");
    exit;
}

$id = (int) $_GET["id"];

$usuario = $controller->buscarPorId($id);

if (!$usuario) {

    die("Usuário não encontrado.");
}

$erro = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        $nome = trim($_POST["nome"] ?? "");
        $cpf = trim($_POST["cpf"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $telefone = trim($_POST["telefone"] ?? "");
        $cpf = preg_replace('/\D+/', '', $cpf);

        /*
         * Verifica CPF de outro usuário
         */
        if (strlen($cpf) !== 11) {
            $erro = "Informe um CPF válido com 11 dígitos.";
        } elseif (
            $controller->cpfExiste(
                $cpf,
                $id
            )
        ) {

            $erro =
                "Este CPF já está cadastrado para outro usuário.";

        /*
         * Verifica e-mail de outro usuário
         */
        } elseif (
            $controller->emailExiste(
                $email,
                $id
            )
        ) {

            $erro =
                "Este e-mail já está cadastrado para outro usuário.";

        } else {

            $usuario->setNome($nome);
            $usuario->setCpf($cpf);
            $usuario->setEmail($email);
            $usuario->setTelefone($telefone);

            $usuario->setPerfil(
                $_POST["perfil"] ?? "Morador"
            );

            /*
             * Unidade selecionada
             */
            $unidadeId = !empty($_POST["unidade_id"])
                ? (int) $_POST["unidade_id"]
                : null;

            $usuario->setUnidadeId($unidadeId);

            /*
             * LGPD
             */
            $termoLgpd =
                isset($_POST["termo_lgpd"]);

            $usuario->setTermoLgpd(
                $termoLgpd
            );

            if ($termoLgpd) {

                $usuario->setDataAceiteLgpd(
                    $usuario->getDataAceiteLgpd()
                    ?? date("Y-m-d H:i:s")
                );

            } else {

                $usuario->setDataAceiteLgpd(null);
            }

            /*
             * Status
             */
            $usuario->setAtivo(
                isset($_POST["ativo"])
            );

            if ($controller->editar($usuario)) {

                header(
                    "Location: index.php?sucesso=2"
                );

                exit;

            } else {

                $erro =
                    "Não foi possível atualizar o usuário.";
            }
        }

    } catch (PDOException $e) {

        if ($e->getCode() == 23000) {

            $erro =
                "CPF ou e-mail já cadastrado para outro usuário.";

        } else {

            $erro =
                "Erro ao atualizar usuário: " .
                $e->getMessage();
        }

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
        Editar Usuário - Koinon Services
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

        <div class="card-header bg-warning">

            <h4 class="mb-0">
                Editar Usuário
            </h4>

        </div>

        <div class="card-body">

            <?php if (!empty($erro)): ?>

                <div class="alert alert-danger">
                    <?= htmlspecialchars($erro) ?>
                </div>

            <?php endif; ?>


            <form method="POST">

                <div class="row">

                    <!-- NOME -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Nome completo
                        </label>

                        <input
                            type="text"
                            name="nome"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $usuario->getNome()
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- CPF -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            CPF
                        </label>

                        <input
                            type="text"
                            name="cpf"
                            class="form-control"
                            maxlength="14"
                            inputmode="numeric"
                            pattern="\d{3}\.\d{3}\.\d{3}-\d{2}"
                            data-cpf
                            value="<?= htmlspecialchars(
                                Cpf::formatar($usuario->getCpf())
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- EMAIL -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            E-mail
                        </label>

                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $usuario->getEmail()
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- TELEFONE / WHATSAPP -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label">WhatsApp / telefone</label>
                        <input type="text" name="telefone" class="form-control" placeholder="(61) 99999-9999"
                               value="<?= htmlspecialchars($_POST["telefone"] ?? $usuario->getTelefone()) ?>">
                    </div>

                    <!-- PERFIL -->


                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Perfil
                        </label>

                        <select
                            name="perfil"
                            class="form-select"
                            required
                        >

                            <option
                                value="Morador"
                                <?= $usuario->getPerfil()
                                    === "Morador"
                                    ? "selected"
                                    : "" ?>
                            >
                                Morador
                            </option>

                            <option
                                value="Sindico"
                                <?= $usuario->getPerfil()
                                    === "Sindico"
                                    ? "selected"
                                    : "" ?>
                            >
                                Síndico
                            </option>

                            <option
                                value="Porteiro"
                                <?= $usuario->getPerfil()
                                    === "Porteiro"
                                    ? "selected"
                                    : "" ?>
                            >
                                Porteiro
                            </option>

                            <option
                                value="Administrador"
                                <?= $usuario->getPerfil()
                                    === "Administrador"
                                    ? "selected"
                                    : "" ?>
                            >
                                Administrador
                            </option>

                        </select>

                    </div>


                    <!-- UNIDADE -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Unidade
                        </label>

                        <select
                            name="unidade_id"
                            class="form-select"
                        >

                            <option value="">
                                Nenhuma unidade
                            </option>

                            <?php foreach ($unidades as $unidade): ?>

                                <option
                                    value="<?= $unidade->getId() ?>"
                                    <?= (
                                        $usuario->getUnidadeId()
                                        === $unidade->getId()
                                    )
                                        ? "selected"
                                        : "" ?>
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

                        <small class="text-muted">
                            Selecione a unidade vinculada ao usuário.
                        </small>

                    </div>


                    <!-- STATUS -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Status
                        </label>

                        <div class="form-check mt-2">

                            <input
                                type="checkbox"
                                name="ativo"
                                value="1"
                                class="form-check-input"
                                <?= $usuario->getAtivo()
                                    ? "checked"
                                    : "" ?>
                            >

                            <label class="form-check-label">
                                Usuário ativo
                            </label>

                        </div>

                    </div>


                    <!-- LGPD -->

                    <div class="col-md-12 mb-3">

                        <div class="form-check">

                            <input
                                type="checkbox"
                                name="termo_lgpd"
                                value="1"
                                class="form-check-input"
                                <?= $usuario->getTermoLgpd()
                                    ? "checked"
                                    : "" ?>
                                required
                            >

                            <label class="form-check-label">

                                Usuário aceitou os termos de tratamento
                                de dados conforme LGPD.

                            </label>

                        </div>

                    </div>

                </div>


                <div class="mt-3">

                    <button
                        type="submit"
                        class="btn btn-success"
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
<script src="../../assets/js/script.js"></script>

</body>

</html>