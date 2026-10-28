<?php

require_once __DIR__ . "/../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../service/Csrf.php";
Csrf::proteger();

require_once __DIR__ . "/../model/dao/Conexao.php";
require_once __DIR__ . "/../service/PasswordLinkService.php";
require_once __DIR__ . "/../service/LimiteTentativas.php";

$tipoAcesso = strtolower(trim($_GET["tipo"] ?? $_POST["tipo"] ?? "morador"));
if (!in_array($tipoAcesso, ["morador", "funcionario"], true)) {
    $tipoAcesso = "morador";
}

$erro = null;
$aviso = null;
$linkDemo = null;

if (!empty($_SESSION["sessao_expirada"])) {
    unset($_SESSION["sessao_expirada"]);
    $aviso = "Sua sessão foi encerrada por inatividade. Entre novamente.";
}
$identificadorInformado = trim($_POST["identificador"] ?? $_POST["cpf"] ?? "");
$primeiroAcesso = false;

function cpfLimpo(string $cpf): string
{
    return preg_replace('/\D+/', '', $cpf);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $identificador = trim($_POST["identificador"] ?? $_POST["cpf"] ?? "");
    $senha = $_POST["senha"] ?? "";
    $cpf = cpfLimpo($identificador);
    $email = filter_var($identificador, FILTER_VALIDATE_EMAIL) ? strtolower($identificador) : "";

    /*
     * Até 5 senhas erradas por conta e 20 por IP a cada 15 minutos.
     * As mensagens são as mesmas para conta inexistente e senha errada,
     * para não revelar quais CPFs/e-mails estão cadastrados.
     */
    $limite = new LimiteTentativas();
    $janela = 15 * 60;
    $chaveConta = "login-conta:" . ($email !== "" ? $email : $cpf);
    $chaveIp = "login-ip:" . LimiteTentativas::ip();
    $espera = max(
        $limite->bloqueadoPor($chaveConta, 5, $janela),
        $limite->bloqueadoPor($chaveIp, 20, $janela)
    );
    $falhou = false;
    $mensagemSemSenha = "Informe sua senha. Se for o seu primeiro acesso, enviamos para o e-mail (ou WhatsApp) cadastrado um link para você criar a senha.";

    if ($identificador === "") {
        $erro = "Informe seu CPF ou e-mail.";
    } elseif ($espera > 0) {
        $erro = "Muitas tentativas. Aguarde " . LimiteTentativas::minutos($espera) . " minuto(s) e tente novamente.";
    } else {
        try {
            $conexao = Conexao::conectar();

            $stmt = $conexao->prepare(
                "SELECT id, unidade_id, nome, cpf, email, telefone, senha_hash,
                        perfil, termo_lgpd, data_aceite_lgpd, ativo,
                        senha_definida, primeiro_acesso
                 FROM usuario
                 WHERE cpf = :cpf OR LOWER(email) = :email
                 LIMIT 1"
            );
            $stmt->bindValue(":cpf", $cpf, PDO::PARAM_STR);
            $stmt->bindValue(":email", $email, PDO::PARAM_STR);
            $stmt->execute();
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($usuario !== null && (int) $usuario["ativo"] !== 1) {
                $usuario = null;
            }

            $senhaDefinida = $usuario !== null && (int) ($usuario["senha_definida"] ?? 1) === 1;
            $primeiroAcesso = $usuario !== null && !$senhaDefinida;

            if ($senha === "") {
                /*
                 * Senha em branco: mesma resposta exista a conta ou não.
                 * Primeiro acesso não entra sem senha (quem sabe o CPF ou o
                 * e-mail de alguém tomaria a conta): a senha é criada pelo
                 * link enviado ao e-mail (ou WhatsApp) cadastrado.
                 */
                $aviso = $mensagemSemSenha;

                if ($primeiroAcesso) {
                    $limite->registrar($chaveConta, $janela);
                    $canal = !empty($usuario["email"]) ? "email" : "whatsapp";
                    $envio = (new PasswordLinkService())->enviarLinkSenha($usuario, $canal);

                    if ($envio["link_demo"] !== null) {
                        $aviso = "Primeiro acesso (modo demonstração): o envio não foi concluído, então o link para criar a senha está abaixo.";
                        $linkDemo = $envio["link_demo"];
                    }
                }
            } elseif (
                // password_verify também roda sem conta, para o tempo de resposta não denunciar quem existe
                !password_verify($senha, $senhaDefinida ? $usuario["senha_hash"] : '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG')
                || !$senhaDefinida
            ) {
                $erro = "CPF/e-mail ou senha inválidos.";
                $falhou = true;
            } else {
                $perfil = $usuario["perfil"];
                $perfilPermitido =
                    ($tipoAcesso === "morador" && $perfil === "Morador") ||
                    ($tipoAcesso === "funcionario" && in_array(
                        $perfil,
                        ["Porteiro", "Sindico", "Administrador"],
                        true
                    ));

                if (!$perfilPermitido) {
                    $erro = $tipoAcesso === "morador"
                        ? "Este acesso é destinado aos moradores."
                        : "Este acesso é destinado aos funcionários.";
                } else {
                    $limite->limpar($chaveConta);
                    session_regenerate_id(true);

                    $_SESSION["usuario_id"] = (int) $usuario["id"];
                    $_SESSION["usuario_nome"] = $usuario["nome"];
                    $_SESSION["usuario_email"] = $usuario["email"];
                    $_SESSION["usuario_telefone"] = $usuario["telefone"] ?? "";
                    $_SESSION["usuario_perfil"] = $usuario["perfil"];
                    $_SESSION["usuario_unidade_id"] = $usuario["unidade_id"];
                    $_SESSION["lgpd_aceito"] = (bool) ($usuario["termo_lgpd"] ?? false);
                    $_SESSION["primeiro_acesso"] = false;
                    $_SESSION["_ultima_atividade"] = time();

                    if (!$usuario["termo_lgpd"]) {
                        header("Location: lgpd.php");
                        exit;
                    }

                    header(
                        $perfil === "Morador"
                            ? "Location: morador/index.php"
                            : "Location: dashboard.php"
                    );
                    exit;
                }
            }

            if ($falhou) {
                $limite->registrar($chaveConta, $janela);
                $limite->registrar($chaveIp, $janela);
            }
        } catch (Throwable $e) {
            $erro = "Não foi possível realizar o login. Verifique a conexão com o banco de dados.";
        }
    }
}

