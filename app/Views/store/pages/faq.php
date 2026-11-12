<?php
/**
 * Built-in FAQ (port of resources/views/storefront/faq.blade.php). Answers in $groups are already escaped/linked HTML.
 *
 * @var array $page
 * @var array<string, list<array{q:string,a:string}>> $groups
 */
use App\Core\View;
use App\Services\ContentPages;
use App\Support\Seo;

Seo::breadcrumbs([['Home', route('home')], ['FAQ', '']]);
if ((int) $page['is_sample'] === 1 && cfg('abiha.show_sample_labels')) {
    Seo::noindex();
}
$entities = [];
foreach ($groups as $items) {
    foreach ($items as $i) {
        $entities[] = ['@type' => 'Question', 'name' => $i['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($i['a']))))]];
    }
}
Seo::schema(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities]);
// Laravel's Str::slug ("Shipping & delivery" -> "shipping-delivery"), so the anchors stay the same as before.
$slug = static fn (string $s): string => trim((string) preg_replace('/[\s_-]+/', '-', (string) preg_replace('/[^a-z0-9\s_-]+/', '', strtolower(str_replace('@', '-at-', $s)))), '-');
$phone = ContentPages::phone();
$email = ContentPages::email();
?>
<div x-data="faq()">
    <section class="faq-hero">
        <div class="container">
            <?= View::partial('store/pages/breadcrumbs', ['class' => 'mb-6']) ?>
            <p class="eyebrow">Help centre</p>
            <h1>Frequently asked questions</h1>
            <p class="faq-hero__lead">Quick answers about ordering, payment, delivery and returns. Cannot find yours? We are happy to help.</p>
            <div class="faq-search">
                <label for="faq-q" class="sr-only">Search the questions</label>
                <?= icon('search', 20) ?>
                <input id="faq-q" type="search" x-model.debounce.150ms="q" placeholder="Search, for example “delivery” or “refund”" autocomplete="off">
            </div>
        </div>
    </section>

    <div class="container faq">
        <nav class="faq-nav" aria-label="Question topics">
            <?php foreach ($groups as $title => $items): ?>
                <a href="#faq-<?= e($slug($title)) ?>" class="faq-pill"><?= e($title) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="faq-body">
            <?php foreach ($groups as $title => $items): ?>
                <section id="faq-<?= e($slug($title)) ?>" class="faq-group" x-show="groupVisible($el)" aria-labelledby="faq-h-<?= e($slug($title)) ?>">
                    <h2 id="faq-h-<?= e($slug($title)) ?>" class="faq-group__h"><?= e($title) ?></h2>
                    <?php foreach ($items as $i): ?>
                        <details class="faq-item" data-faq x-show="match($el)" :open="q.trim() !== '' && match($el)">
                            <summary><span><?= e($i['q']) ?></span><?= icon('plus', 20, 'faq-item__plus') ?></summary>
                            <div class="faq-item__a"><?= $i['a'] ?></div>
                        </details>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>

            <p class="faq-empty" x-show="q.trim() !== '' && none()" x-cloak role="status">
                Nothing matches “<span x-text="q"></span>”. Try another word, or <a href="<?= e(route('contact')) ?>">send us a message</a>.
            </p>
        </div>

        <aside class="faq-help" aria-labelledby="faq-help-h">
            <h2 id="faq-help-h">Still need help?</h2>
            <p>Tell us your order number and what you need, and we will get back to you.</p>
            <div class="faq-help__actions">
                <a href="<?= e(route('contact')) ?>" class="btn">Contact us</a>
                <a href="<?= e(route('track')) ?>" class="btn btn--outline">Track your order</a>
            </div>
            <?php if ($phone !== '' || $email !== ''): ?>
                <ul class="faq-help__lines">
                    <?php if ($phone !== ''): ?><li><?= icon('phone', 16) ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a></li><?php endif; ?>
                    <?php if ($email !== ''): ?><li><?= icon('mail', 16) ?><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></li><?php endif; ?>
                </ul>
            <?php endif; ?>
        </aside>
    </div>
</div>
