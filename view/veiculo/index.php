<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirGestao();

require_once __DIR__ . "/../../controller/VeiculoController.php";
require_once __DIR__ . "/../../service/CondominioConfigService.php";

$controller = new VeiculoController();

/*
|--------------------------------------------------------------------------
| EXCLUIR VEÍCULO
|--------------------------------------------------------------------------
| O tratamento de exclusão precisa acontecer antes das ações de aprovação
| e rejeição. Na versão anterior, "excluir" caía no fluxo genérico de
| atualização de status e por isso mostrava sucesso sem apagar o registro.
*/
if (
    isset($_GET["acao"], $_GET["id"]) &&
    $_GET["acao"] === "excluir" &&
    is_numeric($_GET["id"])
) {
    $id = (int) $_GET["id"];

    try {
        if ($id > 0 && $controller->excluir($id)) {
            header("Location: index.php?sucesso=2");
        } else {
            header("Location: index.php?erro=1");
        }
    } catch (Throwable $e) {
        header("Location: index.php?erro=1");
    }
    exit;
}

/*
|--------------------------------------------------------------------------
| APROVAR / REJEITAR VEÍCULO
|--------------------------------------------------------------------------
*/
if (isset($_GET['acao'], $_GET['id']) && is_numeric($_GET['id'])) {
    $acao = $_GET['acao'];
    $id = (int) $_GET['id'];

    if (in_array($acao, ['aprovar', 'rejeitar'], true)) {
        $resultado = '3';
        $todos = $controller->listar();
        $alvo = null;

        foreach ($todos as $vv) {
            if ((int) $vv->getId() === $id) {
                $alvo = $vv;
                break;
            }
        }

        if ($acao === 'aprovar' && $alvo) {
            $unid = $alvo->getUnidadeId();
            $lim = $unid ? CondominioConfigService::vagasUnidade($unid) : 0;
            $usados = 0;
            foreach ($todos as $vv) {
                if ($vv->getUnidadeId() === $unid && in_array($controller->status((int) $vv->getId()), ['Aprovado', 'Pendente'], true)) {
                    $usados++;
                }
            }
            if ($lim > 0 && $usados < $lim) {
                $controller->alterarStatus($id, 'Aprovado');
            } else {
                $resultado = '4';
            }
        } elseif ($acao === 'rejeitar' && $alvo) {
            $controller->alterarStatus($id, 'Rejeitado');
        } else {
            $resultado = '3';
        }

        header('Location:index.php?sucesso=' . $resultado);
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| LISTAR VEÍCULOS
|--------------------------------------------------------------------------
*/

$veiculos = $controller->listar();

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Veículos - Koinon Services</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<div class="container mt-5">

    <!-- CABEÇALHO -->

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2>Veículos</h2>

            <p class="text-muted mb-0">
                Gerenciamento dos veículos cadastrados no condomínio
            </p>

        </div>

        <a
            href="cadastrar.php"
            class="btn btn-primary"
        >
            + Cadastrar Veículo
        </a>

    </div>


    <!-- MENSAGENS -->

    <?php if (isset($_GET["sucesso"])): ?>
        <?php if ($_GET["sucesso"]==="4"): ?><div class="alert alert-warning">A aprovação não foi realizada porque a quantidade de veículos já atingiu o limite de vagas da unidade.</div><?php endif; ?>

        <?php if ($_GET["sucesso"] == 1): ?>

            <div class="alert alert-success">
                Veículo cadastrado com sucesso!
            </div>

        <?php elseif ($_GET["sucesso"] == 2): ?>

            <div class="alert alert-success">
                Veículo excluído com sucesso!
            </div>

        <?php elseif ($_GET["sucesso"] == 3): ?>

            <div class="alert alert-success">
                Veículo atualizado com sucesso!
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

                            <th>Placa</th>

                            <th>Modelo</th>

                            <th>Cor</th>

                            <th>Proprietário / Unidade</th><th>Status</th>

                            <th>Data de Cadastro</th>

                            <th>Ações</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($veiculos)): ?>

                        <tr>

                            <td
                                colspan="7"
                                class="text-center text-muted"
                            >
                                Nenhum veículo cadastrado.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($veiculos as $veiculo): ?>

                            <tr>

                                <!-- PLACA -->

                                <td>

                                    <strong>

                                        <?= htmlspecialchars(
                                            $veiculo->getPlaca()
                                        ) ?>

                                    </strong>

                                </td>


                                <!-- MODELO -->

                                <td>

                                    <?= htmlspecialchars(
                                        $veiculo->getModelo()
                                    ) ?>

                                </td>


                                <!-- COR -->

                                <td>

                                    <?= htmlspecialchars(
                                        $veiculo->getCor()
                                    ) ?>

                                </td>


                                <!-- PROPRIETÁRIO -->

                                <td>

                                    <div class="fw-semibold"><?= htmlspecialchars($veiculo->getUsuarioNome()??"Não informado") ?></div><small class="text-muted">Unidade: <?= htmlspecialchars($veiculo->getUnidadeIdentificacao()??"Não vinculada") ?></small>

                                </td>


                                <!-- DATA -->

                                <td><?php $status=CondominioConfigService::statusVeiculo((int)$veiculo->getId());$badge=$status==='Aprovado'?'success':($status==='Pendente'?'warning':'danger'); ?><span class="badge text-bg-<?=$badge?>"><?=htmlspecialchars($status)?></span></td><td>
                                    <?php
                                    $data=$veiculo->getCreatedAt();

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

                                        <?php if($status==='Pendente'): ?><a href="index.php?acao=aprovar&id=<?=$veiculo->getId()?>" class="btn btn-sm btn-success">Aprovar</a><a href="index.php?acao=rejeitar&id=<?=$veiculo->getId()?>" class="btn btn-sm btn-outline-danger">Rejeitar</a><?php endif; ?><a href="editar.php?id=<?=$veiculo->getId()?>" class="btn btn-sm btn-warning">Editar</a>


                                        <a
                                            href="index.php?acao=excluir&id=<?= $veiculo->getId() ?>"
                                            class="btn btn-sm btn-danger"
                                            onclick="return confirm('Tem certeza que deseja excluir este veículo?');"
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
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"
></script>
<script src="../../assets/js/script.js"></script>

</body>

</html>