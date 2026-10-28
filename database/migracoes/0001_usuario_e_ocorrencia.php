<?php

/*
 * Colunas de primeiro acesso, telefone e LGPD em usuario, e o histórico
 * (andamento, em JSON) das ocorrências. Bancos importados do dump atual
 * já as têm; bancos antigos ganham as que faltam.
 */
return static function (PDO $pdo): void {
    $usuario = [
        "telefone" => "ALTER TABLE usuario ADD COLUMN telefone VARCHAR(20) NULL AFTER email",
        "senha_definida" => "ALTER TABLE usuario ADD COLUMN senha_definida TINYINT(1) NOT NULL DEFAULT 1 AFTER senha_hash",
        "primeiro_acesso" => "ALTER TABLE usuario ADD COLUMN primeiro_acesso TINYINT(1) NOT NULL DEFAULT 0 AFTER senha_definida",
        "termo_lgpd" => "ALTER TABLE usuario ADD COLUMN termo_lgpd TINYINT(1) NOT NULL DEFAULT 0 AFTER primeiro_acesso",
        "data_aceite_lgpd" => "ALTER TABLE usuario ADD COLUMN data_aceite_lgpd DATETIME NULL AFTER termo_lgpd",
    ];

    foreach ($usuario as $coluna => $sql) {
        if (!Migracoes::colunaExiste($pdo, "usuario", $coluna)) {
            $pdo->exec($sql);
        }
    }

    if (!Migracoes::colunaExiste($pdo, "ocorrencia", "historico")) {
        $pdo->exec("ALTER TABLE ocorrencia ADD COLUMN historico LONGTEXT NULL AFTER data_fechamento");
    }
};
