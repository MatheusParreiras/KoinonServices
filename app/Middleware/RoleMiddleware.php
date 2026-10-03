<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;

/**
 * Route spec "role:a,b,c", the requireRole([...]) check at route level.
 *
 *   ['auth', 'tenant', 'role:super_admin,manager']
 *
 * Place it after "tenant": the tenant middleware refreshes the role from the
 * database, so this check never relies on a stale role in the session.
 * Hiding a button in the UI is not access control; this middleware is.
 */
final class RoleMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Auth::hasRole($this->params)) {
            Logger::warning('Role check failed', [
                'user_id'  => Auth::id(),
                'role'     => Auth::roleCode(),
                'required' => $this->params,
                'path'     => $request->path(),
            ]);
            throw new HttpException(403);
        }

        return $next($request);
    }
}
