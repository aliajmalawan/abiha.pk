<?php
/**
 * Port of admin/products/form.blade.php.
 * @var ?array $product @var array $categories @var array $attrData @var array $images @var array $variantRows
 */
use App\Core\View;
use App\Services\Admin\Form;
use App\Support\Img;

$exists = $product !== null;
$f = static fn (string $k, mixed $d = null): mixed => old($k, $product[$k] ?? $d);
$errs = errors();
$specRows = old('specifications', $exists && $product['specifications'] ? (json_decode((string) $product['specifications'], true) ?: []) : []);
$specRows = array_values(is_array($specRows) ? $specRows : []);
$removeOld = array_map('intval', array_filter((array) old('remove_images', []), 'is_scalar'));
$imgData = array_map(static fn ($i) => [
    'id' => (int) $i['id'], 'url' => Img::url((string) $i['path']),
    'alt' => (string) old('image_alts.' . $i['id'], $i['alt']), 'remove' => in_array((int) $i['id'], $removeOld, true),
], $images);
$firstMatch = static function (string $prefix) use ($errs): ?string {
    foreach ($errs as $k => $m) {
        if (str_starts_with((string) $k, $prefix)) {
            return is_array($m) ? (string) reset($m) : (string) $m;
        }
    }
    return null;
};
$xdata = "{
          name: " . Form::js((string) $f('name', '')) . ",
          sku: " . Form::js((string) $f('sku', '')) . ",
          slug: " . Form::js((string) $f('slug', '')) . ",
          skuAuto: " . ($f('sku') ? 'false' : 'true') . ",
          slugAuto: " . ($f('slug') ? 'false' : 'true') . ",
          slugify(t) { return t.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120); },
          makeSku(t) {
              const w = t.toUpperCase().normalize('NFKD').replace(/[^A-Z0-9 ]/g, '').split(/\s+/).filter(Boolean);
              if (!w.length) return '';
              const code = w.length > 1 ? w.slice(0, 3).map(x => x.slice(0, 3)).join('') : w[0].slice(0, 6);
              return 'ABH-' + code + '-' + (this.skuTail ??= String(Math.floor(1000 + Math.random() * 9000)));
          },
          onName() {
              if (this.slugAuto) this.slug = this.slugify(this.name);
              if (this.skuAuto) this.sku = this.makeSku(this.name);
          },
          hasVariants: " . ($f('has_variants') ? 'true' : 'false') . ",
          attrs: " . Form::js($attrData) . ",
          rows: " . Form::js($variantRows) . ".map(r => ({...r, sel: {}})),
          init() { this.rows.forEach(r => this.attrs.forEach(a => { const hit = (r.options || []).find(id => a.values.some(v => v.id == id)); if (hit) r.sel[a.id] = hit; })); },
          add() { this.rows.push({ id: null, sku: '', price: '', compare_price: '', stock: 0, is_active: true, options: [], sel: {} }); },
          remove(i) { this.rows.splice(i, 1); },
          chosen(r) { return Object.values(r.sel).filter(Boolean); }
      }";
$nErr = count($errs);
?>
<a href="<?= e(route('admin.products.index')) ?>" class="link text-sm inline-flex items-center gap-1 mb-3"><?= icon('chevron-left', 14) ?> Products</a>
<header class="pf-head">
    <div>
        <h1 class="!text-2xl !mb-1"><?= $exists ? 'Edit product' : 'Add a new product' ?></h1>
        <p class="help"><?= $exists ? 'Update the details, photos and stock. Changes go live as soon as you save.' : 'Fill in the details, add photos, set the price and stock, then save. Fields marked * are required.' ?></p>
    </div>
    <?php if ($exists): ?><a href="<?= e(route('product', ['slug' => $product['slug']])) ?>" target="_blank" rel="noopener" class="btn btn--sm btn--outline">View on website</a><?php endif; ?>
</header>
<?php if ($nErr && !isset($errs['inventory'])): ?><div class="alert alert--danger mb-4" role="alert"><strong>Please fix <?= $nErr ?> <?= Form::plural('problem', $nErr) ?> below.</strong></div><?php endif; ?>

<form method="POST" enctype="multipart/form-data" novalidate class="pf pb-4 grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px] items-start"
      action="<?= e($exists ? route('admin.products.update', ['id' => $product['id']]) : route('admin.products.store')) ?>"
      x-data="<?= e($xdata) ?>">
    <?= csrf_field() ?>
    <?php if ($exists): ?><?= method_field('PUT') ?><?php endif; ?>

    <div class="space-y-6">
        <?= View::partial('admin/products/form-main', compact('f', 'specRows', 'categories', 'firstMatch')) ?>
        <?= View::partial('admin/products/form-photos', compact('exists', 'imgData', 'firstMatch', 'product')) ?>
    </div>

    <aside class="space-y-6">
        <section class="card pf-card space-y-3">
            <div class="pf-card__head"><span class="pf-card__icon"><?= icon('eye', 18) ?></span><div><h2 class="!text-lg !mb-0">Visibility</h2><p class="help">Where this product appears.</p></div></div>
            <?php foreach (['is_active' => 'Visible in the shop', 'is_featured' => 'Featured', 'is_new' => 'New arrival', 'is_bestseller' => 'Best seller', 'is_trending' => 'Trending'] as $k => $l): ?>
                <label class="check pf-toggle"><input type="checkbox" name="<?= $k ?>" value="1"<?= $f($k, $k === 'is_active') ? ' checked' : '' ?>> <?= e($l) ?></label>
            <?php endforeach; ?>
            <?= Form::field('badge', 'Custom badge (optional)', $f('badge'), ['help' => 'e.g. Limited']) ?>
        </section>
        <section class="card pf-card space-y-3">
            <div class="pf-card__head"><span class="pf-card__icon"><?= icon('search', 18) ?></span><div><h2 class="!text-lg !mb-0">Search engines</h2><p class="help">Optional. Blank = made from the name and description.</p></div></div>
            <?= Form::field('meta_title', 'SEO title', $f('meta_title')) ?>
            <div class="field"><label class="label" for="meta_description">SEO description</label><textarea class="textarea !min-h-[80px]" id="meta_description" name="meta_description"><?= e($f('meta_description')) ?></textarea></div>
        </section>
    </aside>

    <?php /* The Save button stays at the bottom of the screen, so it is always reachable. */ ?>
    <div class="pf-bar lg:col-span-2 no-print">
        <div class="pf-bar__info">
            <span class="pf-bar__dot" aria-hidden="true"></span>
            <p><?php if ($exists): ?><span class="pf-bar__label">Editing</span><strong><?= e($product['name']) ?></strong><?php else: ?><span class="pf-bar__label">New product</span><strong>Not saved yet</strong><?php endif; ?></p>
        </div>
        <div class="pf-bar__actions">
            <a href="<?= e(route('admin.products.index')) ?>" class="pf-bar__cancel"><?= icon('x', 16) ?> Cancel</a>
            <button type="submit" class="pf-bar__save"><?= icon('check', 18) ?> <?= $exists ? 'Save changes' : 'Save product' ?></button>
        </div>
    </div>
</form>
