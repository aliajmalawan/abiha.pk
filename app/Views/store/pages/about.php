<?php
/**
 * Built-in About us (port of resources/views/storefront/about.blade.php).
 *
 * @var array $page
 * @var list<array{category: array, subs: list<string>}> $collections
 * @var string $paymentLine
 * @var string $phone
 * @var string $email
 */
use App\Core\View;
use App\Support\Seo;

Seo::breadcrumbs([['Home', route('home')], ['About us', '']]);
$name = cfg('abiha.name');
?>
<section class="about-hero">
    <div class="container">
        <?= View::partial('store/pages/breadcrumbs', ['class' => 'mb-6']) ?>
        <p class="eyebrow">About us</p>
        <h1><?= e($name) ?></h1>
        <hr class="gold-rule mx-auto">
        <p class="about-hero__lead">Performance, comfort and style for your active life.</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="about-pillars">
            <article class="about-pillar">
                <span class="about-pillar__icon"><?= icon('check', 26) ?></span>
                <h2>Performance</h2>
                <p>Activewear and sportswear made for movement, whether you train, play or work.</p>
            </article>
            <article class="about-pillar">
                <span class="about-pillar__icon"><?= icon('heart', 26) ?></span>
                <h2>Comfort</h2>
                <p>Pieces chosen to feel good from the first wear to the last repeat of the day.</p>
            </article>
            <article class="about-pillar">
                <span class="about-pillar__icon"><?= icon('star', 26) ?></span>
                <h2>Style</h2>
                <p>Clean looks in black, gold and classic colours that work in the gym and beyond.</p>
            </article>
        </div>
    </div>
</section>

<?php if ($collections): ?>
    <section class="section section--ivory">
        <div class="container">
            <div class="home-head text-center">
                <p class="eyebrow">What we offer</p>
                <h2>Shop by collection</h2>
                <hr class="gold-rule mx-auto">
            </div>
            <ul class="about-cats">
                <?php foreach ($collections as $c): ?>
                    <li>
                        <a href="<?= e(route('category', ['slug' => $c['category']['slug']])) ?>" class="about-cat">
                            <span class="about-cat__name"><?= e($c['category']['name']) ?></span>
                            <?php if ($c['subs']): ?><span class="about-cat__subs"><?= e(implode(' · ', array_slice($c['subs'], 0, 3))) ?><?php if (count($c['subs']) > 3): ?> · and more <?php endif; ?></span><?php endif; ?>
                            <span class="about-cat__go">Shop now <?= icon('arrow-right', 16) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
<?php endif; ?>

<section class="section">
    <div class="container">
        <div class="home-head text-center">
            <p class="eyebrow">Simple and clear</p>
            <h2>How shopping with us works</h2>
            <hr class="gold-rule mx-auto">
        </div>
        <ol class="about-steps">
            <li><span class="about-steps__n">1</span><?= icon('search', 24) ?><h3>Choose</h3><p>Browse the collections, open a product and pick your size and colour.</p></li>
            <li><span class="about-steps__n">2</span><?= icon('shopping-bag', 24) ?><h3>Order</h3><p>Add to your bag and check out as a guest or with an account. No account is needed.</p></li>
            <li><span class="about-steps__n">3</span><?= icon('credit-card', 24) ?><h3>Pay</h3><p><?= e($paymentLine) ?></p></li>
            <li><span class="about-steps__n">4</span><?= icon('truck', 24) ?><h3>Track</h3><p>Follow your parcel any time on the <a href="<?= e(route('track')) ?>">Track your order</a> page.</p></li>
        </ol>
    </div>
</section>

<section class="about-cta">
    <div class="container">
        <h2>We would love to hear from you</h2>
        <p>Questions about an order, a size or a product? Our team is happy to help.</p>
        <div class="about-cta__actions">
            <a href="<?= e(route('contact')) ?>" class="btn">Contact us</a>
            <a href="<?= e(route('page', 'faq')) ?>" class="btn btn--outline">Read the FAQ</a>
        </div>
        <?php if ($phone !== '' || $email !== ''): ?>
            <ul class="about-cta__lines">
                <?php if ($phone !== ''): ?><li><?= icon('phone', 16) ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a></li><?php endif; ?>
                <?php if ($email !== ''): ?><li><?= icon('mail', 16) ?><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></li><?php endif; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
