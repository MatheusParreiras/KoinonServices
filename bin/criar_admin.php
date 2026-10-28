<?php

/*
 * Cria um administrador pela linha de comando (instalação nova, fora do
 * modo demonstração, quando ainda não há quem entre em criar_admin.php).
 *
 * Uso: php bin/criar_admin.php "Nome Completo" 000.000.000-00 email@dominio.com
 * A senha é pedida no terminal, sem aparecer na tela.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/../model/dao/Conexao.php";

[, $nome, $cpf, $email] = array_pad($argv, 4, "");
$nome = trim($nome);
$cpf = preg_replace('/\D+/', '', $cpf);
$email = strtolower(trim($email));

if ($nome === "" || strlen($cpf) !== 11 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php bin/criar_admin.php \"Nome Completo\" 000.000.000-00 email@dominio.com\n");
    exit(1);
}

function lerSenha(string $pergunta): string
{
    echo $pergunta;
    $eco = stream_isatty(STDIN);
    if ($eco) {
        shell_exec("stty -echo");
    }
    $senha = rtrim((string) fgets(STDIN), "\r\n");
    if ($eco) {
        shell_exec("stty echo");
    }
    echo "\n";

    return $senha;
}

$senha = lerSenha("Senha (mínimo 8 caracteres): ");
if (strlen($senha) < 8 || $senha !== lerSenha("Confirme a senha: ")) {
    fwrite(STDERR, "As senhas não conferem ou têm menos de 8 caracteres.\n");
    exit(1);
}

$pdo = Conexao::conectar();

$existe = $pdo->prepare("SELECT id FROM usuario WHERE cpf = :cpf OR LOWER(email) = :email");
$existe->execute([":cpf" => $cpf, ":email" => $email]);
if ($existe->fetch()) {
    fwrite(STDERR, "Já existe um usuário com este CPF ou e-mail.\n");
    exit(1);
}

$pdo->prepare(
    "INSERT INTO usuario
     (unidade_id, nome, cpf, email, senha_hash, senha_definida, primeiro_acesso, perfil, termo_lgpd, ativo)
     VALUES (NULL, :nome, :cpf, :email, :hash, 1, 0, 'Administrador', 0, 1)"
)->execute([
    ":nome" => $nome,
    ":cpf" => $cpf,
    ":email" => $email,
    ":hash" => password_hash($senha, PASSWORD_DEFAULT),
]);

echo "Administrador criado. No primeiro login ele aceita o termo da LGPD.\n";
