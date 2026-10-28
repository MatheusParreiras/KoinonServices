<?php
require_once __DIR__ . "/../../service/Cpf.php";
require_once __DIR__ . "/../../service/Configuracao.php";

class Conexao
{
    private static ?PDO $conexao = null;

    // Mude este valor sempre que a estrutura complementar mudar,
    // para que as sessões em andamento refaçam a verificação.
    private const VERSAO_ESTRUTURA = "2026.10-v5";

    public static function conectar(): PDO
    {
        /*
         * ==========================================================
         * FUSO HORÁRIO DO SISTEMA
         * ==========================================================
         *
         * Brasília = America/Sao_Paulo
         * UTC-3
         */
        date_default_timezone_set("America/Sao_Paulo");

        /*
         * ==========================================================
         * DADOS DO BANCO
         * ==========================================================
         */

        $host = "127.0.0.1";
        $banco = "koinon_service";
        $usuario = "root";
        $senha = "";

        /*
         * ==========================================================
         * CRIA A CONEXÃO APENAS UMA VEZ
         * ==========================================================
         */

        if (self::$conexao === null) {

            $dsn =
                "mysql:"
                . "host=" . $host
                . ";dbname=" . $banco
                . ";charset=utf8mb4";

            try {

                self::$conexao = new PDO(
                    $dsn,
                    $usuario,
                    $senha,
                    [
                        PDO::ATTR_ERRMODE =>
                            PDO::ERRMODE_EXCEPTION,

                        PDO::ATTR_DEFAULT_FETCH_MODE =>
                            PDO::FETCH_ASSOC,

                        PDO::ATTR_EMULATE_PREPARES =>
                            false
                    ]
                );

                /*
                 * ==================================================
                 * CONFIGURA O MYSQL PARA O HORÁRIO DE BRASÍLIA
                 * ==================================================
                 *
                 * Isso é importante porque o banco utiliza
                 * CURRENT_TIMESTAMP e NOW().
                 */

                self::$conexao->exec(
                    "SET time_zone = '-03:00'"
                );

                // Garante a estrutura complementar necessária ao TCC sem apagar dados existentes.
                // As verificações rodam uma única vez por sessão (e não a cada requisição),
                // para não pesar o sistema nem repetir ALTER TABLE desnecessários.
                if (!self::estruturaJaVerificada()) {
                    self::garantirCollation(self::$conexao);
                    self::garantirEstruturaTcc(self::$conexao);
                    self::garantirComprovantes(self::$conexao);
                    self::garantirPagamentos(self::$conexao);
                    self::garantirAdministradorInicial(self::$conexao);
                    self::marcarEstruturaVerificada();
                }

            } catch (PDOException $e) {

                error_log("[Koinon] Falha ao conectar ao banco: " . $e->getMessage());

                http_response_code(503);
                die(
                    "Erro ao conectar ao banco de dados."
                    . (Configuracao::modoDemonstracao() ? " " . $e->getMessage() : "")
                );
            }
        }

        return self::$conexao;
    }

