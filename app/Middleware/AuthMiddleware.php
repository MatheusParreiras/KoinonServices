<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Route spec "auth": only logged-in users get through.
 *
 * Pages redirect to the login form; API calls get a JSON 401 instead (a redirect
 * would hand fetch() the login page HTML).
 */
final class AuthMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Auth::check()) {
            if ($request->wantsJson()) {
                throw new HttpException(401);
            }
            Session::flash('warning', 'Entre para continuar.');

            return Response::redirect('/login');
        }

        return $next($request);
    }
}
