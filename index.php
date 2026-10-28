<?php

require_once __DIR__ . "/service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/service/Csrf.php";
Csrf::proteger();

$usuarioLogado = !empty($_SESSION["usuario_id"]);
$nomeUsuario = $_SESSION["usuario_nome"] ?? null;
$perfilUsuario = $_SESSION["usuario_perfil"] ?? null;

$contatoMensagem = null;
$contatoTipo = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'contato') {
    require_once __DIR__ . '/service/EmailService.php';

    $nome = trim($_POST['contato_nome'] ?? '');
    $email = trim($_POST['contato_email'] ?? '');
    $telefone = trim($_POST['contato_telefone'] ?? '');
    $empresa = trim($_POST['contato_empresa'] ?? '');
    $mensagem = trim($_POST['contato_mensagem'] ?? '');

    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $mensagem === '') {
        $contatoMensagem = 'Preencha nome, e-mail válido e a mensagem para enviar o contato.';
        $contatoTipo = 'danger';
    } else {
        $destino = getenv('KOINON_CONTATO_EMAIL') ?: 'contato@koinonservice.com';
        $corpo = "Novo contato comercial - Koinon Services\n\n"
            . "Nome: {$nome}\n"
            . "E-mail: {$email}\n"
            . "Telefone: {$telefone}\n"
            . "Condomínio/empresa: {$empresa}\n\n"
            . "Mensagem:\n{$mensagem}";

        $emailService = new EmailService();
        $enviado = $emailService->enviar($destino, 'Novo contato comercial - Koinon Services', $corpo);

        if ($enviado) {
            $contatoMensagem = 'Mensagem enviada com sucesso. Entraremos em contato pelos dados informados.';
            $contatoTipo = 'success';
        } else {
            $contatoMensagem = 'Não foi possível enviar o e-mail pelo servidor atual. Configure o SMTP/mail() do XAMPP e tente novamente.';
            $contatoTipo = 'warning';
        }
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

    <title>Koinon Services - Gestão de Condomínios</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"
        rel="stylesheet"
    >

    <style>

        body {
            background-color: var(--k-bg);
            color: var(--k-text);
        }

        .navbar {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.4rem;
        }

        .hero {
            min-height: 560px;
            display: flex;
            align-items: center;
            background:
                linear-gradient(
                    135deg,
                    #0d6efd 0%,
                    #084298 100%
                );
            color: white;
        }

        .hero h1 {
            font-size: 3rem;
            font-weight: 700;
        }

        .hero p {
            font-size: 1.15rem;
            line-height: 1.7;
        }

        .hero-card {
            background-color: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 35px;
            backdrop-filter: blur(8px);
        }

        .section-title {
            font-weight: 700;
            margin-bottom: 10px;
        }

        .section-subtitle {
            color: #6c757d;
            margin-bottom: 40px;
        }

        .service-card {
            height: 100%;
            border: none;
            border-radius: 16px;
            box-shadow: 0 5px 18px rgba(0, 0, 0, 0.08);
            transition: 0.2s;
        }

        .service-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
        }

        .service-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            background-color: #e7f0ff;
            color: #0d6efd;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.7rem;
            margin-bottom: 20px;
        }

        .profile-card {
            border: none;
            border-radius: 18px;
            box-shadow: 0 5px 18px rgba(0, 0, 0, 0.08);
            height: 100%;
        }

        .profile-card ul {
            padding-left: 20px;
        }

        .profile-card li {
            margin-bottom: 8px;
        }

        .about-section {
            background-color: var(--k-surface);
        }

        .cta-section {
            background: linear-gradient(135deg, #1d4ed8, #4f46e5 55%, #7c3aed);
            color: white;
        }

        footer {
            background-color: #212529;
            color: #adb5bd;
        }

        footer strong {
            color: white;
        }

        @media (max-width: 768px) {

            .hero {
                min-height: auto;
                padding: 80px 0;
            }

            .hero h1 {
                font-size: 2.2rem;
            }

            .hero-card {
                margin-top: 35px;
            }

        }

    </style>

    <style>
        /* ===== V14: navbar da página inicial ===== */
        .koinon-landing-nav {
            background: rgba(255, 255, 255, .72);
            -webkit-backdrop-filter: blur(16px) saturate(160%);
            backdrop-filter: blur(16px) saturate(160%);
            border-bottom: 1px solid rgba(99, 102, 241, .14);
            padding: 1rem 0;
            transition: padding .3s ease, box-shadow .3s ease, background .3s ease;
            animation: k-fade-in .6s ease both;
            z-index: 1050;
        }
        html[data-theme="dark"] .koinon-landing-nav { background: rgba(11, 17, 32, .72); border-bottom-color: rgba(129, 140, 248, .22); }
        .koinon-landing-nav.is-scrolled { padding: .45rem 0; box-shadow: 0 10px 34px rgba(15, 23, 42, .14); }
        .koinon-landing-nav::after {
            content: ""; position: absolute; left: 0; right: 0; bottom: -1px; height: 2px;
            background: linear-gradient(90deg, transparent, #6366f1, #0ea5e9, #a855f7, transparent);
            background-size: 200% 100%; animation: k-gradient 6s linear infinite;
            opacity: 0; transition: opacity .3s ease;
        }
        .koinon-landing-nav.is-scrolled::after { opacity: .9; }
        .koinon-landing-nav .navbar-brand span {
            background: linear-gradient(90deg, #4f46e5, #0ea5e9, #7c3aed);
            background-size: 200% auto; -webkit-background-clip: text; background-clip: text;
            -webkit-text-fill-color: transparent; animation: k-gradient 8s ease infinite;
        }
        .koinon-landing-nav .koinon-logo-small { transition: transform .4s cubic-bezier(.2, .8, .2, 1); }
        .koinon-landing-nav .navbar-brand:hover .koinon-logo-small { transform: rotate(-8deg) scale(1.12); }
        .koinon-navlink { position: relative; font-weight: 600; color: var(--k-text) !important; padding: .5rem .9rem !important; transition: color .2s ease; }
        .koinon-navlink::after {
            content: ""; position: absolute; left: .9rem; right: .9rem; bottom: .2rem; height: 2px; border-radius: 2px;
            background: linear-gradient(90deg, #6366f1, #0ea5e9); transform: scaleX(0); transform-origin: left; transition: transform .3s ease;
        }
        .koinon-navlink:hover, .koinon-navlink.is-active { color: #4f46e5 !important; }
        html[data-theme="dark"] .koinon-navlink:hover, html[data-theme="dark"] .koinon-navlink.is-active { color: #a5b4fc !important; }
        .koinon-navlink:hover::after, .koinon-navlink.is-active::after { transform: scaleX(1); }
        .koinon-btn-entrar {
            color: #fff !important; font-weight: 700; border: 0 !important; border-radius: 999px; padding: .55rem 1.35rem;
            background: linear-gradient(135deg, #4f46e5, #0ea5e9) !important; box-shadow: 0 8px 20px rgba(79, 70, 229, .35);
        }
        .koinon-btn-entrar:hover { box-shadow: 0 12px 28px rgba(79, 70, 229, .5); }
        .koinon-dropdown { border: 1px solid var(--k-border); border-radius: 16px; padding: .5rem; min-width: 290px; background: var(--k-surface); animation: k-fade-up .25s ease both; }
        .koinon-dropdown .dropdown-item { display: flex; gap: .8rem; align-items: center; border-radius: 12px; padding: .65rem .8rem; color: var(--k-text); white-space: normal; }
        .koinon-dropdown .dropdown-item small { display: block; color: var(--k-muted); }
        .koinon-dropdown .dropdown-item:hover { background: rgba(99, 102, 241, .1); transform: translateX(3px); }
        .koinon-dd-icon { width: 40px; height: 40px; flex: 0 0 40px; display: grid; place-items: center; border-radius: 12px; background: linear-gradient(135deg, rgba(99,102,241,.16), rgba(14,165,233,.16)); font-size: 1.2rem; }

        /* ===== V14: hero e cartões da página inicial ===== */
        .hero {
            position: relative; overflow: hidden;
            background: linear-gradient(120deg, #1e3a8a, #4338ca 35%, #2563eb 65%, #0ea5e9) !important;
            background-size: 220% 220% !important; animation: k-gradient 16s ease infinite;
        }
        .hero-blob { position: absolute; border-radius: 50%; filter: blur(60px); opacity: .5; pointer-events: none; animation: k-float 14s ease-in-out infinite; }
        .hero-blob-1 { width: 420px; height: 420px; background: #a855f7; top: -140px; left: -120px; }
        .hero-blob-2 { width: 380px; height: 380px; background: #22d3ee; bottom: -160px; right: -100px; animation-delay: -6s; }
        .hero-grid {
            position: absolute; inset: 0; pointer-events: none; opacity: .18;
            background-image: linear-gradient(rgba(255,255,255,.35) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.35) 1px, transparent 1px);
            background-size: 46px 46px; -webkit-mask-image: radial-gradient(ellipse at center, #000 30%, transparent 75%); mask-image: radial-gradient(ellipse at center, #000 30%, transparent 75%);
        }
        .hero h1 { animation: k-fade-up .8s ease both; text-shadow: 0 6px 30px rgba(0, 0, 0, .25); }
        .hero p, .hero .badge { animation: k-fade-up .8s .12s ease both; }
        .hero .btn { animation: k-fade-up .8s .25s ease both; }
        .hero-card { animation: k-fade-up .9s .2s ease both, k-float 9s 1s ease-in-out infinite; box-shadow: 0 30px 70px rgba(0, 0, 0, .28); }
        .service-card { position: relative; overflow: hidden; }
        .service-card::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 4px; background: linear-gradient(90deg, #6366f1, #0ea5e9, #a855f7); transform: scaleX(0); transform-origin: left; transition: transform .4s ease; }
        .service-card:hover::before { transform: scaleX(1); }
        .service-card:hover { transform: translateY(-9px) !important; box-shadow: 0 26px 50px rgba(79, 70, 229, .22) !important; }
        .service-icon { background: linear-gradient(135deg, rgba(99,102,241,.18), rgba(14,165,233,.18)) !important; transition: transform .35s cubic-bezier(.2, .8, .2, 1); }
        .service-card:hover .service-icon { transform: scale(1.15) rotate(-7deg); }
        .profile-card { transition: transform .3s ease, box-shadow .3s ease; }
        .profile-card:hover { transform: translateY(-6px); box-shadow: 0 22px 46px rgba(15, 23, 42, .16); }
        .cta-section { background-size: 220% 220%; animation: k-gradient 14s ease infinite; background-image: linear-gradient(120deg, #1d4ed8, #4f46e5 40%, #7c3aed 70%, #0ea5e9); }
        html { scroll-behavior: smooth; }
        section[id] { scroll-margin-top: 78px; }
    </style>


    <link rel="stylesheet" href="assets/css/estilo.css">

</head>

<body>

<!-- ========================================================= -->
<!-- MENU -->
<!-- ========================================================= -->

<nav class="navbar navbar-expand-lg sticky-top koinon-landing-nav">

    <div class="container">

        <a class="navbar-brand text-primary d-flex align-items-center gap-2" href="index.php">
            <img src="assets/img/logo-placeholder.svg" class="koinon-logo-small" alt="Koinon Services">
            <span>Koinon Services</span>
        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#menuPrincipal"
            aria-controls="menuPrincipal"
            aria-expanded="false"
            aria-label="Abrir menu"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div
            class="collapse navbar-collapse"
            id="menuPrincipal"
        >

            <ul class="navbar-nav ms-auto align-items-lg-center">

                <li class="nav-item">
                    <a
                        class="nav-link koinon-navlink"
                        href="#sobre"
                    >
                        Sobre o sistema
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        class="nav-link koinon-navlink"
                        href="#servicos"
                    >
                        Serviços
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        class="nav-link koinon-navlink"
                        href="#usuarios"
                    >
                        Usuários
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link koinon-navlink" href="#contato">Fale conosco</a>
                </li>

                <?php if ($usuarioLogado): ?>

                    <li class="nav-item ms-lg-2 mt-2 mt-lg-0">

                        <a
                            class="btn btn-primary"
                            href="view/dashboard.php"
                        >
                            Acessar sistema
                        </a>

                    </li>

                <?php else: ?>

                    <li class="nav-item dropdown ms-lg-2 mt-2 mt-lg-0">

                        <a
                            class="btn koinon-btn-entrar dropdown-toggle"
                            href="#"
                            role="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                        >
                            Entrar
                        </a>

                        <ul class="dropdown-menu dropdown-menu-end koinon-dropdown shadow">

                            <li>
                                <a class="dropdown-item" href="view/login.php?tipo=morador">
                                    <span class="koinon-dd-icon">🏠</span>
                                    <span>
                                        <strong>Sou morador</strong>
                                        <small>Reservas, encomendas e ocorrências</small>
                                    </span>
                                </a>
                            </li>

                            <li>
                                <a class="dropdown-item" href="view/login.php?tipo=funcionario">
                                    <span class="koinon-dd-icon">🛡️</span>
                                    <span>
                                        <strong>Sou funcionário</strong>
                                        <small>Administração, síndico e portaria</small>
                                    </span>
                                </a>
                            </li>

                        </ul>

                    </li>

                <?php endif; ?>

            </ul>

        </div>

    </div>

</nav>


<!-- ========================================================= -->
<!-- HERO -->
<!-- ========================================================= -->

<section class="hero">

    <span class="hero-blob hero-blob-1"></span>
    <span class="hero-blob hero-blob-2"></span>
    <span class="hero-grid"></span>

    <div class="container position-relative">

        <div class="row align-items-center">

            <div class="col-lg-7">

                <span class="badge bg-light text-primary mb-3 px-3 py-2">
                    Gestão inteligente de condomínios
                </span>

                <h1>
                    Simplifique a gestão do seu condomínio.
                </h1>

                <p class="mt-4">

                    O Koinon Services reúne em um único sistema
                    recursos para administração, moradores,
                    portaria e gestão do condomínio.

                </p>

                <div class="mt-4 d-flex flex-wrap gap-2">

                    <?php if ($usuarioLogado): ?>

                        <a
                            href="view/dashboard.php"
                            class="btn btn-light btn-lg"
                        >
                            Acessar meu sistema
                        </a>

                    <?php else: ?>

                        <a
                            href="view/login.php?tipo=morador"
                            class="btn btn-light btn-lg"
                        >
                            Entrar como Morador
                        </a>

                        <a
                            href="view/login.php?tipo=funcionario"
                            class="btn btn-outline-light btn-lg"
                        >
                            Entrar como Funcionário
                        </a>

                    <?php endif; ?>

                </div>

            </div>


            <div class="col-lg-5">

                <div class="hero-card">

                    <h3 class="fw-bold mb-4">
                        🏢 Koinon Services
                    </h3>

                    <p class="mb-4">
                        Uma plataforma para organizar as principais
                        atividades de um condomínio de forma simples,
                        centralizada e segura.
                    </p>

                    <div class="row g-3">

                        <div class="col-6">

                            <div class="bg-white bg-opacity-10 rounded p-3">

                                <h4 class="fw-bold mb-1">
                                    🔐
                                </h4>

                                <small>
                                    Controle de acesso
                                </small>

                            </div>

                        </div>

                        <div class="col-6">

                            <div class="bg-white bg-opacity-10 rounded p-3">

                                <h4 class="fw-bold mb-1">
                                    💰
                                </h4>

                                <small>
                                    Gestão financeira
                                </small>

                            </div>

                        </div>

                        <div class="col-6">

                            <div class="bg-white bg-opacity-10 rounded p-3">

                                <h4 class="fw-bold mb-1">
                                    📦
                                </h4>

                                <small>
                                    Encomendas
                                </small>

                            </div>

                        </div>

                        <div class="col-6">

                            <div class="bg-white bg-opacity-10 rounded p-3">

                                <h4 class="fw-bold mb-1">
                                    📅
                                </h4>

                                <small>
                                    Reservas
                                </small>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- ========================================================= -->
<!-- SOBRE -->
<!-- ========================================================= -->

<section
    id="sobre"
    class="about-section py-5"
>

    <div class="container py-4">

        <div class="row align-items-center">

            <div class="col-lg-6">

                <h2 class="section-title">
                    Por que utilizar o Koinon Services?
                </h2>

                <p class="section-subtitle">
                    Mais organização para a administração e
                    mais praticidade para os moradores.
                </p>

                <p>
                    O Koinon Services foi desenvolvido para
                    centralizar as principais atividades de um
                    condomínio em uma única plataforma.
                </p>

                <p>
                    Com o sistema, informações que normalmente
                    ficam espalhadas em planilhas, mensagens
                    e controles manuais podem ser organizadas
                    em um ambiente único.
                </p>

                <p>
                    Isso facilita o acompanhamento das atividades
                    do condomínio, melhora a comunicação e ajuda
                    na organização da rotina dos moradores,
                    funcionários e administração.
                </p>

            </div>


            <div class="col-lg-6">

                <div class="row g-3">

                    <div class="col-md-6">

                        <div class="service-card card p-4">

                            <div class="service-icon">
                                🔐
                            </div>

                            <h5 class="fw-bold">
                                Mais segurança
                            </h5>

                            <p class="text-muted mb-0">
                                Controle de visitantes,
                                autorizações e registros
                                de acesso.
                            </p>

                        </div>

                    </div>


                    <div class="col-md-6">

                        <div class="service-card card p-4">

                            <div class="service-icon">
                                📊
                            </div>

                            <h5 class="fw-bold">
                                Mais organização
                            </h5>

                            <p class="text-muted mb-0">
                                Centralização das informações
                                do condomínio.
                            </p>

                        </div>

                    </div>


                    <div class="col-md-6">

                        <div class="service-card card p-4">

                            <div class="service-icon">
                                ⏱️
                            </div>

                            <h5 class="fw-bold">
                                Mais praticidade
                            </h5>

                            <p class="text-muted mb-0">
                                Processos mais simples para
                                moradores e funcionários.
                            </p>

                        </div>

                    </div>


                    <div class="col-md-6">

                        <div class="service-card card p-4">

                            <div class="service-icon">
                                📱
                            </div>

                            <h5 class="fw-bold">
                                Acesso centralizado
                            </h5>

                            <p class="text-muted mb-0">
                                Informações disponíveis em
                                um único sistema.
                            </p>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- ========================================================= -->
<!-- SERVIÇOS -->
<!-- ========================================================= -->

<section
    id="servicos"
    class="py-5"
>

    <div class="container py-4">

        <div class="text-center">

            <h2 class="section-title">
                Recursos do Koinon Services
            </h2>

            <p class="section-subtitle">
                Recursos desenvolvidos para facilitar a gestão
                do condomínio.
            </p>

        </div>


        <div class="row g-4">

            <div class="col-md-6 col-lg-4">

                <div class="service-card card p-4">

                    <div class="service-icon">
                        👥
                    </div>

                    <h5 class="fw-bold">
                        Gestão de moradores
                    </h5>

                    <p class="text-muted">
                        Cadastro e gerenciamento dos usuários
                        e moradores do condomínio.
                    </p>

                </div>

            </div>


            <div class="col-md-6 col-lg-4">

                <div class="service-card card p-4">

                    <div class="service-icon">
                        🚗
                    </div>

                    <h5 class="fw-bold">
                        Veículos
                    </h5>

                    <p class="text-muted">
                        Cadastro e organização dos veículos
                        vinculados aos moradores.
                    </p>

                </div>

            </div>


            <div class="col-md-6 col-lg-4">

                <div class="service-card card p-4">

                    <div class="service-icon">
                        📅
                    </div>

                    <h5 class="fw-bold">
                        Reservas
                    </h5>

                    <p class="text-muted">
                        Controle de reservas dos espaços
                        disponíveis no condomínio.
                    </p>

                </div>

            </div>


            <div class="col-md-6 col-lg-4">

                <div class="service-card card p-4">

                    <div class="service-icon">
                        📦
                    </div>

                    <h5 class="fw-bold">
                        Encomendas
                    </h5>

                    <p class="text-muted">
                        Controle de recebimento e retirada
                        de encomendas.
                    </p>

                </div>

            </div>


            <div class="col-md-6 col-lg-4">

                <div class="service-card card p-4">

                    <div class="service-icon">
                        🔐
                    </div>

                    <h5 class="fw-bold">
                        Portaria
                    </h5>

                    <p class="text-muted">
                        Controle de visitantes, autorizações,
                        QR Codes e registros de acesso.
                    </p>

                </div>

            </div>


            <div class="col-md-6 col-lg-4">

                <div class="service-card card p-4">

                    <div class="service-icon">
                        💰
                    </div>

                    <h5 class="fw-bold">
                        Financeiro
                    </h5>

                    <p class="text-muted">
                        Organização das cobranças e
                        acompanhamento financeiro.
                    </p>

                </div>

            </div>


            <div class="col-md-6 col-lg-4">

                <div class="service-card card p-4">

                    <div class="service-icon">
                        ⚠️
                    </div>

                    <h5 class="fw-bold">
                        Ocorrências
                    </h5>

                    <p class="text-muted">
                        Registro e acompanhamento de
                        ocorrências do condomínio.
                    </p>

                </div>

            </div>


            <div class="col-md-6 col-lg-4">

                <div class="service-card card p-4">

                    <div class="service-icon">
                        📢
                    </div>

                    <h5 class="fw-bold">
                        Comunicação
                    </h5>

                    <p class="text-muted">
                        Publicações e comunicação entre
                        moradores e administração.
                    </p>

                </div>

            </div>


            <div class="col-md-6 col-lg-4">

                <div class="service-card card p-4">

                    <div class="service-icon">
                        📄
                    </div>

                    <h5 class="fw-bold">
                        Documentos
                    </h5>

                    <p class="text-muted">
                        Organização dos documentos importantes
                        do condomínio.
                    </p>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- ========================================================= -->
<!-- USUÁRIOS -->
<!-- ========================================================= -->

<section
    id="usuarios"
    class="about-section py-5"
>

    <div class="container py-4">

        <div class="text-center">

            <h2 class="section-title">
                Acesso para cada perfil
            </h2>

            <p class="section-subtitle">
                Cada usuário possui acesso aos recursos
                necessários para sua função.
            </p>

        </div>


        <div class="row g-4">

            <!-- MORADOR -->

            <div class="col-md-6 col-lg-3">

                <div class="profile-card card p-4">

                    <div class="service-icon">
                        🏠
                    </div>

                    <h5 class="fw-bold">
                        Morador
                    </h5>

                    <p class="text-muted">
                        Acesso aos recursos relacionados
                        à sua unidade.
                    </p>

                    <ul class="text-muted">

                        <li>Reservas</li>

                        <li>Veículos</li>

                        <li>Encomendas</li>

                        <li>Ocorrências</li>

                        <li>Visitantes</li>

                        <li>Financeiro</li>

                    </ul>

                </div>

            </div>


            <!-- PORTEIRO -->

            <div class="col-md-6 col-lg-3">

                <div class="profile-card card p-4">

                    <div class="service-icon">
                        🛡️
                    </div>

                    <h5 class="fw-bold">
                        Porteiro
                    </h5>

                    <p class="text-muted">
                        Recursos relacionados ao controle
                        de acesso do condomínio.
                    </p>

                    <ul class="text-muted">

                        <li>Portaria</li>

                        <li>Visitantes</li>

                        <li>QR Code</li>

                        <li>Registros de acesso</li>

                        <li>Encomendas</li>

                    </ul>

                </div>

            </div>


            <!-- SÍNDICO -->

            <div class="col-md-6 col-lg-3">

                <div class="profile-card card p-4">

                    <div class="service-icon">
                        👔
                    </div>

                    <h5 class="fw-bold">
                        Síndico
                    </h5>

                    <p class="text-muted">
                        Recursos para acompanhamento
                        e gestão do condomínio.
                    </p>

                    <ul class="text-muted">

                        <li>Reservas</li>

                        <li>Ocorrências</li>

                        <li>Portaria</li>

                        <li>Publicações</li>

                        <li>Documentos</li>

                    </ul>

                </div>

            </div>


            <!-- ADMINISTRADOR -->

            <div class="col-md-6 col-lg-3">

                <div class="profile-card card p-4">

                    <div class="service-icon">
                        ⚙️
                    </div>

                    <h5 class="fw-bold">
                        Administrador
                    </h5>

                    <p class="text-muted">
                        Gerenciamento das principais
                        funcionalidades do condomínio.
                    </p>

                    <ul class="text-muted">

                        <li>Usuários</li>

                        <li>Unidades</li>

                        <li>Financeiro</li>

                        <li>Portaria</li>

                        <li>Relatórios</li>

                    </ul>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- ========================================================= -->
<!-- CTA -->
<!-- ========================================================= -->

<section class="cta-section py-5">

    <div class="container text-center py-4">

        <h2 class="fw-bold">
            Pronto para utilizar o Koinon Services?
        </h2>

        <p class="mt-3 mb-4">
            Acesse o sistema de acordo com seu perfil.
        </p>


        <?php if ($usuarioLogado): ?>

            <a
                href="view/dashboard.php"
                class="btn btn-light btn-lg"
            >
                Acessar sistema
            </a>

        <?php else: ?>

            <div class="d-flex justify-content-center flex-wrap gap-2">

                <a
                    href="view/login.php?tipo=morador"
                    class="btn btn-light btn-lg"
                >
                    Entrar como Morador
                </a>

                <a
                    href="view/login.php?tipo=funcionario"
                    class="btn btn-outline-light btn-lg"
                >
                    Entrar como Funcionário
                </a>

            </div>

        <?php endif; ?>

    </div>

</section>


<!-- ========================================================= -->
<!-- FALE CONOSCO -->
<!-- ========================================================= -->

<section id="contato" class="py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9">
                <div class="card border-0 shadow-sm p-4 p-lg-5">
                    <div class="row g-4 align-items-center">
                        <div class="col-lg-5">
                            <span class="badge text-bg-primary mb-3">Fale conosco</span>
                            <h2 class="section-title">Quer conhecer o Koinon Services?</h2>
                            <p class="text-muted mb-0">Preencha seus dados e conte um pouco sobre o condomínio ou serviço que você procura. A equipe poderá entrar em contato para apresentar a solução.</p>
                        </div>
                        <div class="col-lg-7">
                            <?php if ($contatoMensagem): ?>
                                <div class="alert alert-<?= htmlspecialchars($contatoTipo) ?>"><?= htmlspecialchars($contatoMensagem) ?></div>
                            <?php endif; ?>
                            <form method="POST" action="index.php#contato" class="row g-3">
                                <input type="hidden" name="acao" value="contato">
                                <div class="col-md-6">
                                    <label class="form-label">Nome</label>
                                    <input type="text" name="contato_nome" class="form-control" maxlength="120" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">E-mail</label>
                                    <input type="email" name="contato_email" class="form-control" maxlength="150" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Telefone</label>
                                    <input type="text" name="contato_telefone" class="form-control" maxlength="30">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Condomínio/empresa</label>
                                    <input type="text" name="contato_empresa" class="form-control" maxlength="150">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Como podemos ajudar?</label>
                                    <textarea name="contato_mensagem" class="form-control" rows="4" maxlength="1500" required></textarea>
                                </div>
                                <div class="col-12 d-flex justify-content-between align-items-center gap-3 flex-wrap">
                                    <button class="btn btn-primary px-4" type="submit">Enviar contato</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========================================================= -->
<!-- FOOTER -->
<!-- ========================================================= -->

<footer class="py-4">

    <div class="container">

        <div class="row align-items-center">

            <div class="col-md-6">

                <strong>
                    Koinon Services
                </strong>

                <div class="small mt-1">
                    Sistema de Gestão de Condomínios
                </div>

            </div>

            <div class="col-md-6 text-md-end mt-3 mt-md-0">

                <div class="small">
                    © <?= date("Y"); ?> Koinon Services
                </div>

                <div class="small">
                    Todos os direitos reservados.
                </div>

            </div>

        </div>

    </div>

</footer>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"
></script>


<script src="assets/js/script.js"></script>

</body>

</html>