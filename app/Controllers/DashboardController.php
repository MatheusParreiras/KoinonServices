<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;

/**
 * Logged-in homepage: the Notice Board.
 */
final class DashboardController extends Controller
{
    /** GET / */
    public function home(): Response
    {
        return $this->redirect(Auth::check() ? '/dashboard' : '/login');
    }

    /**
     * GET /dashboard
     *
     * The page itself is a shell; notices are loaded by notices.js from
     * GET /api/notices. canCreateNotice only decides whether the button is shown:
     * the POST endpoint enforces the same rule on the server.
     */
    public function index(): Response
    {
        return $this->view('dashboard/index', [
            'title'           => 'Mural de avisos',
            'activeNav'       => 'notices',
            'canCreateNotice' => Auth::hasRole(NoticeController::CREATOR_ROLES),
            'canManageNotices' => Auth::hasRole(Admin\AdminController::MANAGERS),
            'scripts'         => ['js/notices.js'],
        ]);
    }
}
