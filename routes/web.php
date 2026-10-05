<?php

/**
 * Route table. Returns the configured Router to public/index.php.
 *
 * Middleware run left to right:
 *   guest   - only for logged-out visitors
 *   auth    - only for logged-in users
 *   tenant  - resolves the condominium (must come after auth)
 *   role:.. - requireRole([...]) (must come after tenant, which refreshes the role)
 *   platform - Super Admin only (Phase 5; must come after auth; no tenant)
 *   csrf    - required on every POST
 */

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\Admin\AdminController;
use App\Controllers\Admin\AuditController as AdminAuditController;
use App\Controllers\Admin\CommonAreaController as AdminCommonAreaController;
use App\Controllers\Admin\FinanceController as AdminFinanceController;
use App\Controllers\Admin\NoticeController as AdminNoticeController;
use App\Controllers\Admin\UnitController as AdminUnitController;
use App\Controllers\Admin\UserController as AdminUserController;
use App\Controllers\AuthController;
use App\Controllers\Community\CommentController;
use App\Controllers\Community\CommunityController;
use App\Controllers\Community\LikeController;
use App\Controllers\Community\ModerationController;
use App\Controllers\Community\PostController;
use App\Controllers\Community\ReportController;
use App\Controllers\ConciergeController;
use App\Controllers\DashboardController;
use App\Controllers\FinanceController;
use App\Controllers\InvitationController;
use App\Controllers\NoticeController;
use App\Controllers\OccurrenceController;
use App\Controllers\PasswordResetController;
use App\Controllers\Platform\AuditController as PlatformAuditController;
use App\Controllers\Platform\CondominiumController as PlatformCondominiumController;
use App\Controllers\Platform\DashboardController as PlatformDashboardController;
use App\Controllers\ReservationController;
use App\Controllers\TenantController;
use App\Controllers\VerificationController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\RoleMiddleware;
use App\Middleware\SuperAdminMiddleware;
use App\Middleware\TenantMiddleware;

$router = new Router();

$router->alias('guest', GuestMiddleware::class);
$router->alias('auth', AuthMiddleware::class);
$router->alias('tenant', TenantMiddleware::class);
$router->alias('role', RoleMiddleware::class);
$router->alias('csrf', CsrfMiddleware::class);
$router->alias('platform', SuperAdminMiddleware::class);

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

// =============================================================================
// Phase 4 - Community (social network)
//
// Same chain as Phase 3: auth → tenant → role → csrf. All /api/community/*
// endpoints answer JSON ({"status": "success"|"error", ...}); errors raised by
// middleware (401/403/419) use the same envelope via ErrorHandler.
// {id} is only a lookup key: services re-check that the post is in the session's tenant.
// =============================================================================

$members = $viewers(CommunityController::MEMBERS);
$moderators = $viewers(CommunityController::MODERATORS);

// Pages
$router->get('/community', [PostController::class, 'index'], ['auth', 'tenant', $members]);
$router->get('/community/moderation', [ModerationController::class, 'index'], [
    'auth', 'tenant', $viewers(CommunityController::MODERATION_VIEWERS),
]);

// Feed and posts
$router->get('/api/community/posts', [PostController::class, 'feed'], ['auth', 'tenant', $members]);
$router->post('/api/community/posts', [PostController::class, 'store'], ['auth', 'tenant', $members, 'csrf']);

// Likes (idempotent set / unset)
$router->post('/api/community/posts/{id:\d+}/like', [LikeController::class, 'store'], ['auth', 'tenant', $members, 'csrf']);
$router->delete('/api/community/posts/{id:\d+}/like', [LikeController::class, 'destroy'], ['auth', 'tenant', $members, 'csrf']);

// Comments
$router->get('/api/community/posts/{id:\d+}/comments', [CommentController::class, 'index'], ['auth', 'tenant', $members]);
$router->post('/api/community/posts/{id:\d+}/comments', [CommentController::class, 'store'], ['auth', 'tenant', $members, 'csrf']);

