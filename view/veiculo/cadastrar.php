<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirGestao();

require_once __DIR__ . "/../../controller/VeiculoController.php";
require_once __DIR__ . "/../../controller/UsuarioController.php";

$veiculoController = new VeiculoController();
$usuarioController = new UsuarioController();

$erro = "";

/*
|--------------------------------------------------------------------------
| LISTAR USUÁRIOS
|--------------------------------------------------------------------------
*/

$usuarios = $usuarioController->listar();


/*
|--------------------------------------------------------------------------
| CADASTRAR VEÍCULO
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        $usuarioId = !empty($_POST["usuario_id"])
            ? (int) $_POST["usuario_id"]
            : 0;

        $placa = strtoupper(
            trim($_POST["placa"] ?? "")
        );

        $modelo = trim(
            $_POST["modelo"] ?? ""
        );

        $cor = trim(
            $_POST["cor"] ?? ""
        );


        /*
        |--------------------------------------------------------------------------
        | VALIDAÇÕES
        |--------------------------------------------------------------------------
        */

        if ($usuarioId <= 0) {

            $erro = "Selecione o proprietário do veículo.";

        } elseif (empty($placa)) {

            $erro = "Informe a placa do veículo.";

        } elseif (empty($modelo)) {

            $erro = "Informe o modelo do veículo.";

        } elseif (empty($cor)) {

            $erro = "Informe a cor do veículo.";

        } else {

            $veiculo = new VeiculoDTO();

            $veiculo->setUsuarioId($usuarioId);
            $veiculo->setPlaca($placa);
            $veiculo->setModelo($modelo);
            $veiculo->setCor($cor);


            /*
            |--------------------------------------------------------------------------
            | CADASTRAR
            |--------------------------------------------------------------------------
            */

            if ($veiculoController->cadastrar($veiculo)) {

                header(
                    "Location: index.php?sucesso=1"
                );

                exit;

            } else {

                $erro = "Não foi possível cadastrar o veículo.";

            }
        }

    } catch (PDOException $e) {

        /*
        |--------------------------------------------------------------------------
        | PLACA DUPLICADA
        |--------------------------------------------------------------------------
        */

        if ($e->getCode() == 23000) {

            $erro = "Esta placa já está cadastrada no sistema.";

        } else {

            $erro = "Erro no banco de dados: " . $e->getMessage();

        }

    } catch (Exception $e) {

        $erro = "Erro: " . $e->getMessage();

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

    <title>Cadastrar Veículo - Koinon Services</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container mt-5">

    <div class="card shadow">

        <!-- CABEÇALHO -->

        <div class="card-header bg-primary text-white">

            <h4 class="mb-0">
                Cadastrar Veículo
            </h4>

        </div>


        <div class="card-body">


            <!-- ERRO -->

            <?php if (!empty($erro)): ?>

                <div class="alert alert-danger">

                    <?= htmlspecialchars($erro) ?>

                </div>

            <?php endif; ?>


            <!-- FORMULÁRIO -->

            <form method="POST">


                <div class="row">


                    <!-- PROPRIETÁRIO -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Proprietário
                        </label>

                        <select
                            name="usuario_id"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Selecione o proprietário...
                            </option>


                            <?php foreach ($usuarios as $usuario): ?>

                                <?php if ($usuario->getAtivo()): ?>

                                    <option
                                        value="<?= $usuario->getId() ?>"
                                        <?= (
                                            ($_POST["usuario_id"] ?? "")
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
                                            $usuario->getCpf()
                                        ) ?>

                                    </option>

                                <?php endif; ?>

                            <?php endforeach; ?>

                        </select>

                        <small class="text-muted">
                            Selecione o usuário responsável pelo veículo.
                        </small>

                    </div>


                    <!-- PLACA -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Placa
                        </label>

                        <input
                            type="text"
                            name="placa"
                            class="form-control"
                            maxlength="10"
                            placeholder="Ex.: ABC1D23"
                            value="<?= htmlspecialchars(
                                $_POST["placa"] ?? ""
                            ) ?>"
                            style="text-transform: uppercase;"
                            required
                        >

                        <small class="text-muted">
                            Informe a placa do veículo.
                        </small>

                    </div>


                    <!-- MODELO -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Modelo
                        </label>

                        <input
                            type="text"
                            name="modelo"
                            class="form-control"
                            maxlength="50"
                            placeholder="Ex.: Chevrolet Onix"
                            value="<?= htmlspecialchars(
                                $_POST["modelo"] ?? ""
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- COR -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Cor
                        </label>

                        <input
                            type="text"
                            name="cor"
                            class="form-control"
                            maxlength="30"
                            placeholder="Ex.: Preto"
                            value="<?= htmlspecialchars(
                                $_POST["cor"] ?? ""
                            ) ?>"
                            required
                        >

                    </div>

                </div>


                <!-- BOTÕES -->

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


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>
<script src="../../assets/js/script.js"></script>

</body>

</html>