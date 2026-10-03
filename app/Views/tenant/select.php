<?php
/**
 * @var list<array{id: int, name: string, detail: string}> $options
 * @var bool $isSuperAdmin
 */
?>
<h1 class="auth__title">
    <?= $isSuperAdmin ? 'Escolha o condomínio para administrar' : 'Escolha o condomínio' ?>
</h1>

<?php if ($options === []): ?>
    <div class="alert alert--warning">Nenhum condomínio ativo disponível.</div>
<?php else: ?>
    <ul class="choice-list">
        <?php foreach ($options as $option): ?>
            <li>
                <form method="post" action="/select-condominium">
                    <?= csrf_field() ?>
                    <input type="hidden" name="condominium_id" value="<?= e($option['id']) ?>">
                    <button type="submit" class="choice">
                        <span class="choice__name"><?= e($option['name']) ?></span>
                        <span class="choice__detail"><?= e($option['detail']) ?></span>
                    </button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="post" action="/logout" class="auth__links">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn--ghost">Sair</button>
</form>
