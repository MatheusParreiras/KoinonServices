<?php

/*
 * Todas as tabelas em utf8mb4_general_ci. Tabelas importadas sem
 * collation herdam o padrão do servidor (utf8mb4_uca1400_ai_ci no
 * MariaDB 11.x, utf8mb4_0900_ai_ci no MySQL 8), e comparar texto entre
 * elas falha com "Illegal mix of collations".
 */
return static function (PDO $pdo): void {
    $tabelas = $pdo->query(
        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_TYPE = 'BASE TABLE'
           AND TABLE_COLLATION LIKE 'utf8mb4%'
           AND TABLE_COLLATION <> 'utf8mb4_general_ci'"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tabelas as $tabela) {
        $pdo->exec(
            "ALTER TABLE `" . str_replace("`", "``", $tabela) . "`
             CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
        );
    }
};