    /**
     * Pagamentos simulados: dois atributos novos em cobranca (sem tabela nova)
     * e o status "Aguardando_Pagamento" na reserva.
     */
    private static function garantirPagamentos(PDO $pdo): void
    {
        $colunas = [
            "forma_pagamento"  => "ALTER TABLE cobranca ADD COLUMN forma_pagamento VARCHAR(20) NULL AFTER data_pagamento",
            "codigo_transacao" => "ALTER TABLE cobranca ADD COLUMN codigo_transacao VARCHAR(40) NULL AFTER forma_pagamento",
        ];

        foreach ($colunas as $coluna => $sql) {
            try {
                if (!self::colunaExiste($pdo, "cobranca", $coluna)) {
                    $pdo->exec($sql);
                }
            } catch (Throwable $e) {
            }
        }

        try {
            $st = $pdo->query(
                "SELECT CONSTRAINT_NAME, CHECK_CLAUSE
                 FROM information_schema.CHECK_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'reserva'"
            );

            $jaTem = false;
            $antigas = [];

            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $clausula = (string) $c["CHECK_CLAUSE"];
                if (stripos($clausula, "Aguardando_Pagamento") !== false) {
                    $jaTem = true;
                } elseif (stripos($clausula, "status") !== false) {
                    $antigas[] = $c["CONSTRAINT_NAME"];
                }
            }

            if ($jaTem) {
                return;
            }

            foreach ($antigas as $nome) {
                try {
                    $pdo->exec("ALTER TABLE reserva DROP CONSTRAINT `$nome`");
                } catch (Throwable $e) {
                    try {
                        $pdo->exec("ALTER TABLE reserva DROP CHECK `$nome`");
                    } catch (Throwable $e2) {
                    }
                }
            }

            $pdo->exec(
                "ALTER TABLE reserva ADD CONSTRAINT chk_reserva_status
                 CHECK (status IN ('Pendente','Aguardando_Pagamento','Confirmada','Cancelada','Concluida'))"
            );
        } catch (Throwable $e) {
        }
    }

    private static function estruturaJaVerificada(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE
            && ($_SESSION["_koinon_estrutura"] ?? null) === self::VERSAO_ESTRUTURA;
    }

    private static function marcarEstruturaVerificada(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION["_koinon_estrutura"] = self::VERSAO_ESTRUTURA;
        }
    }

    /*
     * Todas as tabelas usam utf8mb4_general_ci. Tabelas importadas sem
     * collation herdam o padrão do servidor (utf8mb4_uca1400_ai_ci no
     * MariaDB 11.x, utf8mb4_0900_ai_ci no MySQL 8), e comparar texto entre
     * elas falha com "Illegal mix of collations". Converte as que ficaram
     * diferentes.
     */
    private static function garantirCollation(PDO $pdo): void
    {
        try {
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
        } catch (Throwable $e) {
            error_log("[Koinon] Não foi possível ajustar a collation: " . $e->getMessage());
        }
    }

    private static function colunaExiste(PDO $pdo, string $tabela, string $coluna): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabela AND COLUMN_NAME = :coluna"
        );
        $stmt->execute([
            ':tabela' => $tabela,
            ':coluna' => $coluna,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function garantirEstruturaTcc(PDO $pdo): void
    {
        $alteracoes = [
            'telefone' => "ALTER TABLE usuario ADD COLUMN telefone VARCHAR(20) NULL AFTER email",
            'senha_definida' => "ALTER TABLE usuario ADD COLUMN senha_definida TINYINT(1) NOT NULL DEFAULT 1 AFTER senha_hash",
            'primeiro_acesso' => "ALTER TABLE usuario ADD COLUMN primeiro_acesso TINYINT(1) NOT NULL DEFAULT 0 AFTER senha_definida",
            'termo_lgpd' => "ALTER TABLE usuario ADD COLUMN termo_lgpd TINYINT(1) NOT NULL DEFAULT 0 AFTER primeiro_acesso",
            'data_aceite_lgpd' => "ALTER TABLE usuario ADD COLUMN data_aceite_lgpd DATETIME NULL AFTER termo_lgpd",
        ];

        foreach ($alteracoes as $coluna => $sql) {
            if (!self::colunaExiste($pdo, 'usuario', $coluna)) {
                $pdo->exec($sql);
            }
        }

        // Andamento (histórico) das ocorrências, visível ao morador.
        // Guardado em JSON na própria tabela: não cria tabela nova.
        if (!self::colunaExiste($pdo, 'ocorrencia', 'historico')) {
            $pdo->exec("ALTER TABLE ocorrencia ADD COLUMN historico LONGTEXT NULL AFTER data_fechamento");
        }
    }

    private static function garantirComprovantes(PDO $pdo): void
    {
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

        // Em bancos antigos a tabela é criada sem as FKs do dump;
        // tentamos adicioná-las de forma idempotente.
        $fks = [
            "fk_comprovante_encomenda" => ["encomenda_id", "encomenda"],
            "fk_comprovante_cobranca"  => ["cobranca_id",  "cobranca"],
            "fk_comprovante_unidade"   => ["unidade_id",   "unidade"],
            "fk_comprovante_usuario"   => ["usuario_id",   "usuario"],
        ];
        foreach ($fks as $nome => [$coluna, $tabelaPai]) {
            self::garantirFkSetNull($pdo, "comprovante", $nome, $coluna, $tabelaPai);
        }

        require_once __DIR__ . "/../../service/ComprovanteDAO.php";
        $dao = new ComprovanteDAO();
        $dao->garantirHistoricos();
    }

    /**
     * Garante FOREIGN KEY com ON DELETE SET NULL.
     *
     * Antes as FKs de encomenda/cobrança eram RESTRICT: não era possível
     * apagar uma encomenda/cobrança que já tivesse comprovante (erro #1451
     * no phpMyAdmin). Com SET NULL o registro pode ser apagado e o
     * comprovante emitido é preservado (ele guarda uma cópia dos dados em
     * dados_json), sem quebrar a integridade do banco.
     */
    private static function garantirFkSetNull(
        PDO $pdo,
        string $tabela,
        string $nome,
        string $coluna,
        string $tabelaPai
    ): void {
        try {
            $stmt = $pdo->prepare(
                "SELECT DELETE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = :tabela
                   AND CONSTRAINT_NAME = :nome"
            );
            $stmt->execute([":tabela" => $tabela, ":nome" => $nome]);
            $regra = $stmt->fetchColumn();

            if ($regra === "SET NULL") {
                return;
            }

            if ($regra !== false) {
                $pdo->exec("ALTER TABLE $tabela DROP FOREIGN KEY $nome");
            }

            $pdo->exec(
                "ALTER TABLE $tabela ADD CONSTRAINT $nome
                 FOREIGN KEY ($coluna) REFERENCES $tabelaPai(id) ON DELETE SET NULL"
            );
        } catch (Throwable $e) {
            // Não impede o sistema de abrir caso o banco não permita a alteração.
        }
    }

    /**
     * Cria o administrador inicial SOMENTE quando o sistema não possui
     * nenhum administrador (instalação nova). Antes, ele era recriado a
     * cada acesso caso o e-mail admin@koinon.com não existisse, e por isso
     * "voltava" para a lista toda vez que era apagado.
     */
    /*
     * Só no modo demonstração. A conta criada aqui tem CPF e e-mail
     * conhecidos por qualquer um que leia o código; fora da demonstração
     * o primeiro administrador é criado com bin/criar_admin.php.
     */
    private static function garantirAdministradorInicial(PDO $pdo): void
    {
        if (!Configuracao::modoDemonstracao()) {
            return;
        }

        $existe = (int) $pdo->query(
            "SELECT COUNT(*) FROM usuario WHERE perfil = 'Administrador'"
        )->fetchColumn();

        if ($existe > 0) {
            return;
        }

        $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
        $insert = $pdo->prepare(
            "INSERT INTO usuario
             (unidade_id, nome, cpf, email, telefone, senha_hash, senha_definida, primeiro_acesso, perfil, termo_lgpd, data_aceite_lgpd, ativo)
             VALUES (NULL, 'Administrador', '01234567890', 'admin@koinon.com', NULL, :hash, 0, 1, 'Administrador', 0, NULL, 1)"
        );
        $insert->execute([':hash' => $hash]);
    }
}
