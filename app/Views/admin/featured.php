<?php
/** Port of admin/featured.blade.php. @var array $products (Pager) @var array $flags @var string $q */
use App\Core\View;
?>
<h1 class="!text-2xl">Featured products</h1>
<p class="help mb-4">Tick which products appear in each homepage row (Best sellers, New arrivals…). Manage the rows themselves under <a class="link" href="<?= e(route('admin.home.index')) ?>">Homepage</a>.</p>
<form method="GET" class="mb-4 flex gap-2 max-w-md" role="search"><input name="q" value="<?= e($q) ?>" class="input !min-h-[40px]" placeholder="Search name or SKU" aria-label="Search"><button class="btn btn--sm">Search</button></form>

<form method="POST" action="<?= e(route('admin.featured.update')) ?>">
    <?= csrf_field() ?><?= method_field('PUT') ?>
    <div class="card !p-0 overflow-x-auto"><table class="table">
        <thead><tr><th>Product</th><?php foreach ($flags as $label): ?><th class="text-center"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($products['items'] as $p): ?>
            <tr><td><input type="hidden" name="ids[]" value="<?= (int) $p['id'] ?>"><div class="flex items-center gap-3"><span class="size-10 rounded bg-ivory border border-line overflow-hidden shrink-0"><?php if ($p['image_path']): ?><img src="<?= e(media($p['image_path'])) ?>" alt="" class="size-full object-cover"><?php endif; ?></span>
                <a class="link" href="<?= e(route('admin.products.edit', ['id' => $p['id']])) ?>"><?= e($p['name']) ?></a><?php if (!$p['is_active']): ?><span class="badge badge--ivory">Hidden</span><?php endif; ?></div></td>
                <?php foreach ($flags as $flag => $label): ?><td class="text-center"><label class="check justify-center"><input type="checkbox" name="flags[<?= (int) $p['id'] ?>][<?= $flag ?>]" value="1"<?= $p[$flag] ? ' checked' : '' ?> aria-label="<?= e($label) ?>: <?= e($p['name']) ?>"></label></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        <?php if (!$products['items']): ?><tr><td colspan="5" class="text-center py-10 muted">No products found.</td></tr><?php endif; ?>
        </tbody></table></div>
    <div class="mt-4 flex items-center gap-4"><button class="btn">Save changes on this page</button><span class="help">Saves only the products shown on this page.</span></div>
</form>
<div class="mt-6"><?= View::partial('admin/partials/pagination', ['pager' => $products]) ?></div>
