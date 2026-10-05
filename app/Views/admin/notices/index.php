<?php
/**
 * Every notice of the condominium, with its visibility state.
 *
 * @var list<array<string, mixed>> $notices
 * @var array{page: int, per_page: int, total: int, pages: int} $pagination
 */
$visibility = [
    'visible'   => ['active', 'Visível'],
    'scheduled' => ['pending', 'Agendado'],
    'expired'   => ['inactive', 'Expirado'],
    'archived'  => ['inactive', 'Arquivado'],
    'draft'     => ['inactive', 'Rascunho'],
];
$priorities = ['normal' => 'Normal', 'important' => 'Importante', 'urgent' => 'Urgente'];
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Avisos</h1>
        <p class="page-header__subtitle">Edite, fixe, defina a expiração ou exclua avisos do mural</p>
    </div>
    <a class="btn btn--primary" href="/dashboard">Publicar novo aviso</a>
</section>

<section class="panel">
    <?php if ($notices === []): ?>
        <p class="state">Nenhum aviso publicado ainda.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Título</th><th>Prioridade</th><th>Publicado em</th><th>Expira em</th><th>Autor</th><th>Situação</th><th class="table__action">Ações</th></tr>
                </thead>
                <tbody>
                <?php foreach ($notices as $notice): ?>
                    <?php [$variant, $label] = $visibility[$notice['visibility']] ?? ['inactive', (string) $notice['visibility']]; ?>
                    <tr>
                        <td class="strong">
                            <?= e($notice['title']) ?>
                            <?php if ((int) $notice['is_pinned'] === 1): ?><span class="tag">Fixado</span><?php endif; ?>
                        </td>
                        <td><?= e($priorities[$notice['priority']] ?? $notice['priority']) ?></td>
                        <td><?= e(local_datetime($notice['publish_at'])) ?></td>
                        <td><?= e($notice['expires_at'] === null ? '—' : local_datetime((string) $notice['expires_at'])) ?></td>
                        <td><?= e($notice['author_name'] ?? 'Administração') ?></td>
                        <td><?= pill($variant, $label) ?></td>
                        <td class="table__action">
                            <span class="inline-form">
                                <a class="btn btn--small" href="/admin/notices/<?= e($notice['id']) ?>/edit">Editar</a>
                                <form method="post" action="/admin/notices/<?= e($notice['id']) ?>/delete"
                                      data-confirm="Excluir o aviso &quot;<?= e($notice['title']) ?>&quot;? Esta ação não pode ser desfeita.">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn--small btn--danger-ghost">Excluir</button>
                                </form>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= partial('pagination', ['pagination' => $pagination, 'basePath' => '/admin/notices', 'query' => []]) ?>
</section>
