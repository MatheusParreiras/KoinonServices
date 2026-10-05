<?php
/**
 * @var list<array<string, mixed>> $areas
 * @var array<string, string>      $types
 */
$hours = static fn (array $a): string => $a['opens_at'] === null
    ? 'Dia todo'
    : substr((string) $a['opens_at'], 0, 5) . '–' . substr((string) $a['closes_at'], 0, 5);
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Áreas comuns</h1>
        <p class="page-header__subtitle">Espaços disponíveis para reserva</p>
    </div>
    <a class="btn btn--primary" href="/admin/common-areas/new">Nova área</a>
</section>

<section class="panel">
    <?php if ($areas === []): ?>
        <p class="state">Nenhuma área cadastrada.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Área</th><th>Tipo</th><th class="num">Capacidade</th><th>Horário</th>
                    <th>Duração máx.</th><th>Aprovação</th><th class="num">Reservas</th><th>Situação</th><th class="table__action"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($areas as $area): ?>
                    <tr>
                        <td class="strong"><?= e($area['name']) ?></td>
                        <td><?= e($types[$area['area_type']] ?? $area['area_type']) ?></td>
                        <td class="num"><?= e($area['max_people'] ?? '—') ?></td>
                        <td><?= e($hours($area)) ?></td>
                        <td><?= e($area['max_duration_minutes'] === null ? 'Sem limite' : $area['max_duration_minutes'] . ' min') ?></td>
                        <td><?= (int) $area['requires_approval'] === 1 ? 'Exige aprovação' : 'Automática' ?></td>
                        <td class="num"><?= e((int) $area['reservation_count']) ?></td>
                        <td><?= (int) $area['is_active'] === 1 ? pill('active', 'Ativa') : pill('inactive', 'Inativa') ?></td>
                        <td class="table__action"><a class="btn btn--small" href="/admin/common-areas/<?= e($area['id']) ?>/edit">Editar</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
