<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;

/**
 * Route spec "platform" (always after "auth"): only the Super Admin gets through.
 *
 * /platform routes have no "tenant" middleware: the Super Admin has no
 * session condominium_id. A platform action on one condominium takes the id
 * from the URL, loads that row (404 when missing) and writes an audit entry
 * with it. Auth::isSuperAdmin() reads users.is_super_admin from the database
 * row re-loaded on every request, never from the session or the request.
 */
final class SuperAdminMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Auth::isSuperAdmin()) {
            Logger::warning('Platform access refused', ['user_id' => Auth::id(), 'path' => $request->path()]);
            throw new HttpException(403);
        }

        return $next($request);
    }
}
