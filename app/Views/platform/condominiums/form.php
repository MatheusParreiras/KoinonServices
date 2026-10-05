<?php
/**
 * Create / edit a condominium (Super Admin).
 *
 * @var array<string, mixed>|null $condominium null when creating.
 * @var array<string, string>     $plans
 * @var list<string>              $timezones
 * @var array<string, string>     $errors
 * @var array<string, string>     $old
 */
$value = static fn (string $field, string $default = ''): string
    => (string) ($old[$field] ?? ($condominium[$field] ?? $default));
$action = $condominium === null ? '/platform/condominiums' : '/platform/condominiums/' . (int) $condominium['id'];
$back = $condominium === null ? '/platform/condominiums' : '/platform/condominiums/' . (int) $condominium['id'];
?>
<section class="page-header">
    <div>
        <p class="page-header__eyebrow"><a href="<?= e($back) ?>">← Voltar</a></p>
        <h1 class="page-header__title"><?= $condominium === null ? 'Novo condomínio' : 'Editar ' . e($condominium['name']) ?></h1>
    </div>
</section>

<section class="panel panel--padded panel--narrow">
    <form method="post" action="<?= e($action) ?>" class="form" novalidate>
        <?= csrf_field() ?>
        <?= field_error($errors, 'general') ?>
        <label class="form__field">
            <span class="form__label">Nome</span>
            <input type="text" name="name" maxlength="150" required value="<?= e($value('name')) ?>">
            <?= field_error($errors, 'name') ?>
        </label>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">CNPJ (opcional)</span>
                <input type="text" name="legal_id" maxlength="18" inputmode="numeric" placeholder="00.000.000/0000-00" value="<?= e($value('legal_id')) ?>">
                <?= field_error($errors, 'legal_id') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Plano</span>
                <select name="plan">
                    <?php foreach ($plans as $code => $label): ?>
                        <option value="<?= e($code) ?>"<?= $value('plan', 'basic') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'plan') ?>
            </label>
        </div>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Responsável (contato)</span>
                <input type="text" name="contact_name" maxlength="150" value="<?= e($value('contact_name')) ?>">
                <?= field_error($errors, 'contact_name') ?>
            </label>
            <label class="form__field">
                <span class="form__label">E-mail de contato</span>
                <input type="email" name="email" maxlength="254" value="<?= e($value('email')) ?>">
                <?= field_error($errors, 'email') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Telefone</span>
                <input type="tel" name="phone" maxlength="30" value="<?= e($value('phone')) ?>">
                <?= field_error($errors, 'phone') ?>
            </label>
        </div>
        <label class="form__field">
            <span class="form__label">Endereço</span>
            <input type="text" name="address_line" maxlength="200" required value="<?= e($value('address_line')) ?>">
            <?= field_error($errors, 'address_line') ?>
        </label>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Cidade</span>
                <input type="text" name="city" maxlength="100" required value="<?= e($value('city')) ?>">
                <?= field_error($errors, 'city') ?>
            </label>
            <label class="form__field">
                <span class="form__label">UF</span>
                <input type="text" name="state_province" maxlength="50" required value="<?= e($value('state_province')) ?>">
                <?= field_error($errors, 'state_province') ?>
            </label>
            <label class="form__field">
                <span class="form__label">CEP</span>
                <input type="text" name="postal_code" maxlength="9" inputmode="numeric" required value="<?= e($value('postal_code')) ?>">
                <?= field_error($errors, 'postal_code') ?>
            </label>
        </div>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Fuso horário</span>
                <select name="timezone">
                    <?php foreach ($timezones as $zone): ?>
                        <option value="<?= e($zone) ?>"<?= $value('timezone', 'America/Sao_Paulo') === $zone ? ' selected' : '' ?>><?= e($zone) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'timezone') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Dia de vencimento das taxas</span>
                <input type="number" name="billing_due_day" min="1" max="28" value="<?= e($value('billing_due_day', '10')) ?>">
                <?= field_error($errors, 'billing_due_day') ?>
            </label>
        </div>
        <div class="form__actions">
            <a class="btn" href="<?= e($back) ?>">Cancelar</a>
            <button type="submit" class="btn btn--primary">Salvar</button>
        </div>
    </form>
</section>
