<?php

/*
 * Pagamentos simulados: forma de pagamento e código da transação em
 * cobranca, e o status "Aguardando_Pagamento" na reserva.
 */
return static function (PDO $pdo): void {
    $cobranca = [
        "forma_pagamento" => "ALTER TABLE cobranca ADD COLUMN forma_pagamento VARCHAR(20) NULL AFTER data_pagamento",
        "codigo_transacao" => "ALTER TABLE cobranca ADD COLUMN codigo_transacao VARCHAR(40) NULL AFTER forma_pagamento",
    ];

    foreach ($cobranca as $coluna => $sql) {
        if (!Migracoes::colunaExiste($pdo, "cobranca", $coluna)) {
            $pdo->exec($sql);
        }
    }

    $restricoes = $pdo->query(
        "SELECT CONSTRAINT_NAME, CHECK_CLAUSE
         FROM information_schema.CHECK_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'reserva'"
    )->fetchAll(PDO::FETCH_ASSOC);

    $antigas = [];
    foreach ($restricoes as $c) {
        $clausula = (string) $c["CHECK_CLAUSE"];
        if (stripos($clausula, "Aguardando_Pagamento") !== false) {
            return;
        }
        if (stripos($clausula, "status") !== false) {
            $antigas[] = $c["CONSTRAINT_NAME"];
        }
    }

    foreach ($antigas as $nome) {
        $nome = str_replace("`", "``", $nome);
        try {
            $pdo->exec("ALTER TABLE reserva DROP CONSTRAINT `$nome`");
        } catch (PDOException $e) {
            $pdo->exec("ALTER TABLE reserva DROP CHECK `$nome`"); // MySQL 8.0.16+
        }
    }

    $pdo->exec(
        "ALTER TABLE reserva ADD CONSTRAINT chk_reserva_status
         CHECK (status IN ('Pendente','Aguardando_Pagamento','Confirmada','Cancelada','Concluida'))"
    );
};
