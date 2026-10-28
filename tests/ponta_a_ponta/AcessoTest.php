<?php

require_once __DIR__ . "/../../service/Migracoes.php";

const CPF_MORADOR = "78945612300";   // usuário 22, unidade 5 (koinon_service.sql)
const CPF_PORTEIRO = "78945612301";  // usuário 23
const EMAIL_SINDICO = "TESTESINDICO@GMAIL.COM";

function semErroDePhp(array $resposta, string $pagina): void
{
    foreach (["Fatal error", "Warning:", "Notice:", "Deprecated:", "Uncaught"] as $erro) {
        Teste::naoContem($erro, $resposta["corpo"], "$pagina:");
    }
}

Teste::caso("Primeiro acesso sem senha não entra na conta", function (): void {
    $pdo = Navegador::pdo();
    $pdo->exec("UPDATE usuario SET senha_definida = 0, primeiro_acesso = 1 WHERE id = 22");

    try {
        $navegador = new Navegador();
        $resposta = $navegador->entrar(CPF_MORADOR, "morador", "");

        Teste::igual(200, $resposta["status"], "Não pode redirecionar para dentro do sistema.");
        Teste::naoContem("token=", $resposta["corpo"], "O link de senha não aparece na tela.");
        Teste::igual(302, $navegador->get("view/morador/index.php")["status"], "A área do morador continua fechada.");
    } finally {
        $pdo->exec("UPDATE usuario SET senha_definida = 1, primeiro_acesso = 0 WHERE id = 22");
    }
});

Teste::caso("Conta inexistente e senha errada têm a mesma resposta", function (): void {
    $navegador = new Navegador();
    $inexistente = $navegador->entrar("11122233344", "morador", "qualquer-coisa")["corpo"];
    $senhaErrada = $navegador->entrar(CPF_MORADOR, "morador", "senha-errada")["corpo"];

    Teste::contem("CPF/e-mail ou senha inválidos", $inexistente);
    Teste::contem("CPF/e-mail ou senha inválidos", $senhaErrada);
    Teste::naoContem("não encontrado", $inexistente);
});

Teste::caso("A sexta senha errada seguida bloqueia a conta", function (): void {
    $navegador = new Navegador();
    for ($i = 1; $i <= 5; $i++) {
        $navegador->entrar(CPF_PORTEIRO, "funcionario", "errada-$i");
    }

    $resposta = $navegador->entrar(CPF_PORTEIRO, "funcionario", Navegador::SENHA);

    Teste::contem("Muitas tentativas", $resposta["corpo"], "Nem a senha certa entra durante o bloqueio.");
});

Teste::caso("POST sem token CSRF é recusado", function (): void {
    $navegador = new Navegador();
    $navegador->get("view/login.php");

    $resposta = $navegador->post("view/login.php", ["identificador" => CPF_MORADOR, "senha" => Navegador::SENHA], false);

    Teste::igual(403, $resposta["status"]);
});

Teste::caso("Esqueci minha senha: mesma resposta e nenhum link na tela", function (): void {
    foreach (["99988877766", CPF_MORADOR] as $cpf) {
        foreach (["email", "whatsapp"] as $canal) {
            $navegador = new Navegador();
            $navegador->get("view/esqueci_senha.php");
            $corpo = $navegador->post("view/esqueci_senha.php", ["cpf" => $cpf, "canal" => $canal])["corpo"];

            Teste::contem("Se o CPF estiver cadastrado", $corpo, "CPF $cpf, $canal:");
            Teste::naoContem("token=", $corpo, "CPF $cpf, $canal:");
            Teste::naoContem("wa.me", $corpo, "CPF $cpf, $canal:");
        }
    }
});

Teste::caso("Fora do modo demonstração não é criado o administrador padrão", function (): void {
    (new Navegador())->get("view/login.php");

    $existe = Navegador::pdo()->query("SELECT COUNT(*) FROM usuario WHERE email = 'admin@koinon.com'")->fetchColumn();

    Teste::igual(0, (int) $existe);
});

Teste::caso("Morador só abre as cobranças da própria unidade", function (): void {
    $pdo = Navegador::pdo();
    $pdo->exec(
        "INSERT INTO cobranca (id, unidade_id, mes_referencia, valor, vencimento) VALUES
         (9001, 3, '2026-10', 450.00, '2026-11-10'),
         (9002, 5, '2026-10', 450.00, '2026-11-10')"
    );

    $navegador = new Navegador();
    $navegador->entrar(CPF_MORADOR);

    $outraUnidade = $navegador->get("view/morador/boleto.php?id=9001");
    $propria = $navegador->get("view/morador/boleto.php?id=9002");

    Teste::igual(302, $outraUnidade["status"], "Cobrança da unidade 3.");
    Teste::contem("financeiro.php", $outraUnidade["location"]);
    Teste::igual(200, $propria["status"], "Cobrança da própria unidade.");
});

Teste::caso("Páginas do morador abrem sem erro", function (): void {
    $navegador = new Navegador();
    Teste::contem("morador/index.php", $navegador->entrar(CPF_MORADOR)["location"]);

    foreach (glob(__DIR__ . "/../../view/morador/*.php") as $arquivo) {
        $pagina = "view/morador/" . basename($arquivo);
        if (in_array(basename($arquivo), ["cancelar_reserva.php", "cancelar_autorizacao.php", "comprovante.php"], true)) {
            continue; // ações e PDF: precisam de id
        }
        $resposta = $navegador->get($pagina);
        Teste::verdadeiro(in_array($resposta["status"], [200, 302], true), "$pagina respondeu {$resposta["status"]}.");
        semErroDePhp($resposta, $pagina);
    }
});

Teste::caso("Telas da gestão abrem sem erro (síndico)", function (): void {
    $navegador = new Navegador();
    Teste::contem("dashboard.php", $navegador->entrar(EMAIL_SINDICO, "funcionario")["location"]);

    $paginas = array_merge(["view/dashboard.php", "view/notificacoes.php"], array_map(
        static fn (string $a): string => "view/" . basename(dirname($a)) . "/index.php",
        glob(__DIR__ . "/../../view/*/index.php")
    ));

    foreach ($paginas as $pagina) {
        $resposta = $navegador->get($pagina);
        Teste::verdadeiro(in_array($resposta["status"], [200, 302], true), "$pagina respondeu {$resposta["status"]}.");
        semErroDePhp($resposta, $pagina);
    }
});

Teste::caso("Todas as migrações ficam registradas no banco", function (): void {
    (new Navegador())->get("view/login.php");

    Teste::igual(array_keys(Migracoes::disponiveis()), Migracoes::aplicadas(Navegador::pdo()));
});
