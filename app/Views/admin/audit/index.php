<?php
/**
 * Audit log, shared by the Property Manager view (/admin/audit, this
 * condominium only) and the Super Admin view (/platform/audit, every entry).
 * Details are JSON from the database: printed as text through e(), never parsed as HTML.
 *
 * @var list<array<string, mixed>> $entries
 * @var array<string, string>      $modules
 * @var array<string, string>      $query
 * @var array{page: int, per_page: int, total: int, pages: int} $pagination
 * @var string                     $basePath
 * @var bool                       $showTenant   Super Admin view.
 * @var array<int, string>         $condominiums id => name (Super Admin view only)
 */
$format = $showTenant
    ? static fn (string $utc): string => date_br($utc, true) . ' UTC'
    : static fn (string $utc): string => local_datetime($utc);
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title"><?= $showTenant ? 'Auditoria da plataforma' : 'Auditoria' ?></h1>
        <p class="page-header__subtitle">
            <?= $showTenant ? 'Todos os eventos registrados, de todos os condomínios' : 'Ações administrativas e de segurança deste condomínio' ?>
        </p>
    </div>
</section>

<section class="panel">
    <form class="filters" method="get" action="<?= e($basePath) ?>">
        <?php if ($showTenant): ?>
            <label class="filters__field">
                <span class="sr-only">Condomínio</span>
                <select name="condominium_id">
                    <option value="">Todos os condomínios</option>
                    <?php foreach ($condominiums as $id => $name): ?>
                        <option value="<?= e($id) ?>"<?= ($query['condominium_id'] ?? '') === (string) $id ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <label class="filters__field">
            <span class="sr-only">Módulo</span>
            <select name="module">
                <option value="">Todos os módulos</option>
                <?php foreach ($modules as $code => $label): ?>
                    <option value="<?= e($code) ?>"<?= ($query['module'] ?? '') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filters__field filters__field--grow">
            <span class="sr-only">Autor</span>
            <input type="search" name="q" maxlength="100" placeholder="Autor (nome ou e-mail)" value="<?= e($query['q'] ?? '') ?>">
        </label>
        <label class="filters__field">
            <span class="sr-only">De</span>
            <input type="date" name="from" value="<?= e($query['from'] ?? '') ?>">
        </label>
        <label class="filters__field">
            <span class="sr-only">Até</span>
            <input type="date" name="to" value="<?= e($query['to'] ?? '') ?>">
        </label>
        <button type="submit" class="btn">Filtrar</button>
    </form>

    <?php if ($entries === []): ?>
        <p class="state">Nenhum evento encontrado.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--compact">
                <thead>
                <tr>
                    <th>Quando</th><th>Ação</th>
                    <?php if ($showTenant): ?><th>Condomínio</th><?php endif; ?>
                    <th>Autor</th><th>Alvo</th><th>Detalhes</th><th>IP</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td class="nowrap"><?= e($format((string) $entry['created_at'])) ?></td>
                        <td><code><?= e($entry['action_code']) ?></code></td>
                        <?php if ($showTenant): ?>
                            <td><?= e($entry['condominium_name'] ?? '— plataforma') ?></td>
                        <?php endif; ?>
                        <td>
                            <?= e($entry['actor_name'] ?? 'Sistema / anônimo') ?>
                            <?php if ($entry['actor_email'] !== null): ?><div class="small muted"><?= e($entry['actor_email']) ?></div><?php endif; ?>
                        </td>
                        <td><?= e($entry['entity_type'] === null ? '' : $entry['entity_type'] . ' #' . $entry['entity_id']) ?></td>
                        <td class="audit__details"><?= e($entry['details'] ?? '') ?></td>
                        <td class="mono small"><?= e($entry['ip'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= partial('pagination', ['pagination' => $pagination, 'basePath' => $basePath, 'query' => $query]) ?>
</section>
