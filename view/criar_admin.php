<?php

require_once "../model/dao/Conexao.php";
require_once "../controller/PermissaoController.php";

PermissaoController::exigirAdministrador();

$mensagem = "";
$erro = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $nome = trim($_POST["nome"] ?? "");
    $cpf = preg_replace('/\D+/', '', trim($_POST["cpf"] ?? ""));
    $email = trim($_POST["email"] ?? "");
    $telefone = trim($_POST["telefone"] ?? "");
    $senha = bin2hex(random_bytes(24));

    if ($nome === "" || $cpf === "" || $email === "" || $senha === "") {

        $erro = "Preencha todos os campos.";

    } elseif (strlen($cpf) !== 11) {

        $erro = "Informe o CPF no padrão 000.000.000-00.";

    } else {

        try {

            $conexao = new Conexao();
            $pdo = $conexao->conectar();

            // Verifica CPF ou e-mail
            $sqlVerifica = "SELECT id FROM usuario
                            WHERE cpf = :cpf
                            OR email = :email";

            $stmtVerifica = $pdo->prepare($sqlVerifica);
            $stmtVerifica->bindValue(":cpf", $cpf);
            $stmtVerifica->bindValue(":email", $email);
            $stmtVerifica->execute();

            if ($stmtVerifica->fetch()) {

                $erro = "Já existe um usuário com este CPF ou e-mail.";

            } else {

                $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

                $sql = "INSERT INTO usuario
                        (nome, cpf, email, telefone, senha_hash, senha_definida, primeiro_acesso, perfil, ativo, termo_lgpd)
                        VALUES
                        (:nome, :cpf, :email, :telefone, :senha_hash, 0, 1,
                         'Administrador', 1, 0)";

                $stmt = $pdo->prepare($sql);

                $stmt->bindValue(":nome", $nome);
                $stmt->bindValue(":cpf", $cpf);
                $stmt->bindValue(":email", $email);
                $stmt->bindValue(":telefone", $telefone);
                $stmt->bindValue(":senha_hash", $senhaHash);

                $stmt->execute();

                $mensagem = "Administrador criado com sucesso!";
            }

        } catch (PDOException $e) {

            $erro = "Erro no banco de dados: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Criar Administrador - Koinon Services</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            background: #f2f2f2;
            margin: 0;
            padding: 0;
        }

        .container {
            width: 400px;
            max-width: 90%;
            margin: 80px auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.15);
        }

        h2 {
            text-align: center;
            margin-bottom: 25px;
        }

        label {
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 10px;
            margin-top: 5px;
            margin-bottom: 15px;
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 5px;
            background: #198754;
            color: white;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background: #157347;
        }

        .sucesso {
            background: #d1e7dd;
            color: #0f5132;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            text-align: center;
        }

        .erro {
            background: #f8d7da;
            color: #842029;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            text-align: center;
        }

        .login {
            text-align: center;
            margin-top: 20px;
        }

        .login a {
            color: #0d6efd;
            text-decoration: none;
        }

    </style>


    <link rel="stylesheet" href="../assets/css/estilo.css">

</head>

<body>

<div class="container">

    <h2>👤 Criar Administrador</h2>

    <?php if ($mensagem !== ""): ?>

        <div class="sucesso">
            <?= htmlspecialchars($mensagem) ?>
        </div>

    <?php endif; ?>

    <?php if ($erro !== ""): ?>

        <div class="erro">
            <?= htmlspecialchars($erro) ?>
        </div>

    <?php endif; ?>

    <form method="POST">

        <label>Nome:</label>

        <input
            type="text"
            name="nome"
            placeholder="Nome completo"
            required
        >

        <label>CPF:</label>

        <input
            type="text"
            name="cpf"
            data-cpf
            inputmode="numeric"
            placeholder="000.000.000-00"
            maxlength="14"
            required
        >

        <label>E-mail:</label>

        <input
            type="email"
            name="email"
            placeholder="admin@koinon.com"
            required
        >

        <label>WhatsApp / telefone:</label>
        <input type="text" name="telefone" placeholder="(61) 99999-9999">

        <div style="margin:10px 0;color:#667085;font-size:14px;">
            A senha será definida pelo administrador no primeiro acesso, após o aceite da LGPD.
        </div>

        <button type="submit">
            Criar Administrador
        </button>

    </form>

    <div class="login">

        <a href="login.php">
            ← Voltar para o Login
        </a>

    </div>

</div>
<script src="../assets/js/script.js"></script>

</body>

</html>