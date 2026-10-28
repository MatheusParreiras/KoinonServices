<?php

require_once __DIR__ . "/../../controller/PermissaoController.php";

PermissaoController::exigirPortaria();

require_once __DIR__ . "/../../controller/RegistroAcessoController.php";
require_once __DIR__ . "/../../controller/VisitanteController.php";
require_once __DIR__ . "/../../controller/EncomendaController.php";
require_once __DIR__ . "/../../controller/AutorizacaoAcessoController.php";

$registroController = new RegistroAcessoController();
$visitanteController = new VisitanteController();
$isAdministrador = (($_SESSION["usuario_perfil"] ?? "") === "Administrador");

if ($isAdministrador && isset($_GET["excluir"]) && is_numeric($_GET["excluir"])) {
    $resultado = $registroController->excluir((int) $_GET["excluir"]);
    header("Location: index.php?" . ($resultado ? "sucesso=2" : "erro=1"));
    exit;
}

$mensagem="";$tipoMensagem="";

if (isset($_GET["sucesso"])) {

    if ($_GET["sucesso"] == "1") {
        $mensagem = "Registro de acesso realizado com sucesso!";
        $tipoMensagem = "success";
    }

    if ($_GET["sucesso"] == "2") {
        $mensagem = "Registro de acesso excluído com sucesso!";
        $tipoMensagem = "success";
    }
}

if (isset($_GET["erro"])) {

    if ($_GET["erro"] == "1") {
        $mensagem = "Erro ao registrar o acesso.";
        $tipoMensagem = "danger";
    }
}

$registros = $registroController->listar();
$visitantes = $visitanteController->listar();

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Portaria - Koinon Services</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body>

<nav class="navbar navbar-expand-lg bg-white">
    <div class="container-fluid px-4">
        <a href="../dashboard.php" class="navbar-brand text-primary d-flex align-items-center gap-2 text-decoration-none">
            <span>Koinon Services</span>
        </a>
        <div class="d-flex align-items-center gap-2 koinon-nav-actions">
            <!-- Apenas sino e troca de tema nesta tela. -->
        </div>
    </div>
</nav>

<div class="container-fluid mt-4">

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2>🛂 Portaria</h2>

            <p class="text-muted mb-0">
                Controle de entrada e saída de moradores e visitantes
            </p>

        </div>



    </div>


    <?php if (!empty($mensagem)): ?>

        <div class="alert alert-<?= $tipoMensagem ?>">

            <?= htmlspecialchars($mensagem) ?>

        </div>

    <?php endif; ?>


    <!-- AÇÕES DA PORTARIA -->

    <div class="row g-3 mb-4">

        <div class="col-md-3">

            <div class="card shadow-sm h-100">

                <div class="card-body text-center">

                    <h4>📱 QR Code</h4>

                    <p class="text-muted">
                        Validar um QR Code apresentado pelo visitante.
                    </p>

                    <a
                        href="validar_qr.php"
                        class="btn btn-primary"
                    >
                        Validar QR Code
                    </a>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card shadow-sm h-100">

                <div class="card-body text-center">

                    <h4>👤 Acesso Manual</h4>

                    <p class="text-muted">
                        Registrar entrada ou saída manualmente.
                    </p>

                    <a
                        href="cadastrar.php"
                        class="btn btn-success"
                    >
                        Registrar Acesso
                    </a>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card shadow-sm h-100">

                <div class="card-body text-center">

                    <h4>👥 Visitantes</h4>

                    <p class="text-muted">
                        Cadastrar e consultar visitantes.
                    </p>

                    <a
                        href="../visitante/index.php"
                        class="btn btn-info text-white"
                    >
                        Gerenciar Visitantes
                    </a>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card shadow-sm h-100">

                <div class="card-body text-center">

                    <h4>🔎 Achados e Perdidos</h4>

                    <p class="text-muted">
                        Registrar itens encontrados ou perdidos.
                    </p>

                    <a
                        href="achados_perdidos.php"
                        class="btn btn-warning"
                    >
                        Abrir Achados e Perdidos
                    </a>

                </div>

            </div>

        </div>

    </div>



