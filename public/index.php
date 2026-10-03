<?php

/**
 * Front controller: the single PHP entry point exposed by the web server.
 *
 * Every request that is not a static file arrives here (public/.htaccess, or
 * Nginx try_files). Nothing outside public/ is reachable by URL, so config,
 * .env, logs, sessions and source code can never be downloaded.
 */

declare(strict_types=1);

use App\Core\Config;
use App\Core\ErrorHandler;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;

// PHP built-in development server: let it serve existing static files itself.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/bootstrap.php';

$request = Request::fromGlobals();

try {
    Session::start();
    /** @var \App\Core\Router $router */
    $router = require BASE_PATH . '/routes/web.php';
    $response = $router->dispatch($request);
} catch (HttpException $e) {
    $response = ErrorHandler::fromHttpException($e, $request);
} catch (Throwable $e) {
    $response = ErrorHandler::fromThrowable($e, $request);
}

// Security headers on every dynamic response (controllers may override some, e.g. Referrer-Policy).
$response = $response
    ->withDefaultHeader('X-Content-Type-Options', 'nosniff')
    ->withDefaultHeader('X-Frame-Options', 'DENY')
    ->withDefaultHeader('Referrer-Policy', 'same-origin')
    // Scripts and styles only from this origin: no inline <script>, no style="".
    // Even if some user text slipped through unescaped, it could not run.
    ->withDefaultHeader(
        'Content-Security-Policy',
        "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
    )
    // Authenticated pages must not be stored by the browser or shared caches.
    ->withDefaultHeader('Cache-Control', 'no-store');

if (Config::get('session.secure')) {
    $response = $response->withDefaultHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
}

$response->send();
