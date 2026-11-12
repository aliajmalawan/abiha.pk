<?php
/** Port of admin/home/edit.blade.php. @var array $section @var array $types @var array $sources @var array $layouts @var array $collections */
use App\Services\Admin\Form;
use App\Support\Img;

$s = $section['settings'];
$type = $section['type'];
?>
<a href="<?= e(route('admin.home.index')) ?>" class="link text-sm inline-flex items-center gap-1 mb-4"><?= icon('chevron-left', 14) ?> Homepage</a>
<h1 class="!text-2xl"><?= e($types[$type] ?? $type) ?></h1>
<form method="POST" action="<?= e(route('admin.home.update', ['id' => $section['id']])) ?>" class="card mt-4 max-w-2xl space-y-4" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?><?= method_field('PUT') ?>
    <?= Form::field('title', 'Heading', $section['title']) ?>
    <?= Form::field('subtitle', 'Small text / subheading', $section['subtitle']) ?>
    <?php if (in_array($type, ['categories', 'products', 'collections', 'testimonials'], true)): ?>
        <?= Form::field('limit', 'How many to show (1-12)', $s['limit'] ?? 4, ['type' => 'number', 'attrs' => ['min' => '1', 'max' => '12']]) ?>
    <?php endif; ?>
    <?php if ($type === 'products'): ?>
        <div class="field"><label class="label" for="source">Which products</label>
            <select class="select" id="source" name="source"><?php foreach ($sources as $k => $l): ?><option value="<?= e($k) ?>"<?= old('source', $s['source'] ?? null) === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php if ($m = error('source')): ?><span class="error-text"><?= e($m) ?></span><?php endif; ?></div>
        <div class="field"><label class="label" for="collection">Collection (when "A specific collection" is chosen)</label>
            <select class="select" id="collection" name="collection"><option value="">None</option><?php foreach ($collections as $slug => $name): ?><option value="<?= e($slug) ?>"<?= old('collection', $s['collection'] ?? null) === (string) $slug ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label class="label" for="layout">Layout</label>
            <select class="select" id="layout" name="layout"><?php foreach ($layouts as $k => $l): ?><option value="<?= e($k) ?>"<?= old('layout', $s['layout'] ?? 'carousel') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <label class="check"><input type="checkbox" name="tabs" value="1"<?= old('tabs', $s['tabs'] ?? false) ? ' checked' : '' ?>> Show tabs for the collections (each tab lists this block's products from one collection)</label>
        <?= Form::field('link', '"View all" link', $s['link'] ?? null, ['help' => 'Site path like /shop?sort=newest. Blank hides the link.']) ?>
    <?php endif; ?>
    <?php if ($type === 'brand'): ?>
        <div class="field"><label class="label" for="body">Brand introduction text</label><textarea class="textarea" id="body" name="body" rows="5"><?= e(old('body', $s['body'] ?? '')) ?></textarea><?php if ($m = error('body')): ?><span class="error-text"><?= e($m) ?></span><?php endif; ?></div>
        <?= Form::field('cta_label', 'Button text', $s['cta_label'] ?? null) ?>
        <?= Form::field('cta_url', 'Button link', $s['cta_url'] ?? null) ?>
        <div class="field"><label class="label" for="image">Brand image</label>
            <?php if (!empty($s['image'])): ?><img src="<?= e(Img::url((string) $s['image'])) ?>" alt="" class="h-24 rounded border border-line mb-2"><label class="check mb-2"><input type="checkbox" name="remove_image" value="1"> Remove the current image</label><?php endif; ?>
            <input class="input !pt-2.5" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp"><span class="help">JPG, PNG or WebP, up to 4 MB. Without an image a neutral placeholder is shown.</span><?php if ($m = error('image')): ?><span class="error-text" role="alert"><?= e($m) ?></span><?php endif; ?></div>
    <?php endif; ?>
    <?php if ($type === 'social'): ?>
        <div class="field"><span class="label">Pictures (up to 6)</span>
            <?php $pics = array_values((array) ($s['images'] ?? [])); ?>
            <?php if ($pics): ?><div class="grid grid-cols-3 sm:grid-cols-6 gap-2 mb-2"><?php foreach ($pics as $i => $path): ?><label class="block text-center text-xs"><img src="<?= e(Img::url((string) $path)) ?>" alt="" class="aspect-square object-cover rounded border border-line w-full"><span class="check justify-center mt-1"><input type="checkbox" name="remove_images[]" value="<?= $i ?>"> Remove</span></label><?php endforeach; ?></div><?php endif; ?>
            <?php $imgErr = error('images'); foreach (errors() as $k => $msg) { if ($imgErr === null && str_starts_with((string) $k, 'images.')) { $imgErr = is_array($msg) ? reset($msg) : $msg; } } ?>
            <input class="input !pt-2.5" type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp" aria-label="Add pictures"><span class="help">Upload your own photos: JPG, PNG or WebP, up to 4 MB each, at most 6 photos. The Instagram link comes from Settings → Social links; the pictures link to it.</span><?php if ($imgErr): ?><span class="error-text" role="alert"><?= e($imgErr) ?></span><?php endif; ?></div>
    <?php endif; ?>
    <div class="flex gap-3"><button class="btn">Save</button><a href="<?= e(route('admin.home.index')) ?>" class="btn btn--outline">Cancel</a></div>
</form>
