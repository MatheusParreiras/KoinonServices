<?php

/*
 * Tabela de comprovantes (encomenda retirada, cobrança paga).
 *
 * As chaves estrangeiras usam ON DELETE SET NULL: antes eram RESTRICT e
 * não era possível apagar uma encomenda/cobrança que já tivesse
 * comprovante (erro #1451 no phpMyAdmin). O comprovante guarda uma cópia
 * dos dados em dados_json, então continua válido.
 */
return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS comprovante (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        numero_comprovante VARCHAR(40) NOT NULL,
        tipo VARCHAR(40) NOT NULL,
        encomenda_id BIGINT(20) DEFAULT NULL,
        cobranca_id BIGINT(20) DEFAULT NULL,
        unidade_id BIGINT(20) DEFAULT NULL,
        usuario_id BIGINT(20) DEFAULT NULL,
        data_emissao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        dados_json LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_comprovante_numero (numero_comprovante),
        UNIQUE KEY uq_comprovante_encomenda (encomenda_id),
        UNIQUE KEY uq_comprovante_cobranca (cobranca_id),
        KEY idx_comprovante_unidade (unidade_id),
        KEY idx_comprovante_usuario (usuario_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $fks = [
        "fk_comprovante_encomenda" => ["encomenda_id", "encomenda"],
        "fk_comprovante_cobranca" => ["cobranca_id", "cobranca"],
        "fk_comprovante_unidade" => ["unidade_id", "unidade"],
        "fk_comprovante_usuario" => ["usuario_id", "usuario"],
    ];

    foreach ($fks as $nome => [$coluna, $tabelaPai]) {
        $stmt = $pdo->prepare(
            "SELECT DELETE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'comprovante' AND CONSTRAINT_NAME = :nome"
        );
        $stmt->execute([":nome" => $nome]);
        $regra = $stmt->fetchColumn();

        if ($regra === "SET NULL") {
            continue;
        }

        try {
            if ($regra !== false) {
                $pdo->exec("ALTER TABLE comprovante DROP FOREIGN KEY $nome");
            }
            $pdo->exec(
                "ALTER TABLE comprovante ADD CONSTRAINT $nome
                 FOREIGN KEY ($coluna) REFERENCES $tabelaPai(id) ON DELETE SET NULL"
            );
        } catch (PDOException $e) {
            // Bancos antigos podem ter comprovantes apontando para registros
            // já apagados; o sistema funciona sem a chave, então só registra.
            error_log("[Koinon] Migração 0002: chave $nome não criada: " . $e->getMessage());
        }
    }
};
