<?php

/*
 * Limite de tentativas (login, recuperação de senha).
 *
 * Cada chave (ex.: "login-conta:12345678900", "login-ip:10.0.0.1") guarda
 * os horários das tentativas recentes num arquivo em storage/limites,
 * como os tokens de senha. Passado o limite dentro da janela, a ação fica
 * bloqueada até a tentativa mais antiga sair da janela.
 */
class LimiteTentativas
{
    private string $diretorio;

    public function __construct(?string $diretorio = null)
    {
        $this->diretorio = $diretorio ?? __DIR__ . "/../storage/limites";

        if (!is_dir($this->diretorio)) {
            @mkdir($this->diretorio, 0700, true);
        }
    }

    /** Segundos até poder tentar de novo (0 = liberado). */
    public function bloqueadoPor(string $chave, int $maximo, int $janelaSegundos): int
    {
        $tentativas = $this->ler($chave, $janelaSegundos);

        if (count($tentativas) < $maximo) {
            return 0;
        }

        return max(1, min($tentativas) + $janelaSegundos - time());
    }

    public function registrar(string $chave, int $janelaSegundos): void
    {
        $tentativas = $this->ler($chave, $janelaSegundos);
        $tentativas[] = time();

        @file_put_contents($this->arquivo($chave), json_encode($tentativas), LOCK_EX);
    }

    public function limpar(string $chave): void
    {
        @unlink($this->arquivo($chave));
    }

    /** @return list<int> */
    private function ler(string $chave, int $janelaSegundos): array
    {
        $conteudo = @file_get_contents($this->arquivo($chave));
        $tentativas = is_string($conteudo) ? json_decode($conteudo, true) : null;

        if (!is_array($tentativas)) {
            return [];
        }

        $limite = time() - $janelaSegundos;

        return array_values(array_filter(
            array_map("intval", $tentativas),
            static fn (int $t): bool => $t > $limite
        ));
    }

    private function arquivo(string $chave): string
    {
        return $this->diretorio . "/" . hash("sha256", $chave) . ".json";
    }

    public static function ip(): string
    {
        return (string) ($_SERVER["REMOTE_ADDR"] ?? "desconhecido");
    }

    public static function minutos(int $segundos): int
    {
        return (int) ceil($segundos / 60);
    }
}
