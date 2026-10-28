<?php

require_once __DIR__ . "/../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

require_once __DIR__ . "/../model/dao/Conexao.php";
require_once __DIR__ . "/../service/PasswordLinkService.php";
require_once __DIR__ . "/../service/LimiteTentativas.php";

$erro = null;
$sucesso = null;
$linkLocal = null;
$linkWhatsApp = null;

$diretorioTokens = __DIR__ . "/../storage/password_resets";
if (!is_dir($diretorioTokens)) {
    @mkdir($diretorioTokens, 0700, true);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $cpf = preg_replace('/\D+/', '', trim($_POST["cpf"] ?? ""));
    $canal = strtolower(trim($_POST["canal"] ?? "email"));

    // Até 3 pedidos por CPF e 10 por IP a cada 15 minutos (evita encher a caixa de alguém de links).
    $limite = new LimiteTentativas();
    $janela = 15 * 60;
    $espera = max(
        $limite->bloqueadoPor("senha-cpf:" . $cpf, 3, $janela),
        $limite->bloqueadoPor("senha-ip:" . LimiteTentativas::ip(), 10, $janela)
    );

    if ($cpf === "") {
        $erro = "Informe o CPF cadastrado.";
    } elseif (!in_array($canal, ["email", "whatsapp"], true)) {
        $erro = "Selecione um canal válido.";
    } elseif ($espera > 0) {
        $erro = "Muitas solicitações. Aguarde " . LimiteTentativas::minutos($espera) . " minuto(s) e tente novamente.";
    } else {
        $limite->registrar("senha-cpf:" . $cpf, $janela);
        $limite->registrar("senha-ip:" . LimiteTentativas::ip(), $janela);

        try {
            $pdo = Conexao::conectar();
            $stmt = $pdo->prepare(
                "SELECT id, nome, email, telefone, ativo
                 FROM usuario
                 WHERE cpf = :cpf LIMIT 1"
            );
            $stmt->bindValue(":cpf", $cpf, PDO::PARAM_STR);
            $stmt->execute();
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            /*
             * A resposta é a mesma exista ou não o CPF, e o link só vai
             * para o canal cadastrado: quem sabe apenas o CPF de alguém
             * não descobre se ele tem conta nem recebe o link.
             */
            $sucesso = $canal === "email"
                ? "Se o CPF estiver cadastrado, enviamos o link seguro para o e-mail cadastrado."
                : "Se o CPF estiver cadastrado, enviamos o link seguro para o WhatsApp cadastrado.";

            if ($usuario && (int)$usuario["ativo"] === 1) {
                $envio = (new PasswordLinkService())->enviarLinkSenha($usuario, $canal);

                // Modo demonstração: o envio falhou e o link aparece abaixo.
                if ($envio["link_demo"] !== null) {
                    $sucesso = "Modo demonstração: o envio não foi concluído, então o link está disponível abaixo.";
                    $linkLocal = $envio["link_demo"];
                    $linkWhatsApp = $envio["whatsapp_demo"];
                }
            }
        } catch (Throwable $e) {
            $erro = "Não foi possível processar a recuperação. Verifique a conexão com o banco de dados.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recuperar senha - Koinon Services</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/estilo.css">
</head>
<body class="koinon-auth-page">
<div class="container py-5">
    <div class="card koinon-auth-card mx-auto">
        <div class="card-body p-4 p-md-5">
            <span class="koinon-eyebrow">Acesso seguro</span>
            <h1 class="h3 fw-bold mt-2">Recuperar senha</h1>
            <p class="text-muted">Informe seu CPF e escolha o canal cadastrado para receber o link seguro.</p>

            <?php if ($erro): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
            <?php endif; ?>
            <?php if ($sucesso): ?>
                <div class="alert alert-success"><?= htmlspecialchars($sucesso) ?></div>
            <?php endif; ?>

            <?php if ($linkLocal): ?>
                <div class="alert alert-warning">
                    <strong>Link (modo demonstração):</strong>
                    <div class="small text-break mt-2"><?= htmlspecialchars($linkLocal) ?></div>
                    <a href="<?= htmlspecialchars($linkLocal) ?>" class="btn btn-sm btn-warning mt-3">Abrir definição de senha</a>
                </div>
            <?php endif; ?>

            <?php if ($linkWhatsApp): ?>
                <a href="<?= htmlspecialchars($linkWhatsApp) ?>" target="_blank" rel="noopener" class="btn btn-success w-100 mb-3">
                    Abrir WhatsApp e enviar link
                </a>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="cpf">CPF</label>
                    <input type="text" class="form-control form-control-lg" id="cpf" name="cpf"
                           maxlength="14" placeholder="000.000.000-00" required>
                    <div class="form-text">O CPF é o único documento utilizado pelo Koinon Services para identificação do usuário.</div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Receber link por</label>
                    <div class="d-grid gap-2">
                        <label class="border rounded-3 p-3">
                            <input type="radio" name="canal" value="email" checked>
                            <strong class="ms-2">E-mail cadastrado</strong>
                        </label>
                        <label class="border rounded-3 p-3">
                            <input type="radio" name="canal" value="whatsapp">
                            <strong class="ms-2">WhatsApp cadastrado</strong>
                        </label>
                    </div>
                </div>

                <button class="btn btn-primary btn-lg w-100">Enviar link seguro</button>
            </form>

            <div class="text-center mt-4">
                <a href="login.php" class="text-decoration-none">← Voltar para o login</a>
            </div>
        </div>
    </div>
</div>
<script src="../assets/js/script.js"></script>
</body>
</html>
