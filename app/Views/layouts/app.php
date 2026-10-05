<?php
/**
 * Main layout of the management area (corporate, desktop-first).
 *
 * @var string                $content         Rendered page HTML (already escaped by the page template).
 * @var string                $title
 * @var string                $activeNav
 * @var list<string>          $scripts         Paths under public/assets loaded as ES modules.
 * @var string|null           $currentUserName
 * @var string|null           $currentRole
 * @var bool                  $isSuperAdmin
 * @var string|null           $tenantName
 * @var array<string, string> $flashes
 * @var list<array{key: string, label: string, href: string, section?: string}> $navigation Entries allowed for the current role.
 */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> · Koinon</title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="app">
<header class="topbar">
    <a class="topbar__brand" href="/dashboard">Koinon</a>

    <div class="topbar__tenant">
        <?php if ($tenantName !== null): ?>
            <span class="topbar__tenant-name"><?= e($tenantName) ?></span>
        <?php endif; ?>
        <?php if ($isSuperAdmin): ?>
            <span class="tag tag--admin">Modo Super Admin</span>
            <a class="topbar__switch" href="/platform">Plataforma</a>
        <?php endif; ?>
        <a class="topbar__switch" href="/select-condominium">Trocar condomínio</a>
    </div>

    <div class="topbar__user">
        <div class="topbar__identity">
            <a class="topbar__name" href="/account"><?= e($currentUserName) ?></a>
            <span class="topbar__role"><?= e($currentRole) ?></span>
        </div>
        <form method="post" action="/logout">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--ghost">Sair</button>
        </form>
    </div>
</header>

<div class="shell">
    <nav class="sidebar" aria-label="Módulos">
        <ul class="sidebar__list">
            <?php foreach ($navigation as $item): ?>
                <?php if (isset($item['section'])): ?>
                    <li class="sidebar__section"><?= e($item['section']) ?></li>
                <?php endif; ?>
                <li>
                    <a class="sidebar__link<?= $activeNav === $item['key'] ? ' is-active' : '' ?>"
                       href="<?= e($item['href']) ?>"
                       <?= $activeNav === $item['key'] ? 'aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <main class="main">
        <?php foreach ($flashes as $type => $message): ?>
            <div class="alert alert--<?= e($type) ?>" role="status"><?= e($message) ?></div>
        <?php endforeach; ?>

        <?= $content /* already-escaped page HTML */ ?>
    </main>
</div>

<footer class="footer">
    <span>© <?= e(date('Y')) ?> Koinon · Gestão condominial</span>
</footer>

<?php foreach ($scripts as $script): ?>
    <script type="module" src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
