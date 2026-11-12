<?php
/** Port of admin/products/index.blade.php. @var array $products (Pager) @var array $categories @var array $filters */
use App\Core\View;

$cur = cfg('abiha.currency.symbol');
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <h1 class="!text-2xl !mb-0">Products</h1>
    <a href="<?= e(route('admin.products.create')) ?>" class="btn btn--sm">Add product</a>
</div>

<form method="GET" class="mb-4 flex flex-wrap gap-2" role="search">
    <input name="q" value="<?= e($filters['q'] ?? '') ?>" class="input !min-h-[40px] max-w-xs" placeholder="Search name or SKU" aria-label="Search">
    <select name="category" class="select !min-h-[40px] !w-auto" aria-label="Category"><option value="">All categories</option>
        <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (string) ($filters['category'] ?? '') === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <select name="status" class="select !min-h-[40px] !w-auto" aria-label="Status"><option value="">Any status</option>
        <?php foreach (['active' => 'Active', 'inactive' => 'Hidden', 'low' => 'Low stock', 'out' => 'Out of stock'] as $k => $l): ?><option value="<?= $k ?>"<?= ($filters['status'] ?? null) === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <button class="btn btn--sm">Filter</button>
</form>

<div class="card !p-0 overflow-x-auto">
    <table class="table">
        <thead><tr><th></th><th>Product</th><th>SKU</th><th>Category</th><th class="num">Price</th><th class="num">Stock</th><th>Status</th><th class="num">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($products['items'] as $p): ?>
            <?php $hasVariants = (bool) $p['has_variants']; $stock = $hasVariants ? (int) $p['variant_stock'] : (int) $p['stock']; ?>
            <tr>
                <td class="w-14"><div class="size-12 rounded bg-ivory border border-line overflow-hidden"><?php if ($p['image_path']): ?><img src="<?= e(media($p['image_path'])) ?>" alt="" class="size-full object-cover"><?php endif; ?></div></td>
                <td><a class="link" href="<?= e(route('admin.products.edit', ['id' => $p['id']])) ?>"><?= e($p['name']) ?></a> <?php if ($p['is_sample']): ?><span class="sample-flag">SAMPLE</span><?php endif; ?>
                    <?php if ($hasVariants): ?><br><span class="text-xs text-grey-text"><?= (int) $p['variant_count'] ?> variants</span><?php endif; ?></td>
                <td class="text-grey-text"><?= e($p['sku']) ?></td>
                <td><?= e($p['category_name'] ?? '-') ?></td>
                <td class="num"><?= e($cur) ?> <?= e(number_format((int) $p['price'])) ?></td>
                <td class="num"><span class="<?= $stock <= 0 ? 'text-danger font-semibold' : (!$hasVariants && $stock <= (int) $p['low_stock_threshold'] ? 'text-warning font-semibold' : '') ?>"><?= $stock ?></span></td>
                <td><span class="badge <?= $p['is_active'] ? 'badge--success' : 'badge--ivory' ?>"><?= $p['is_active'] ? 'Active' : 'Hidden' ?></span></td>
                <td class="num whitespace-nowrap"><span class="act-group"><a class="act act--edit" href="<?= e(route('admin.products.edit', ['id' => $p['id']])) ?>"><?= icon('pencil', 14) ?> Edit</a>
                    <form method="POST" action="<?= e(route('admin.products.destroy', ['id' => $p['id']])) ?>" class="inline" data-confirm="Delete this product? Existing orders keep their details."><?= csrf_field() ?><?= method_field('DELETE') ?><button type="submit" class="act act--del"><?= icon('trash-2', 14) ?> Delete</button></form></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$products['items']): ?>
            <tr><td colspan="8" class="text-center py-10 muted">No products found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<div class="mt-6"><?= View::partial('admin/partials/pagination', ['pager' => $products]) ?></div>
