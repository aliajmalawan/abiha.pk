<?php
/* Loaded into the quick-view modal via fetch; Alpine initialises it inside x-html. Var: $product (Catalog card). */
use App\Core\View;
use App\Services\Catalog;
use App\Support\Js;

$p = $product;
$urls = array_map(static fn ($i) => App\Support\Img::publicUrl($i['path']), $p['images']);
$stats = Catalog::reviewStats((int) $p['id']);
?>
<div class="grid gap-8 p-6 md:grid-cols-2 md:p-8" x-data="gallery(<?= Js::from($urls) ?>)">
    <div>
        <div class="relative aspect-[4/3] md:aspect-[4/5] overflow-hidden rounded-md bg-ivory border border-line">
            <template x-if="images.length"><img :src="images[active]" alt="<?= e($p['name']) ?>" class="size-full object-contain"></template>
            <?php if (!$p['images']): ?><?= View::partial('components/placeholder') ?><?php endif; ?>
        </div>
        <?php if (count($p['images']) > 1): ?>
            <div class="mt-3 flex gap-2 overflow-x-auto">
                <template x-for="(src, i) in images" :key="i"><button type="button" class="size-16 shrink-0 rounded border" :class="i === active ? 'border-ink' : 'border-line'" @click="active = i" :aria-label="'Image ' + (i + 1)"><img :src="src" alt="" class="size-full object-contain rounded"></button></template>
            </div>
        <?php endif; ?>
    </div>
    <div class="space-y-5">
        <?php if ($p['category']): ?><p class="eyebrow"><?= e($p['category']['name']) ?></p><?php endif; ?>
        <h2 class="!text-2xl !mb-0"><?= e($p['name']) ?></h2>
        <?php if ($stats['count']): ?><p class="flex items-center gap-2 text-sm"><?= View::partial('components/stars', ['rating' => $stats['average']]) ?> <span class="muted">(<?= (int) $stats['count'] ?>)</span></p><?php endif; ?>
        <?php if ($p['short_description']): ?><p class="muted"><?= e($p['short_description']) ?></p><?php endif; ?>
        <?= View::partial('store/partials/product-form', ['product' => $p, 'compact' => true]) ?>
        <a href="<?= e(route('product', $p)) ?>" class="link text-sm inline-block">View full details</a>
    </div>
</div>
