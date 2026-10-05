<?php
/**
 * Previous / next links for a server-rendered list. Keeps the current filters:
 * $query holds the already-validated filter values, and http_build_query()
 * URL-encodes them before e() escapes the attribute.
 *
 * @var array{page: int, per_page: int, total: int, pages: int} $pagination
 * @var string                $basePath e.g. "/admin/units"
 * @var array<string, string> $query    Current filters (without "page").
 */
$link = static fn (int $page): string => $basePath . '?' . http_build_query($query + ['page' => $page]);
?>
<nav class="pager" aria-label="Paginação">
    <span class="pager__info muted">
        <?= e($pagination['total']) ?> registro(s) · página <?= e($pagination['page']) ?> de <?= e($pagination['pages']) ?>
    </span>
    <?php if ($pagination['page'] > 1): ?>
        <a class="btn btn--small" href="<?= e($link($pagination['page'] - 1)) ?>">Anterior</a>
    <?php endif; ?>
    <?php if ($pagination['page'] < $pagination['pages']): ?>
        <a class="btn btn--small" href="<?= e($link($pagination['page'] + 1)) ?>">Próxima</a>
    <?php endif; ?>
</nav>
