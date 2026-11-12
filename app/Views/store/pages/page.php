<?php
/**
 * CMS page (port of resources/views/storefront/page.blade.php).
 *
 * @var array $page
 */
use App\Core\View;
use App\Support\Seo;

Seo::breadcrumbs([['Home', route('home')], [$page['title'], '']]);
if ((int) $page['is_sample'] === 1) {
    Seo::noindex();   // placeholder legal/policy text must never be indexed
}
?>
<div class="container py-12 lg:py-16 max-w-3xl">
    <?= View::partial('store/pages/breadcrumbs', ['class' => 'mb-6']) ?>
    <h1 class="!text-3xl lg:!text-4xl"><?= e($page['title']) ?></h1>
    <?php if ((int) $page['is_sample'] === 1 && cfg('abiha.show_sample_labels')): ?><p><span class="sample-flag">SAMPLE CONTENT: replace before launch</span></p><?php endif; ?>
    <?php /* Admin-authored rich text, rebuilt through an allow-list sanitizer (no scripts, no event handlers, safe URLs only). */ ?>
    <div class="prose-abiha mt-6"><?= rich(App\Support\HtmlSanitizer::clean($page['body'])) ?></div>
</div>
