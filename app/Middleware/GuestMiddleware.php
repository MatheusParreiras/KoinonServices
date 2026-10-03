<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Route spec "guest": pages for logged-out visitors (login, resend link).
 * A logged-in user is sent to the dashboard instead.
 */
final class GuestMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (Auth::check()) {
            return Response::redirect('/dashboard');
        }

        return $next($request);
    }
}
