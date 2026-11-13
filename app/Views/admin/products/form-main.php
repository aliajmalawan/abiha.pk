<?php
/**
 * Product form: Basic details, Specifications, Pricing & stock, Variants (inside the form's x-data scope).
 * @var callable $f @var array $specRows @var array $categories @var callable $firstMatch
 */
use App\Services\Admin\Form;
?>
<section class="card pf-card space-y-4">
    <div class="pf-card__head"><span class="pf-card__icon"><?= icon('package', 18) ?></span><div><h2 class="!text-lg !mb-0">Basic details</h2><p class="help">Name, code, category and the text customers read.</p></div></div>
    <?= Form::field('name', 'Product name', $f('name'), ['required' => true, 'attrs' => ['x-model' => 'name', '@input' => 'onName()']]) ?>
    <div class="grid gap-4 sm:grid-cols-2">
        <?= Form::field('sku', 'SKU', $f('sku'), ['required' => true, 'help' => 'Made from the name automatically. You can change it; it must be unique.', 'attrs' => ['x-model' => 'sku', '@input' => "skuAuto = sku.trim() === ''"]]) ?>
        <?= Form::field('slug', 'URL slug', $f('slug'), ['help' => 'Made from the name automatically. You can change it (letters, numbers and hyphens).', 'attrs' => ['x-model' => 'slug', '@input' => "slugAuto = slug.trim() === ''"]]) ?>
    </div>
    <div class="field"><label class="label" for="category_id">Category</label>
        <select class="select" id="category_id" name="category_id"><option value="">None</option>
            <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (string) $f('category_id') === (string) $c['id'] ? ' selected' : '' ?>><?= $c['parent_id'] ? '— ' : '' ?><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <?= Form::field('short_description', 'Short description', $f('short_description')) ?>
    <div class="field"><label class="label" for="description">Description <span class="text-grey-text font-normal text-sm">(use the buttons for bold, lists and links)</span></label>
        <textarea class="textarea !min-h-[180px]" id="description" name="description" data-rich data-rich-compact><?= e(is_scalar($f('description')) ? $f('description') : '') ?></textarea><?php if ($m = error('description')): ?><span class="error-text"><?= e($m) ?></span><?php endif; ?></div>
</section>

<section class="card pf-card space-y-4" x-data="{ rows: <?= Form::js($specRows) ?> }">
    <div class="pf-card__head"><span class="pf-card__icon"><?= icon('sliders-horizontal', 18) ?></span><div class="flex-1"><h2 class="!text-lg !mb-0">Specifications</h2><p class="help">Optional facts shown as a table on the product page, e.g. fabric, care, fit.</p></div>
        <button type="button" class="btn btn--sm btn--outline" @click="if (rows.length < 25) rows.push({ label: '', value: '' })"><?= icon('plus', 14) ?> Add row</button></div>
    <template x-for="(row, i) in rows" :key="i">
        <div class="pf-spec">
            <input class="input" type="text" maxlength="60" x-model="row.label" :name="`specifications[${i}][label]`" placeholder="Label, e.g. Fabric" :aria-label="`Specification ${i + 1} label`">
            <input class="input" type="text" maxlength="300" x-model="row.value" :name="`specifications[${i}][value]`" placeholder="Value" :aria-label="`Specification ${i + 1} value`">
            <button type="button" class="btn btn--sm btn--ghost" @click="rows.splice(i, 1)" :aria-label="`Remove specification ${i + 1}`">Remove</button>
        </div>
    </template>
    <p class="text-sm text-grey-text" x-show="rows.length === 0">No specifications yet.</p>
</section>

