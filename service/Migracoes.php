<?php

/*
 * Migrações do banco (database/migracoes/NNNN_nome.php).
 *
 * Cada arquivo devolve uma função que recebe o PDO e altera a estrutura.
 * A tabela schema_migracao registra as já aplicadas: cada migração roda
 * uma única vez, em ordem, e uma falha interrompe o processo (e aparece
 * no log) em vez de ser ignorada.
 *
 * Aplicadas automaticamente no primeiro acesso de cada sessão (Conexao)
 * ou à mão com:  php bin/migrar.php
 */
class Migracoes
{
    private const PASTA = __DIR__ . "/../database/migracoes";

    /** @return array<string, string> versão => caminho, em ordem */
    public static function disponiveis(): array
    {
        $arquivos = glob(self::PASTA . "/[0-9][0-9][0-9][0-9]_*.php") ?: [];
        sort($arquivos, SORT_STRING);

        $lista = [];
        foreach ($arquivos as $arquivo) {
            $lista[basename($arquivo, ".php")] = $arquivo;
        }

        return $lista;
    }

    /** @return list<string> */
    public static function aplicadas(PDO $pdo): array
    {
        self::criarTabelaDeControle($pdo);

        return $pdo->query("SELECT versao FROM schema_migracao ORDER BY versao")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<string> */
    public static function pendentes(PDO $pdo): array
    {
        return array_values(array_diff(array_keys(self::disponiveis()), self::aplicadas($pdo)));
    }

    /**
     * Aplica as pendentes e devolve as versões aplicadas agora.
     * Um bloqueio no banco impede duas requisições de migrarem ao mesmo tempo.
     *
     * @return list<string>
     */
    public static function aplicarPendentes(PDO $pdo): array
    {
        if (self::pendentes($pdo) === []) {
            return [];
        }

        if ((int) $pdo->query("SELECT GET_LOCK('koinon_migracoes', 30)")->fetchColumn() !== 1) {
            throw new RuntimeException("Outra atualização do banco está em andamento.");
        }

        $aplicadasAgora = [];

        try {
            $disponiveis = self::disponiveis();

            foreach (self::pendentes($pdo) as $versao) {
                $migracao = require $disponiveis[$versao];

                try {
                    $migracao($pdo);
                } catch (Throwable $e) {
                    throw new RuntimeException("Migração $versao falhou: " . $e->getMessage(), 0, $e);
                }

                $pdo->prepare("INSERT INTO schema_migracao (versao) VALUES (:v)")
                    ->execute([":v" => $versao]);
                $aplicadasAgora[] = $versao;
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('koinon_migracoes')");
        }

        return $aplicadasAgora;
    }

    public static function colunaExiste(PDO $pdo, string $tabela, string $coluna): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabela AND COLUMN_NAME = :coluna"
        );
        $stmt->execute([":tabela" => $tabela, ":coluna" => $coluna]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function criarTabelaDeControle(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migracao (
                versao VARCHAR(100) NOT NULL,
                aplicada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (versao)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
