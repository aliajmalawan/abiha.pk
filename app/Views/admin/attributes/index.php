<?php
/** Port of admin/attributes/index.blade.php. @var array $attributes (each with 'values') */
use App\Services\Admin\Form;
?>
<h1 class="!text-2xl">Attributes</h1>
<p class="help mb-4">The options customers choose between (size, colour…). <strong>Size</strong> and <strong>Colour</strong> power the shop filters.</p>
<div class="grid gap-6 lg:grid-cols-[1fr_320px] items-start">
    <div class="space-y-6">
        <?php foreach ($attributes as $a): ?>
            <section class="card">
                <div class="flex items-center justify-between"><h2 class="!text-lg !mb-0"><?= e($a['name']) ?> <span class="text-xs text-grey-text font-normal">(<?= e($a['slug']) ?>)</span></h2>
                    <?php if (!in_array($a['slug'], ['size', 'color'], true)): ?><form method="POST" action="<?= e(route('admin.attributes.destroy', ['id' => $a['id']])) ?>" data-confirm="Delete this attribute?"><?= csrf_field() ?><?= method_field('DELETE') ?><button type="submit" class="act act--del"><?= icon('trash-2', 14) ?> Delete</button></form><?php endif; ?></div>
                <ul class="flex flex-wrap gap-2 my-4">
                    <?php foreach ($a['values'] as $v): ?>
                        <li class="inline-flex items-center gap-2 rounded-full border border-line bg-ivory pl-3 pr-1 py-1 text-sm">
                            <?php if ($v['color_hex']): ?><span class="size-4 rounded-full border border-input" style="background:<?= e($v['color_hex']) ?>"></span><?php endif; ?> <?= e($v['value']) ?>
                            <form method="POST" action="<?= e(route('admin.attributes.values.destroy', ['id' => $v['id']])) ?>" data-confirm="Remove <?= e($v['value']) ?>?"><?= csrf_field() ?><?= method_field('DELETE') ?><button class="size-6 grid place-content-center rounded-full hover:bg-white" aria-label="Remove <?= e($v['value']) ?>"><?= icon('x', 14) ?></button></form>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form method="POST" action="<?= e(route('admin.attributes.values.store', ['id' => $a['id']])) ?>" class="flex flex-wrap items-end gap-2"><?= csrf_field() ?>
                    <div class="field"><label class="label text-xs" for="v<?= (int) $a['id'] ?>">New value</label><input class="input !min-h-[40px]" id="v<?= (int) $a['id'] ?>" name="value" required maxlength="60"></div>
                    <?php if ($a['type'] === 'color'): ?><div class="field"><label class="label text-xs" for="h<?= (int) $a['id'] ?>">Colour</label><input class="input !min-h-[40px] !w-20 !p-1" type="color" id="h<?= (int) $a['id'] ?>" name="color_hex" value="#101010"></div><?php endif; ?>
                    <button class="btn btn--sm !min-h-[40px]">Add</button>
                </form>
            </section>
        <?php endforeach; ?>
    </div>
    <form method="POST" action="<?= e(route('admin.attributes.store')) ?>" class="card space-y-3"><?= csrf_field() ?>
        <h2 class="!text-lg">New attribute</h2>
        <?= Form::field('name', 'Name', null, ['required' => true, 'attrs' => ['placeholder' => 'e.g. Material']]) ?>
        <?= Form::field('slug', 'Identifier', null, ['required' => true, 'help' => 'Lowercase letters, numbers, - or _.', 'attrs' => ['placeholder' => 'e.g. material']]) ?>
        <div class="field"><label class="label" for="type">Type</label><select class="select" id="type" name="type"><option value="select">List of options</option><option value="color">Colour swatches</option></select></div>
        <button class="btn btn--block">Add attribute</button>
    </form>
</div>
