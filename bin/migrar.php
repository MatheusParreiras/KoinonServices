<?php

/*
 * Aplica as migrações pendentes do banco (database/migracoes).
 *
 * Uso: php bin/migrar.php            aplica as pendentes
 *      php bin/migrar.php --status   só lista aplicadas e pendentes
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/../model/dao/Conexao.php";

try {
    if (in_array("--status", $argv, true)) {
        // Conexao::conectar() aplicaria as pendentes; o status usa um PDO próprio.
        $c = Configuracao::banco();
        $pdo = new PDO(
            "mysql:host={$c["host"]};port={$c["porta"]};dbname={$c["banco"]};charset=utf8mb4",
            $c["usuario"],
            $c["senha"],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $aplicadas = Migracoes::aplicadas($pdo);
        foreach (array_keys(Migracoes::disponiveis()) as $versao) {
            echo (in_array($versao, $aplicadas, true) ? "[aplicada] " : "[pendente] "), $versao, "\n";
        }
        exit(0);
    }

    // conectar() já aplica as pendentes; a segunda chamada confirma que não sobrou nenhuma.
    $pdo = Conexao::conectar();
    Migracoes::aplicarPendentes($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "Banco atualizado. Migrações: " . implode(", ", Migracoes::aplicadas($pdo)) . "\n";
