<?php
/**
 * Notice Board. The list is filled by public/assets/js/notices.js from
 * GET /api/notices. The <template> is cloned and filled with textContent, so
 * notice text is never parsed as HTML.
 *
 * @var string|null $tenantName
 * @var bool        $canCreateNotice UI only; POST /api/notices enforces the rule.
 */
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Mural de avisos</h1>
        <p class="page-header__subtitle">Comunicados oficiais de <?= e($tenantName) ?></p>
    </div>
    <?php if ($canCreateNotice): ?>
        <button type="button" class="btn btn--primary" id="open-notice-form">Novo aviso</button>
    <?php endif; ?>
</section>

<section id="notice-board" class="panel" aria-live="polite" aria-busy="true">
    <div class="state" data-state="loading">
        <span class="spinner" aria-hidden="true"></span>
        Carregando avisos…
    </div>
    <div class="state" data-state="empty" hidden>
        Nenhum aviso publicado no momento.
    </div>
    <div class="state state--error" data-state="error" hidden>
        <p>Não foi possível carregar os avisos.</p>
        <button type="button" class="btn" id="retry-notices">Tentar novamente</button>
    </div>
    <ul class="notice-list" id="notice-list" hidden></ul>
</section>

<template id="notice-template">
    <li class="notice">
        <div class="notice__header">
            <span class="badge" data-field="priority"></span>
            <span class="tag" data-field="pinned" hidden>Fixado</span>
            <h2 class="notice__title" data-field="title"></h2>
        </div>
        <p class="notice__body" data-field="body"></p>
        <div class="notice__meta">
            <span data-field="author"></span>
            <span aria-hidden="true">·</span>
            <time data-field="date"></time>
        </div>
    </li>
</template>

<?php if ($canCreateNotice): ?>
    <dialog id="notice-dialog" class="dialog" aria-labelledby="notice-dialog-title">
        <form id="notice-form" class="form" novalidate>
            <h2 id="notice-dialog-title" class="dialog__title">Novo aviso</h2>
            <div class="alert alert--error" id="notice-form-error" hidden></div>

            <label class="form__field">
                <span class="form__label">Título</span>
                <input type="text" name="title" maxlength="150" required>
                <span class="form__error" data-error-for="title"></span>
            </label>

            <label class="form__field">
                <span class="form__label">Mensagem</span>
                <textarea name="body" rows="8" maxlength="10000" required></textarea>
                <span class="form__error" data-error-for="body"></span>
            </label>

            <div class="form__row">
                <label class="form__field">
                    <span class="form__label">Prioridade</span>
                    <select name="priority">
                        <option value="normal">Normal</option>
                        <option value="important">Importante</option>
                        <option value="urgent">Urgente</option>
                    </select>
                    <span class="form__error" data-error-for="priority"></span>
                </label>
                <label class="form__check">
                    <input type="checkbox" name="is_pinned" value="1">
                    Fixar no topo do mural
                </label>
            </div>

            <div class="dialog__actions">
                <button type="button" class="btn" data-close>Cancelar</button>
                <button type="submit" class="btn btn--primary">Publicar aviso</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>
