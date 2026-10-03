<?php
/**
 * Ticket list. The rows were already filtered by the model: a resident's
 * query only ever returned their own tickets.
 *
 * @var list<array<string, mixed>> $occurrences
 * @var string|null                $filter
 * @var bool                       $seeAll
 * @var bool                       $canCreate
 * @var array<string, string>      $types
 * @var array<string, string>      $statuses
 */
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Ocorrências</h1>
        <p class="page-header__subtitle">
            <?= $seeAll ? 'Todas as ocorrências do condomínio' : 'Suas reclamações e relatos de dano' ?>
        </p>
    </div>
    <?php if ($canCreate): ?>
        <a class="btn btn--primary" href="/occurrences/new">Nova ocorrência</a>
    <?php endif; ?>
</section>

<nav class="tabs" aria-label="Filtrar por status">
    <a class="tabs__item<?= $filter === null ? ' is-active' : '' ?>" href="/occurrences">Todas</a>
    <?php foreach ($statuses as $value => $label): ?>
        <a class="tabs__item<?= $filter === $value ? ' is-active' : '' ?>" href="/occurrences?status=<?= e(urlencode($value)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<section class="panel">
    <?php if ($occurrences === []): ?>
        <p class="state">Nenhuma ocorrência encontrada.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Nº</th><th>Título</th><th>Tipo</th>
                    <?php if ($seeAll): ?><th>Aberta por</th><?php endif; ?>
                    <th>Aberta em</th><th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($occurrences as $o): ?>
                    <tr>
                        <td class="mono"><?= e($o['protocol_number']) ?></td>
                        <td><a href="/occurrences/<?= e($o['id']) ?>"><?= e($o['title']) ?></a></td>
                        <td><?= e($types[$o['occurrence_type']] ?? $o['occurrence_type']) ?></td>
                        <?php if ($seeAll): ?><td><?= e($o['reporter_name']) ?></td><?php endif; ?>
                        <td><?= e(local_datetime($o['created_at'])) ?></td>
                        <td><span class="pill pill--<?= e($o['status']) ?>"><?= e($statuses[$o['status']] ?? $o['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
