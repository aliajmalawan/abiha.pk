<?php
/**
 * Shop / category / collection listing (port of resources/views/storefront/shop.blade.php).
 * @var App\Support\Paginator $products
 */
use App\Core\View;

$cur = $filters['sort'] ?? 'featured';
$catId = $category ? (int) $category['id'] : null;
$total = $products->total;
?>
<div class="container py-8 lg:py-12" x-data="{ filtersOpen: false }" @keydown.escape.window="filtersOpen = false">
    <?= View::partial('components/breadcrumbs', ['class' => 'mb-4']) ?>

    <header class="mb-8">
        <h1 class="!text-3xl lg:!text-4xl !mb-2"><?= e($title) ?></h1>
        <?php if ($q): ?><p class="muted">Results for “<?= e($q) ?>”</p>
        <?php elseif (($category['description'] ?? null) || ($collection['description'] ?? null)): ?><p class="lead"><?= e(($category['description'] ?? null) ?? $collection['description']) ?></p><?php endif; ?>
    </header>

    <div class="shop-toolbar flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-y border-line py-3 mb-8">
        <button type="button" class="btn btn--outline btn--sm lg:hidden" @click="filtersOpen = true"><?= icon('sliders-horizontal', 16) ?> Filters <?php if ($activeCount): ?>(<?= (int) $activeCount ?>)<?php endif; ?></button>
        <p class="text-sm muted" role="status"><?= $total ?> <?= $total === 1 ? 'product' : 'products' ?></p>
        <div class="flex items-center gap-2">
            <label for="sort" class="text-sm hidden sm:block">Sort by</label>
            <select id="sort" name="sort" form="filters" class="select !min-h-[40px] !w-auto max-w-[10.5rem] text-sm" @change="$el.form.requestSubmit()">
                <?php foreach ($sorts as $key => $label): ?><option value="<?= e($key) ?>" <?= $cur === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="grid gap-10 lg:grid-cols-[260px_1fr]">
        <?php /* Filters: sidebar on desktop, slide-over drawer on mobile (single form) */ ?>
        <div class="lg:block" :class="filtersOpen ? 'fixed inset-0 z-[220]' : 'hidden'">
            <div class="absolute inset-0 bg-ink/50 lg:hidden" @click="filtersOpen = false"></div>
            <form id="filters" method="GET" action="<?= e(route('shop')) ?>" aria-label="Product filters" @change="if (window.matchMedia('(min-width: 1024px)').matches) $el.requestSubmit()"
                  class="max-lg:absolute max-lg:inset-y-0 max-lg:left-0 max-lg:w-[88%] max-lg:max-w-sm max-lg:bg-white max-lg:overflow-y-auto max-lg:p-6 space-y-7">
                <div class="flex items-center justify-between lg:hidden">
                    <h2 class="!text-lg !mb-0">Filters</h2>
                    <button type="button" class="btn btn--ghost !px-2" @click="filtersOpen = false" aria-label="Close filters"><?= icon('x', 22) ?></button>
                </div>
                <?php if ($q): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>

                <fieldset>
                    <legend class="eyebrow mb-3">Category</legend>
                    <div class="space-y-2">
                        <label class="check"><input type="radio" name="category" value="" <?= !$category ? 'checked' : '' ?>> All</label>
                        <?php foreach ($categories as $c): $open = $catId === (int) $c['id'] || in_array($catId, array_map(static fn ($k) => (int) $k['id'], $c['children']), true); ?>
                            <div class="fgroup" x-data="{ open: <?= $open ? 'true' : 'false' ?> }">
                                <div class="fgroup__head">
                                    <label class="check"><input type="radio" name="category" value="<?= e($c['slug']) ?>" <?= $catId === (int) $c['id'] ? 'checked' : '' ?>> <?= e($c['name']) ?></label>
                                    <?php if ($c['children']): ?><button type="button" class="fgroup__toggle" @click="open = !open" :aria-expanded="open" aria-label="Show <?= e($c['name']) ?> categories"><?= icon('chevron-down', 16) ?></button><?php endif; ?>
                                </div>
                                <?php if ($c['children']): ?>
                                    <div class="fgroup__kids" x-show="open" <?= $open ? '' : 'x-cloak' ?>>
                                        <?php foreach ($c['children'] as $child): ?>
                                            <label class="check pl-6"><input type="radio" name="category" value="<?= e($child['slug']) ?>" <?= $catId === (int) $child['id'] ? 'checked' : '' ?>> <span class="text-grey-text"><?= e($child['name']) ?></span></label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="eyebrow mb-3">Price (<?= e(cfg('abiha.currency.symbol')) ?>)</legend>
                    <div class="flex items-center gap-2">
                        <label class="sr-only" for="min">Minimum price</label><input id="min" class="input !min-h-[44px]" type="number" min="0" inputmode="numeric" name="min" placeholder="Min" value="<?= e($filters['min'] ?? '') ?>">
                        <span aria-hidden="true">–</span>
                        <label class="sr-only" for="max">Maximum price</label><input id="max" class="input !min-h-[44px]" type="number" min="0" inputmode="numeric" name="max" placeholder="<?= e($priceCeiling ?: 'Max') ?>" value="<?= e($filters['max'] ?? '') ?>">
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="eyebrow mb-3">Availability</legend>
                    <label class="check"><input type="checkbox" name="availability" value="in_stock" <?= ($filters['availability'] ?? null) === 'in_stock' ? 'checked' : '' ?>> In stock only</label>
                    <label class="check mt-2"><input type="checkbox" name="sale" value="1" <?= !empty($filters['sale']) ? 'checked' : '' ?>> On sale</label>
                </fieldset>

                <?php if ($colors): ?>
                    <fieldset>
                        <legend class="eyebrow mb-3">Colour</legend>
                        <div class="flex flex-wrap gap-3">
                            <?php foreach ($colors as $c): ?>
                                <label class="cursor-pointer" title="<?= e($c['value']) ?>">
                                    <input class="peer sr-only" type="checkbox" name="color[]" value="<?= e($c['value']) ?>" <?= in_array($c['value'], (array) ($filters['color'] ?? []), true) ? 'checked' : '' ?>>
                                    <span class="block size-9 rounded-full border border-input peer-checked:outline peer-checked:outline-2 peer-checked:outline-offset-2 peer-checked:outline-ink peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-gold" style="background:<?= e($c['color_hex'] ?? '#ccc') ?>"></span>
                                    <span class="sr-only"><?= e($c['value']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endif; ?>

                <?php if ($sizes): ?>
                    <fieldset>
                        <legend class="eyebrow mb-3">Size</legend>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($sizes as $s): ?>
                                <label>
                                    <input class="peer sr-only" type="checkbox" name="size[]" value="<?= e($s) ?>" <?= in_array($s, (array) ($filters['size'] ?? []), true) ? 'checked' : '' ?>>
                                    <span class="chip grid place-content-center cursor-pointer peer-checked:bg-ink peer-checked:text-white peer-checked:border-ink peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-gold"><?= e($s) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endif; ?>

                <div class="flex gap-3 max-lg:sticky max-lg:bottom-0 max-lg:bg-white max-lg:py-3">
                    <button class="btn btn--sm flex-1">Apply</button>
                    <a href="<?= e(route('shop')) ?>" class="btn btn--sm btn--outline">Clear</a>
                </div>
            </form>
        </div>

        <section aria-label="Products">
            <?php if ($products->isEmpty()): ?>
                <?php if ($activeCount || $q || $category || $collection): ?>
                    <?= View::partial('components/empty-state', ['icon' => 'search', 'title' => 'No products found', 'text' => 'Try removing a filter or searching for something else.', 'action' => 'View all products', 'href' => route('shop')]) ?>
                <?php else: ?>
                    <?= View::partial('components/empty-state', ['icon' => 'shopping-bag', 'title' => 'No products yet', 'text' => 'Our collection is being prepared. Please check back soon.', 'action' => 'Back to home', 'href' => route('home')]) ?>
                <?php endif; ?>
            <?php else: ?>
                <div class="grid grid--3 grid--products" style="--gap:1.75rem 1.25rem" data-reveal-group>
                    <?php foreach (array_values($products->items) as $i => $product): ?><?= View::partial('store/partials/product-card', ['product' => $product, 'eager' => $i < 4, 'reveal' => true]) ?><?php endforeach; ?>
                </div>
                <div class="mt-12"><?= $products->links() ?></div>
            <?php endif; ?>
        </section>
    </div>
</div>
