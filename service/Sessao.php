<?php

require_once __DIR__ . "/Configuracao.php";

/*
 * Início de sessão único para todo o sistema.
 *
 * - Cookie HttpOnly (o JavaScript não lê o id da sessão), SameSite=Lax,
 *   Secure quando a página está em HTTPS e restrito à pasta do sistema.
 * - Modo estrito: um id de sessão inventado por terceiros é recusado.
 * - Inatividade: depois de "sessao_inatividade_minutos" (padrão 30) sem
 *   abrir nenhuma página, a sessão é encerrada e o login é pedido de novo.
 */
class Sessao
{
    /** Consultas automáticas (sino de notificações): não contam como atividade. */
    private const SCRIPTS_PASSIVOS = [
        "notificacao_badge.php",
        "notificacao_popup.php",
    ];

    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (!headers_sent()) {
            ini_set("session.use_strict_mode", "1");
            ini_set("session.use_only_cookies", "1");

            session_set_cookie_params([
                "lifetime" => 0,
                "path" => Configuracao::basePath(),
                "secure" => self::https(),
                "httponly" => true,
                "samesite" => "Lax",
            ]);
        }

        session_start();

        self::controlarInatividade();
    }

    public static function limiteInatividade(): int
    {
        $minutos = (int) (Configuracao::todas()["sessao_inatividade_minutos"] ?? 30);

        return max(1, $minutos) * 60;
    }

    private static function controlarInatividade(): void
    {
        if (empty($_SESSION["usuario_id"])) {
            return;
        }

        $agora = time();
        $ultima = (int) ($_SESSION["_ultima_atividade"] ?? $agora);

        if ($agora - $ultima > self::limiteInatividade()) {
            $_SESSION = ["sessao_expirada" => true];
            session_regenerate_id(true);
            return;
        }

        if (!in_array(basename($_SERVER["SCRIPT_NAME"] ?? ""), self::SCRIPTS_PASSIVOS, true)) {
            $_SESSION["_ultima_atividade"] = $agora;
        }
    }

    private static function https(): bool
    {
        return (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
            || str_starts_with(strtolower((string) (Configuracao::todas()["base_url"] ?? "")), "https://");
    }
}
