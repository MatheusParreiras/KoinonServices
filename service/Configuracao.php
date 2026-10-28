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
            $caminho = __DIR__ . "/../config_canais.php";
            self::$dados = is_file($caminho)
                ? (array) require $caminho
                : [];
        }

        return self::$dados;
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
