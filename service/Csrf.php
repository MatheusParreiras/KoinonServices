<?php

require_once __DIR__ . "/Sessao.php";

/*
 * ==========================================================
 * PROTEÇÃO CONTRA CSRF (Cross-Site Request Forgery)
 * ==========================================================
 *
 * Problema: ações como excluir, pagar e mudar status são feitas por
 * links simples (GET), por exemplo  index.php?acao=excluir&id=5 .
 * Um site mal-intencionado poderia fazer o navegador de um
 * administrador logado abrir esse link sem ele perceber.
 *
 * Solução (sem precisar reescrever as telas):
 *
 *  1. O servidor gera um token por sessão e o grava também em um
 *     cookie legível pelo JavaScript (koinon_csrf).
 *  2. O assets/js/script.js acrescenta  _csrf=<token>  em todo link
 *     que executa uma ação (excluir, pagar, status, cancelar...).
 *  3. Antes de executar, o servidor confere se o token do link é o
 *     mesmo da sessão. Um site externo não consegue ler o cookie,
 *     portanto não consegue montar um link válido.
 *
 * Para POST (formulários e fetch), o token também é obrigatório:
 *
 *  - todo <form method="post"> recebe um campo oculto _csrf, inserido
 *    pelo servidor na saída da página (as telas não precisam mudar);
 *  - o fetch do script.js envia o token no cabeçalho X-CSRF-Token;
 *  - o cabeçalho Origin/Referer, quando existe, também é conferido.
 */
class Csrf
{
    private const COOKIE = "koinon_csrf";

    /** Páginas que executam uma ação já ao serem abertas (sem formulário). */
    private const SCRIPTS_DE_ACAO = [
        "cancelar_reserva.php",
        "cancelar_autorizacao.php",
    ];

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            Sessao::iniciar();
        }

        if (empty($_SESSION["_csrf"])) {
            $_SESSION["_csrf"] = bin2hex(random_bytes(32));
        }

        // Cookie legível pelo script.js (por isso não é HttpOnly).
        if (!headers_sent() && ($_COOKIE[self::COOKIE] ?? "") !== $_SESSION["_csrf"]) {
            setcookie(self::COOKIE, $_SESSION["_csrf"], [
                "expires"  => 0,
                "path"     => "/",
                "secure"   => !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off",
                "httponly" => false,
                "samesite" => "Lax",
            ]);
        }

        return $_SESSION["_csrf"];
    }

    /**
     * Esta requisição GET executa uma ação que altera dados?
     * (A mesma regra existe no script.js para marcar os links.)
     */
    public static function ehAcaoGet(): bool
    {
        if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "GET") {
            return false;
        }

        if (isset($_GET["excluir"])) {
            return true;
        }

        if (isset($_GET["acao"]) && $_GET["acao"] !== "") {
            return true;
        }

        if (isset($_GET["status"]) && isset($_GET["id"])) {
            return true;
        }

        $script = basename($_SERVER["SCRIPT_NAME"] ?? "");

        return in_array($script, self::SCRIPTS_DE_ACAO, true)
            && isset($_GET["id"]);
    }

    /**
     * Chamado por PermissaoController::exigirLogin().
     * Interrompe a requisição se for suspeita.
     */
    public static function proteger(): void
    {
        $token = self::token();
        $metodo = $_SERVER["REQUEST_METHOD"] ?? "GET";

        self::inserirNosFormularios($token);

        if ($metodo === "POST") {
            $enviado = (string) ($_POST["_csrf"] ?? $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "");

            if (
                $enviado === ""
                || !hash_equals($token, $enviado)
                || !self::origemConfiavel()
            ) {
                self::bloquear();
            }
            self::auditar();
            return;
        }

        if (self::ehAcaoGet()) {
            $enviado = (string) ($_GET["_csrf"] ?? "");

            if ($enviado === "" || !hash_equals($token, $enviado)) {
                self::bloquear();
            }
            self::auditar();
        }
    }

    /**
     * Acrescenta o campo oculto _csrf em todo formulário POST da página.
     * Feito na saída (output buffering) para valer em todas as telas,
     * inclusive formulários novos, sem editar cada uma.
     */
    private static function inserirNosFormularios(string $token): void
    {
        static $ativo = false;

        if ($ativo) {
            return;
        }
        $ativo = true;

        ob_start(static fn (string $html): string => self::inserirCampo($html, $token));
    }

    /** Devolve o HTML com o campo _csrf logo após cada <form method="post">. */
    public static function inserirCampo(string $html, string $token): string
    {
        $campo = '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token, ENT_QUOTES, "UTF-8") . '">';

        return preg_replace_callback(
            '/<form\b[^>]*>/i',
            static fn (array $m): string => preg_match('/\bmethod\s*=\s*["\']?post\b/i', $m[0])
                ? $m[0] . $campo
                : $m[0],
            $html
        ) ?? $html;
    }

    /** Registra "quem fez o quê" (service/Auditoria.php). */
    private static function auditar(): void
    {
        require_once __DIR__ . "/Auditoria.php";
        Auditoria::registrarRequisicao();
    }

    private static function origemConfiavel(): bool
    {
        $origem = $_SERVER["HTTP_ORIGIN"] ?? ($_SERVER["HTTP_REFERER"] ?? "");

        // Alguns navegadores/ferramentas não enviam nenhum dos dois.
        if ($origem === "" || $origem === "null") {
            return true;
        }

        $partes = parse_url($origem);

        if (empty($partes["host"])) {
            return false;
        }

        $esperado = parse_url("//" . ($_SERVER["HTTP_HOST"] ?? ""));

        $hostOrigem   = strtolower($partes["host"]);
        $hostEsperado = strtolower($esperado["host"] ?? "");

        $portaOrigem = $partes["port"]
            ?? (($partes["scheme"] ?? "http") === "https" ? 443 : 80);
        $portaEsperada = $esperado["port"]
            ?? (!empty($_SERVER["HTTPS"]) ? 443 : 80);

        return $hostOrigem === $hostEsperado
            && (int) $portaOrigem === (int) $portaEsperada;
    }

    private static function bloquear(): void
    {
        http_response_code(403);

        if (str_contains((string) ($_SERVER["HTTP_ACCEPT"] ?? ""), "application/json") || isset($_SERVER["HTTP_X_CSRF_TOKEN"])) {
            header("Content-Type: application/json; charset=UTF-8");
            echo json_encode(["ok" => false, "erro" => "csrf"]);
            exit;
        }

        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Ação bloqueada</title>'
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" rel="stylesheet">'
            . '</head><body class="bg-light"><div class="container py-5" style="max-width:560px">'
            . '<div class="alert alert-warning shadow-sm">'
            . '<h5 class="alert-heading">Ação bloqueada por segurança</h5>'
            . '<p class="mb-2">O link desta ação expirou ou não foi gerado pelo sistema.</p>'
            . '<p class="mb-0">Volte à página anterior, atualize-a (F5) e tente novamente.</p>'
            . '</div><a href="javascript:history.back()" class="btn btn-secondary">← Voltar</a>'
            . '</div></body></html>';

        exit;
    }
}
