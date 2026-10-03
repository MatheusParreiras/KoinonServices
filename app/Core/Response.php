<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * An HTTP response built by a controller and sent once by the front controller.
 *
 * Controllers return Response objects instead of echoing, so middleware and the
 * front controller can still add headers (security headers, cache control).
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private array $headers = []
    ) {
    }

    /** An HTML page. */
    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * A JSON document. JSON_HEX_* escapes <, >, & and quotes so the output stays
     * inert even if a browser were tricked into treating it as HTML.
     */
    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return new self($body, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /**
     * A redirect to a path inside this application. External URLs are refused,
     * so no code path can be turned into an open redirect.
     */
    public static function redirect(string $path, int $status = 302): self
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Only same-site redirects are allowed.');
        }

        return new self('', $status, ['Location' => $path]);
    }

    /** Returns a copy with the header set (replacing any existing value). */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    /** Returns a copy with the header set only if the controller did not set it. */
    public function withDefaultHeader(string $name, string $value): self
    {
        return isset($this->headers[$name]) ? $this : $this->withHeader($name, $value);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** Writes status, headers and body to the client. */
    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
