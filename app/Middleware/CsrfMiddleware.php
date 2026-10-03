<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;

/**
 * Route spec "csrf": state-changing requests must carry the session's CSRF token,
 * either in the "_csrf" form field or in the X-CSRF-Token header (fetch()).
 *
 * Add it to every POST route, including the login form: without it an attacker
 * could log a victim into the attacker's account ("login CSRF").
 */
final class CsrfMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if ($request->isStateChanging()) {
            $token = $request->string('_csrf');
            if ($token === '') {
                $token = (string) $request->header('X-CSRF-Token');
            }

            if (!Csrf::validate($token)) {
                Logger::warning('CSRF token mismatch', ['path' => $request->path(), 'ip' => $request->ip()]);
                throw new HttpException(419);
            }
        }

        return $next($request);
    }
}
