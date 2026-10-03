<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable view of the current HTTP request.
 *
 * Wrapping the superglobals in one object keeps controllers testable and gives
 * one place to normalise the path and to decode JSON bodies sent by fetch().
 */
final class Request
{
    /** JSON bodies larger than this are ignored (cheap protection against huge payloads). */
    private const MAX_JSON_BYTES = 1_048_576;

    /**
     * @param array<string, mixed>  $query  Query-string parameters ($_GET).
     * @param array<string, mixed>  $body   Form fields ($_POST) or decoded JSON body.
     * @param array<string, mixed>  $server Server variables ($_SERVER).
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $server,
        private readonly bool $malformedJson = false
    ) {
    }

    /** Builds the request from PHP's superglobals. */
    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // "/login/" and "/login" are the same route; "/" stays "/".
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = '/' . trim(is_string($path) ? $path : '/', '/');

        $body = $_POST;
        $malformed = false;
        if (str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $raw = file_get_contents('php://input', false, null, 0, self::MAX_JSON_BYTES);
            $decoded = json_decode($raw === false ? '' : $raw, true);
            $body = is_array($decoded) ? $decoded : [];
            // An empty body is fine (e.g. a like); a non-empty one that is not a JSON object is not.
            $malformed = $raw !== false && trim($raw) !== '' && !is_array($decoded);
        }

        return new self($method, $path, $_GET, $body, $_SERVER, $malformed);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** True when a JSON body was sent but could not be decoded into an object (answer 400). */
    public function hasMalformedJson(): bool
    {
        return $this->malformedJson;
    }

    /** Returns a query-string value. */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** Returns a body value (form field or JSON property). */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Returns a body value as a string, or '' when it is missing or not a
     * string. Prevents "Array to string" errors when a client sends
     * title[]=x or {"title": {...}} to probe the application.
     */
    public function string(string $key): string
    {
        $value = $this->body[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /** Returns a body value interpreted as a checkbox/boolean. */
    public function boolean(string $key): bool
    {
        $value = $this->body[$key] ?? false;

        return $value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'true';
    }

    /** Returns a request header, e.g. header('X-CSRF-Token'). */
    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** True for fetch()/API calls, which must receive JSON errors instead of HTML pages. */
    public function wantsJson(): bool
    {
        return str_starts_with($this->path, '/api/')
            || str_contains((string) $this->header('Accept'), 'application/json');
    }

    /** True for methods that change state and therefore require a CSRF token. */
    public function isStateChanging(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * Client IP. REMOTE_ADDR only: X-Forwarded-For is client-controlled and must
     * not be trusted unless a known reverse proxy is configured.
     */
    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}
