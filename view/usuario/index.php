<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../controller/UsuarioController.php";

PermissaoController::exigirAdministrador();

$controller = new UsuarioController();

$usuarios = $controller->listar();

/*
 * LGPD: resumo e filtro (a consulta dos aceites fica aqui, na tela de Usuários).
 */
$totalAceitos = 0;
$totalPendentes = 0;

foreach ($usuarios as $u) {
    if ($u->getTermoLgpd() && $u->getDataAceiteLgpd()) {
        $totalAceitos++;
    } else {
        $totalPendentes++;
    }
}

$filtroLgpd = $_GET["lgpd"] ?? "";

if ($filtroLgpd === "aceito" || $filtroLgpd === "pendente") {
    $usuarios = array_values(array_filter(
        $usuarios,
        function ($u) use ($filtroLgpd) {
            $aceitou = $u->getTermoLgpd() && $u->getDataAceiteLgpd();
            return $filtroLgpd === "aceito" ? $aceitou : !$aceitou;
        }
    ));
}

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Usuários - Koinon Services</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../../assets/css/estilo.css">

</head>

<body class="bg-light">

<div class="container mt-5">

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>
            <h2>Usuários</h2>
            <p class="text-muted mb-0">
                Gerenciamento de usuários do condomínio
            </p>
        </div>

        <div>
            <a href="cadastrar.php" class="btn btn-primary">
                + Novo Usuário
            </a>

            <a
                href="/koinon-service/view/logout.php"
                class="btn btn-outline-danger"
            >
                Sair
            </a>
        </div>

    </div>

    <?php if (isset($_GET["sucesso"])): ?>

        <div class="alert alert-success">
            Operação realizada com sucesso!
        </div>

    <?php endif; ?>


    <?php if (isset($_GET["erro"])): ?>

        <div class="alert alert-danger">
            Ocorreu um erro ao realizar a operação.
        </div>

    <?php endif; ?>


    <div class="row g-3 mb-3">

        <div class="col-md-4">
            <a href="index.php?lgpd=aceito" class="text-decoration-none">
                <div class="card shadow-sm h-100 <?= $filtroLgpd === "aceito" ? "border-success" : "" ?>">
                    <div class="card-body">
                        <div class="small text-muted">🔐 Aceitaram o termo LGPD</div>
                        <div class="h3 fw-bold mb-0 text-success"><?= $totalAceitos ?></div>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-md-4">
            <a href="index.php?lgpd=pendente" class="text-decoration-none">
                <div class="card shadow-sm h-100 <?= $filtroLgpd === "pendente" ? "border-warning" : "" ?>">
                    <div class="card-body">
                        <div class="small text-muted">⏳ Ainda não aceitaram</div>
                        <div class="h3 fw-bold mb-0 text-warning"><?= $totalPendentes ?></div>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-md-4">
            <a href="index.php" class="text-decoration-none">
                <div class="card shadow-sm h-100 <?= $filtroLgpd === "" ? "border-primary" : "" ?>">
                    <div class="card-body">
                        <div class="small text-muted">👥 Todos os usuários</div>
                        <div class="h3 fw-bold mb-0 text-primary"><?= $totalAceitos + $totalPendentes ?></div>
                    </div>
                </div>
            </a>
        </div>

    </div>

    <div class="card shadow-sm">

        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-bordered table-hover align-middle">

                    <thead class="table-dark">

                        <tr>
                            <th>Nome</th>
                            <th>CPF</th>
                            <th>E-mail</th>
                            <th>Perfil</th>
                            <th>Unidade</th>
                            <th>Status</th>
                            <th>LGPD</th>
                            <th>Ações</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($usuarios)): ?>

                        <tr>

                            <td colspan="8" class="text-center text-muted">

                                Nenhum usuário cadastrado.

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($usuarios as $usuario): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars($usuario->getNome()) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($usuario->getCpf()) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($usuario->getEmail()) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($usuario->getPerfil()) ?>
                                </td>

                                <td>

                                    <?php

                                    $unidade =
                                        $usuario->getUnidadeIdentificacao();

                                    echo htmlspecialchars(
                                        $unidade ?: "Não vinculada"
                                    );

                                    ?>

                                </td>

                                <td>

                                    <?php if ($usuario->getAtivo()): ?>

                                        <span class="badge bg-success">
                                            Ativo
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-danger">
                                            Inativo
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?php if ($usuario->getTermoLgpd() && $usuario->getDataAceiteLgpd()): ?>
                                        <span class="badge bg-success">✔ Aceito</span>
                                        <div class="small text-muted mt-1">
                                            em <?=htmlspecialchars(date('d/m/Y', strtotime($usuario->getDataAceiteLgpd())))?>
                                            às <?=htmlspecialchars(date('H:i', strtotime($usuario->getDataAceiteLgpd())))?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Pendente</span>
                                    <?php endif; ?>
                                </td>

                                <td>

                                    <a
                                        href="editar.php?id=<?= $usuario->getId() ?>"
                                        class="btn btn-sm btn-warning"
                                    >
                                        Editar
                                    </a>

                                    <?php if ($usuario->getAtivo()): ?>

                                        <a
                                            href="../../controller/UsuarioController.php?acao=excluir&id=<?= $usuario->getId() ?>"
                                            class="btn btn-sm btn-danger"
                                            onclick="return confirm('Deseja realmente desativar este usuário?');"
                                        >
                                            Desativar
                                        </a>

                                    <?php else: ?>

                                        <a
                                            href="../../controller/UsuarioController.php?acao=reativar&id=<?= $usuario->getId() ?>"
                                            class="btn btn-sm btn-success"
                                            onclick="return confirm('Deseja reativar este usuário?');"
                                        >
                                            Reativar
                                        </a>

                                    <?php endif; ?>

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
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>
<script src="../../assets/js/script.js"></script>

</body>
</html>