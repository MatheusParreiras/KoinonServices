<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/../controller/PermissaoController.php";

PermissaoController::exigirLogin();

$usuarioId = PermissaoController::usuarioId();
$usuarioNome = PermissaoController::usuarioNome();
$usuarioEmail = PermissaoController::usuarioEmail();
$usuarioPerfil = PermissaoController::usuarioPerfil();

// O Morador possui uma área própria e não utiliza o dashboard administrativo.
if ($usuarioPerfil === "Morador") {
    header("Location: morador/index.php");
    exit;
}

$nomeExibicao = $usuarioNome ?: "Usuário";

$perfilLabel = match ($usuarioPerfil) {

    "Morador" => "Morador",

    "Porteiro" => "Porteiro",

    "Sindico" => "Síndico",

    "Administrador" => "Administrador",

    default => "Usuário"
};

$isMorador =
    $usuarioPerfil === "Morador";

$isPorteiro =
    $usuarioPerfil === "Porteiro";

$isSindico =
    $usuarioPerfil === "Sindico";

$isAdministrador =
    $usuarioPerfil === "Administrador";

$isAdmin =
    $isAdministrador;

$isGestao =
    $isAdmin ||
    $isSindico;

$isPortaria =
    $isGestao ||
    $isPorteiro;

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
        Dashboard - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body {
            background-color: #f5f7fa;
        }

        .navbar {
            box-shadow:
                0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .navbar-brand {
            font-weight: 700;
        }

        .sidebar-card {
            border: none;
            border-radius: 16px;
            box-shadow:
                0 5px 18px rgba(0, 0, 0, 0.07);
        }

        .welcome-card {
            border: none;
            border-radius: 18px;

            background:
                linear-gradient(
                    135deg,
                    #0d6efd,
                    #084298
                );

            color: white;

            box-shadow:
                0 8px 25px rgba(
                    13,
                    110,
                    253,
                    0.20
                );
        }

        .module-card {
            border: none;
            border-radius: 16px;

            box-shadow:
                0 5px 18px rgba(
                    0,
                    0,
                    0,
                    0.07
                );

            transition: 0.2s;

            height: 100%;
        }

        .module-card:hover {
            transform: translateY(-4px);

            box-shadow:
                0 10px 25px rgba(
                    0,
                    0,
                    0,
                    0.11
                );
        }

        .module-icon {
            width: 55px;
            height: 55px;

            border-radius: 14px;

            display: flex;
            align-items: center;
            justify-content: center;

            background-color: #eaf3ff;

            font-size: 1.6rem;
        }

        .profile-badge {
            font-size: 0.85rem;
        }

        .section-title {
            font-weight: 700;
        }

        .menu-link {
            text-decoration: none;
        }

    </style>


    <link rel="stylesheet" href="../assets/css/estilo.css">

</head>

<body>


<!-- ========================================================= -->
<!-- NAVBAR -->
<!-- ========================================================= -->

<nav class="navbar navbar-expand-lg bg-white">

    <div class="container-fluid px-4">

        <a
            href="../index.php"
            class="navbar-brand text-primary"
        >
            Koinon Services
        </a>


        <div class="d-flex align-items-center gap-3 koinon-nav-actions">

            <div class="text-end d-none d-md-block">

                <div class="fw-semibold koinon-user-name">

                    <?= htmlspecialchars(
                        $nomeExibicao
                    ); ?>

                </div>

                <small class="text-muted">

                    <?= htmlspecialchars(
                        $perfilLabel
                    ); ?>

                </small>

            </div>


            <a
                href="../logout.php"
                class="btn btn-outline-danger btn-sm"
            >
                Sair
            </a>

        </div>

    </div>

</nav>


<!-- ========================================================= -->
<!-- CONTEÚDO -->
<!-- ========================================================= -->

<div class="container-fluid py-4 px-4">

    <!-- ===================================================== -->
    <!-- BEM-VINDO -->
    <!-- ===================================================== -->

    <div class="welcome-card p-4 mb-4">

        <div class="row align-items-center">

            <div class="col-lg-8">

                <div class="mb-2">

                    <span class="badge bg-light text-primary profile-badge">

                        <?= htmlspecialchars(
                            $perfilLabel
                        ); ?>

                    </span>

                </div>

                <h2 class="fw-bold">

                    Olá,
                    <?= htmlspecialchars(
                        $nomeExibicao
                    ); ?>! 👋

                </h2>

                <p class="mb-0">

                    Bem-vindo ao painel do
                    Koinon Services.

                    Selecione uma das funcionalidades
                    disponíveis para o seu perfil.

                </p>

            </div>


            <div class="col-lg-4 text-lg-end mt-4 mt-lg-0">

                <div class="small opacity-75">
                    Usuário
                </div>

                <div class="fw-bold">

                    <?= htmlspecialchars(
                        $nomeExibicao
                    ); ?>

                </div>

                <div class="small opacity-75 mt-2">

                    <?= htmlspecialchars(
                        $usuarioEmail ?? ""
                    ); ?>

                </div>

            </div>

        </div>

    </div>
    
    <!-- ===================================================== -->
    <!-- MORADOR -->
    <!-- ===================================================== -->

    <?php if ($isMorador): ?>

        <div class="mb-4">

            <h4 class="section-title">
                Área do Morador
            </h4>

            <p class="text-muted">
                Acesse os recursos disponíveis
                para sua unidade.
            </p>

        </div>


        <div class="row g-4">


            <!-- Área do Morador -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="morador/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            🏠
                        </div>

                        <h5 class="fw-bold">
                            Minha Área
                        </h5>

                        <p class="text-muted mb-0">
                            Consulte sua unidade,
                            reservas, encomendas,
                            ocorrências e outras
                            informações.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Reservas -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="morador/nova_reserva.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📅
                        </div>

                        <h5 class="fw-bold">
                            Nova Reserva
                        </h5>

                        <p class="text-muted mb-0">
                            Solicite uma reserva
                            de espaço do condomínio.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Veículos -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="morador/veiculos.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            🚗
                        </div>

                        <h5 class="fw-bold">
                            Meus Veículos
                        </h5>

                        <p class="text-muted mb-0">
                            Cadastre e consulte
                            seus veículos.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Ocorrências -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="morador/ocorrencias.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            ⚠️
                        </div>

                        <h5 class="fw-bold">
                            Ocorrências
                        </h5>

                        <p class="text-muted mb-0">
                            Registre e acompanhe
                            suas ocorrências.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Visitantes -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="morador/autorizacoes.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            🔐
                        </div>

                        <h5 class="fw-bold">
                            Visitantes
                        </h5>

                        <p class="text-muted mb-0">
                            Autorize visitantes
                            e acompanhe seus
                            acessos.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Publicações -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="morador/publicacoes.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📢
                        </div>

                        <h5 class="fw-bold">
                            Publicações
                        </h5>

                        <p class="text-muted mb-0">
                            Consulte e participe
                            das publicações.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Financeiro -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="financeiro/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            💰
                        </div>

                        <h5 class="fw-bold">
                            Financeiro
                        </h5>

                        <p class="text-muted mb-0">
                            Consulte suas cobranças
                            e situação financeira.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Encomendas -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="encomenda/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📦
                        </div>

                        <h5 class="fw-bold">
                            Encomendas
                        </h5>

                        <p class="text-muted mb-0">
                            Consulte suas encomendas
                            recebidas pela portaria.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Documentos -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="documento/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📄
                        </div>

                        <h5 class="fw-bold">
                            Documentos
                        </h5>

                        <p class="text-muted mb-0">
                            Consulte documentos
                            disponibilizados pelo condomínio.
                        </p>

                    </div>

                </a>

            </div>


        </div>

    <?php endif; ?>


    <!-- ===================================================== -->
    <!-- PORTEIRO -->
    <!-- ===================================================== -->

    <?php if ($isPorteiro): ?>

        <div class="mb-4 mt-2">

            <h4 class="section-title">
                Área da Portaria
            </h4>

            <p class="text-muted">
                Recursos necessários para o controle
                de acesso e atendimento da portaria.
            </p>

        </div>


        <div class="row g-4">


            <!-- Portaria -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="portaria/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            🛡️
                        </div>

                        <h5 class="fw-bold">
                            Portaria
                        </h5>

                        <p class="text-muted mb-0">
                            Acesse o painel da
                            portaria.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Validar QR -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="portaria/validar_qr.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📱
                        </div>

                        <h5 class="fw-bold">
                            Validar QR Code
                        </h5>

                        <p class="text-muted mb-0">
                            Valide autorizações
                            de visitantes.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Registrar acesso -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="portaria/cadastrar.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            🚪
                        </div>

                        <h5 class="fw-bold">
                            Registrar Acesso
                        </h5>

                        <p class="text-muted mb-0">
                            Registre entradas e
                            saídas manualmente.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Encomendas -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="encomenda/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📦
                        </div>

                        <h5 class="fw-bold">
                            Encomendas
                        </h5>

                        <p class="text-muted mb-0">
                            Controle encomendas
                            recebidas na portaria.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Visitantes -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="visitante/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            👤
                        </div>

                        <h5 class="fw-bold">
                            Visitantes
                        </h5>

                        <p class="text-muted mb-0">
                            Consulte os visitantes
                            cadastrados.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Registros -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="portaria/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📋
                        </div>

                        <h5 class="fw-bold">
                            Registros de Acesso
                        </h5>

                        <p class="text-muted mb-0">
                            Consulte os registros
                            realizados pela portaria.
                        </p>

                    </div>

                </a>

            </div>

            <div class="col-md-6 col-lg-4">

                <a href="portaria/achados_perdidos.php" class="menu-link">

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">🔎</div>

                        <h5 class="fw-bold">Achados e Perdidos</h5>

                        <p class="text-muted mb-0">Registre itens encontrados e devolva aos moradores.</p>

                    </div>

                </a>

            </div>



        </div>

    <?php endif; ?>


    <!-- ===================================================== -->
    <!-- SÍNDICO -->
    <!-- ===================================================== -->

    <?php if ($isSindico): ?>

        <div class="mb-4 mt-2">

            <h4 class="section-title">
                Gestão do Condomínio
            </h4>

            <p class="text-muted">
                Recursos disponíveis para acompanhamento
                e administração do condomínio.
            </p>

        </div>


        <div class="row g-4">


            <!-- Unidades -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="unidade/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            🏠
                        </div>

                        <h5 class="fw-bold">
                            Unidades
                        </h5>

                        <p class="text-muted mb-0">
                            Consulte as unidades
                            do condomínio.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Reservas -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="reserva/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📅
                        </div>

                        <h5 class="fw-bold">
                            Reservas
                        </h5>

                        <p class="text-muted mb-0">
                            Gerencie as reservas
                            do condomínio.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Ocorrências -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="ocorrencia/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            ⚠️
                        </div>

                        <h5 class="fw-bold">
                            Ocorrências
                        </h5>

                        <p class="text-muted mb-0">
                            Acompanhe ocorrências
                            registradas.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Portaria -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="portaria/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            🛡️
                        </div>

                        <h5 class="fw-bold">
                            Portaria
                        </h5>

                        <p class="text-muted mb-0">
                            Acompanhe o controle
                            de acesso.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Publicações -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="publicacao/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📢
                        </div>

                        <h5 class="fw-bold">
                            Publicações
                        </h5>

                        <p class="text-muted mb-0">
                            Gerencie as publicações
                            do condomínio.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Achados e Perdidos -->

            <div class="col-md-6 col-lg-4">

                <a href="portaria/achados_perdidos.php" class="menu-link">

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">🔎</div>

                        <h5 class="fw-bold">Achados e Perdidos</h5>

                        <p class="text-muted mb-0">Consulte e acompanhe itens encontrados e perdidos no condomínio.</p>

                    </div>

                </a>

            </div>


            <!-- Documentos -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="documento/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📄
                        </div>

                        <h5 class="fw-bold">
                            Documentos
                        </h5>

                        <p class="text-muted mb-0">
                            Gerencie documentos
                            do condomínio.
                        </p>

                    </div>

                </a>

            </div>

                <!-- Financeiro -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="financeiro/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            💰
                        </div>

                        <h5 class="fw-bold">
                            Financeiro
                        </h5>

                        <p class="text-muted mb-0">
                            Consulte as informações
                            financeiras.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Relatórios -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="relatorios/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📊
                        </div>

                        <h5 class="fw-bold">
                            Relatórios
                        </h5>

                        <p class="text-muted mb-0">
                            Consulte indicadores e
                            relatórios do condomínio.
                        </p>

                    </div>

                </a>

            </div>

            <div class="col-md-6 col-lg-4">

                <a href="relatorios/prestacao_contas.php" class="menu-link">

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">🧾</div>

                        <h5 class="fw-bold">Prestação de contas</h5>

                        <p class="text-muted mb-0">Resumo financeiro por período para a assembleia.</p>

                    </div>

                </a>

            </div>


            <div class="col-md-6 col-lg-4">

                <a href="assembleia/index.php" class="menu-link">

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">🏛️</div>

                        <h5 class="fw-bold">Assembleias</h5>

                        <p class="text-muted mb-0">Convoque os condôminos e organize as atas.</p>

                    </div>

                </a>

            </div>



            <!-- Encomendas -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="encomenda/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📦
                        </div>

                        <h5 class="fw-bold">
                            Encomendas
                        </h5>

                        <p class="text-muted mb-0">
                            Acompanhe as encomendas
                            do condomínio.
                        </p>

                    </div>

                </a>

            </div>

            <!-- Veículos (síndico também cadastra veículos) -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="veiculo/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            🚗
                        </div>

                        <h5 class="fw-bold">
                            Veículos
                        </h5>

                        <p class="text-muted mb-0">
                            Cadastre e aprove os veículos
                            dos moradores.
                        </p>

                    </div>

                </a>

            </div>


        </div>

    <?php endif; ?>


    <!-- ===================================================== -->
    <!-- ADMINISTRADOR -->
    <!-- ===================================================== -->

    <?php if ($isAdmin): ?>

        <div class="mb-4 mt-2">

            <h4 class="section-title">
                Administração
            </h4>

            <p class="text-muted">
                Gerenciamento das funcionalidades
                administrativas do condomínio.
            </p>

        </div>


        <div class="row g-4">


            <!-- Usuários -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="usuario/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            👥
                        </div>

                        <h5 class="fw-bold">
                            Usuários
                        </h5>

                        <p class="text-muted mb-0">
                            Cadastre, edite e gerencie
                            os usuários.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Unidades -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="unidade/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            🏠
                        </div>

                        <h5 class="fw-bold">
                            Unidades
                        </h5>

                        <p class="text-muted mb-0">
                            Gerencie as unidades
                            do condomínio.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Veículos -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="veiculo/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            🚗
                        </div>

                        <h5 class="fw-bold">
                            Veículos
                        </h5>

                        <p class="text-muted mb-0">
                            Gerencie veículos
                            cadastrados.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Visitantes -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="visitante/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            👤
                        </div>

                        <h5 class="fw-bold">
                            Visitantes
                        </h5>

                        <p class="text-muted mb-0">
                            Gerencie visitantes
                            cadastrados.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Autorizações -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="autorizacao_acesso/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            🔐
                        </div>

                        <h5 class="fw-bold">
                            Autorizações
                        </h5>

                        <p class="text-muted mb-0">
                            Gerencie autorizações
                            de acesso.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Portaria -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="portaria/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            🛡️
                        </div>

                        <h5 class="fw-bold">
                            Portaria
                        </h5>

                        <p class="text-muted mb-0">
                            Controle acessos,
                            visitantes e registros.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Reservas -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="reserva/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📅
                        </div>

                        <h5 class="fw-bold">
                            Reservas
                        </h5>

                        <p class="text-muted mb-0">
                            Gerencie reservas
                            e taxas.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Locais de Reserva -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="reserva/locais.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            🏢
                        </div>

                        <h5 class="fw-bold">
                            Locais de Reserva
                        </h5>

                        <p class="text-muted mb-0">
                            Cadastre e configure
                            os espaços disponíveis
                            para reservas.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Financeiro -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="financeiro/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            💰
                        </div>

                        <h5 class="fw-bold">
                            Financeiro
                        </h5>

                        <p class="text-muted mb-0">
                            Gerencie cobranças
                            e pagamentos.
                        </p>

                    </div>

                </a>

            </div>

            <!-- Relatórios -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="relatorios/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📊
                        </div>

                        <h5 class="fw-bold">
                            Relatórios
                        </h5>

                        <p class="text-muted mb-0">
                            Consulte indicadores e
                            relatórios do condomínio.
                        </p>

                    </div>

                </a>

            </div>

            <div class="col-md-6 col-lg-4">

                <a href="relatorios/prestacao_contas.php" class="menu-link">

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">🧾</div>

                        <h5 class="fw-bold">Prestação de contas</h5>

                        <p class="text-muted mb-0">Resumo financeiro por período para a assembleia.</p>

                    </div>

                </a>

            </div>


            <div class="col-md-6 col-lg-4">

                <a href="assembleia/index.php" class="menu-link">

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">🏛️</div>

                        <h5 class="fw-bold">Assembleias</h5>

                        <p class="text-muted mb-0">Convoque os condôminos e organize as atas.</p>

                    </div>

                </a>

            </div>


            <div class="col-md-6 col-lg-4">

                <a href="auditoria.php" class="menu-link">

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">🕵️</div>

                        <h5 class="fw-bold">Auditoria</h5>

                        <p class="text-muted mb-0">Veja quem executou cada ação que altera dados no sistema.</p>

                    </div>

                </a>

            </div>

            <!-- Ocorrências -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="ocorrencia/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            ⚠️
                        </div>

                        <h5 class="fw-bold">
                            Ocorrências
                        </h5>

                        <p class="text-muted mb-0">
                            Gerencie ocorrências
                            do condomínio.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Encomendas -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="encomenda/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📦
                        </div>

                        <h5 class="fw-bold">
                            Encomendas
                        </h5>

                        <p class="text-muted mb-0">
                            Controle todas as
                            encomendas.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Publicações -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="publicacao/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📢
                        </div>

                        <h5 class="fw-bold">
                            Publicações
                        </h5>

                        <p class="text-muted mb-0">
                            Gerencie conteúdos
                            e comunicados.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Achados e Perdidos -->

            <div class="col-md-6 col-lg-4">

                <a href="portaria/achados_perdidos.php" class="menu-link">

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">🔎</div>

                        <h5 class="fw-bold">Achados e Perdidos</h5>

                        <p class="text-muted mb-0">Consulte e acompanhe itens encontrados e perdidos no condomínio.</p>

                    </div>

                </a>

            </div>


            <!-- Documentos -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="documento/index.php"
                    class="menu-link"
                >

                    <div class="card module-card p-4">

                        <div class="module-icon mb-3">
                            📄
                        </div>

                        <h5 class="fw-bold">
                            Documentos
                        </h5>

                        <p class="text-muted mb-0">
                            Gerencie documentos
                            do condomínio.
                        </p>

                    </div>

                </a>

            </div>


        </div>

    <?php endif; ?>


    <!-- ===================================================== -->
    <!-- RODAPÉ -->
    <!-- ===================================================== -->

    <div class="text-center text-muted mt-5">

        <small>
            Koinon Services —
            Sistema de Gestão de Condomínios
        </small>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>
<script src="../assets/js/script.js"></script>

</body>

</html>