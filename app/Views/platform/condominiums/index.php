<?php
/**
 * @var list<array<string, mixed>> $condominiums
 * @var array<string, string>      $statuses
 * @var array<string, string>      $plans
 * @var array<string, string>      $query
 * @var array{page: int, per_page: int, total: int, pages: int} $pagination
 */
$variants = ['active' => 'active', 'suspended' => 'suspended', 'archived' => 'inactive'];
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Condomínios</h1>
        <p class="page-header__subtitle">Clientes (tenants) da plataforma</p>
    </div>
    <a class="btn btn--primary" href="/platform/condominiums/new">Novo condomínio</a>
</section>

<section class="panel">
    <form class="filters" method="get" action="/platform/condominiums">
        <label class="filters__field filters__field--grow">
            <span class="sr-only">Buscar</span>
            <input type="search" name="q" maxlength="100" placeholder="Nome, cidade ou CNPJ" value="<?= e($query['q'] ?? '') ?>">
        </label>
        <label class="filters__field">
            <span class="sr-only">Situação</span>
            <select name="status">
                <option value="">Todas as situações</option>
                <?php foreach ($statuses as $code => $label): ?>
                    <option value="<?= e($code) ?>"<?= ($query['status'] ?? '') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn">Filtrar</button>
    </form>

    <?php if ($condominiums === []): ?>
        <p class="state">Nenhum condomínio encontrado.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Condomínio</th><th>Cidade</th><th>Plano</th><th class="num">Usuários ativos</th><th class="num">Síndicos</th><th>Situação</th><th class="table__action"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($condominiums as $c): ?>
                    <tr>
                        <td class="strong"><?= e($c['name']) ?></td>
                        <td><?= e($c['city'] . '/' . $c['state_province']) ?></td>
                        <td><?= e($plans[$c['plan']] ?? $c['plan']) ?></td>
                        <td class="num"><?= e((int) $c['active_members']) ?></td>
                        <td class="num"><?= e((int) $c['active_managers']) ?></td>
                        <td><?= pill($variants[$c['status']] ?? 'inactive', $statuses[$c['status']] ?? (string) $c['status']) ?></td>
                        <td class="table__action"><a class="btn btn--small" href="/platform/condominiums/<?= e($c['id']) ?>">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= partial('pagination', ['pagination' => $pagination, 'basePath' => '/platform/condominiums', 'query' => $query]) ?>
</section>
