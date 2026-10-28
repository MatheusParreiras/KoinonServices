<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirGestao();

require_once __DIR__ . "/../../controller/UnidadeController.php";
require_once __DIR__ . "/../../service/CondominioConfigService.php";

$controller = new UnidadeController();

$erro = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        $unidade = new UnidadeDTO();

        $unidade->setIdentificacao(
            trim($_POST["identificacao"] ?? "")
        );

        $unidade->setTipoUnidade(
            $_POST["tipo_unidade"] ?? ""
        );
        $vagasGaragem=(int)($_POST["vagas_garagem"]??1);
        $unidade->setQuantidadeVagas($vagasGaragem);
        $bloco=trim($_POST["bloco"]??"");
        $numero=trim($_POST["numero_unidade"]??$_POST["identificacao"]??"");
        if($bloco!==""&&$numero!=="")$unidade->setIdentificacao("Bloco ".$bloco." - ".$numero); else $unidade->setIdentificacao($numero);


        if (
            empty($unidade->getIdentificacao()) ||
            empty($unidade->getTipoUnidade())
        ) {
            $erro = "Preencha todos os campos obrigatórios.";
        } elseif (
            !in_array(
                $unidade->getTipoUnidade(),
                [
                    "Residencial",
                    "Comercial",
                    "Vaga",
                    "Depósito"
                ]
            )
        ) {
            $erro = "Tipo de unidade inválido.";
        } elseif (!in_array($vagasGaragem,[0,1,2],true)) {
            $erro="Quantidade de vagas inválida.";
        } else {

            if ($controller->cadastrar($unidade)) {
                header("Location: index.php?sucesso=1");
                exit;

            } else {

                $erro = "Não foi possível cadastrar a unidade.";

            }
        }

    } catch (PDOException $e) {

        $erro = "Erro no banco de dados: " . $e->getMessage();

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

    <title>Cadastrar Unidade - Koinon Services</title>

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
                Cadastrar Unidade
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
                                    ($_POST["tipo_unidade"] ?? "") ===
                                    "Residencial"
                                ) ? "selected" : "" ?>
                            >
                                Residencial
                            </option>

                            <option
                                value="Comercial"
                                <?= (
                                    ($_POST["tipo_unidade"] ?? "") ===
                                    "Comercial"
                                ) ? "selected" : "" ?>
                            >
                                Comercial
                            </option>

                            <option
                                value="Vaga"
                                <?= (
                                    ($_POST["tipo_unidade"] ?? "") ===
                                    "Vaga"
                                ) ? "selected" : "" ?>
                            >
                                Vaga
                            </option>

                            <option
                                value="Depósito"
                                <?= (
                                    ($_POST["tipo_unidade"] ?? "") ===
                                    "Depósito"
                                ) ? "selected" : "" ?>
                            >
                                Depósito
                            </option>

                        </select>

                    </div>




                    <div class="col-md-6 mb-3"><label class="form-label fw-semibold">Bloco (opcional)</label><input type="text" name="bloco" class="form-control" maxlength="15" placeholder="Ex.: A" value="<?=htmlspecialchars($_POST["bloco"]??"")?>"><small class="text-muted">Use para identificar o bloco do condomínio.</small></div>
                    <div class="col-md-6 mb-3"><label class="form-label fw-semibold">Número / identificação</label><input type="text" name="numero_unidade" class="form-control" maxlength="35" placeholder="Ex.: 101" value="<?=htmlspecialchars($_POST["numero_unidade"]??$_POST["identificacao"]??"")?>" required></div>
                    <div class="col-md-6 mb-3"><label class="form-label fw-semibold">Vagas de garagem</label><select name="vagas_garagem" class="form-select" required><option value="0">Sem vaga</option><option value="1" selected>1 vaga</option><option value="2">2 vagas</option></select><small class="text-muted">A regra será usada no controle de veículos dos moradores.</small></div>
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