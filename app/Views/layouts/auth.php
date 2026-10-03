<?php
/**
 * Minimal centred layout for logged-out pages, the condominium selector and
 * error pages. It needs no database access, so error pages can always render.
 *
 * @var string                $content Rendered page HTML (already escaped).
 * @var string                $title
 * @var array<string, string> $flashes
 */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Koinon') ?> · Koinon</title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="auth">
<main class="auth__card">
    <div class="auth__brand">Koinon</div>

    <?php foreach (($flashes ?? []) as $type => $message): ?>
        <div class="alert alert--<?= e($type) ?>" role="status"><?= e($message) ?></div>
    <?php endforeach; ?>

    <?= $content /* already-escaped page HTML */ ?>
</main>
</body>
</html>
