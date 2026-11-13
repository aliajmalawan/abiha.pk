<?php
/**
 * The product's video under the photo gallery. An uploaded file (or a direct .mp4/.webm link) uses the browser's own player
 * (nothing loads until the visitor presses play). A YouTube / Vimeo link shows the poster with a play button and only loads
 * the player (youtube-nocookie.com / player.vimeo.com) after a click, so the page stays fast and no tracking starts early.
 *
 * @var array $p the product  @var ?array $video App\Support\Video::parse() result  @var ?string $poster poster image path on the public disk
 */
use App\Support\Img;

$video = $video ?? null;
if (!$video) {
    return;
}
$poster = $poster ?? null;
?>
<section class="pdp-video" aria-label="Video of <?= e($p['name']) ?>">
    <?php if ($video['type'] === 'file'): ?>
        <video class="pdp-video__el" controls playsinline preload="none" <?= $poster ? 'poster="' . e(Img::src($poster, 960)) . '"' : '' ?>>
            <source src="<?= e($video['src']) ?>" type="video/<?= str_ends_with(strtolower((string) parse_url($video['src'], PHP_URL_PATH)), '.webm') ? 'webm' : 'mp4' ?>">
            Your browser cannot play this video. <a href="<?= e($video['src']) ?>">Open it</a>.
        </video>
    <?php else: ?>
        <div class="pdp-video__frame" data-video-embed="<?= e($video['embed']) ?>" data-video-title="Video of <?= e($p['name']) ?>">
            <?php if ($poster): ?><?= Img::picture($poster, '', '(min-width:1024px) 50vw, 100vw', false, false, ['class' => 'pdp-video__poster']) ?>
            <?php elseif (!empty($video['poster'])): ?><img class="pdp-video__poster" src="<?= e($video['poster']) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
            <button type="button" class="pdp-video__play" data-video-play aria-label="Play video of <?= e($p['name']) ?>"><span><?= icon('play', 28) ?></span></button>
        </div>
    <?php endif; ?>
</section>
