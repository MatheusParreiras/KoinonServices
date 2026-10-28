<?php
require_once __DIR__ . "/../../service/Cpf.php";
require_once __DIR__ . "/../../service/Configuracao.php";
require_once __DIR__ . "/../../service/Migracoes.php";

class Conexao
{
    private static ?PDO $conexao = null;

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

        [
            "host" => $host,
            "porta" => $porta,
            "banco" => $banco,
            "usuario" => $usuario,
            "senha" => $senha,
        ] = Configuracao::banco();

        /*
         * ==========================================================
         * CRIA A CONEXÃO APENAS UMA VEZ
         * ==========================================================
         */

        if (self::$conexao === null) {

            $dsn =
                "mysql:"
                . "host=" . $host
                . ";port=" . $porta
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

                // Migrações pendentes (database/migracoes) e manutenção de dados.
                // Conferidas uma única vez por sessão, e não a cada requisição.
                if (!self::estruturaJaVerificada()) {
                    self::atualizarEstrutura(self::$conexao);
                    self::emitirComprovantesPendentes();
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

    private static function atualizarEstrutura(PDO $pdo): void
    {
        try {
            $aplicadas = Migracoes::aplicarPendentes($pdo);

            if ($aplicadas !== []) {
                error_log("[Koinon] Migrações aplicadas: " . implode(", ", $aplicadas));
            }
        } catch (Throwable $e) {
            self::$conexao = null;

            if (PHP_SAPI === "cli") {
                throw $e;
            }

            error_log("[Koinon] " . $e->getMessage());

            http_response_code(503);
            die(
                "O banco de dados precisa ser atualizado. Rode: php bin/migrar.php"
                . (Configuracao::modoDemonstracao() ? " (" . $e->getMessage() . ")" : "")
            );
        }
    }

    /*
     * Encomendas entregues e cobranças pagas sem comprovante (registros
     * anteriores aos comprovantes) ganham o seu. Não impede o acesso se falhar.
     */
    private static function emitirComprovantesPendentes(): void
    {
        try {
            require_once __DIR__ . "/../../service/ComprovanteDAO.php";
            (new ComprovanteDAO())->garantirHistoricos();
        } catch (Throwable $e) {
            error_log("[Koinon] Comprovantes pendentes não emitidos: " . $e->getMessage());
        }
    }

    private static function estruturaJaVerificada(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE
            && ($_SESSION["_koinon_estrutura"] ?? null) === self::versaoEstrutura();
    }

    private static function marcarEstruturaVerificada(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION["_koinon_estrutura"] = self::versaoEstrutura();
        }
    }

    /* A última migração existente: uma migração nova faz as sessões abertas conferirem de novo. */
    private static function versaoEstrutura(): string
    {
        return (string) array_key_last(Migracoes::disponiveis());
    }

    /**
     * Cria o administrador inicial SOMENTE quando o sistema não possui
     * nenhum administrador (instalação nova), e só no modo demonstração:
     * a conta criada aqui tem CPF e e-mail conhecidos por qualquer um que
     * leia o código. Fora da demonstração o primeiro administrador é
     * criado com bin/criar_admin.php.
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