// Reports
$router->post('/api/community/posts/{id:\d+}/reports', [ReportController::class, 'store'], ['auth', 'tenant', $members, 'csrf']);

// Moderation (managers only)
$router->delete('/api/community/moderation/posts/{id:\d+}', [ModerationController::class, 'destroy'], [
    'auth', 'tenant', $moderators, 'csrf',
]);
$router->post('/api/community/moderation/posts/{id:\d+}/dismiss', [ModerationController::class, 'dismiss'], [
    'auth', 'tenant', $moderators, 'csrf',
]);

// =============================================================================
// Phase 5 - administration, account self-service and recovery
// =============================================================================

// --- /account: every role ----------------------------------------------------------
// "auth" only (no tenant): a Super Admin has no condominium, and a member with
// several condominiums manages ONE global account. Every action works on
// Auth::id(); there is no user id in these URLs.
$router->get('/account', [AccountController::class, 'show'], ['auth']);
$router->post('/account/profile', [AccountController::class, 'updateProfile'], ['auth', 'csrf']);
$router->get('/account/avatar', [AccountController::class, 'avatar'], ['auth']);
$router->post('/account/password', [AccountController::class, 'changePassword'], ['auth', 'csrf']);
$router->post('/account/email', [AccountController::class, 'requestEmailChange'], ['auth', 'csrf']);

// Token links: work logged out (the e-mail may be opened on another device).
// GET only displays a confirmation form; the token is consumed by the POST.
$router->get('/account/forgot-password', [PasswordResetController::class, 'showForgot'], ['guest']);
$router->post('/account/forgot-password', [PasswordResetController::class, 'sendLink'], ['guest', 'csrf']);
$router->get('/account/reset-password', [PasswordResetController::class, 'showReset']);
$router->post('/account/reset-password', [PasswordResetController::class, 'reset'], ['csrf']);
$router->get('/account/invitation', [InvitationController::class, 'show']);
$router->post('/account/invitation', [InvitationController::class, 'accept'], ['csrf']);
$router->get('/account/email/confirm', [AccountController::class, 'showEmailConfirmation']);
$router->post('/account/email/confirm', [AccountController::class, 'confirmEmail'], ['csrf']);

// --- /admin: Property Manager of the session's condominium --------------------------
// auth → tenant (condominium from $_SESSION, re-validated) → role:manager → csrf.
// {id} is only a lookup key into tenant-scoped models (another tenant's id = 404).
$admin = ['auth', 'tenant', $viewers(AdminController::MANAGERS)];
$adminWrite = [...$admin, 'csrf'];

$router->get('/admin/users', [AdminUserController::class, 'index'], $admin);
$router->get('/admin/users/search', [AdminUserController::class, 'search'], $admin);
$router->post('/admin/users/invitations', [AdminUserController::class, 'invite'], $adminWrite);
$router->get('/admin/users/{id:\d+}/edit', [AdminUserController::class, 'edit'], $admin);
$router->post('/admin/users/{id:\d+}', [AdminUserController::class, 'update'], $adminWrite);
$router->post('/admin/users/{id:\d+}/deactivate', [AdminUserController::class, 'deactivate'], $adminWrite);
$router->post('/admin/users/{id:\d+}/reactivate', [AdminUserController::class, 'reactivate'], $adminWrite);
$router->post('/admin/users/{id:\d+}/invitation/resend', [AdminUserController::class, 'resendInvitation'], $adminWrite);

$router->get('/admin/units', [AdminUnitController::class, 'index'], $admin);
$router->post('/admin/units', [AdminUnitController::class, 'store'], $adminWrite);
$router->post('/admin/units/bulk', [AdminUnitController::class, 'bulk'], $adminWrite);
$router->get('/admin/units/{id:\d+}/edit', [AdminUnitController::class, 'edit'], $admin);
$router->post('/admin/units/{id:\d+}', [AdminUnitController::class, 'update'], $adminWrite);

