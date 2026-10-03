<?php

declare(strict_types=1);

namespace App\Controllers\Community;

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Models\PostReport;
use App\Services\BusinessRuleException;
use App\Services\CommunityService;

/**
 * Reporting posts to the moderators.
 */
final class ReportController extends CommunityController
{
    /**
     * POST /api/community/posts/{id}/reports  body: {"reason": "spam", "details": "..."}
     *
     * 201 {"status":"success","data":{"reported":true}}
     * 409 already reported by this user (UNIQUE constraint) · 422 invalid reason / own post
     * 404 post not in this condominium
     */
    public function store(string $id): Response
    {
        $this->requireRole(self::MEMBERS);
        if ($error = $this->malformedBody()) {
            return $error;
        }
        if (!RateLimiter::attempt('community.report', 10, 600)) {
            return $this->failure('Muitas denúncias em pouco tempo. Tente novamente mais tarde.', 429);
        }

        // Allowlist: only the reasons of the schema's ENUM.
        $reason = $this->request->string('reason');
        if (!array_key_exists($reason, PostReport::REASONS)) {
            return $this->failure('Escolha um motivo.', 422, ['reason' => 'Escolha um motivo.']);
        }
        $details = self::cleanText($this->request->string('details'));
        if (mb_strlen($details) > 500) {
            return $this->failure('Detalhes: até 500 caracteres.', 422, ['details' => 'Até 500 caracteres.']);
        }

        try {
            (new CommunityService())->report((int) $id, (int) Auth::id(), $reason, $details === '' ? null : $details);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e);
        }

        // Whether the post was auto-hidden is not revealed to the reporter.
        return $this->success(['reported' => true], 201);
    }
}
