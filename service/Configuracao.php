<?php

/*
 * Configuração local do sistema, lida de config_canais.php
 * (arquivo fora do Git; modelo em config_canais.exemplo.php).
 */
class Configuracao
{
    private static ?array $dados = null;

    public static function todas(): array
    {
        if (self::$dados === null) {
            // KOINON_CONFIG aponta outro arquivo (testes, servidores com mais de uma instalação).
            $caminho = getenv("KOINON_CONFIG") ?: __DIR__ . "/../config_canais.php";
            self::$dados = is_file($caminho)
                ? (array) require $caminho
                : [];
        }

        return self::$dados;
    }

    /*
     * Caminho do sistema no servidor, com barra no início e no fim
     * ("/koinon-service/", ou "/" quando está na raiz do domínio).
     * Vem de "base_path" em config_canais.php; sem ele, é deduzido da
     * pasta do projeto dentro do DOCUMENT_ROOT.
     */
    public static function basePath(): string
    {
        $configurado = trim((string) (self::todas()["base_path"] ?? ""));

        if ($configurado === "") {
            $projeto = realpath(__DIR__ . "/..");
            $documentRoot = (string) ($_SERVER["DOCUMENT_ROOT"] ?? "");
            // realpath("") devolveria a pasta atual: sem DOCUMENT_ROOT (CLI), vale o padrão.
            $raiz = $documentRoot !== "" ? realpath($documentRoot) : false;

            $configurado = ($projeto !== false && $raiz !== false
                && ($projeto === $raiz || str_starts_with($projeto, rtrim($raiz, "/\\") . DIRECTORY_SEPARATOR)))
                ? str_replace("\\", "/", substr($projeto, strlen($raiz)))
                : "/koinon-service";
        }

        $caminho = "/" . trim($configurado, "/") . "/";

        return $caminho === "//" ? "/" : $caminho;
    }

    /* Endereço absoluto (a partir da raiz do domínio) de um arquivo do sistema. */
    public static function url(string $caminho = ""): string
    {
        return self::basePath() . ltrim($caminho, "/");
    }

    /*
     * Conexão com o banco. Os valores padrão são os do XAMPP local.
     *
     * @return array{host: string, porta: int, banco: string, usuario: string, senha: string}
     */
    public static function banco(): array
    {
        $banco = (array) (self::todas()["banco"] ?? []);

        return [
            "host" => (string) ($banco["host"] ?? "127.0.0.1"),
            "porta" => (int) ($banco["porta"] ?? 3306),
            "banco" => (string) ($banco["nome"] ?? "koinon_service"),
            "usuario" => (string) ($banco["usuario"] ?? "root"),
            "senha" => (string) ($banco["senha"] ?? ""),
        ];
    }

    /*
     * Modo demonstração (apresentação do TCC, ambiente local):
     * mostra na tela os links de senha que não puderam ser enviados
     * e cria o administrador inicial. Nunca ligue em produção.
     *
     * Liga com "modo_demonstracao" => true em config_canais.php
     * ou com a variável de ambiente KOINON_MODO_DEMONSTRACAO=1.
     */
    public static function modoDemonstracao(): bool
    {
        $ambiente = getenv("KOINON_MODO_DEMONSTRACAO");

        if ($ambiente !== false && $ambiente !== "") {
            return filter_var($ambiente, FILTER_VALIDATE_BOOLEAN);
        }

        return !empty(self::todas()["modo_demonstracao"]);
    }
}
