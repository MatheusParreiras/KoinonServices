<?php

/* Mini estrutura de testes: Teste::caso("nome", fn) e as verificações abaixo. */
final class Teste
{
    /** @var array<string, callable> */
    private static array $casos = [];

    public static function caso(string $nome, callable $corpo): void
    {
        self::$casos[$nome] = $corpo;
    }

    public static function rodar(): bool
    {
        $falhas = 0;

        foreach (self::$casos as $nome => $corpo) {
            try {
                $corpo();
                echo "  ok    $nome\n";
            } catch (Throwable $e) {
                $falhas++;
                echo "  FALHA $nome\n        " . $e->getMessage() . "\n";
            }
        }

        $total = count(self::$casos);
        echo "\n" . ($total - $falhas) . " de $total testes passaram.\n";

        return $falhas === 0;
    }

    public static function igual(mixed $esperado, mixed $obtido, string $mensagem = ""): void
    {
        if ($esperado !== $obtido) {
            throw new RuntimeException(trim("$mensagem Esperado " . var_export($esperado, true) . ", obtido " . var_export($obtido, true) . "."));
        }
    }

    public static function verdadeiro(bool $condicao, string $mensagem): void
    {
        if (!$condicao) {
            throw new RuntimeException($mensagem);
        }
    }

    public static function contem(string $trecho, string $texto, string $mensagem = ""): void
    {
        if (!str_contains($texto, $trecho)) {
            throw new RuntimeException(trim("$mensagem Não contém: \"$trecho\"."));
        }
    }

    public static function naoContem(string $trecho, string $texto, string $mensagem = ""): void
    {
        if (str_contains($texto, $trecho)) {
            throw new RuntimeException(trim("$mensagem Não devia conter: \"$trecho\"."));
        }
    }
}
