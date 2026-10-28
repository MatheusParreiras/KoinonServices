<?php
/**
 * Utilitário de CPF: apenas o padrão 000.000.000-00 (sem validação de dígitos).
 * No banco o CPF é guardado só com números; na tela é exibido com máscara.
 */
class Cpf
{
    public static function limpar($valor): string
    {
        return preg_replace('/\D+/', '', (string) $valor);
    }

    public static function padraoValido($valor): bool
    {
        return strlen(self::limpar($valor)) === 11;
    }

    public static function formatar($valor): string
    {
        $d = self::limpar($valor);
        if (strlen($d) !== 11) {
            return (string) $valor;
        }
        return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2);
    }
}
