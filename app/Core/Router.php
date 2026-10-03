<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\Middleware;
use LogicException;

/**
 * Maps "METHOD /path" to [Controller::class, 'action'] with a middleware list.
 *
 *   $router->get('/dashboard', [DashboardController::class, 'index'], ['auth', 'tenant']);
 *   $router->post('/api/notices', [NoticeController::class, 'store'],
 *       ['auth', 'tenant', 'csrf', 'role:super_admin,manager,concierge']);
 *
 * Path parameters: "/notices/{id:\d+}" passes $id to the action as a named
 * argument. A parameter without a pattern matches one path segment ([^/]+).
 * Patterns cannot contain "}" (use \d+ rather than \d{1,5}).
 *
 * Middleware specs are "alias" or "alias:arg1,arg2"; aliases are registered
 * with alias() and run in the order listed, before the controller.
 */
final class Router
{
    /** @var array<string, class-string<Middleware>> */
    private array $aliases = [];

    /** @var list<array{method: string, regex: string, handler: array{0: class-string, 1: string}, middleware: list<string>}> */
    private array $routes = [];

    /**
     * Registers a middleware alias usable in route definitions.
     *
     * @param class-string<Middleware> $class
     */
    public function alias(string $name, string $class): void
    {
        $this->aliases[$name] = $class;
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /**
     * Finds the matching route and runs it through its middleware.
     *
     * @throws HttpException 404 when no path matches, 405 when the path exists for another method.
     */
    public function dispatch(Request $request): Response
    {
        // HEAD is answered by the GET route (the body is discarded by the web server).
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path(), $matches) !== 1) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }

            // Keep only the named groups ("id" => "42"), not the numeric ones.
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            return $this->run($route, $request, $params);
        }

        if ($allowed !== []) {
            throw new HttpException(405, null, ['Allow' => implode(', ', array_unique($allowed))]);
        }

        throw new HttpException(404);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    private function add(string $method, string $path, array $handler, array $middleware): void
    {
        [$class, $action] = $handler;
        if (!method_exists($class, $action)) {
            // Fail at boot rather than at the first visit to a mistyped route.
            throw new LogicException(sprintf('Route %s %s: %s::%s() does not exist.', $method, $path, $class, $action));
        }

        $this->routes[] = [
            'method'     => $method,
            'regex'      => $this->compile($path),
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    /** Converts "/notices/{id:\d+}" into "#^/notices/(?P<id>\d+)$#". */
    private function compile(string $path): string
    {
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#',
            static fn (array $m): string => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $path
        );

        return '#^' . $regex . '$#';
    }

    /**
     * Builds the middleware "onion" around the controller call and runs it.
     *
     * @param array{method: string, regex: string, handler: array{0: class-string, 1: string}, middleware: list<string>} $route
     * @param array<string, string> $params
     */
    private function run(array $route, Request $request, array $params): Response
    {
        [$class, $action] = $route['handler'];

        $pipeline = static function (Request $request) use ($class, $action, $params): Response {
            $controller = new $class($request);

            return $controller->$action(...$params);
        };

        // Wrap from the last middleware to the first so the first listed runs first.
        foreach (array_reverse($route['middleware']) as $spec) {
            [$name, $arguments] = array_pad(explode(':', $spec, 2), 2, null);
            $middlewareClass = $this->aliases[$name]
                ?? throw new LogicException(sprintf('Unknown middleware "%s".', $name));
            $middleware = new $middlewareClass($arguments === null ? [] : explode(',', $arguments));

            $next = $pipeline;
            $pipeline = static fn (Request $request): Response => $middleware->handle($request, $next);
        }

        return $pipeline($request);
    }
}
