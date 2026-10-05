<?php
/**
 * User management. The table is filled by public/assets/js/admin/users.js from
 * GET /admin/users/search, by cloning <template id="user-row"> and writing
 * every value with textContent. The invite form is a normal POST.
 *
 * @var list<array{id: int, label: string}> $units
 * @var array<string, string>               $roles         code => label
 * @var array<string, string>               $relationships code => label
 * @var array<string, string>               $errors
 * @var array<string, string>               $old
 */
$selected = static fn (string $field, string|int $value): string
    => (string) ($old[$field] ?? '') === (string) $value ? ' selected' : '';
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Usuários</h1>
        <p class="page-header__subtitle">Moradores, portaria e síndicos deste condomínio</p>
    </div>
</section>

<div class="columns">
    <div class="columns__side">
        <section class="panel panel--padded">
            <h2 class="panel__title">Convidar usuário</h2>
            <form method="post" action="/admin/users/invitations" class="form" novalidate>
                <?= csrf_field() ?>
                <?= field_error($errors, 'general') ?>
                <label class="form__field">
                    <span class="form__label">Nome completo</span>
                    <input type="text" name="full_name" maxlength="150" required value="<?= e($old['full_name'] ?? '') ?>">
                    <?= field_error($errors, 'full_name') ?>
                </label>
                <label class="form__field">
                    <span class="form__label">E-mail</span>
                    <input type="email" name="email" maxlength="254" required value="<?= e($old['email'] ?? '') ?>">
                    <?= field_error($errors, 'email') ?>
                </label>
                <label class="form__field">
                    <span class="form__label">Perfil</span>
                    <select name="role" required>
                        <?php foreach ($roles as $code => $label): ?>
                            <option value="<?= e($code) ?>"<?= $selected('role', $code) ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error($errors, 'role') ?>
                </label>
                <div class="form__row">
                    <label class="form__field">
                        <span class="form__label">Unidade</span>
                        <select name="unit_id">
                            <option value="">Nenhuma</option>
                            <?php foreach ($units as $unit): ?>
                                <option value="<?= e($unit['id']) ?>"<?= $selected('unit_id', $unit['id']) ?>><?= e($unit['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= field_error($errors, 'unit_id') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">Vínculo</span>
                        <select name="relationship">
                            <?php foreach ($relationships as $code => $label): ?>
                                <option value="<?= e($code) ?>"<?= $selected('relationship', $code) ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <p class="form__hint">A pessoa recebe um link de uso único, válido por 72 horas, para aceitar o convite.</p>
                <button type="submit" class="btn btn--primary">Enviar convite</button>
            </form>
        </section>
    </div>

    <div class="columns__main">
        <section class="panel">
            <form class="filters" id="user-filters" role="search" novalidate>
                <label class="filters__field filters__field--grow">
                    <span class="sr-only">Buscar</span>
                    <input type="search" name="q" placeholder="Buscar por nome ou e-mail" maxlength="100" autocomplete="off">
                </label>
                <label class="filters__field">
                    <span class="sr-only">Perfil</span>
                    <select name="role">
                        <option value="">Todos os perfis</option>
                        <?php foreach ($roles as $code => $label): ?>
                            <option value="<?= e($code) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="filters__field">
                    <span class="sr-only">Unidade</span>
                    <select name="unit_id">
                        <option value="">Todas as unidades</option>
                        <?php foreach ($units as $unit): ?>
                            <option value="<?= e($unit['id']) ?>"><?= e($unit['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="filters__field">
                    <span class="sr-only">Situação</span>
                    <select name="status">
                        <option value="">Todas as situações</option>
                        <option value="active">Ativos</option>
                        <option value="invited">Convite pendente</option>
                        <option value="inactive">Desativados</option>
                    </select>
                </label>
            </form>

            <div id="user-list" aria-live="polite" aria-busy="true">
                <div class="state" data-state="loading"><span class="spinner" aria-hidden="true"></span> Carregando usuários…</div>
                <div class="state" data-state="empty" hidden>Nenhum usuário encontrado com estes filtros.</div>
                <div class="state state--error" data-state="error" hidden>
                    <p>Não foi possível carregar os usuários.</p>
                    <button type="button" class="btn" data-retry>Tentar novamente</button>
                </div>
                <div class="table-wrap" data-state="list" hidden>
                    <table class="table">
                        <thead>
                        <tr><th>Nome</th><th>Perfil</th><th>Unidade</th><th>Situação</th><th class="table__action">Ações</th></tr>
                        </thead>
                        <tbody id="user-rows"></tbody>
                    </table>
                </div>
                <nav class="pager" data-pager hidden aria-label="Paginação">
                    <span class="pager__info muted" data-pager-info></span>
                    <button type="button" class="btn btn--small" data-page="prev">Anterior</button>
                    <button type="button" class="btn btn--small" data-page="next">Próxima</button>
                </nav>
            </div>
        </section>
    </div>
</div>

<template id="user-row">
    <tr data-row>
        <td>
            <a class="strong" data-field="name"></a>
            <span class="tag" data-field="self" hidden>Você</span>
            <div class="small muted" data-field="email"></div>
        </td>
        <td data-field="role"></td>
        <td data-field="unit"></td>
        <td><span class="pill" data-field="status"></span></td>
        <td class="table__action">
            <span class="inline-form">
                <button type="button" class="btn btn--small" data-action="resend" hidden>Reenviar convite</button>
                <button type="button" class="btn btn--small btn--danger-ghost" data-action="deactivate" hidden>Desativar</button>
                <button type="button" class="btn btn--small" data-action="reactivate" hidden>Reativar</button>
                <span class="feedback" data-feedback role="status"></span>
            </span>
        </td>
    </tr>
</template>
