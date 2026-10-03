<?php
/**
 * Moderation queue, rendered on the server: every piece of user content is
 * printed with e(). Actions (delete / dismiss) are sent by
 * community/moderation.js and enforced server-side (role "manager").
 *
 * Reporters are not identified here on purpose (data minimisation).
 *
 * @var list<array<string, mixed>> $queue    Posts with pending reports, each with 'reports'.
 * @var array<string, string>      $reasons
 * @var bool                       $canModerate False for the Super Admin (view only).
 */
?>
<div class="c-page-head">
    <div>
        <h1 class="c-page-title">Moderação</h1>
        <p class="c-muted">Publicações denunciadas pelos moradores. Publicações com 3 ou mais denúncias ficam ocultas até a sua análise.</p>
    </div>
    <span class="c-pill" data-queue-count><?= e(count($queue)) ?> pendente(s)</span>
</div>

<?php if (!$canModerate): ?>
    <div class="c-alert c-alert--warning">Modo de consulta: apenas a administração do condomínio pode remover publicações ou descartar denúncias.</div>
<?php endif; ?>

<section class="c-modlist" id="moderation-list">
    <?php if ($queue === []): ?>
        <div class="c-card c-state c-state--empty">
            <p class="c-state__title">Tudo tranquilo por aqui ✨</p>
            <p>Nenhuma denúncia pendente.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($queue as $post): ?>
        <article class="c-card c-mod" data-post-id="<?= e($post['id']) ?>">
            <header class="c-post__header">
                <span class="c-avatar" data-initials-of="<?= e($post['author_name']) ?>" aria-hidden="true"></span>
                <div class="c-post__meta">
                    <strong class="c-post__author"><?= e($post['author_name']) ?></strong>
                    <span class="c-post__time"><?= e(local_datetime($post['created_at'])) ?></span>
                </div>
                <span class="c-badge c-badge--<?= e($post['category_code']) ?>"><?= e($post['category_name']) ?></span>
                <?php if ($post['status'] === 'hidden'): ?>
                    <span class="c-pill c-pill--warning">Oculta automaticamente</span>
                <?php endif; ?>
            </header>

            <p class="c-post__body"><?= e($post['body']) ?></p>

            <div class="c-mod__reports">
                <strong><?= e($post['pending_reports']) ?> denúncia(s)</strong>
                <ul>
                    <?php foreach ($post['reports'] as $report): ?>
                        <li>
                            <span class="c-pill c-pill--danger"><?= e($reasons[$report['reason']] ?? $report['reason']) ?></span>
                            <?php if (!empty($report['details'])): ?>
                                <span class="c-mod__details">“<?= e($report['details']) ?>”</span>
                            <?php endif; ?>
                            <span class="c-muted"><?= e(local_datetime($report['created_at'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if ($canModerate): ?>
                <div class="c-mod__actions">
                    <button type="button" class="c-btn" data-action="dismiss">Descartar denúncias</button>
                    <button type="button" class="c-btn c-btn--danger" data-action="delete">Excluir publicação</button>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
