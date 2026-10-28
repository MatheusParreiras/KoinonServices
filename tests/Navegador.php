<?php

/*
 * Testes de ponta a ponta: prepara o banco descartável, sobe o servidor
 * embutido do PHP e faz requisições como um navegador (cookies, token CSRF).
 */
final class Navegador
{
    public const SENHA = "Senha-de-Teste-123";

    private static string $base = "";
    private static ?PDO $pdo = null;
    /** @var resource|null */
    private static $servidor = null;

    private string $cookies;
    private string $ultimaPagina = "";

    public function __construct()
    {
        $this->cookies = tempnam(sys_get_temp_dir(), "koinon-cookies-");
    }

    public function __destruct()
    {
        @unlink($this->cookies);
    }

    /* ---------------------------------------------------------------- */

    public static function prepararAmbiente(string $arquivoConfig): bool
    {
        $config = is_file($arquivoConfig) ? (array) require $arquivoConfig : [];
        $banco = (array) ($config["banco"] ?? []);
        $nome = (string) ($banco["nome"] ?? "");

        if (!str_ends_with($nome, "_teste")) {
            fwrite(STDERR, "O banco de teste precisa terminar em \"_teste\" (ele é apagado). Recebido: \"$nome\".\n");
            return false;
        }

        if (!empty($config["modo_demonstracao"])) {
            fwrite(STDERR, "Desligue modo_demonstracao no config de teste.\n");
            return false;
        }

        self::$pdo = new PDO(
            "mysql:host=" . ($banco["host"] ?? "127.0.0.1") . ";port=" . (int) ($banco["porta"] ?? 3306) . ";dbname=$nome;charset=utf8mb4",
            (string) ($banco["usuario"] ?? "root"),
            (string) ($banco["senha"] ?? ""),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        self::recriarBanco();

        $projeto = realpath(__DIR__ . "/..");
        array_map("unlink", glob("$projeto/storage/limites/*.json") ?: []);

        $porta = self::portaLivre();
        self::$base = "http://127.0.0.1:$porta/" . basename($projeto) . "/";

        $ambiente = getenv();
        $ambiente["KOINON_CONFIG"] = realpath($arquivoConfig);
        unset($ambiente["KOINON_MODO_DEMONSTRACAO"]);

        self::$servidor = proc_open(
            [PHP_BINARY, "-S", "127.0.0.1:$porta", "-t", dirname($projeto)],
            [0 => ["file", "/dev/null", "r"], 1 => ["file", "/dev/null", "w"], 2 => ["file", "/dev/null", "w"]],
            $pipes,
            null,
            $ambiente
        );

        register_shutdown_function(static function (): void {
            if (is_resource(self::$servidor)) {
                proc_terminate(self::$servidor);
            }
        });

        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen("127.0.0.1", $porta)) {
                return true;
            }
            usleep(100000);
        }

        fwrite(STDERR, "O servidor de teste não subiu na porta $porta.\n");
        return false;
    }

    public static function pdo(): PDO
    {
        return self::$pdo;
    }

    /** Recria as tabelas a partir de koinon_service.sql e define a senha de teste. */
    private static function recriarBanco(): void
    {
        $pdo = self::$pdo;
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$tabela]) {
            $pdo->exec("DROP TABLE `" . str_replace("`", "``", $tabela) . "`");
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        $dump = file_get_contents(__DIR__ . "/../koinon_service.sql");
        foreach (preg_split('/;\s*$/m', $dump) as $comando) {
            $comando = trim(preg_replace('/^--.*$/m', "", $comando));
            if ($comando !== "") {
                $pdo->exec($comando);
            }
        }

        $pdo->prepare("UPDATE usuario SET senha_hash = :h, senha_definida = 1, primeiro_acesso = 0")
            ->execute([":h" => password_hash(self::SENHA, PASSWORD_DEFAULT)]);
    }

    private static function portaLivre(): int
    {
        $socket = stream_socket_server("tcp://127.0.0.1:0");
        $porta = (int) substr(strrchr(stream_socket_get_name($socket, false), ":"), 1);
        fclose($socket);

        return $porta;
    }

    /* ---------------------------------------------------------------- */

    /** @return array{status: int, location: string, corpo: string} */
    public function get(string $caminho): array
    {
        return $this->requisitar("GET", $caminho);
    }

    /**
     * POST com o token CSRF da última página aberta (como o formulário faria).
     *
     * @param array<string, string> $dados
     * @return array{status: int, location: string, corpo: string}
     */
    public function post(string $caminho, array $dados, bool $comToken = true): array
    {
        if ($comToken && preg_match('/name="_csrf" value="([a-f0-9]+)"/', $this->ultimaPagina, $m)) {
            $dados["_csrf"] = $m[1];
        }

        return $this->requisitar("POST", $caminho, $dados);
    }

    public function entrar(string $identificador, string $tipo = "morador", string $senha = self::SENHA): array
    {
        $this->get("view/login.php?tipo=$tipo");

        return $this->post("view/login.php?tipo=$tipo", [
            "tipo" => $tipo,
            "identificador" => $identificador,
            "senha" => $senha,
        ]);
    }

    /** @return array{status: int, location: string, corpo: string} */
    private function requisitar(string $metodo, string $caminho, array $dados = []): array
    {
        $curl = curl_init(self::$base . ltrim($caminho, "/"));
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEFILE => $this->cookies,
            CURLOPT_COOKIEJAR => $this->cookies,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($metodo === "POST") {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($dados));
        }

        $resposta = (string) curl_exec($curl);
        $tamanhoCabecalho = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $cabecalho = substr($resposta, 0, $tamanhoCabecalho);
        $corpo = substr($resposta, $tamanhoCabecalho);
        preg_match('/^Location:\s*(\S+)/mi', $cabecalho, $local);

        if ($metodo === "GET") {
            $this->ultimaPagina = $corpo;
        }

        return ["status" => $status, "location" => $local[1] ?? "", "corpo" => $corpo];
    }
}
