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
use App\Controllers\ConciergeController;
use App\Controllers\DashboardController;
use App\Controllers\FinanceController;
use App\Controllers\NoticeController;
use App\Controllers\OccurrenceController;
use App\Controllers\ReservationController;
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

// =============================================================================
// Phase 3 - management modules
//
// Every route: auth → tenant (TenantContext from the session) → role → csrf (POST).
// Each controller action ALSO calls requireRole() with the same list, and the
// role lists live as constants on the controllers, so routes, controllers and
// the sidebar can never disagree. URL ids ({id}) are only lookup keys: every
// model query adds condominium_id, plus the user/unit ownership filter for residents.
// =============================================================================

$viewers = static fn (array $roles): string => 'role:' . implode(',', $roles);

// --- Concierge (Portaria) -----------------------------------------------------
$router->get('/concierge', [ConciergeController::class, 'index'], [
    'auth', 'tenant', $viewers(ConciergeController::VIEWERS),
]);
$router->post('/concierge/visits', [ConciergeController::class, 'storeVisit'], [
    'auth', 'tenant', $viewers(ConciergeController::OPERATORS), 'csrf',
]);
$router->post('/api/concierge/visits/{id:\d+}/exit', [ConciergeController::class, 'exitVisit'], [
    'auth', 'tenant', $viewers(ConciergeController::OPERATORS), 'csrf',
]);
$router->post('/concierge/packages', [ConciergeController::class, 'storePackage'], [
    'auth', 'tenant', $viewers(ConciergeController::OPERATORS), 'csrf',
]);
$router->post('/api/concierge/packages/{id:\d+}/pickup', [ConciergeController::class, 'pickupPackage'], [
    'auth', 'tenant', $viewers(ConciergeController::OPERATORS), 'csrf',
]);

// --- Reservations (Reservas) ----------------------------------------------------
$router->get('/reservations', [ReservationController::class, 'index'], [
    'auth', 'tenant', $viewers(ReservationController::VIEWERS),
]);
$router->post('/reservations', [ReservationController::class, 'store'], [
    'auth', 'tenant', $viewers(ReservationController::BOOKERS), 'csrf',
]);
$router->post('/reservations/{id:\d+}/cancel', [ReservationController::class, 'cancel'], [
    'auth', 'tenant', $viewers(ReservationController::BOOKERS), 'csrf',
]);
$router->post('/reservations/{id:\d+}/decision', [ReservationController::class, 'decide'], [
    'auth', 'tenant', $viewers(ReservationController::MANAGERS), 'csrf',
]);

// --- Occurrences (Ocorrências) ---------------------------------------------------
$router->get('/occurrences', [OccurrenceController::class, 'index'], [
    'auth', 'tenant', $viewers(OccurrenceController::VIEWERS),
]);
$router->get('/occurrences/new', [OccurrenceController::class, 'create'], [
    'auth', 'tenant', $viewers(OccurrenceController::REPORTERS),
]);
$router->post('/occurrences', [OccurrenceController::class, 'store'], [
    'auth', 'tenant', $viewers(OccurrenceController::REPORTERS), 'csrf',
]);
$router->get('/occurrences/{id:\d+}', [OccurrenceController::class, 'show'], [
    'auth', 'tenant', $viewers(OccurrenceController::VIEWERS),
]);
$router->post('/occurrences/{id:\d+}/replies', [OccurrenceController::class, 'reply'], [
    'auth', 'tenant', $viewers(OccurrenceController::REPORTERS), 'csrf',
]);
$router->post('/occurrences/{id:\d+}/status', [OccurrenceController::class, 'updateStatus'], [
    'auth', 'tenant', $viewers(OccurrenceController::HANDLERS), 'csrf',
]);

// --- Financial (Financeiro) -------------------------------------------------------
$router->get('/finance', [FinanceController::class, 'index'], [
    'auth', 'tenant', $viewers(FinanceController::VIEWERS),
]);
$router->post('/finance/charges', [FinanceController::class, 'storeCharge'], [
    'auth', 'tenant', $viewers(FinanceController::MANAGERS), 'csrf',
]);

return $router;
