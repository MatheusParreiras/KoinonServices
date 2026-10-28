<?php


require_once __DIR__ . "/../../controller/UsuarioController.php";
require_once __DIR__ . "/../../controller/UnidadeController.php";
require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../service/Erro.php";

PermissaoController::exigirAdministrador();

$controller = new UsuarioController();
$unidadeController = new UnidadeController();

$unidades = $unidadeController->listar();

$erro = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        $nome = trim($_POST["nome"] ?? "");
        $cpf = trim($_POST["cpf"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $telefone = trim($_POST["telefone"] ?? "");
        $cpf = preg_replace('/\D+/', '', $cpf);
        $senha = bin2hex(random_bytes(24));

        if (strlen($cpf) !== 11) {
            $erro = "Informe um CPF válido com 11 dígitos.";
        }

        /*
         * Verifica CPF
         */
        if ($erro !== "") {
            // CPF inválido; não continua o cadastro.
        } elseif ($controller->cpfExiste($cpf)) {

            $erro = "Este CPF já está cadastrado no sistema.";

        /*
         * Verifica e-mail
         */
        } elseif ($controller->emailExiste($email)) {

            $erro = "Este e-mail já está cadastrado no sistema.";

        } else {

            $usuario = new UsuarioDTO();

            $usuario->setNome($nome);
            $usuario->setCpf($cpf);
            $usuario->setEmail($email);
            $usuario->setTelefone($telefone);

            $usuario->setSenhaHash(
                password_hash(
                    $senha,
                    PASSWORD_DEFAULT
                )
            );

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
             *
             * O cadastro administrativo NÃO pode registrar o aceite em
             * nome do usuário. O aceite deve ser realizado pelo próprio
             * titular no primeiro acesso.
             */
            $usuario->setTermoLgpd(false);
            $usuario->setDataAceiteLgpd(null);

            /*
             * Status
             */
            $usuario->setAtivo(
                isset($_POST["ativo"])
            );

            if ($controller->cadastrar($usuario)) {

                header(
                    "Location: index.php?sucesso=1"
                );

                exit;

            } else {

                $erro =
                    "Não foi possível cadastrar o usuário.";
            }
        }

    } catch (PDOException $e) {

        if ($e->getCode() == 23000) {

            $erro =
                "CPF ou e-mail já cadastrado no sistema.";

        } else {

            $erro =
                "Erro ao cadastrar usuário: " .
                Erro::mensagem($e);
        }

    } catch (Exception $e) {

        $erro =
            "Erro: " .
            Erro::mensagem($e);
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
        Cadastrar Usuário - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container mt-5">

    <div class="card shadow">

        <div class="card-header bg-primary text-white">

            <h4 class="mb-0">
                Cadastrar Usuário
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
                                $_POST["nome"] ?? ""
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
                                $_POST["cpf"] ?? ""
                            ) ?>"
                            placeholder="000.000.000-00"
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
                                $_POST["email"] ?? ""
                            ) ?>"
                            placeholder="exemplo@email.com"
                            required
                        >

                    </div>


                    <!-- SENHA INICIAL -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Senha inicial</label>
                        <div class="form-control bg-body-secondary text-muted">
                            Definida pelo usuário no primeiro acesso
                        </div>
                        <div class="form-text">Após o aceite da LGPD, o usuário será direcionado para criar sua senha pessoal.</div>
                    </div>

                    <!-- TELEFONE / WHATSAPP -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label">WhatsApp / telefone</label>
                        <input type="text" name="telefone" class="form-control" placeholder="(61) 99999-9999"
                               value="<?= htmlspecialchars($_POST["telefone"] ?? "") ?>">
                        <div class="form-text">Usado para recuperação de senha via WhatsApp quando configurado.</div>
                    </div>

                    <!-- PERFIL -->

                    <div class="col-md-4 mb-3">

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
                                <?= ($_POST["perfil"] ?? "Morador")
                                    === "Morador"
                                    ? "selected"
                                    : "" ?>
                            >
                                Morador
                            </option>

                            <option
                                value="Sindico"
                                <?= ($_POST["perfil"] ?? "")
                                    === "Sindico"
                                    ? "selected"
                                    : "" ?>
                            >
                                Síndico
                            </option>

                            <option
                                value="Porteiro"
                                <?= ($_POST["perfil"] ?? "")
                                    === "Porteiro"
                                    ? "selected"
                                    : "" ?>
                            >
                                Porteiro
                            </option>

                            <option
                                value="Administrador"
                                <?= ($_POST["perfil"] ?? "")
                                    === "Administrador"
                                    ? "selected"
                                    : "" ?>
                            >
                                Administrador
                            </option>

                        </select>

                    </div>


                    <!-- UNIDADE -->

                    <div class="col-md-4 mb-3">

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
                                        isset($_POST["unidade_id"]) &&
                                        $_POST["unidade_id"] ==
                                        $unidade->getId()
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

                    <div class="col-md-4 mb-3">

                        <label class="form-label">
                            Status
                        </label>

                        <div class="form-check mt-2">

                            <input
                                type="checkbox"
                                name="ativo"
                                value="1"
                                class="form-check-input"
                                <?= isset($_POST["ativo"])
                                    || $_SERVER["REQUEST_METHOD"] !== "POST"
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

                        <div class="alert alert-info mb-0">

                            <strong>LGPD:</strong> o usuário será solicitado a
                            ler e aceitar o termo de privacidade no primeiro
                            acesso. O administrador não pode aceitar o termo
                            em nome dele.

                        </div>

                    </div>

                </div>


                <div class="mt-3">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        Cadastrar
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