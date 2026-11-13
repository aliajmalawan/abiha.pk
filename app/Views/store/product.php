<?php
/**
 * Product page (port of resources/views/storefront/product.blade.php).
 * @var array $product  Catalog card (+ review_stats)
 * @var App\Support\Paginator $reviews
 */
use App\Core\View;
use App\Services\Catalog;
use App\Support\HtmlSanitizer;
use App\Support\Img;
use App\Support\Js;

$p = $product;
$stats = $p['review_stats'];
$imageUrls = array_map(static fn ($i) => Img::publicUrl($i['path']), $p['images']);
$sample = cfg('abiha.show_sample_labels');
$signedIn = App\Auth\Auth::check();
$sizeGuide = trim((string) setting('size.guide'));
?>
<div class="container py-8 lg:py-12">
    <?= View::partial('components/breadcrumbs', ['class' => 'mb-6']) ?>

    <div class="grid gap-10 lg:grid-cols-[1.1fr_1fr] lg:gap-16">
        <?php /* Gallery with hover zoom */ ?>
        <div x-data="gallery(<?= Js::from($imageUrls) ?>)" data-lightbox="<?= e(json_encode(array_map(static fn ($im) => ['src' => Img::url($im['path']), 'thumb' => Img::src($im['path'], 160), 'alt' => (string) ($im['alt'] ?: $p['name'])], $p['images']), JSON_UNESCAPED_SLASHES)) ?>" class="lg:sticky lg:top-28 self-start">
            <div class="grid gap-3 <?= count($imageUrls) > 1 ? 'lg:grid-cols-[84px_1fr]' : '' ?>">
                <?php if (count($imageUrls) > 1): ?>
                    <div class="order-2 lg:order-1 flex lg:flex-col gap-2 overflow-x-auto lg:overflow-visible" role="tablist" aria-label="Product images">
                        <?php foreach ($p['images'] as $i => $img): ?>
                            <button type="button" role="tab" :aria-selected="active === <?= $i ?>" class="size-20 shrink-0 rounded border overflow-hidden bg-ivory" :class="active === <?= $i ?> ? 'border-ink' : 'border-line'" @click="active = <?= $i ?>" aria-label="Show image <?= $i + 1 ?>"><?= Img::picture($img['path'], '', '80px', false, false, ['class' => 'size-full object-contain']) ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="pdp-media order-1 lg:order-2 relative aspect-[4/5] overflow-hidden rounded-lg bg-ivory border border-line"
                     @mouseenter="zoom = images.length > 0 && window.matchMedia('(hover:hover)').matches" @mouseleave="zoom = false" @mousemove="move($event)" :class="zoom && 'cursor-zoom-in'">
                    <?php if ($imageUrls): ?>
                        <?php /* Rendered by the server (not Alpine) so the main photo is in the HTML for the browser to fetch at once: it is the page's LCP element. */ ?>
                        <?php foreach ($p['images'] as $i => $img): ?>
                            <?= Img::picture($img['path'], ($img['alt'] ?: $p['name']) . ($i > 0 ? ' (view ' . ($i + 1) . ')' : ''), '(min-width:1024px) 50vw, 100vw', false, $i === 0, [
                                'x-bind:aria-hidden' => "active !== {$i}",
                                'class' => trim('absolute inset-0 size-full object-contain transition-opacity duration-300 will-change-transform ' . ($i === 0 ? '' : 'opacity-0')),
                                'x-bind:class' => "active === {$i} ? 'opacity-100' : 'opacity-0'",
                                'x-bind:style' => "active === {$i} && zoom ? `transform:scale(2);transform-origin:\${ox}% \${oy}%;transition:transform .2s` : ''",
                            ]) ?>
                        <?php endforeach; ?>
                        <?php if (count($imageUrls) > 1): ?>
                            <button type="button" class="absolute left-2 top-1/2 -translate-y-1/2 btn btn--light !px-2.5 !min-h-10 lg:hidden" @click="prev()" aria-label="Previous image"><?= icon('chevron-left') ?></button>
                            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 btn btn--light !px-2.5 !min-h-10 lg:hidden" @click="next()" aria-label="Next image"><?= icon('chevron-right') ?></button>
                        <?php endif; ?>
                    <?php else: ?>
                        <?= View::partial('components/placeholder') ?>
                    <?php endif; ?>
                    <?php if ($label = $p['badge_label']): ?><span class="badge badge--gold absolute left-3 top-3"><?= e($label) ?></span><?php endif; ?>
                    <?php if ($imageUrls): ?><button type="button" class="pdp-enlarge" data-lightbox-open aria-label="View photos full screen"><?= icon('maximize', 18) ?></button><?php endif; ?>
                </div>
            </div>
            <?= View::partial('store/partials/product-video', ['p' => $p, 'video' => \App\Support\Video::parse($p['video'] ?? null), 'poster' => $p['images'][0]['path'] ?? null]) ?>
        </div>

        <?php /* Details */ ?>
        <div class="space-y-6">
            <div>
                <?php if ($p['category']): ?><a href="<?= e(route('category', ['slug' => $p['category']['slug']])) ?>" class="eyebrow hit no-underline hover:underline"><?= e($p['category']['name']) ?></a><?php endif; ?>
                <h1 class="!text-3xl lg:!text-4xl !mt-2 !mb-2"><?= e($p['name']) ?></h1>
                <?php if ($stats['count']): ?>
                    <a href="#reviews" class="inline-flex items-center gap-2 text-sm no-underline hit"><?= View::partial('components/stars', ['rating' => $stats['average']]) ?> <span class="muted"><?= e($stats['average']) ?> (<?= (int) $stats['count'] ?> <?= $stats['count'] === 1 ? 'review' : 'reviews' ?>)</span></a>
                <?php endif; ?>
                <?php if ($p['is_sample'] && $sample): ?><div class="mt-2"><span class="sample-flag">SAMPLE PRODUCT</span></div><?php endif; ?>
            </div>

            <?php if ($p['short_description']): ?><p class="muted"><?= e($p['short_description']) ?></p><?php endif; ?>

            <?= View::partial('store/partials/product-form', ['product' => $p]) ?>
            <?= View::partial('store/partials/assurance') ?>

            <div class="border-t border-line divide-y divide-line" x-data="{ open: 'details' }">
                <?php foreach ([
                    'details' => ['Description', $p['description'], true],
                    'specs' => ['Specifications', $p['specifications'], 'specs'],
                    'delivery' => ['Delivery information', $deliveryInfo, false],
                    'returns' => ['Return policy', $returnPolicy, false],
                ] as $id => [$heading, $content, $isHtml]):
                    if (!$content) {
                        continue;
                    } ?>
                    <div>
                        <h2 class="!mb-0 !text-base"><button type="button" class="w-full flex items-center justify-between py-4 text-left font-display font-semibold" @click="open = open === '<?= $id ?>' ? null : '<?= $id ?>'" :aria-expanded="open === '<?= $id ?>'"><?= e($heading) ?> <?= icon('chevron-down', 18, [':class' => "open === '{$id}' && 'rotate-180'"]) ?></button></h2>
                        <?php if ($isHtml === 'specs'): ?>
                            <div x-show="open === '<?= $id ?>'" class="pb-5 text-sm"><table class="w-full"><caption class="sr-only">Product specifications</caption><tbody>
                                <?php foreach ($content as $row): ?><tr class="border-b border-line last:border-0"><th scope="row" class="py-2.5 pr-4 text-left font-semibold align-top w-2/5"><?= e($row['label']) ?></th><td class="py-2.5 text-grey-text"><?= e($row['value']) ?></td></tr><?php endforeach; ?>
                            </tbody></table></div>
                        <?php else: ?>
                        <div x-show="open === '<?= $id ?>'" class="pb-5 text-sm leading-relaxed prose-abiha"><?= $isHtml ? rich(HtmlSanitizer::clean((string) $content)) : nl2br(e($content)) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php /* Reviews */ ?>
    <section id="reviews" class="mt-20 scroll-mt-28" aria-labelledby="reviews-h">
        <h2 id="reviews-h" class="!text-2xl">Customer reviews</h2>
        <div class="grid gap-10 lg:grid-cols-[280px_1fr]">
            <div>
                <?php if ($stats['count']): ?>
                    <p class="font-display font-extrabold text-5xl leading-none"><?= e($stats['average']) ?></p>
                    <?= View::partial('components/stars', ['rating' => $stats['average'], 'size' => 20, 'class' => 'mt-2']) ?>
                    <p class="muted text-sm mt-1"><?= (int) $stats['count'] ?> <?= $stats['count'] === 1 ? 'review' : 'reviews' ?></p>
                    <div class="mt-4 space-y-1.5">
                        <?php foreach ([5, 4, 3, 2, 1] as $star): $c = $ratingCounts[$star] ?? 0; $pct = $stats['count'] ? round($c / $stats['count'] * 100) : 0; ?>
                            <div class="flex items-center gap-2 text-xs"><span class="w-3"><?= $star ?></span><div class="flex-1 h-1.5 bg-line rounded"><div class="h-full bg-gold rounded" style="width:<?= $pct ?>%"></div></div><span class="w-6 text-right text-grey-text"><?= (int) $c ?></span></div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="muted">No reviews yet.</p>
                <?php endif; ?>
            </div>
            <div class="space-y-8">
                <?php foreach ($reviews->items as $r): ?>
                    <article class="border-b border-line pb-6" data-review>
                        <div class="flex flex-wrap items-center gap-3"><?= View::partial('components/stars', ['rating' => (int) $r['rating']]) ?> <?php if ($r['is_verified_purchase']): ?><span class="badge badge--success">Verified purchase</span><?php endif; ?> <?php if ($r['is_sample'] && $sample): ?><span class="sample-flag">SAMPLE</span><?php endif; ?></div>
                        <?php if ($r['title']): ?><h3 class="!text-base !mt-2 !mb-1"><?= e($r['title']) ?></h3><?php endif; ?>
                        <p class="mt-1 text-sm"><?= e($r['body']) ?></p>
                        <p class="mt-2 text-xs text-grey-text"><?= e($r['name']) ?> · <?= e(dt($r['created_at'], 'j M Y')) ?></p>
                    </article>
                <?php endforeach; ?>
                <?= $reviews->links() ?>

                <?php if ($signedIn): ?>
                    <?php if ($canReview): ?>
                        <form method="POST" action="<?= e(route('reviews.store', $p)) ?>" class="card space-y-4 !bg-ivory !border-transparent">
                            <?= csrf_field() ?>
                            <h3 class="!text-lg !mb-0">Write a review</h3>
                            <fieldset><legend class="label mb-2">Your rating</legend>
                                <div class="flex gap-4"><?php foreach ([1, 2, 3, 4, 5] as $s): ?><label class="check"><input type="radio" name="rating" value="<?= $s ?>" <?= (string) old('rating') === (string) $s ? 'checked' : '' ?> required> <?= $s ?>★</label><?php endforeach; ?></div>
                                <?php if (error('rating')): ?><span class="error-text"><?= e(error('rating')) ?></span><?php endif; ?>
                            </fieldset>
                            <?php $terr = error('title'); $tval = old('title'); ?>
                            <div class="field">
                                <label class="label" for="f-title">Title (optional) </label>
                                <input class="input" id="f-title" name="title" type="text" value="<?= e(is_scalar($tval) ? $tval : '') ?>"<?= $terr ? ' aria-invalid="true" aria-describedby="e-title"' : '' ?>>
                                <?php if ($terr): ?><span class="error-text" id="e-title"><?= e($terr) ?></span><?php endif; ?>
                            </div>
                            <div class="field"><label class="label" for="f-body">Review <span class="req">*</span></label><textarea class="textarea" id="f-body" name="body" required minlength="10"><?= e(old('body')) ?></textarea><?php if (error('body')): ?><span class="error-text"><?= e(error('body')) ?></span><?php endif; ?></div>
                            <button class="btn">Submit review</button>
                            <p class="help">Reviews are published after moderation.</p>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-sm"><a class="link" href="<?= e(route('login')) ?>">Sign in</a> to write a review.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($related): ?>
        <section class="mt-20" aria-labelledby="rel-h">
            <h2 id="rel-h" class="!text-2xl">You may also like</h2>
            <div class="grid grid--4 grid--products mt-8"><?php foreach ($related as $rp): ?><?= View::partial('store/partials/product-card', ['product' => $rp]) ?><?php endforeach; ?></div>
        </section>
    <?php endif; ?>

    <?php if ($recent): ?>
        <section class="mt-20" aria-labelledby="rec-h">
            <h2 id="rec-h" class="!text-2xl">Recently viewed</h2>
            <div class="grid grid--4 grid--products mt-8"><?php foreach (array_slice($recent, 0, 4) as $rp): ?><?= View::partial('store/partials/product-card', ['product' => $rp]) ?><?php endforeach; ?></div>
        </section>
    <?php endif; ?>
