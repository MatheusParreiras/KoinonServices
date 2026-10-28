<?php

require_once __DIR__ . "/Configuracao.php";

/*
 * Mensagem de erro que pode ser mostrada ao usuário.
 *
 * Exceções do próprio sistema (validações, regras de negócio) são
 * exibidas como estão. Erros do banco (PDOException) e erros internos
 * do PHP (Error) podem revelar tabelas, colunas, caminhos e dados: vão
 * para o log do PHP e o usuário vê uma mensagem genérica (o detalhe
 * aparece só no modo demonstração).
 */
class Erro
{
    public static function mensagem(
        Throwable $e,
        string $padrao = "não foi possível concluir a operação. Tente novamente."
    ): string {

        if (!$e instanceof PDOException && !$e instanceof Error) {
            return $e->getMessage();
        }

        error_log("[Koinon] " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());

        return Configuracao::modoDemonstracao()
            ? $padrao . " (" . $e->getMessage() . ")"
            : $padrao;
    }
}
