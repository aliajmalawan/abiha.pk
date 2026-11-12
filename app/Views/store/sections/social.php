<?php
use App\Core\View;
use App\Services\HomePage;
use App\Support\Img;

$url = setting('social.instagram');
$pics = array_slice(array_values((array) HomePage::setting($section, 'images', [])), 0, 6);
$sample = cfg('abiha.show_sample_labels');
if (!($url || $pics || $sample)) {
    return;
}
?>
<section class="section <?= e($toneClass ?? '') ?>">
    <div class="container">
        <div class="text-center">
            <p class="eyebrow">Social</p>
            <h2><?= e($section['title']) ?></h2>
            <?php if ($url): ?><a href="<?= e($url) ?>" target="_blank" rel="noopener" class="link">Follow us on Instagram</a>
            <?php elseif ($sample): ?><p class="muted">Instagram link to be added <span class="sample-flag">SAMPLE</span></p><?php endif; ?>
        </div>
        <?php /* Pictures are uploaded by staff under Admin -> Homepage -> Social block (never scraped, never invented). They link to the profile. */ ?>
        <?php if ($pics || $sample): ?>
            <div class="grid grid-cols-3 gap-2 mt-10 lg:grid-cols-6">
                <?php if ($pics): ?>
                    <?php foreach ($pics as $i => $path): ?>
                        <a href="<?= e($url ?: '#') ?>" <?php if ($url): ?> target="_blank" rel="noopener" <?php endif; ?> class="relative block aspect-square overflow-hidden rounded-md bg-white border border-line" aria-label="Social post <?= $i + 1 ?>">
                            <?= Img::picture((string) $path, 'Social post ' . ($i + 1), '(min-width:1024px) 16vw, 33vw', false, false, ['class' => 'size-full object-contain']) ?>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <a href="<?= e($url ?: '#') ?>" <?php if ($url): ?> target="_blank" rel="noopener" <?php endif; ?> class="relative block aspect-square overflow-hidden rounded-md bg-white border border-line" aria-label="Instagram post <?= $i + 1 ?>"><?= View::partial('components/placeholder', ['label' => false]) ?></a>
                    <?php endfor; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