<?php $hojeEntradas=0;$hojeSaidas=0;$estadoMoradores=[];foreach($registros as $r){$dt=$r->getDataHora();if($dt&&date('Y-m-d',strtotime($dt))===date('Y-m-d')){if($r->getDirecao()==='Entrada')$hojeEntradas++;if($r->getDirecao()==='Saida')$hojeSaidas++;}if($r->getUsuarioId()!==null&&!isset($estadoMoradores[$r->getUsuarioId()]))$estadoMoradores[$r->getUsuarioId()]=$r->getDirecao();}$dentro=count(array_filter($estadoMoradores,fn($x)=>$x==='Entrada'));$max=max(1,count($registros));?>
<div class="row g-3 mb-4"><div class="col-lg-4"><a href="registrar_morador.php" class="koinon-action-card"><div class="koinon-action-icon">🏠</div><h5 class="fw-bold">Entrada / saída de morador</h5><p class="text-muted mb-0">A pé ou em veículo aprovado.</p></a></div><div class="col-lg-4"><div class="koinon-stat"><div class="text-muted small">Entradas hoje</div><div class="h3 fw-bold mb-0"><?=$hojeEntradas?></div><div class="text-muted small">Saídas hoje: <?=$hojeSaidas?></div></div></div><div class="col-lg-4"><div class="koinon-stat"><div class="text-muted small">Moradores atualmente dentro</div><div class="h3 fw-bold mb-0"><?=$dentro?></div><div class="text-muted small">Pelo último registro de cada morador.</div></div></div></div>
<div class="card mb-4"><div class="card-body"><h5 class="fw-bold mb-3">Movimentação de hoje</h5><div class="mb-3"><div class="d-flex justify-content-between small mb-1"><span>Entradas</span><strong><?=$hojeEntradas?></strong></div><div class="koinon-bar"><span style="width:<?=min(100,($hojeEntradas/$max)*100)?>%"></span></div></div><div><div class="d-flex justify-content-between small mb-1"><span>Saídas</span><strong><?=$hojeSaidas?></strong></div><div class="koinon-bar"><span style="width:<?=min(100,($hojeSaidas/$max)*100)?>%"></span></div></div></div></div>

<?php
/* Painel operacional da portaria — usa apenas dados já existentes (sem novas tabelas). */
$visitantesDentro = [];
$visitantesVistos = [];
foreach ($registros as $r) {
    $vid = $r->getVisitanteId();
    if ($vid === null || isset($visitantesVistos[$vid])) { continue; }
    $visitantesVistos[$vid] = true;
    $dtReg = $r->getDataHora();
    if ($r->getDirecao() === 'Entrada' && $dtReg && (time() - strtotime($dtReg)) <= 86400) {
        $visitantesDentro[] = $r;
    }
}
$encAguardando = 0;
foreach ((new EncomendaController())->listar() as $enc) {
    if ($enc->getStatus() === 'Aguardando_Retirada') { $encAguardando++; }
}
$autAtivas = 0;
$agoraTs = time();
foreach ((new AutorizacaoAcessoController())->listar() as $aut) {
    if ($aut->getStatus() === 'Ativo' && $aut->getDataInicio() && $aut->getDataFim()
        && strtotime($aut->getDataInicio()) <= $agoraTs && strtotime($aut->getDataFim()) >= $agoraTs) {
        $autAtivas++;
    }
}
?>
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <a href="../encomenda/index.php" class="koinon-action-card text-decoration-none">
            <div class="koinon-action-icon">📦</div>
            <h5 class="fw-bold">Encomendas aguardando retirada</h5>
            <div class="h3 fw-bold mb-0"><?= (int) $encAguardando ?></div>
            <p class="text-muted small mb-0">Toque para registrar a retirada.</p>
        </a>
    </div>
    <div class="col-md-4">
        <a href="../autorizacao_acesso/index.php" class="koinon-action-card text-decoration-none">
            <div class="koinon-action-icon">🎟️</div>
            <h5 class="fw-bold">Autorizações válidas agora</h5>
            <div class="h3 fw-bold mb-0"><?= (int) $autAtivas ?></div>
            <p class="text-muted small mb-0">Visitantes com QR Code dentro do período.</p>
        </a>
    </div>
    <div class="col-md-4">
        <div class="koinon-stat h-100">
            <div class="text-muted small">Visitantes no condomínio agora</div>
            <div class="h3 fw-bold mb-1"><?= count($visitantesDentro) ?></div>
            <?php if (empty($visitantesDentro)): ?>
                <div class="text-muted small">Nenhum visitante com entrada sem saída nas últimas 24 horas.</div>
            <?php else: ?>
                <ul class="list-unstyled small mb-0">
                    <?php foreach (array_slice($visitantesDentro, 0, 4) as $vd): ?>
                        <li>
                            <strong><?= htmlspecialchars($vd->getVisitanteNome() ?? 'Visitante') ?></strong>
                            <span class="text-muted">desde <?= date('H:i', strtotime($vd->getDataHora())) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a href="cadastrar.php" class="small">Registrar saída →</a>
            <?php endif; ?>
        </div>
    </div>
