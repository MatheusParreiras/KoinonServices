<?php
/**
 * Community feed page.
 *
 * Post cards are NOT rendered here: community/feed.js loads them from
 * GET /api/community/posts and builds each card by cloning #post-card-template
 * and filling it with textContent. The template holds only static markup, so
 * the card structure is defined once (here) and user content never touches innerHTML.
 *
 * @var list<array{id: int, code: string, name: string}> $categories Allowlist from social_categories.
 * @var array<string, string> $reasons    Report reasons (PostReport::REASONS).
 * @var int                   $bodyMax
 * @var int                   $commentMax
 * @var string|null           $currentUserName
 * @var bool                  $canModerate
 */
?>
<div class="c-grid">
    <section class="c-column">
        <!-- Composer -->
        <form class="c-card c-composer" id="post-composer" novalidate>
            <div class="c-composer__row">
                <span class="c-avatar" data-initials-of="<?= e($currentUserName) ?>" aria-hidden="true"></span>
                <label class="c-sr-only" for="composer-body">Escreva uma publicação</label>
                <textarea id="composer-body" name="body" rows="3" maxlength="<?= e($bodyMax) ?>"
                          placeholder="Compartilhe algo com seus vizinhos…" required></textarea>
            </div>
            <div class="c-composer__footer">
                <label class="c-select">
                    <span class="c-sr-only">Categoria</span>
                    <select name="category" required>
                        <option value="">Categoria…</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= e($category['code']) ?>"><?= e($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <span class="c-counter" data-char-counter data-max="<?= e($bodyMax) ?>">0 / <?= e($bodyMax) ?></span>
                <button type="submit" class="c-btn c-btn--primary">Publicar</button>
            </div>
        </form>

        <!-- Category filter -->
        <nav class="c-chips" id="category-filter" aria-label="Filtrar por categoria">
            <button type="button" class="c-chip is-active" data-category="" aria-pressed="true">Tudo</button>
            <?php foreach ($categories as $category): ?>
                <button type="button" class="c-chip c-chip--<?= e($category['code']) ?>"
                        data-category="<?= e($category['code']) ?>" aria-pressed="false"><?= e($category['name']) ?></button>
            <?php endforeach; ?>
        </nav>

        <!-- Feed (event delegation root) -->
        <section id="feed" class="c-feed" aria-live="polite" aria-busy="true"
                 data-comment-max="<?= e($commentMax) ?>">
            <div class="c-state" data-state="loading">
                <div class="c-skeleton"></div>
                <div class="c-skeleton"></div>
            </div>
            <div class="c-state c-state--empty" data-state="empty" hidden>
                <p class="c-state__title">Nada por aqui ainda</p>
                <p>Seja o primeiro a publicar nesta categoria!</p>
            </div>
            <div class="c-state c-state--error" data-state="error" hidden>
                <p class="c-state__title">Não foi possível carregar o mural.</p>
                <button type="button" class="c-btn" data-action="retry">Tentar novamente</button>
            </div>
            <div class="c-feed__list" data-feed-list></div>
        </section>

        <div class="c-more">
            <button type="button" class="c-btn c-btn--soft" id="load-more" hidden>Carregar mais publicações</button>
        </div>
    </section>

    <aside class="c-aside">
        <div class="c-card c-card--padded">
            <h2 class="c-aside__title">Boas-vindas à comunidade 👋</h2>
            <p>Um espaço para vizinhos se ajudarem: vendas, achados e perdidos, dicas e pets.</p>
            <ul class="c-rules">
                <li>Seja gentil e respeitoso.</li>
                <li>Nada de dados pessoais de terceiros.</li>
                <li>Viu algo inadequado? Use “Denunciar”.</li>
            </ul>
            <?php if ($canModerate): ?>
                <a class="c-btn c-btn--soft c-btn--block" href="/community/moderation">Abrir moderação</a>
            <?php endif; ?>
        </div>
    </aside>
</div>

<!-- Post card template: static markup only; filled by community/render.js -->
<template id="post-card-template">
    <article class="c-card c-post">
        <header class="c-post__header">
            <span class="c-avatar" data-field="avatar" aria-hidden="true"></span>
            <div class="c-post__meta">
                <strong class="c-post__author" data-field="author"></strong>
                <time class="c-post__time" data-field="time"></time>
            </div>
            <span class="c-badge" data-field="category"></span>
        </header>

        <p class="c-post__body" data-field="body"></p>

        <div class="c-post__actions">
            <button type="button" class="c-action c-action--like" data-action="like" aria-pressed="false">
                <span class="c-action__icon" aria-hidden="true">♥</span>
                <span data-field="likes-label">Curtir</span>
                <span class="c-action__count" data-field="likes-count">0</span>
            </button>
            <button type="button" class="c-action" data-action="focus-comment">
                <span class="c-action__icon" aria-hidden="true">💬</span>
                Comentar
                <span class="c-action__count" data-field="comments-count">0</span>
            </button>
            <button type="button" class="c-action c-action--report" data-action="report">
                <span class="c-action__icon" aria-hidden="true">⚑</span>
                <span data-field="report-label">Denunciar</span>
            </button>
        </div>

        <div class="c-comments">
            <button type="button" class="c-link" data-action="load-comments" hidden></button>
            <ul class="c-comments__list" data-field="comments"></ul>
            <form class="c-comment-form" data-comment-form novalidate>
                <label class="c-sr-only">Escreva um comentário
                    <input type="text" name="body" required autocomplete="off">
                </label>
                <button type="submit" class="c-btn c-btn--small c-btn--primary">Enviar</button>
            </form>
        </div>
    </article>
</template>

<!-- One report dialog, reused for every post (data-post-id set by feed.js) -->
<dialog class="c-dialog" id="report-dialog" aria-labelledby="report-title">
    <form method="dialog" class="c-dialog__form" id="report-form" novalidate>
        <h2 id="report-title" class="c-dialog__title">Denunciar publicação</h2>
        <p class="c-muted">A administração vai analisar. Quem publicou não verá quem denunciou.</p>
        <fieldset class="c-reasons">
            <legend class="c-sr-only">Motivo</legend>
            <?php foreach ($reasons as $value => $label): ?>
                <label class="c-reason">
                    <input type="radio" name="reason" value="<?= e($value) ?>" required>
                    <span><?= e($label) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <label class="c-field">
            <span>Detalhes (opcional)</span>
            <textarea name="details" rows="3" maxlength="500"></textarea>
        </label>
        <div class="c-dialog__actions">
            <button type="button" class="c-btn" data-close-dialog>Cancelar</button>
            <button type="submit" class="c-btn c-btn--danger" value="send">Enviar denúncia</button>
        </div>
    </form>
</dialog>
