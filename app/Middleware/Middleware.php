<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * A step that runs before the controller and may stop the request.
 *
 * $params carries the comma-separated arguments of the route spec, e.g.
 * "role:manager,concierge" creates the middleware with ['manager', 'concierge'].
 */
abstract class Middleware
{
    /** @param list<string> $params */
    public function __construct(protected readonly array $params = [])
    {
    }

    /**
     * Either returns a Response itself (redirect, error) or calls $next to continue.
     *
     * @param callable(Request): Response $next
     */
    abstract public function handle(Request $request, callable $next): Response;
}