</div>


    <!-- REGISTROS -->

    <div class="card shadow">

        <div class="card-header bg-dark text-white">

            <h5 class="mb-0">
                Histórico de Acessos
            </h5>

        </div>


        <div class="card-body">

            <?php if (empty($registros)): ?>

                <div class="alert alert-info">

                    Nenhum registro de acesso encontrado.

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-bordered table-hover align-middle">

                        <thead class="table-dark">

                            <tr>

                                <th>Pessoa</th>

                                <th>CPF</th>

                                <th>Tipo</th>

                                <th>Autorizado por</th>

                                <th>Data/Hora</th>

                                <th>Direção</th>

                                <th>Método</th>

                                <th>Observação</th>

                                <?php if ($isAdministrador): ?>
                                    <th>Ações</th>
                                <?php endif; ?>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($registros as $registro): ?>
                            <tr>

                                <td>
                                    <?= htmlspecialchars(
                                        $registro->getVisitanteNome()
                                        ?? $registro->getUsuarioNome()
                                        ?? "Não informado"
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        ($registro->getVisitanteDocumento() ? Cpf::formatar($registro->getVisitanteDocumento()) : null)
                                        ?? Cpf::formatar($registro->getUsuarioCpf() ?? "")
                                        ?: "—"
                                    ) ?>
                                </td>

                                <td>
                                    <?php
                                    $tipoPessoa = $registro->getVisitanteTipo();
                                    if ($tipoPessoa === null && $registro->getUsuarioId() !== null) {
                                        $tipoPessoa = "Morador";
                                    }
                                    ?>
                                    <?= htmlspecialchars($tipoPessoa ?? "Não informado") ?>
                                </td>

                                <td>
                                    <?php if ($registro->getAutorizadorNome() || $registro->getAutorizadorLogin()): ?>
                                        <div class="fw-semibold">
                                            <?= htmlspecialchars($registro->getAutorizadorNome() ?? "Não informado") ?>
                                        </div>
                                        <small class="text-muted">
                                            Perfil: <?= htmlspecialchars($registro->getAutorizadorPerfil() ?? "Não informado") ?><br>
                                            Login: <?= htmlspecialchars($registro->getAutorizadorLogin() ?? "Não informado") ?>
                                        </small>
                                    <?php elseif ($registro->getUsuarioId() !== null): ?>
                                        <span class="text-muted">Acesso próprio</span>
                                    <?php elseif ($registro->getDirecao() === "Saida"): ?>
                                        <span class="text-muted">Sem autorização necessária</span>
                                    <?php else: ?>
                                        <span class="text-muted">Não informado</span>
                                    <?php endif; ?>
                                </td>

                                <td>

                                    <?php

                                    $dataHora = $registro->getDataHora();

                                    if (!empty($dataHora)) {

                                        echo date(
                                            "d/m/Y H:i",
                                            strtotime($dataHora)
                                        );

                                    } else {

                                        echo "-";

                                    }

                                    ?>

                                </td>

                                <td>

                                    <?php if ($registro->getDirecao() === "Entrada"): ?>

                                        <span class="badge bg-success">
                                            Entrada
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-warning text-dark">
                                            Saída
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?php

                                    $metodo = $registro->getMetodoLeitura();

                                    $nomesMetodo = [
                                        "Manual" => "Manual",
                                        "QR_Code" => "QR Code",
                                        "Facial" => "Reconhecimento Facial",
                                        "Placa_LPR" => "Placa LPR",
                                        "Tag_RFID" => "Tag RFID"
                                    ];

                                    echo htmlspecialchars(
                                        $nomesMetodo[$metodo] ?? $metodo
                                    );

                                    ?>

                                </td>

                                <td>

                                    <?= htmlspecialchars(
                                        $registro->getObservacao() ?? "-"
                                    ) ?>

                                </td>

                                <?php if ($isAdministrador): ?>
                                    <td>
                                        <a
                                            href="?excluir=<?= $registro->getId() ?>"
                                            class="btn btn-sm btn-danger"
                                            onclick="return confirm('Deseja realmente excluir este registro?');"
                                        >
                                            Excluir
                                        </a>
                                    </td>
                                <?php endif; ?>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>
<script src="../../assets/js/script.js"></script>

</body>

</html>