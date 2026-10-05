<?php
/**
 * Edit one member: role, unit and status. Name and e-mail are shown read-only:
 * they belong to the person's global account.
 *
 * @var array<string, mixed>                $member      Member::findByUser() row.
 * @var array<string, mixed>|null           $currentUnit
 * @var list<array{id: int, label: string}> $units
 * @var array<string, string>               $roles
 * @var array<string, string>               $relationships
 * @var bool                                $isSelf
 * @var array<string, string>               $errors
 */
$statusLabels = ['active' => 'Ativo', 'inactive' => 'Desativado', 'invited' => 'Convite pendente'];
$unitId = (int) ($currentUnit['unit_id'] ?? 0);
$relationship = (string) ($currentUnit['relationship'] ?? 'owner');
$userId = (int) $member['user_id'];
?>
<section class="page-header">
    <div>
        <p class="page-header__eyebrow"><a href="/admin/users">← Usuários</a></p>
        <h1 class="page-header__title"><?= e($member['full_name']) ?></h1>
        <p class="page-header__subtitle">
            <?= e($member['email']) ?> ·
            <?= pill((string) $member['status'], $statusLabels[$member['status']] ?? (string) $member['status']) ?>
        </p>
    </div>
</section>

<?= field_error($errors, 'general') ?>

<div class="grid-2">
    <section class="panel panel--padded">
        <h2 class="panel__title">Perfil e unidade</h2>
        <form method="post" action="/admin/users/<?= e($userId) ?>" class="form" novalidate>
            <?= csrf_field() ?>
            <label class="form__field">
                <span class="form__label">Perfil</span>
                <select name="role" <?= $isSelf ? 'disabled' : '' ?>>
                    <?php foreach ($roles as $code => $label): ?>
                        <option value="<?= e($code) ?>"<?= $member['role_code'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($isSelf): ?>
                    <!-- A disabled select is not submitted: send the current role so only the unit changes. -->
                    <input type="hidden" name="role" value="<?= e($member['role_code']) ?>">
                    <span class="form__hint">Você não pode alterar o seu próprio perfil.</span>
                <?php endif; ?>
                <?= field_error($errors, 'role') ?>
            </label>
            <div class="form__row">
                <label class="form__field">
                    <span class="form__label">Unidade</span>
                    <select name="unit_id">
                        <option value="">Nenhuma</option>
                        <?php foreach ($units as $unit): ?>
                            <option value="<?= e($unit['id']) ?>"<?= $unitId === (int) $unit['id'] ? ' selected' : '' ?>><?= e($unit['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error($errors, 'unit_id') ?>
                </label>
                <label class="form__field">
                    <span class="form__label">Vínculo</span>
                    <select name="relationship">
                        <?php foreach ($relationships as $code => $label): ?>
                            <option value="<?= e($code) ?>"<?= $relationship === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="form__actions">
                <button type="submit" class="btn btn--primary">Salvar alterações</button>
            </div>
        </form>
    </section>

    <section class="panel panel--padded">
        <h2 class="panel__title">Acesso</h2>
        <?php if ($member['status'] === 'invited'): ?>
            <p>O convite ainda não foi aceito.</p>
            <form method="post" action="/admin/users/<?= e($userId) ?>/invitation/resend" class="form">
                <?= csrf_field() ?>
                <button type="submit" class="btn">Reenviar convite</button>
            </form>
        <?php endif; ?>

        <?php if ($isSelf): ?>
            <p class="muted">Você não pode desativar a sua própria conta.</p>
        <?php elseif ($member['status'] === 'inactive'): ?>
            <p>Usuário desativado<?= $member['deactivated_at'] !== null ? ' em ' . e(local_datetime((string) $member['deactivated_at'])) : '' ?>. O histórico dele foi mantido.</p>
            <form method="post" action="/admin/users/<?= e($userId) ?>/reactivate" class="form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--primary">Reativar usuário</button>
            </form>
        <?php else: ?>
            <p>Ao desativar, a pessoa perde o acesso a este condomínio na próxima ação. Publicações, cobranças e ocorrências dela são mantidas.</p>
            <form method="post" action="/admin/users/<?= e($userId) ?>/deactivate" class="form"
                  data-confirm="Desativar <?= e($member['full_name']) ?>? A pessoa perderá o acesso a este condomínio.">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--danger">Desativar usuário</button>
            </form>
        <?php endif; ?>
    </section>
</div>
