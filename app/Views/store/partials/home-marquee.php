<?php
/*
 * Home page: a slow, endless line of the shop's own category names (links), tilting in 3D with the scroll. The names are the
 * top-level categories that really have products; with fewer than three nothing is shown. The second copy only makes the loop
 * seamless, so it is hidden from screen readers and keyboards. With reduced motion the line is static and wraps.
 */
use App\Services\HomePage;
use App\Support\CatalogCache;

$names = CatalogCache::remember('home.marquee', 600, static fn (): array => array_map(
    static fn ($c) => ['name' => (string) $c['name'], 'slug' => (string) $c['slug']],
    HomePage::topCategories()
));
if (count($names) < 3) {
    return;
}
?>
<section class="home-marquee" data-parallax aria-label="Our collections">
    <div class="home-marquee__stage">
        <div class="home-marquee__track">
            <?php foreach ([false, true] as $copy): ?>
                <ul class="home-marquee__set"<?= $copy ? ' aria-hidden="true"' : '' ?>>
                    <?php foreach ($names as $i => $c): ?>
                        <li class="home-marquee__item">
                            <a href="<?= e(route('category', ['slug' => $c['slug']])) ?>" class="home-marquee__link <?= $i % 2 ? 'is-outline' : '' ?>"<?= $copy ? ' tabindex="-1"' : '' ?>><?= e($c['name']) ?></a>
                            <span class="home-marquee__gem" aria-hidden="true"></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </div>
    </div>
</section>
