<?php
/**
 * One condominium (Super Admin): data, Property Managers + invitation, and
 * suspension / reactivation.
 *
 * @var array<string, mixed>       $condominium
 * @var list<array<string, mixed>> $managers
 * @var array<string, string>      $statuses
 * @var array<string, string>      $plans
 * @var array<string, string>      $errors
 * @var array<string, string>      $old
 */
$variants = ['active' => 'active', 'suspended' => 'suspended', 'archived' => 'inactive'];
$id = (int) $condominium['id'];
$memberLabel = static function (array $m): array {
    if ($m['status'] === 'invited') {
        return (int) ($m['invitation_expired'] ?? 1) === 1 ? ['expired', 'Convite expirado'] : ['invited', 'Convite pendente'];
    }

    return $m['status'] === 'active' ? ['active', 'Ativo'] : ['inactive', 'Desativado'];
};
?>
<section class="page-header">
    <div>
        <p class="page-header__eyebrow"><a href="/platform/condominiums">← Condomínios</a></p>
        <h1 class="page-header__title"><?= e($condominium['name']) ?></h1>
        <p class="page-header__subtitle">
            <?= pill($variants[$condominium['status']] ?? 'inactive', $statuses[$condominium['status']] ?? (string) $condominium['status']) ?>
            · Plano <?= e($plans[$condominium['plan']] ?? $condominium['plan']) ?>
        </p>
    </div>
    <a class="btn" href="/platform/condominiums/<?= e($id) ?>/edit">Editar dados</a>
</section>

<?= field_error($errors, 'general') ?>

<div class="grid-2">
    <section class="panel panel--padded">
        <h2 class="panel__title">Dados</h2>
        <dl class="details">
            <dt>CNPJ</dt><dd><?= e($condominium['legal_id'] ?? '—') ?></dd>
            <dt>Endereço</dt><dd><?= e($condominium['address_line'] . ', ' . $condominium['city'] . '/' . $condominium['state_province'] . ' · ' . $condominium['postal_code']) ?></dd>
            <dt>Contato</dt><dd><?= e(trim(($condominium['contact_name'] ?? '') . ' ' . ($condominium['email'] ?? '') . ' ' . ($condominium['phone'] ?? '')) ?: '—') ?></dd>
            <dt>Fuso horário</dt><dd><?= e($condominium['timezone']) ?></dd>
            <dt>Código de cadastro</dt><dd class="mono"><?= e($condominium['signup_code']) ?></dd>
            <dt>Criado em</dt><dd><?= e(date_br((string) $condominium['created_at'], true)) ?> UTC</dd>
            <?php if ($condominium['status'] === 'suspended'): ?>
                <dt>Suspenso em</dt><dd><?= e(date_br((string) $condominium['suspended_at'], true)) ?> UTC</dd>
                <dt>Motivo</dt><dd class="prewrap"><?= e($condominium['suspension_reason']) ?></dd>
            <?php endif; ?>
        </dl>
    </section>

    <section class="panel panel--padded">
        <h2 class="panel__title">Síndicos</h2>
        <?php if ($managers === []): ?>
            <p class="muted">Nenhum síndico ainda. Convide o primeiro abaixo.</p>
        <?php else: ?>
            <ul class="person-list">
                <?php foreach ($managers as $manager): ?>
                    <?php [$variant, $label] = $memberLabel($manager); ?>
                    <li class="person-list__item">
                        <div>
                            <div class="strong"><?= e($manager['full_name']) ?></div>
                            <div class="small muted"><?= e($manager['email']) ?></div>
                        </div>
                        <?= pill($variant, $label) ?>
                        <?php if ($manager['status'] === 'invited'): ?>
                            <form method="post" action="/platform/condominiums/<?= e($id) ?>/managers/<?= e($manager['user_id']) ?>/resend">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn--small">Reenviar convite</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($condominium['status'] === 'active'): ?>
            <form method="post" action="/platform/condominiums/<?= e($id) ?>/managers" class="form form--separated" novalidate>
                <?= csrf_field() ?>
                <h3 class="panel__subtitle">Convidar síndico</h3>
                <div class="form__row">
                    <label class="form__field">
                        <span class="form__label">Nome</span>
                        <input type="text" name="full_name" maxlength="150" required value="<?= e($old['full_name'] ?? '') ?>">
                        <?= field_error($errors, 'full_name') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">E-mail</span>
                        <input type="email" name="email" maxlength="254" required value="<?= e($old['email'] ?? '') ?>">
                        <?= field_error($errors, 'email') ?>
                    </label>
                </div>
                <button type="submit" class="btn btn--primary">Enviar convite</button>
            </form>
        <?php endif; ?>
    </section>
</div>

<section class="panel panel--padded panel--danger">
    <?php if ($condominium['status'] === 'active'): ?>
        <h2 class="panel__title">Suspender condomínio</h2>
        <p>Os usuários deste condomínio perdem o acesso imediatamente (inclusive sessões abertas). Nenhum dado é apagado.</p>
        <form method="post" action="/platform/condominiums/<?= e($id) ?>/suspend" class="form" novalidate
              data-confirm="Suspender <?= e($condominium['name']) ?>? Todos os usuários dele perderão o acesso.">
            <?= csrf_field() ?>
            <label class="form__field">
                <span class="form__label">Motivo</span>
                <input type="text" name="suspension_reason" minlength="5" maxlength="255" required>
                <?= field_error($errors, 'suspension_reason') ?>
            </label>
            <button type="submit" class="btn btn--danger">Suspender</button>
        </form>
    <?php elseif ($condominium['status'] === 'suspended'): ?>
        <h2 class="panel__title">Reativar condomínio</h2>
        <form method="post" action="/platform/condominiums/<?= e($id) ?>/reactivate" class="form"
              data-confirm="Reativar <?= e($condominium['name']) ?>? Os usuários voltam a ter acesso.">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--primary">Reativar</button>
        </form>
    <?php else: ?>
        <p class="muted">Condomínio arquivado.</p>
    <?php endif; ?>
</section>
