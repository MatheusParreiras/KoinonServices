<?php
/**
 * Platform overview (Super Admin).
 *
 * @var array<string, int>         $counts       status => number of condominiums
 * @var array<string, string>      $statuses     status => label
 * @var list<array<string, mixed>> $condominiums Largest condominiums by active members.
 * @var list<array<string, mixed>> $events       Latest audit entries.
 */
$variants = ['active' => 'active', 'suspended' => 'suspended', 'archived' => 'inactive'];
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Visão geral da plataforma</h1>
        <p class="page-header__subtitle">Condomínios, usuários e eventos recentes</p>
    </div>
    <a class="btn btn--primary" href="/platform/condominiums/new">Novo condomínio</a>
</section>

<div class="kpis">
    <?php foreach ($statuses as $status => $label): ?>
        <a class="kpi kpi--link" href="/platform/condominiums?status=<?= e($status) ?>">
            <span class="kpi__label">Condomínios <?= e(mb_strtolower($label)) ?>s</span>
            <strong class="kpi__value"><?= e($counts[$status] ?? 0) ?></strong>
        </a>
    <?php endforeach; ?>
</div>

<div class="grid-2">
    <section class="panel">
        <h2 class="panel__title panel__title--bar">Usuários por condomínio</h2>
        <?php if ($condominiums === []): ?>
            <p class="state">Nenhum condomínio cadastrado.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--compact">
                    <thead><tr><th>Condomínio</th><th class="num">Ativos</th><th class="num">Convites</th><th class="num">Síndicos</th><th>Situação</th></tr></thead>
                    <tbody>
                    <?php foreach ($condominiums as $c): ?>
                        <tr>
                            <td><a href="/platform/condominiums/<?= e($c['id']) ?>"><?= e($c['name']) ?></a></td>
                            <td class="num"><?= e((int) $c['active_members']) ?></td>
                            <td class="num"><?= e((int) $c['invited_members']) ?></td>
                            <td class="num"><?= e((int) $c['active_managers']) ?></td>
                            <td><?= pill($variants[$c['status']] ?? 'inactive', $statuses[$c['status']] ?? (string) $c['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2 class="panel__title panel__title--bar">
            Eventos recentes
            <a class="btn btn--small panel__title-action" href="/platform/audit">Ver auditoria</a>
        </h2>
        <?php if ($events === []): ?>
            <p class="state">Nenhum evento registrado.</p>
        <?php else: ?>
            <ul class="event-list">
                <?php foreach ($events as $event): ?>
                    <li class="event-list__item">
                        <code><?= e($event['action_code']) ?></code>
                        <span class="muted small">
                            <?= e($event['actor_name'] ?? 'anônimo') ?>
                            · <?= e($event['condominium_name'] ?? 'plataforma') ?>
                            · <?= e(date_br((string) $event['created_at'], true)) ?> UTC
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
