<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../service/Erro.php";

PermissaoController::exigirGestao();

require_once __DIR__ . "/../../controller/UnidadeController.php";
require_once __DIR__ . "/../../service/CondominioConfigService.php";

$controller = new UnidadeController();

/*
|--------------------------------------------------------------------------
| VERIFICAÇÃO DO ID
|--------------------------------------------------------------------------
*/

if (
    !isset($_GET["id"]) ||
    !is_numeric($_GET["id"])
) {
    header("Location: index.php");
    exit;
}

$id = (int) $_GET["id"];


/*
|--------------------------------------------------------------------------
| BUSCAR UNIDADE
|--------------------------------------------------------------------------
*/

$unidade = $controller->buscarPorId($id);

if (!$unidade) { die("Unidade não encontrada."); }
$vagasGaragem=CondominioConfigService::vagasUnidade($id);
$identificacaoAtual=$unidade->getIdentificacao();$blocoAtual="";$numeroAtual=$identificacaoAtual;if(preg_match("/^Bloco\s+(.+?)\s+-\s+(.+)$/u",$identificacaoAtual,$m)){ $blocoAtual=$m[1];$numeroAtual=$m[2];}


/*
|--------------------------------------------------------------------------
| ATUALIZAÇÃO
|--------------------------------------------------------------------------
*/

$erro = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        /*
        |--------------------------------------------------------------
        | RECEBER DADOS DO FORMULÁRIO
        |--------------------------------------------------------------
        */

        $identificacao = trim(
            $_POST["identificacao"] ?? ""
        );

        $tipoUnidade = $_POST["tipo_unidade"] ?? "";
        $bloco=trim($_POST["bloco"]??"");$numero=trim($_POST["numero_unidade"]??"");if($bloco!==""&&$numero!=="")$identificacao="Bloco ".$bloco." - ".$numero;else $identificacao=$numero;
        $vagasGaragem=(int)($_POST["vagas_garagem"]??1);

        /*
        |--------------------------------------------------------------
        | ATUALIZAR DTO
        |--------------------------------------------------------------
        */

        $unidade->setIdentificacao($identificacao);

        $unidade->setTipoUnidade(
            $tipoUnidade
        );
        $unidade->setQuantidadeVagas($vagasGaragem);


        /*
        |--------------------------------------------------------------
        | VALIDAÇÕES
        |--------------------------------------------------------------
        */

        if (empty($identificacao)) {

            $erro = "Informe a identificação da unidade.";

        } elseif (
            !in_array(
                $tipoUnidade,
                [
                    "Residencial",
                    "Comercial",
                    "Vaga",
                    "Depósito"
                ]
            )
        ) {

            $erro = "Selecione um tipo de unidade válido.";

        } elseif(!in_array($vagasGaragem,[0,1,2],true)){
            $erro="Quantidade de vagas inválida.";

        } else {

            /*
            |----------------------------------------------------------
            | SALVAR ALTERAÇÕES
            |----------------------------------------------------------
            */

            if ($controller->editar($unidade)) {
                header(
                    "Location: index.php?sucesso=3"
                );

                exit;

            } else {

                $erro =
                    "Não foi possível atualizar a unidade.";

            }
        }

    } catch (PDOException $e) {

        $erro =
            "Erro no banco de dados: " .
            Erro::mensagem($e);

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
        Editar Unidade - Koinon Services
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

        <div class="card-header bg-warning">

            <h4 class="mb-0">
                Editar Unidade
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

                    <!-- TIPO -->

                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Tipo de Unidade
                        </label>

                        <select
                            name="tipo_unidade"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Selecione...
                            </option>

                            <option
                                value="Residencial"
                                <?= (
                                    $unidade->getTipoUnidade()
                                    === "Residencial"
                                )
                                    ? "selected"
                                    : "" ?>
                            >
                                Residencial
                            </option>

                            <option
                                value="Comercial"
                                <?= (
                                    $unidade->getTipoUnidade()
                                    === "Comercial"
                                )
                                    ? "selected"
                                    : "" ?>
                            >
                                Comercial
                            </option>

                            <option
                                value="Vaga"
                                <?= (
                                    $unidade->getTipoUnidade()
                                    === "Vaga"
                                )
                                    ? "selected"
                                    : "" ?>
                            >
                                Vaga
                            </option>

                            <option
                                value="Depósito"
                                <?= (
                                    $unidade->getTipoUnidade()
                                    === "Depósito"
                                )
                                    ? "selected"
                                    : "" ?>
                            >
                                Depósito
                            </option>

                        </select>

                    </div>




                    <div class="col-md-6 mb-3"><label class="form-label fw-semibold">Bloco (opcional)</label><input type="text" name="bloco" class="form-control" maxlength="15" value="<?=htmlspecialchars($blocoAtual)?>"></div><div class="col-md-6 mb-3"><label class="form-label fw-semibold">Número / identificação</label><input type="text" name="numero_unidade" class="form-control" maxlength="35" value="<?=htmlspecialchars($numeroAtual)?>" required></div><div class="col-md-6 mb-3"><label class="form-label fw-semibold">Vagas de garagem</label><select name="vagas_garagem" class="form-select" required><option value="0" <?= $vagasGaragem===0?'selected':'' ?>>Sem vaga</option><option value="1" <?= $vagasGaragem===1?'selected':'' ?>>1 vaga</option><option value="2" <?= $vagasGaragem===2?'selected':'' ?>>2 vagas</option></select><small class="text-muted">Limite de veículos permitidos para esta unidade.</small></div>
                </div>


                <!-- BOTÕES -->

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


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"
></script>
<script src="../../assets/js/script.js"></script>

</body>

</html>