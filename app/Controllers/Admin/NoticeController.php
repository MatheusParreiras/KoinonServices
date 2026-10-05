<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\TenantContext;
use App\Core\Validator;
use App\Models\Notice;
use App\Services\AuditLogger;

/**
 * Notice Board management (Property Manager, Phase 5): list every notice
 * (visible, scheduled, expired), edit, pin, set expiry, delete.
 *
 * Creating a notice stays on the dashboard dialog (Phase 2, POST /api/notices),
 * which now also accepts an expiry date. Managers may edit or delete any notice
 * of their condominium, including those published by the concierge.
 */
final class NoticeController extends AdminController
{
    /** GET /admin/notices */
    public function index(): Response
    {
        $this->requireRole(self::MANAGERS);
        $notices = new Notice();
        $pagination = $this->pagination(25)->withTotal($notices->countAll());

        return $this->view('admin/notices/index', [
            'title'      => 'Avisos',
            'activeNav'  => 'admin-notices',
            'notices'    => $notices->forAdmin($pagination),
            'pagination' => $pagination->toArray(),
            'scripts'    => ['js/admin/confirm.js'],
        ]);
    }

    /** GET /admin/notices/{id}/edit */
    public function edit(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $notice = (new Notice())->find((int) $id) ?? throw new HttpException(404);   // tenant-scoped

        return $this->view('admin/notices/edit', [
            'title'     => 'Editar aviso',
            'activeNav' => 'admin-notices',
            'notice'    => $notice,
            'expiresLocal' => $notice['expires_at'] === null ? '' : TenantContext::utcToLocal((string) $notice['expires_at']),
        ]);
    }

    /** POST /admin/notices/{id} */
    public function update(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $notices = new Notice();
        $notice = $notices->find((int) $id) ?? throw new HttpException(404);
        $back = "/admin/notices/{$id}/edit";

        $v = new Validator($this->request);
        $title = $v->string('title', 'Título', 3, Notice::TITLE_MAX);
        $body = $v->string('body', 'Mensagem', 1, Notice::BODY_MAX);
        $priority = $v->enum('priority', 'Prioridade', Notice::PRIORITIES);
        $expiresAt = null;
        if ($v->filled('expires_at')) {
            $local = $v->dateTimeLocal('expires_at', 'Data de expiração');
            if ($local !== null) {
                $expiresAt = TenantContext::localToUtc($local);
                // Schema CHECK ck_notices_window: expires_at > publish_at.
                if ($notice['publish_at'] !== null && $expiresAt <= (string) $notice['publish_at']) {
                    $v->addError('expires_at', 'A expiração deve ser depois da publicação.');
                }
            }
        }
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back);
        }

        Database::transaction(function () use ($notices, $id, $title, $body, $priority, $expiresAt): void {
            $notices->update((int) $id, [
                'title'      => $title,
                'body'       => $body,
                'priority'   => $priority,
                'is_pinned'  => $this->request->boolean('is_pinned') ? 1 : 0,
                'expires_at' => $expiresAt,
            ]);
            (new AuditLogger($this->request))->tenant('notice.updated', 'notice', (int) $id);
        });

        return $this->done('Aviso atualizado.', '/admin/notices');
    }

    /**
     * POST /admin/notices/{id}/delete
     *
     * A real DELETE (read receipts cascade). The title is kept in the audit
     * entry, written in the same transaction, so the deletion stays traceable.
     */
    public function destroy(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $notices = new Notice();
        $notice = $notices->find((int) $id) ?? throw new HttpException(404);

        Database::transaction(function () use ($notices, $notice): void {
            (new AuditLogger($this->request))->tenant('notice.deleted', 'notice', (int) $notice['id'], [
                'title'      => $notice['title'],
                'publish_at' => $notice['publish_at'],
            ]);
            $notices->delete((int) $notice['id']);
        });

        return $this->done('Aviso excluído.', '/admin/notices');
    }
}
