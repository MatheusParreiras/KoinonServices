<?php
/**
 * Layout of the Community tab: friendly and card-based, deliberately different
 * from the corporate management layout (Phase 1, NFR-ARCH-07). Loads its own
 * stylesheet (community.css) instead of app.css.
 *
 * @var string                $content         Rendered page HTML (already escaped by the page template).
 * @var string                $title
 * @var list<string>          $scripts
 * @var string|null           $currentUserName
 * @var string|null           $tenantName
 * @var array<string, string> $flashes
 * @var string                $activeTab         "feed" | "moderation"
 * @var bool                  $showModerationTab Manager or Super Admin.
 * @var bool                  $isMember          False for the Super Admin (moderation view only).
 */
$isMember ??= true;
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> · Koinon</title>
    <link rel="stylesheet" href="<?= e(asset('css/community.css')) ?>">
</head>
<body class="community">
<header class="c-topbar">
    <div class="c-topbar__inner">
        <a class="c-brand" href="/community">
            <span class="c-brand__mark" aria-hidden="true">K</span>
            <span>Comunidade <span class="c-brand__tenant"><?= e($tenantName) ?></span></span>
        </a>

        <nav class="c-tabs" aria-label="Seções">
            <a class="c-tabs__item" href="/dashboard">Gestão</a>
            <?php if ($isMember): ?>
                <a class="c-tabs__item<?= $activeTab === 'feed' ? ' is-active' : '' ?>" href="/community">Mural</a>
            <?php endif; ?>
            <?php if ($showModerationTab): ?>
                <a class="c-tabs__item<?= $activeTab === 'moderation' ? ' is-active' : '' ?>" href="/community/moderation">Moderação</a>
            <?php endif; ?>
        </nav>

        <div class="c-me">
            <span class="c-me__name"><?= e($currentUserName) ?></span>
            <form method="post" action="/logout">
                <?= csrf_field() ?>
                <button type="submit" class="c-btn c-btn--ghost">Sair</button>
            </form>
        </div>
    </div>
</header>

<main class="c-main">
    <?php foreach ($flashes as $type => $message): ?>
        <div class="c-alert c-alert--<?= e($type) ?>" role="status"><?= e($message) ?></div>
    <?php endforeach; ?>

    <?= $content /* already-escaped page HTML */ ?>
</main>

<!-- Toasts are created by community/toast.js with textContent only. -->
<div class="c-toasts" id="toast-region" aria-live="polite" aria-atomic="false"></div>

<?php foreach ($scripts as $script): ?>
    <script type="module" src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