<section class="card pf-card space-y-4">
    <div class="pf-card__head"><span class="pf-card__icon"><?= icon('tag', 18) ?></span><div><h2 class="!text-lg !mb-0">Pricing &amp; stock</h2><p class="help">Prices are whole PKR. Customers are always charged the price saved here.</p></div></div>
    <div class="grid gap-4 sm:grid-cols-3">
        <?= Form::field('price', 'Price (PKR)', $f('price'), ['type' => 'number', 'required' => true, 'attrs' => ['min' => '0']]) ?>
        <?= Form::field('compare_price', 'Original price (optional)', $f('compare_price'), ['type' => 'number', 'help' => 'Must be higher than the price. Shown struck through.', 'attrs' => ['min' => '0']]) ?>
        <?= Form::field('low_stock_threshold', 'Low-stock alert at', $f('low_stock_threshold', 3), ['type' => 'number', 'attrs' => ['min' => '0']]) ?>
    </div>
    <label class="check"><input type="checkbox" name="has_variants" value="1" x-model="hasVariants"> This product has variants (size / colour…)</label>
    <div x-show="!hasVariants"><?= Form::field('stock', 'Stock on hand', $f('stock', 0), ['type' => 'number', 'help' => 'Changes are recorded in the inventory ledger.', 'attrs' => ['min' => '0']]) ?></div>
</section>

<section class="card pf-card space-y-4" x-show="hasVariants" x-cloak>
    <div class="pf-card__head"><span class="pf-card__icon"><?= icon('layout-dashboard', 18) ?></span><div class="flex-1"><h2 class="!text-lg !mb-0">Variants</h2><p class="help">Each variant has its own SKU, stock and optional price (blank = product price).</p></div>
        <button type="button" class="btn btn--sm btn--outline" @click="add()"><?= icon('plus', 14) ?> Add variant</button></div>
    <p class="help">Manage the size / colour options under <a class="link" href="<?= e(route('admin.attributes.index')) ?>">Attributes</a>.</p>
    <?php if ($m = error('variants')): ?><p class="error-text"><?= e($m) ?></p><?php endif; ?>
    <template x-for="(r, i) in rows" :key="i">
        <div class="pf-variant">
            <input type="hidden" :name="`variants[${i}][id]`" :value="r.id">
            <template x-for="id in chosen(r)" :key="id"><input type="hidden" :name="`variants[${i}][options][]`" :value="id"></template>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <template x-for="a in attrs" :key="a.id">
                    <div class="field"><label class="label text-xs" x-text="a.name"></label>
                        <select class="select !min-h-[40px]" x-model="r.sel[a.id]"><option value="">None</option>
                            <template x-for="v in a.values" :key="v.id"><option :value="v.id" x-text="v.value" :selected="r.sel[a.id] == v.id"></option></template></select></div>
                </template>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="field"><label class="label text-xs">SKU</label><input class="input !min-h-[40px]" :name="`variants[${i}][sku]`" x-model="r.sku" required></div>
                <div class="field"><label class="label text-xs">Price (PKR, optional)</label><input class="input !min-h-[40px]" type="number" min="0" :name="`variants[${i}][price]`" x-model="r.price"></div>
                <div class="field"><label class="label text-xs">Original price (optional)</label><input class="input !min-h-[40px]" type="number" min="0" :name="`variants[${i}][compare_price]`" x-model="r.compare_price"></div>
                <div class="field"><label class="label text-xs">Stock</label><input class="input !min-h-[40px]" type="number" min="0" :name="`variants[${i}][stock]`" x-model="r.stock" required></div>
            </div>
            <div class="flex items-center justify-between">
                <label class="check"><input type="checkbox" value="1" :name="`variants[${i}][is_active]`" x-model="r.is_active"> Available to customers</label>
                <button type="button" class="text-sm underline text-danger" @click="remove(i)">Remove variant</button>
            </div>
        </div>
    </template>
    <p class="muted text-sm" x-show="rows.length === 0">No variants yet. Add at least one.</p>
    <?php foreach (errors() as $key => $msg): ?><?php if (str_starts_with((string) $key, 'variants.')): ?><p class="error-text"><?= e($key) ?>: <?= e(is_array($msg) ? reset($msg) : $msg) ?></p><?php endif; ?><?php endforeach; ?>
</section>
