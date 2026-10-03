<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * An expected HTTP error (404, 403, 419...) thrown anywhere during a request and
 * turned into an HTML page or JSON body by ErrorHandler. The message is shown
 * to the user, so it must never contain internal details.
 */
final class HttpException extends RuntimeException
{
    private const DEFAULT_MESSAGES = [
        400 => 'Requisição inválida.',
        401 => 'Entre para continuar.',
        403 => 'Você não tem permissão para acessar este recurso.',
        404 => 'Página não encontrada.',
        405 => 'Método não permitido.',
        419 => 'Sua sessão expirou. Recarregue a página e tente novamente.',
        422 => 'Os dados enviados são inválidos.',
        500 => 'Ocorreu um erro inesperado. Tente novamente em instantes.',
    ];

    /**
     * @param array<string, string> $headers Extra response headers (e.g. Allow for 405).
     */
    public function __construct(
        private readonly int $status,
        ?string $message = null,
        private readonly array $headers = []
    ) {
        parent::__construct($message ?? (self::DEFAULT_MESSAGES[$status] ?? 'Erro.'));
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
