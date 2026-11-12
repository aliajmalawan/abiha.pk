<?php
/** Port of admin/home/index.blade.php. @var array $sections @var array $types @var array $single */
use App\Services\Admin\Form;

$oldType = old('type');
?>
<h1 class="!text-2xl">Homepage layout</h1>
<p class="help mb-4">Every block on the homepage is managed here: turn blocks on or off, set their order (lowest number first), edit their text and options, add new blocks and remove ones you do not want. Banner images (hero, main campaign, promotional banner) are edited under <a class="link" href="<?= e(route('admin.banners.index')) ?>">Banners</a>; testimonials under <a class="link" href="<?= e(route('admin.testimonials.index')) ?>">Testimonials</a>; the announcement bar, contact details, social links and footer text under <a class="link" href="<?= e(route('admin.settings.edit')) ?>">Settings</a>.</p>

<form method="POST" action="<?= e(route('admin.home.reorder')) ?>" class="card !p-0 overflow-x-auto"><?= csrf_field() ?><?= method_field('PATCH') ?>
    <table class="table"><thead><tr><th>Block</th><th>Heading</th><th class="w-28">Order</th><th>Visible</th><th class="num">Edit</th><th class="num">Remove</th></tr></thead><tbody>
        <?php foreach ($sections as $s): ?>
            <?php $typeLabel = $types[$s['type']] ?? $s['type']; ?>
            <tr><td><?= e($typeLabel) ?><?php if ($s['type'] === 'products' && !empty($s['settings']['source'])): ?> <span class="text-grey-text text-xs">(<?= e($s['settings']['source']) ?>)</span><?php endif; ?></td><td><?= e($s['title'] ?: '-') ?></td>
                <td><input class="input !min-h-[36px] !w-20" type="number" min="0" max="999" name="sections[<?= (int) $s['id'] ?>][sort_order]" value="<?= (int) $s['sort_order'] ?>" aria-label="Order for <?= e($s['title'] ?: $typeLabel) ?>"></td>
                <td><label class="check"><input type="checkbox" name="sections[<?= (int) $s['id'] ?>][is_active]" value="1"<?= $s['is_active'] ? ' checked' : '' ?>> Show</label></td>
                <td class="num"><?php if (!in_array($s['type'], $single, true)): ?><a class="act act--edit" href="<?= e(route('admin.home.edit', ['id' => $s['id']])) ?>"><?= icon('pencil', 14) ?> Edit</a><?php else: ?><span class="text-grey-text text-xs">via Banners</span><?php endif; ?></td>
                <td class="num"><button type="submit" form="remove-<?= (int) $s['id'] ?>" class="underline text-danger" data-confirm="Remove this block from the homepage?">Remove</button></td></tr>
        <?php endforeach; ?>
        <?php if (!$sections): ?>
            <tr><td colspan="6" class="text-grey-text text-center py-6">The homepage has no blocks yet. Add one below.</td></tr>
        <?php endif; ?>
    </tbody></table>
    <div class="p-4"><button class="btn btn--sm">Save layout</button></div>
</form>
<?php foreach ($sections as $s): ?>
    <form id="remove-<?= (int) $s['id'] ?>" method="POST" action="<?= e(route('admin.home.destroy', ['id' => $s['id']])) ?>" class="hidden" data-confirm="Remove this block from the homepage?"><?= csrf_field() ?><?= method_field('DELETE') ?></form>
<?php endforeach; ?>

<form method="POST" action="<?= e(route('admin.home.store')) ?>" class="card mt-6 max-w-2xl space-y-4" novalidate><?= csrf_field() ?>
    <h2 class="!text-lg !mb-0">Add a block</h2>
    <p class="help">New blocks start hidden at the bottom, so the live page does not change until you tick Show. You can add as many product rows as you like (new arrivals, best sellers, trending, one per collection…). The hero, main campaign and promotional banner can each appear once.</p>
    <div class="field"><label class="label" for="type">Type of block</label>
        <select class="select" id="type" name="type"><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"<?= $oldType === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <?php if ($m = error('type')): ?><span class="error-text" role="alert"><?= e($m) ?></span><?php endif; ?></div>
    <?= Form::field('title', 'Heading (optional)') ?>
    <button class="btn btn--sm">Add block</button>
</form>
