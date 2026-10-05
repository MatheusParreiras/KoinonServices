<?php
/**
 * Units: filters + list (server-rendered, paginated), single and bulk creation.
 *
 * @var list<array<string, mixed>>             $units
 * @var list<string>                           $buildings
 * @var array<string, string>                  $types
 * @var array<string, string>                  $query      Current filters.
 * @var array{page: int, per_page: int, total: int, pages: int} $pagination
 * @var int                                    $bulkMax
 * @var array<string, string>                  $errors
 * @var array<string, string>                  $old
 */
$selected = static fn (string $field, string $value): string
    => (string) ($old[$field] ?? '') === $value ? ' selected' : '';
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Unidades</h1>
        <p class="page-header__subtitle">Apartamentos, casas e salas do condomínio</p>
    </div>
</section>

<?= field_error($errors, 'general') ?>

<div class="columns">
    <div class="columns__side">
        <section class="panel panel--padded">
            <h2 class="panel__title">Nova unidade</h2>
            <form method="post" action="/admin/units" class="form" novalidate>
                <?= csrf_field() ?>
                <div class="form__row">
                    <label class="form__field">
                        <span class="form__label">Bloco/Torre</span>
                        <input type="text" name="building" maxlength="30" value="<?= e($old['building'] ?? '') ?>" placeholder="Ex.: A">
                        <?= field_error($errors, 'building') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">Número</span>
                        <input type="text" name="unit_number" maxlength="20" required value="<?= e($old['unit_number'] ?? '') ?>">
                        <?= field_error($errors, 'unit_number') ?>
                    </label>
                </div>
                <div class="form__row">
                    <label class="form__field">
                        <span class="form__label">Andar</span>
                        <input type="number" name="floor_number" min="-5" max="200" value="<?= e($old['floor_number'] ?? '') ?>">
                        <?= field_error($errors, 'floor_number') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">Tipo</span>
                        <select name="unit_type">
                            <?php foreach ($types as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $selected('unit_type', $value) ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <button type="submit" class="btn btn--primary">Cadastrar unidade</button>
            </form>
        </section>

        <section class="panel panel--padded">
            <h2 class="panel__title">Cadastro em lote</h2>
            <form method="post" action="/admin/units/bulk" class="form" novalidate>
                <?= csrf_field() ?>
                <label class="form__field">
                    <span class="form__label">Bloco/Torre</span>
                    <input type="text" name="bulk_building" maxlength="30" required value="<?= e($old['bulk_building'] ?? '') ?>" placeholder="Ex.: Torre A">
                    <?= field_error($errors, 'bulk_building') ?>
                </label>
                <div class="form__row">
                    <label class="form__field">
                        <span class="form__label">Do andar</span>
                        <input type="number" name="first_floor" min="0" max="200" required value="<?= e($old['first_floor'] ?? '1') ?>">
                        <?= field_error($errors, 'first_floor') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">Até o andar</span>
                        <input type="number" name="last_floor" min="0" max="200" required value="<?= e($old['last_floor'] ?? '10') ?>">
                        <?= field_error($errors, 'last_floor') ?>
                    </label>
                </div>
                <div class="form__row">
                    <label class="form__field">
                        <span class="form__label">Unidades por andar</span>
                        <input type="number" name="units_per_floor" min="1" max="50" required value="<?= e($old['units_per_floor'] ?? '4') ?>">
                        <?= field_error($errors, 'units_per_floor') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">Tipo</span>
                        <select name="bulk_unit_type">
                            <?php foreach ($types as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $selected('bulk_unit_type', $value) ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <p class="form__hint">Numeração: andar × 100 + posição (andar 3, unidade 2 = 302). Unidades já existentes são mantidas. Máximo de <?= e($bulkMax) ?> por vez.</p>
                <button type="submit" class="btn">Gerar unidades</button>
            </form>
        </section>
    </div>

    <div class="columns__main">
        <section class="panel">
            <form class="filters" method="get" action="/admin/units">
                <label class="filters__field filters__field--grow">
                    <span class="sr-only">Número</span>
                    <input type="search" name="q" placeholder="Buscar por número" maxlength="20" value="<?= e($query['q'] ?? '') ?>">
                </label>
                <label class="filters__field">
                    <span class="sr-only">Bloco</span>
                    <select name="building">
                        <option value="">Todos os blocos</option>
                        <?php foreach ($buildings as $building): ?>
                            <option value="<?= e($building) ?>"<?= ($query['building'] ?? '') === $building ? ' selected' : '' ?>><?= e($building) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="filters__field">
                    <span class="sr-only">Situação</span>
                    <select name="status">
                        <option value="">Ativas e inativas</option>
                        <option value="active"<?= ($query['status'] ?? '') === 'active' ? ' selected' : '' ?>>Ativas</option>
                        <option value="inactive"<?= ($query['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Inativas</option>
                    </select>
                </label>
                <button type="submit" class="btn">Filtrar</button>
            </form>

            <?php if ($units === []): ?>
                <p class="state">Nenhuma unidade encontrada.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th>Unidade</th><th>Andar</th><th>Tipo</th><th class="num">Moradores</th><th>Situação</th><th class="table__action"></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($units as $unit): ?>
                            <tr>
                                <td class="strong"><?= e($unit['label']) ?></td>
                                <td><?= e($unit['floor_number']) ?></td>
                                <td><?= e($types[$unit['unit_type']] ?? $unit['unit_type']) ?></td>
                                <td class="num"><?= e((int) $unit['resident_count']) ?></td>
                                <td><?= (int) $unit['is_active'] === 1 ? pill('active', 'Ativa') : pill('inactive', 'Inativa') ?></td>
                                <td class="table__action"><a class="btn btn--small" href="/admin/units/<?= e($unit['id']) ?>/edit">Editar</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?= partial('pagination', ['pagination' => $pagination, 'basePath' => '/admin/units', 'query' => $query]) ?>
        </section>
    </div>
</div>
