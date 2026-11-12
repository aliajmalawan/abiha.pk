<?php
/* A product block: centred heading, optional collection tabs, then the products in the chosen layout. */
use App\Core\View;
use App\Services\HomePage;

$layout = HomePage::setting($section, 'layout', 'carousel');
?>
<section class="section <?= e($toneClass ?? '') ?>">
    <div class="container">
        <div class="home-head" data-reveal>
            <?php if ($section['subtitle']): ?><p class="eyebrow !m-0"><?= e($section['subtitle']) ?></p><?php endif; ?>
            <h2><?= e($section['title']) ?></h2>
            <hr class="gold-rule">
        </div>

        <?php if (!empty($tabs)): ?>
            <div x-data="{ tab: 0 }">
                <div class="home-tabs" role="tablist" aria-label="<?= e($section['title']) ?>">
                    <?php foreach ($tabs as $k => $t): ?>
                        <button type="button" role="tab" class="home-tab" :aria-selected="tab === <?= $k ?>" @click="tab = <?= $k ?>; $nextTick(() => window.dispatchEvent(new Event('resize')))"><?= e($t['label']) ?></button>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($tabs as $k => $t): ?>
                    <div role="tabpanel" x-show="tab === <?= $k ?>" <?php if ($k > 0): ?> x-cloak <?php endif; ?>>
                        <?= View::partial('store/partials/product-panel', ['products' => $t['products'], 'layout' => $layout, 'link' => $t['link'] ?? null, 'label' => $t['label']]) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <?= View::partial('store/partials/product-panel', ['products' => $products, 'layout' => $layout, 'link' => HomePage::setting($section, 'link'), 'label' => $section['title']]) ?>
        <?php endif; ?>
    </div>
</section>
