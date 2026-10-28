<?php

/*
 * Testes automatizados (sem dependências: só PHP com curl e pdo_mysql).
 *
 *   php tests/rodar.php
 *       Testes de unidade (não usam banco).
 *
 *   KOINON_TESTE_CONFIG=/caminho/config_teste.php php tests/rodar.php
 *       Também os testes de ponta a ponta: sobe o servidor embutido do PHP
 *       e usa o sistema como um navegador. O arquivo tem o mesmo formato de
 *       config_canais.php e aponta para um banco DESCARTÁVEL, cujo nome
 *       precisa terminar em "_teste": ele é apagado e recriado a partir de
 *       koinon_service.sql a cada execução.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/Teste.php";

// Os testes de unidade não leem o config_canais.php da máquina: valem os padrões.
putenv("KOINON_CONFIG=" . __DIR__ . "/nao-existe.php");
putenv("KOINON_MODO_DEMONSTRACAO");

foreach (glob(__DIR__ . "/unidade/*Test.php") as $arquivo) {
    require $arquivo;
}

$configTeste = getenv("KOINON_TESTE_CONFIG");

if ($configTeste) {
    require_once __DIR__ . "/Navegador.php";

    if (!Navegador::prepararAmbiente($configTeste)) {
        exit(1);
    }

    foreach (glob(__DIR__ . "/ponta_a_ponta/*Test.php") as $arquivo) {
        require $arquivo;
    }
} else {
    echo "(Testes de ponta a ponta ignorados: defina KOINON_TESTE_CONFIG.)\n";
}

exit(Teste::rodar() ? 0 : 1);
