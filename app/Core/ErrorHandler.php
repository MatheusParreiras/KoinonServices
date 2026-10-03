<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Converts exceptions into user-safe responses.
 *
 * Expected errors (HttpException) show their message. Anything else is logged
 * with full details and answered with a generic 500, because exception
 * messages can contain SQL, file paths or configuration values.
 */
final class ErrorHandler
{
    /** Response for an expected HTTP error. */
    public static function fromHttpException(HttpException $e, Request $request): Response
    {
        $response = self::render($e->status(), $e->getMessage(), $request);
        foreach ($e->headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /** Logs an unexpected error and returns a generic 500. */
    public static function fromThrowable(Throwable $e, Request $request): Response
    {
        Logger::error('Unhandled ' . $e::class, [
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'method'  => $request->method(),
            'path'    => $request->path(),
        ]);

        return self::render(500, (new HttpException(500))->getMessage(), $request);
    }

    private static function render(int $status, string $message, Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['status' => 'error', 'message' => $message, 'error' => $message], $status);
        }

        try {
            // Error pages use the auth layout: it needs no database or session
            // data, so it still renders when the error came from either of them.
            $html = View::render('errors/error', [
                'title'     => 'Erro ' . $status,
                'status'    => $status,
                'message'   => $message,
                'requestId' => Logger::requestId(),
                'flashes'   => [],
            ], 'layouts/auth');
        } catch (Throwable) {
            $html = '<!doctype html><meta charset="utf-8"><title>Erro</title><p>' . e($message) . '</p>';
        }

        return Response::html($html, $status);
    }
}
