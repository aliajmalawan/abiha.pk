<?php
/**
 * Storefront footer (port of resources/views/partials/footer.blade.php): newsletter strip (not on the home page),
 * brand column, Shop/Help/Company link columns, contact column, copyright bar with courier logos, back-to-top button.
 */
use App\Core\Database;
use App\Support\Menus;

$toPairs = static fn (string $key): array => array_map(static fn ($l) => [$l['label'], $l['href']], Menus::get($key));
$cols = array_filter(['Shop' => $toPairs('footer_shop'), 'Help' => $toPairs('footer_help')]);       // editable under Admin -> Menus
try {
    $footerPages = Database::get()->all('SELECT title, slug FROM pages WHERE is_published = 1 AND show_in_footer = 1 ORDER BY sort_order, id');
} catch (\Throwable) {
    $footerPages = [];
}
if ($footerPages) {
    $cols['Company'] = array_map(static fn ($p) => [$p['title'], route('page', ['slug' => $p['slug']])], $footerPages);
}
$email = setting('contact.email', cfg('abiha.contact.email'));
$phone = setting('contact.phone', cfg('abiha.contact.phone'));
$address = trim((string) setting('contact.address', cfg('abiha.contact.address')));
$hours = trim((string) setting('contact.hours'));
$social = array_filter([
    'Instagram' => setting('social.instagram'),
    'Facebook' => setting('social.facebook'),
    'TikTok' => setting('social.tiktok'),
    'YouTube' => setting('social.youtube'),
]);
$whatsapp = preg_replace('/\D/', '', (string) setting('contact.whatsapp'));
if ($whatsapp) {
    $social['WhatsApp'] = 'https://wa.me/' . $whatsapp;
}
$footerText = setting('footer.text', cfg('abiha.tagline'));
?>
<footer class="footer mt-auto">
    <?php /* Newsletter strip (the home page has its own sign-up block, so it is not repeated there) */ ?>
    <?php if (!route_is('home')): ?>
        <div class="footer__news">
            <div class="container footer__news-in" x-data="newsletter('<?= e(route('newsletter')) ?>')">
                <div>
                    <p class="footer__news-title">Join the ABIHA list</p>
                    <p class="footer__news-sub">New arrivals and offers, straight to your inbox.</p>
                </div>
                <form method="POST" action="<?= e(route('newsletter')) ?>" @submit.prevent="submit()" novalidate class="footer__news-form">
                    <?= csrf_field() ?>
                    <label for="ft-nl-email" class="sr-only">Email address</label>
                    <input id="ft-nl-email" type="email" name="email" x-model="email" class="footer__input" placeholder="Your email address" required autocomplete="email">
                    <button class="footer__btn" :class="state === 'busy' && 'is-loading'">Subscribe</button>
                    <p class="footer__news-msg" x-show="message" :class="state === 'error' ? 'is-error' : 'is-ok'" x-text="message" role="status"></p>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="container footer__grid">
        <div class="footer__brand">
            <span class="footer__logo"><?= logo(96, true) ?></span>
            <?php if ($footerText): ?><p class="footer__about"><?= e($footerText) ?></p><?php endif; ?>
            <?php if ($social): ?>
                <ul class="footer__social" aria-label="Follow us">
                    <?php foreach ($social as $name => $link): ?><li><a href="<?= e($link) ?>" rel="noopener" target="_blank" aria-label="<?= e($name) ?> (opens in a new tab)"><?= e($name) ?></a></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php foreach ($cols as $title => $links): ?>
            <nav class="footer__col" aria-label="<?= e($title) ?>">
                <h2 class="footer__h"><?= e($title) ?></h2>
                <ul>
                    <?php foreach ($links as [$label, $href]): ?><li><a href="<?= e($href) ?>"><?= e($label) ?></a></li><?php endforeach; ?>
                </ul>
            </nav>
        <?php endforeach; ?>

        <div class="footer__col footer__contact">
            <h2 class="footer__h">Contact</h2>
            <ul>
                <?php if ($address): ?><li><?= icon('map-pin', 16) ?><span><?= nl2br(e($address)) ?></span></li><?php endif; ?>
                <?php if ($phone): ?><li><?= icon('phone', 16) ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $phone)) ?>"><?= e($phone) ?></a></li><?php endif; ?>
                <?php if ($email): ?><li><?= icon('mail', 16) ?><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></li><?php endif; ?>
                <?php if ($hours): ?><li><?= icon('truck', 16) ?><span><?= e($hours) ?></span></li><?php endif; ?>
                <?php if (!$email && !$phone && cfg('abiha.show_sample_labels')): ?><li class="footer__muted">Contact details to be added <span class="sample-flag">SAMPLE</span></li><?php endif; ?>
            </ul>
        </div>
    </div>

    <div class="footer__bar">
        <div class="container footer__bar-in">
            <span>© <?= date('Y') ?> <?= e(cfg('abiha.name')) ?>®. All rights reserved.</span>
            <span class="footer__ship"><span>We ship with</span><?php foreach (['dhl', 'fedex', 'ups'] as $c): ?><span class="footer__ship-tile"><?= carrier_logo($c, 22) ?></span><?php endforeach; ?></span>
        </div>
    </div>
    <?php /* Back to top: appears after scrolling, shows how far down the page you are, and sits above the mobile buy bar on product pages */ ?>
    <button type="button" class="to-top <?php if (route_is('product')): ?>to-top--lifted<?php endif; ?>" aria-label="Back to top" title="Back to top"
            x-data="{ show: false, pct: 0, update() { const max = document.documentElement.scrollHeight - innerHeight; this.pct = max > 0 ? Math.min(1, scrollY / max) : 0; this.show = scrollY > 420; } }"
            x-init="update()" @scroll.window.passive="update()" @resize.window.passive="update()"
            x-show="show" x-cloak x-transition.opacity.duration.200ms
            @click="window.scrollTo({ top: 0, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })">
        <svg class="to-top__ring" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><circle class="to-top__track" cx="24" cy="24" r="22"/><circle class="to-top__bar" cx="24" cy="24" r="22" :style="'stroke-dashoffset:' + (138.23 * (1 - pct))"/></svg>
        <?= icon('arrow-up', 20) ?>
    </button>
</footer>