$router->get('/admin/common-areas', [AdminCommonAreaController::class, 'index'], $admin);
$router->get('/admin/common-areas/new', [AdminCommonAreaController::class, 'create'], $admin);
$router->post('/admin/common-areas', [AdminCommonAreaController::class, 'store'], $adminWrite);
$router->get('/admin/common-areas/{id:\d+}/edit', [AdminCommonAreaController::class, 'edit'], $admin);
$router->post('/admin/common-areas/{id:\d+}', [AdminCommonAreaController::class, 'update'], $adminWrite);

$router->get('/admin/notices', [AdminNoticeController::class, 'index'], $admin);
$router->get('/admin/notices/{id:\d+}/edit', [AdminNoticeController::class, 'edit'], $admin);
$router->post('/admin/notices/{id:\d+}', [AdminNoticeController::class, 'update'], $adminWrite);
$router->post('/admin/notices/{id:\d+}/delete', [AdminNoticeController::class, 'destroy'], $adminWrite);

$router->get('/admin/finance/reports', [AdminFinanceController::class, 'reports'], $admin);
$router->get('/admin/finance/reports/period', [AdminFinanceController::class, 'period'], $admin);
$router->get('/admin/finance/reports/period.csv', [AdminFinanceController::class, 'periodCsv'], $admin);
$router->get('/admin/finance/reports/delinquency.csv', [AdminFinanceController::class, 'delinquencyCsv'], $admin);
$router->get('/admin/finance/invoices', [AdminFinanceController::class, 'invoices'], $admin);
$router->get('/admin/finance/invoices/{id:\d+}', [AdminFinanceController::class, 'show'], $admin);
$router->post('/admin/finance/invoices/{id:\d+}/payment', [AdminFinanceController::class, 'pay'], $adminWrite);
$router->post('/admin/finance/invoices/{id:\d+}/cancel', [AdminFinanceController::class, 'cancel'], $adminWrite);

$router->get('/admin/audit', [AdminAuditController::class, 'index'], $admin);

// --- /platform: Super Admin --------------------------------------------------------
// auth → platform (users.is_super_admin from the database) → csrf. No "tenant":
// the target condominium is the {id} in the URL, loaded (404 if missing) and
// written to the audit log by every action.
$platform = ['auth', 'platform'];
$platformWrite = [...$platform, 'csrf'];

$router->get('/platform', [PlatformDashboardController::class, 'index'], $platform);
$router->get('/platform/condominiums', [PlatformCondominiumController::class, 'index'], $platform);
$router->get('/platform/condominiums/new', [PlatformCondominiumController::class, 'create'], $platform);
$router->post('/platform/condominiums', [PlatformCondominiumController::class, 'store'], $platformWrite);
$router->get('/platform/condominiums/{id:\d+}', [PlatformCondominiumController::class, 'show'], $platform);
$router->get('/platform/condominiums/{id:\d+}/edit', [PlatformCondominiumController::class, 'edit'], $platform);
$router->post('/platform/condominiums/{id:\d+}', [PlatformCondominiumController::class, 'update'], $platformWrite);
$router->post('/platform/condominiums/{id:\d+}/suspend', [PlatformCondominiumController::class, 'suspend'], $platformWrite);
$router->post('/platform/condominiums/{id:\d+}/reactivate', [PlatformCondominiumController::class, 'reactivate'], $platformWrite);
$router->post('/platform/condominiums/{id:\d+}/managers', [PlatformCondominiumController::class, 'inviteManager'], $platformWrite);
$router->post(
    '/platform/condominiums/{id:\d+}/managers/{userId:\d+}/resend',
    [PlatformCondominiumController::class, 'resendManagerInvitation'],
    $platformWrite
);
$router->get('/platform/audit', [PlatformAuditController::class, 'index'], $platform);

return $router;
