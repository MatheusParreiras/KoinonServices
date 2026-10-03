<?php
/**
 * One ticket and its timeline. Reaching this view means the visibility check
 * passed. Internal notes are only present in $updates for staff.
 *
 * @var array<string, mixed>       $occurrence
 * @var list<array<string, mixed>> $updates
 * @var bool                       $canReply
 * @var bool                       $isHandler
 * @var array<string, string>      $types
 * @var array<string, string>      $categories
 * @var array<string, string>      $statuses
 * @var array<string, string>      $errors
 * @var array<string, string>      $old
 */
$status = (string) $occurrence['status'];
$statusLabel = static fn (?string $s): string => $s === null ? '' : ($statuses[$s] ?? $s);
?>
<section class="page-header">
    <div>
        <p class="page-header__eyebrow"><a href="/occurrences">← Ocorrências</a></p>
        <h1 class="page-header__title">
            Nº <?= e($occurrence['protocol_number']) ?> · <?= e($occurrence['title']) ?>
        </h1>
        <p class="page-header__subtitle">
            <?= e($types[$occurrence['occurrence_type']] ?? $occurrence['occurrence_type']) ?>
            · <?= e($categories[$occurrence['category']] ?? $occurrence['category']) ?>
            · aberta por <?= e($occurrence['reporter_name']) ?> em <?= e(local_datetime($occurrence['created_at'])) ?>
        </p>
    </div>
    <span class="pill pill--<?= e($status) ?> pill--large"><?= e($statusLabel($status)) ?></span>
</section>

<div class="columns">
    <div class="columns__main">
        <section class="panel panel--padded">
            <h2 class="panel__title">Descrição</h2>
            <p class="prewrap"><?= e($occurrence['description']) ?></p>
            <?php if (!empty($occurrence['location'])): ?>
                <p class="muted">Local: <?= e($occurrence['location']) ?></p>
            <?php endif; ?>
        </section>

        <section class="panel panel--padded">
            <h2 class="panel__title">Histórico</h2>
            <?php if ($updates === []): ?>
                <p class="muted">Ainda não há respostas.</p>
            <?php else: ?>
                <ol class="timeline">
                    <?php foreach ($updates as $u): ?>
                        <li class="timeline__item<?= (bool) $u['is_internal'] ? ' timeline__item--internal' : '' ?>">
                            <div class="timeline__meta">
                                <strong><?= e($u['author_name']) ?></strong>
                                <?php if (in_array($u['author_role'], ['manager', 'concierge'], true)): ?>
                                    <span class="tag">Administração</span>
                                <?php endif; ?>
                                <?php if ((bool) $u['is_internal']): ?>
                                    <span class="tag tag--warning">Nota interna</span>
                                <?php endif; ?>
                                <span class="muted"><?= e(local_datetime($u['created_at'])) ?></span>
                            </div>
                            <?php if ($u['status_to'] !== null): ?>
                                <p class="timeline__status">
                                    Status: <?= e($statusLabel($u['status_from'])) ?> → <strong><?= e($statusLabel($u['status_to'])) ?></strong>
                                </p>
                            <?php endif; ?>
                            <?php if ($u['message'] !== null): ?>
                                <p class="prewrap"><?= e($u['message']) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <?php if ($canReply): ?>
                <form method="post" action="/occurrences/<?= e($occurrence['id']) ?>/replies" class="form form--separated" novalidate>
                    <?= csrf_field() ?>
                    <label class="form__field">
                        <span class="form__label">Responder</span>
                        <textarea name="message" rows="4" maxlength="5000" required><?= e($old['message'] ?? '') ?></textarea>
                        <?= field_error($errors, 'message') ?>
                    </label>
                    <div class="form__actions form__actions--split">
                        <?php if ($isHandler): ?>
                            <label class="form__check">
                                <input type="checkbox" name="is_internal" value="1">
                                Nota interna (não visível para o morador)
                            </label>
                        <?php endif; ?>
                        <button type="submit" class="btn btn--primary">Enviar resposta</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($isHandler): ?>
        <div class="columns__side">
            <section class="panel panel--padded">
                <h2 class="panel__title">Alterar status</h2>
                <form method="post" action="/occurrences/<?= e($occurrence['id']) ?>/status" class="form" novalidate>
                    <?= csrf_field() ?>
                    <label class="form__field">
                        <span class="form__label">Novo status</span>
                        <select name="status">
                            <?php foreach ($statuses as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $value === $status ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= field_error($errors, 'status') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">Mensagem ao morador (opcional)</span>
                        <textarea name="message" rows="3" maxlength="5000"></textarea>
                    </label>
                    <button type="submit" class="btn btn--primary">Atualizar status</button>
                </form>
            </section>
        </div>
    <?php endif; ?>
</div>