</div>

<?php /* Size guide: the owner's own measurements (Admin -> Settings -> Size guide), shown in a modal. Hidden entirely until they write one. */ ?>
<?php if ($sizeGuide !== ''): ?>
    <div x-data="{ open: false }" @open-size-guide.window="open = true" @keydown.escape.window="open = false">
        <div x-cloak x-show="open" class="fixed inset-0 z-[300] grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="size-guide-title"
             x-transition:enter="transition-opacity duration-200" x-transition:enter-start="opacity-0" x-transition:leave="transition-opacity duration-150" x-transition:leave-end="opacity-0">
            <div class="absolute inset-0 dialog-scrim" @click="open = false"></div>
            <div class="dialog-surface relative rounded-lg shadow-lift w-full max-w-2xl max-h-[88vh] overflow-y-auto p-6 md:p-8" tabindex="-1" x-trap="open">
                <button type="button" class="absolute right-3 top-3 btn btn--ghost !px-2" @click="open = false" aria-label="Close size guide"><?= icon('x', 22) ?></button>
                <h2 id="size-guide-title" class="!text-2xl">Size guide</h2>
                <div class="prose-abiha text-sm overflow-x-auto"><?= rich(HtmlSanitizer::clean($sizeGuide)) ?></div>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php View::share('scripts', (string) View::shared('scripts', '') . '<script type="module" src="' . e(asset('assets/abiha/media.js')) . '"></script>'); ?>
