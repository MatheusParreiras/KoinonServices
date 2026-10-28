<?php

require_once __DIR__ . "/../../service/Configuracao.php";
require_once __DIR__ . "/../../service/Erro.php";
require_once __DIR__ . "/../../service/LimiteTentativas.php";
require_once __DIR__ . "/../../service/Csrf.php";
require_once __DIR__ . "/../../service/Migracoes.php";

Teste::caso("Erro: mensagens de validação aparecem como estão", function (): void {
    Teste::igual("Horário indisponível.", Erro::mensagem(new Exception("Horário indisponível.")));
});

Teste::caso("Erro: falhas do banco e do PHP não vão para a tela", function (): void {
    $logAntes = ini_set("error_log", "/dev/null");

    $banco = Erro::mensagem(new PDOException("SQLSTATE[42S22]: Unknown column 'senha_hash'"));
    $php = Erro::mensagem(new TypeError("Argument #1 must be of type int, /var/www/x.php"));

    ini_set("error_log", (string) $logAntes);
    Teste::naoContem("SQLSTATE", $banco);
    Teste::naoContem("/var/www", $php);
});

Teste::caso("Erro: o modo demonstração mostra o detalhe", function (): void {
    $logAntes = ini_set("error_log", "/dev/null");
    putenv("KOINON_MODO_DEMONSTRACAO=1");

    $mensagem = Erro::mensagem(new PDOException("SQLSTATE detalhe"));

    putenv("KOINON_MODO_DEMONSTRACAO");
    ini_set("error_log", (string) $logAntes);
    Teste::contem("SQLSTATE detalhe", $mensagem);
});

Teste::caso("Configuracao: URLs partem da pasta do sistema", function (): void {
    Teste::igual("/koinon-service/view/login.php", Configuracao::url("view/login.php"));
    Teste::igual("/koinon-service/", Configuracao::url());
});

Teste::caso("LimiteTentativas: bloqueia depois do limite e libera ao limpar", function (): void {
    $pasta = sys_get_temp_dir() . "/koinon-limites-" . bin2hex(random_bytes(4));
    $limite = new LimiteTentativas($pasta);

    for ($i = 0; $i < 3; $i++) {
        Teste::igual(0, $limite->bloqueadoPor("conta", 3, 900), "Tentativa $i ainda liberada.");
        $limite->registrar("conta", 900);
    }

    Teste::verdadeiro($limite->bloqueadoPor("conta", 3, 900) > 0, "A quarta tentativa devia ser bloqueada.");
    Teste::igual(0, $limite->bloqueadoPor("outra-conta", 3, 900), "Cada chave tem o seu limite.");

    $limite->limpar("conta");
    Teste::igual(0, $limite->bloqueadoPor("conta", 3, 900));

    array_map("unlink", glob("$pasta/*"));
    rmdir($pasta);
});

Teste::caso("Csrf: o token entra só nos formulários POST", function (): void {
    $html = "<form method=\"get\" action=\"busca.php\"></form>\n"
        . "<form\n    method=\"POST\"\n    enctype=\"multipart/form-data\"\n></form>\n"
        . "<form action='x.php' method=post></form>";

    $saida = Csrf::inserirCampo($html, "abc\"123");

    Teste::igual(2, substr_count($saida, 'name="_csrf"'));
    Teste::contem('<form method="get" action="busca.php"></form>', $saida);
    Teste::contem('value="abc&quot;123"', $saida, "O token é escapado.");
});

Teste::caso("Migracoes: arquivos numerados, em ordem e sem repetição", function (): void {
    $versoes = array_keys(Migracoes::disponiveis());
    $numeros = array_map(static fn (string $v): string => substr($v, 0, 4), $versoes);

    Teste::verdadeiro($versoes !== [], "Nenhuma migração encontrada.");
    Teste::igual($numeros, array_values(array_unique($numeros)), "Números de migração repetidos.");

    $ordenadas = $versoes;
    sort($ordenadas, SORT_STRING);
    Teste::igual($ordenadas, $versoes);
});
