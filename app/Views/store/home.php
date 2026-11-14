<?php
/**
 * Home page (port of resources/views/storefront/home.blade.php): the admin-managed blocks of App\Services\HomePage.
 * @var list<array{section:array,data:array}> $sections
 */
use App\Core\View;

$hasHero = false;
foreach ($sections as $r) {
    $hasHero = $hasHero || $r['section']['type'] === 'hero';
}
// A clean boutique rhythm: white and ivory sections take turns; only the campaign banner is black.
$fills = ['categories' => 'white', 'collections' => 'ivory', 'promo' => 'white', 'campaign' => 'dark', 'brand' => 'white',
          'testimonials' => 'ivory', 'social' => 'white', 'newsletter' => 'ivory', 'account' => 'white'];
$n = 0;
?>
<?php /* Every page needs exactly one h1; the hero banner supplies it, so only add a hidden one when there is no hero. */ ?>
<div class="home-3d">
<?php if (!$hasHero): ?><h1 class="sr-only"><?= e(cfg('abiha.name')) ?>®</h1><?php endif; ?>
<?php foreach ($sections as $row):
    $type = $row['section']['type'];
    if ($type === 'hero') {
        $tone = 'ivory';
    } elseif ($type === 'products') {
        $tone = $n++ % 2 === 0 ? 'white' : 'ivory';
    } else {
        $tone = $fills[$type] ?? 'white';
    }
    $toneClass = ['white' => '', 'ivory' => 'section--ivory', 'grey' => 'section--grey', 'dark' => 'section--dark on-dark'][$tone];
    if (!View::exists('store/sections/' . $type)) {
        continue;
    }
?>
    <?php /* product rows reveal card-by-card inside their own grid; every other section fades up as a whole */ ?>
    <div <?php if (!in_array($type, ['hero', 'products'], true)): ?> data-reveal <?php endif; ?>>
        <?= View::partial('store/sections/' . $type, ['section' => $row['section'], 'tone' => $tone, 'toneClass' => $toneClass] + $row['data']) ?>
    </div>
<?php endforeach; ?>
</div>
<?php View::share('scripts', (string) View::shared('scripts', '') . '<script type="module" src="' . e(asset('assets/abiha/home.js')) . '"></script>'); ?>
