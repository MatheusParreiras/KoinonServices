<?php

declare(strict_types=1);

namespace App\Controllers\Community;

use App\Core\Auth;
use App\Core\Response;
use App\Models\PostReport;
use App\Services\BusinessRuleException;
use App\Services\ModerationService;

/**
 * Moderation of reported posts.
 *
 * Server-side authorization on EVERY action. Each route carries a role:
 * middleware and each action repeats requireRole(), so no endpoint depends on
 * the UI hiding a button.
 */
final class ModerationController extends CommunityController
{
    /** GET /community/moderation: the queue (Super Admin can view; only managers can act). */
    public function index(): Response
    {
        $this->requireRole(self::MODERATION_VIEWERS);

        return $this->view('community/moderation', [
            'title'       => 'Moderação',
            'queue'       => (new ModerationService())->queue(),
            'reasons'     => PostReport::REASONS,
            'canModerate' => Auth::hasRole(self::MODERATORS),
            'isMember'    => Auth::hasRole(self::MEMBERS),
            'activeTab'   => 'moderation',
            'showModerationTab' => true,
            'scripts'     => ['js/community/moderation.js'],
        ], layout: 'layouts/community');
    }

    /**
     * DELETE /api/community/moderation/posts/{id}
     * 200 {"status":"success","data":{"deleted":true}} · 403 not a manager · 404 not in this condominium
     */
    public function destroy(string $id): Response
    {
        $this->requireRole(self::MODERATORS);

        try {
            (new ModerationService())->deletePost((int) $id, (int) Auth::id(), $this->request);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e);
        }

        return $this->success(['deleted' => true]);
    }

    /**
     * POST /api/community/moderation/posts/{id}/dismiss
     * 200 {"status":"success","data":{"dismissed":int}} · 403 · 404 · 409 nothing pending
     */
    public function dismiss(string $id): Response
    {
        $this->requireRole(self::MODERATORS);

        try {
            $count = (new ModerationService())->dismissReports((int) $id, (int) Auth::id(), $this->request);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e);
        }

        return $this->success(['dismissed' => $count]);
    }
}
