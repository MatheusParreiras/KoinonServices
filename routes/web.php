<?php

/**
 * Route table. Returns the configured Router to public/index.php.
 *
 * Middleware run left to right:
 *   guest   - only for logged-out visitors
 *   auth    - only for logged-in users
 *   tenant  - resolves the condominium (must come after auth)
 *   role:.. - requireRole([...]) (must come after tenant, which refreshes the role)
 *   csrf    - required on every POST
 */

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\NoticeController;
use App\Controllers\TenantController;
use App\Controllers\VerificationController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\RoleMiddleware;
use App\Middleware\TenantMiddleware;

$router = new Router();

$router->alias('guest', GuestMiddleware::class);
$router->alias('auth', AuthMiddleware::class);
$router->alias('tenant', TenantMiddleware::class);
$router->alias('role', RoleMiddleware::class);
$router->alias('csrf', CsrfMiddleware::class);

// --- Authentication ---------------------------------------------------------
$router->get('/', [DashboardController::class, 'home']);
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);
$router->post('/logout', [AuthController::class, 'logout'], ['auth', 'csrf']);

// --- E-mail verification (works logged out: the user cannot log in yet) ------
$router->get('/verify-email', [VerificationController::class, 'show']);
$router->post('/verify-email', [VerificationController::class, 'verify'], ['csrf']);
$router->get('/verify-email/resend', [VerificationController::class, 'showResend'], ['guest']);
$router->post('/verify-email/resend', [VerificationController::class, 'resend'], ['guest', 'csrf']);

// --- Tenant selection ---------------------------------------------------------
$router->get('/select-condominium', [TenantController::class, 'select'], ['auth']);
$router->post('/select-condominium', [TenantController::class, 'choose'], ['auth', 'csrf']);

// --- Dashboard / Notice Board -------------------------------------------------
$router->get('/dashboard', [DashboardController::class, 'index'], ['auth', 'tenant']);
$router->get('/api/notices', [NoticeController::class, 'index'], ['auth', 'tenant']);
$router->post('/api/notices', [NoticeController::class, 'store'], [
    'auth',
    'tenant',
    'role:' . implode(',', NoticeController::CREATOR_ROLES),
    'csrf',
]);

return $router;