$tituloLogin = $tipoAcesso === "morador" ? "Área do Morador" : "Acesso do Funcionário";
$descricaoLogin = $tipoAcesso === "morador"
    ? "Acesse reservas, encomendas, ocorrências, visitantes e os serviços do condomínio."
    : "Bem-vindo de volta! Entre com seu CPF ou e-mail para abrir o seu painel de trabalho.";
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
        <?= htmlspecialchars($tituloLogin); ?>
        - Koinon Services
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at 15% 20%, rgba(37,99,235,.16), transparent 32%),
                radial-gradient(circle at 85% 15%, rgba(124,58,237,.12), transparent 28%),
                linear-gradient(135deg,#eef4ff,#f8f7ff 55%,#eef2ff);

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 30px 15px;
        }

        .login-wrapper {
            width: 100%;
            max-width: 950px;
        }

        .login-card {
            background-color: var(--k-surface);
            border-radius: 22px;
            overflow: hidden;
            box-shadow:
                0 15px 50px rgba(0, 0, 0, 0.20);
        }

        .login-info {
            background:
                linear-gradient(160deg,#2563eb,#1d4ed8);

            color: white;

            padding: 45px;
        }

        .login-form {
            padding: 45px;
        }

        .brand {
            font-size: 1.6rem;
            font-weight: 700;
        }

        .icon-box {
            width: 70px;
            height: 70px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 18px;

            background-color: rgba(
                255,
                255,
                255,
                0.15
            );

            font-size: 2rem;

            margin-bottom: 25px;
        }

        .form-control {
            min-height: 50px;
            border-radius: 10px;
        }

        .btn-login {
            min-height: 50px;
            border-radius: 10px;
            font-weight: 600;
        }

        .access-option {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 12px;
            text-decoration: none;
            display: block;
            color: var(--k-text);
            transition: 0.2s;
        }

        .access-option:hover {
            border-color: #0d6efd;
            background-color: #f5f9ff;
        }

        .access-option.active {
            border-color: #0d6efd;
            background-color: #eaf3ff;
            color: #0d6efd;
        }

        .small-muted {
            color: var(--k-muted);
            font-size: 0.9rem;
        }

        @media (max-width: 767px) {

            .login-info {
                padding: 30px;
            }

            .login-form {
                padding: 30px;
            }

        }

    </style>


    <link rel="stylesheet" href="../assets/css/estilo.css">

</head>

<body>

<div class="login-wrapper">

    <div class="login-card">

        <div class="row g-0">

            <!-- ================================================= -->
            <!-- LADO ESQUERDO -->
            <!-- ================================================= -->

            <div class="col-lg-5">

                <div class="login-info h-100">

                    <div class="brand mb-4">
                        🏢 Koinon Services
                    </div>

                    <div class="icon-box">

                        <?php if ($tipoAcesso === "morador"): ?>

                            🏠

                        <?php else: ?>

                            👔

                        <?php endif; ?>

                    </div>

                    <h2 class="fw-bold">

                        <?= htmlspecialchars(
                            $tituloLogin
                        ); ?>

                    </h2>

                    <p class="mt-3">

                        <?= htmlspecialchars(
                            $descricaoLogin
                        ); ?>

                    </p>

                </div>

            </div>


            <!-- ================================================= -->
            <!-- FORMULÁRIO -->
            <!-- ================================================= -->

            <div class="col-lg-7">

                <div class="login-form">

                    <div class="mb-4">

                        <h3 class="fw-bold">
                            Entrar
                        </h3>

                        <p class="small-muted mb-0">
                            Informe seus dados para acessar o sistema.
                        </p>

                    </div>


                    <!-- ========================================= -->
                    <!-- ESCOLHA DO ACESSO -->
                    <!-- ========================================= -->

                    <div class="row g-2 mb-4">

                        <div class="col-6">

                            <a
                                href="login.php?tipo=morador"
                                class="
                                    access-option
                                    <?= $tipoAcesso === "morador"
                                        ? "active"
                                        : ""; ?>
                                "
                            >

                                <div class="fw-bold">
                                    🏠 Morador
                                </div>

                                <small class="text-muted">
                                    Área do morador
                                </small>

                            </a>

                        </div>


                        <div class="col-6">

                            <a
                                href="login.php?tipo=funcionario"
                                class="
                                    access-option
                                    <?= $tipoAcesso === "funcionario"
                                        ? "active"
                                        : ""; ?>
                                "
                            >

                                <div class="fw-bold">
                                    👔 Funcionário
                                </div>

                                <small class="text-muted">
                                    Área administrativa
                                </small>

                            </a>

                        </div>

                    </div>


                    <!-- ========================================= -->
                    <!-- ERRO -->
                    <!-- ========================================= -->

                    <?php if ($erro !== null): ?>

                        <div
                            class="alert alert-danger"
                            role="alert"
                        >

                            <strong>
                                Atenção:
                            </strong>

                            <?= htmlspecialchars(
                                $erro
                            ); ?>

                        </div>

                    <?php endif; ?>


                    <?php if ($aviso !== null): ?>

                        <div class="alert alert-info" role="status">
                            <?= htmlspecialchars($aviso); ?>
                            <?php if ($linkDemo !== null): ?>
                                <div class="small text-break mt-2"><?= htmlspecialchars($linkDemo) ?></div>
                                <a href="<?= htmlspecialchars($linkDemo) ?>" class="btn btn-sm btn-primary mt-3">Criar minha senha</a>
                            <?php endif; ?>
                        </div>

                    <?php endif; ?>


                    <!-- ========================================= -->
                    <!-- FORMULÁRIO -->
                    <!-- ========================================= -->

                    <form
                        method="POST"
                        action="login.php?tipo=<?= urlencode(
                            $tipoAcesso
                        ); ?>"
                    >

                        <input
                            type="hidden"
                            name="tipo"
                            value="<?= htmlspecialchars(
                                $tipoAcesso
                            ); ?>"
                        >


                        <div class="mb-3">
    <label for="identificador" class="form-label fw-semibold">CPF ou e-mail</label>
    <input
        type="text"
        class="form-control"
        id="identificador"
        data-cpf-ou-email
        name="identificador"
        placeholder="000.000.000-00 ou seu@email.com"
        autocomplete="username"
        required
        value="<?= htmlspecialchars($identificadorInformado) ?>"
    >
    <div class="form-text">Você pode entrar usando o CPF ou o e-mail cadastrado.</div>
</div>

<div class="mb-4">
    <label for="senha" class="form-label fw-semibold">Senha <span class="text-muted small">(em branco no primeiro acesso)</span></label>
    <input
        type="password"
        class="form-control"
        id="senha"
        name="senha"
        placeholder="Digite sua senha"
        autocomplete="current-password"
        
    >
    <div class="form-text">No primeiro acesso, deixe a senha em branco: enviaremos um link para o seu e-mail (ou WhatsApp) cadastrado para você criar sua senha.</div>
</div>


                        <button
                            type="submit"
                            class="btn btn-primary w-100 btn-login"
                        >

                            Entrar no sistema

                        </button>

                    </form>


                    <!-- ========================================= -->
                    <!-- VOLTAR -->
                    <!-- ========================================= -->

                    <div class="text-center mt-4">

                        <a
                            href="esqueci_senha.php"
                            class="text-decoration-none fw-semibold d-block mb-3"
                        >
                            Esqueci minha senha
                        </a>

                        <a
                            href="../index.php"
                            class="text-decoration-none"
                        >
                            ← Voltar para página inicial
                        </a>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>
<script src="../assets/js/script.js"></script>

</body>

</html>