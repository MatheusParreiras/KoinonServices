<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Core\TenantContext;
use App\Core\Validator;
use App\Models\AuditLog;
use App\Models\Notice;
use DateTimeImmutable;
use DateTimeZone;

/**
 * JSON API of the Notice Board. Both routes run behind auth + tenant, so every
 * query is limited to the current condominium by the Notice model.
 */
final class NoticeController extends Controller
{
    /**
     * Who may publish notices. The single source of truth for the route
     * middleware (routes/web.php), the defence-in-depth check in store() and
     * the "Novo aviso" button (DashboardController).
     */
    public const CREATOR_ROLES = [Auth::SUPER_ADMIN, 'manager', 'concierge'];

    /** GET /api/notices: current notices of the current tenant. */
    public function index(): Response
    {
        $notices = array_map([$this, 'present'], (new Notice())->current());

        return $this->json(['data' => $notices]);
    }

    /**
     * POST /api/notices: publishes a notice immediately.
     *
     * Protected three times: "role:" middleware on the route, requireRole()
     * here, and the composite foreign key that only accepts a member of this
     * condominium as author.
     */
    public function store(): Response
    {
        $this->requireRole(self::CREATOR_ROLES);

        $title = trim($this->request->string('title'));
        $body = trim($this->request->string('body'));
        $priority = $this->request->string('priority') ?: 'normal';
        $isPinned = $this->request->boolean('is_pinned');

        $errors = [];
        if (mb_strlen($title) < 3 || mb_strlen($title) > Notice::TITLE_MAX) {
            $errors['title'] = 'O título deve ter entre 3 e ' . Notice::TITLE_MAX . ' caracteres.';
        }
        if ($body === '' || mb_strlen($body) > Notice::BODY_MAX) {
            $errors['body'] = 'A mensagem é obrigatória e pode ter até ' . Notice::BODY_MAX . ' caracteres.';
        }
        if (!in_array($priority, Notice::PRIORITIES, true)) {
            $errors['priority'] = 'Prioridade inválida.';
        }
        // Phase 5: optional expiry, typed in the condominium's local time.
        $expiresAt = null;
        $v = new Validator($this->request);
        if ($v->filled('expires_at')) {
            $local = $v->dateTimeLocal('expires_at', 'Data de expiração');
            if ($local !== null) {
                $expiresAt = TenantContext::localToUtc($local);
                if ($expiresAt <= gmdate('Y-m-d H:i:s')) {
                    $v->addError('expires_at', 'A expiração deve ser no futuro.');
                }
            }
            $errors += $v->errors();
        }
        if ($errors !== []) {
            return $this->json(['errors' => $errors], 422);
        }

        // Exactly one author column is set. A Super Admin has no membership in the
        // condominium, so they are recorded in author_super_admin_id (migration 0001).
        $userId = (int) Auth::id();
        $isSuperAdmin = Auth::isSuperAdmin();

        $notices = new Notice();
        $id = $notices->insert([
            'author_user_id'        => $isSuperAdmin ? null : $userId,
            'author_super_admin_id' => $isSuperAdmin ? $userId : null,
            'title'                 => $title,
            'body'                  => $body,
            'priority'              => $priority,
            'is_pinned'             => $isPinned ? 1 : 0,
            'status'                => 'published',
            'publish_at'            => gmdate('Y-m-d H:i:s'),
            'expires_at'            => $expiresAt,
        ]);

        (new AuditLog())->record('notice.published', $this->request, $userId, TenantContext::id(), 'notice', $id);

        return $this->json(['data' => $this->present((array) $notices->findForDisplay($id))], 201);
    }

    /**
     * Shapes a row for the client. Only the fields the UI needs are exposed;
     * dates become ISO-8601 UTC so the browser can format them in local time.
     *
     * @param array<string, mixed> $notice
     * @return array<string, mixed>
     */
    private function present(array $notice): array
    {
        return [
            'id'           => (int) $notice['id'],
            'title'        => (string) $notice['title'],
            'body'         => (string) $notice['body'],
            'priority'     => (string) $notice['priority'],
            'is_pinned'    => (bool) $notice['is_pinned'],
            'published_at' => self::isoUtc($notice['publish_at']),
            'author_name'  => $notice['author_name'] !== null ? (string) $notice['author_name'] : null,
        ];
    }

    private static function isoUtc(?string $datetime): ?string
    {
        if ($datetime === null) {
            return null;
        }

        return (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
