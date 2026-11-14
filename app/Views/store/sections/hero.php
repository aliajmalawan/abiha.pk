<?php
/* Hero: one big banner (several cross-fade). With a picture the picture is always shown whole; the banner's words are drawn in a
   white card over it (below it on smaller screens), or left out when the picture has its own design ("show text" unticked), in which
   case only the button sits over it. Without a picture the banner is a solid black block with the words. */
use App\Support\Img;

$n = count($banners);
$sample = cfg('abiha.show_sample_labels');
?>
<section class="relative" data-hero x-data="{ i: 0, n: <?= $n ?>, t: null,
        go(k) { this.i = (k + this.n) % this.n },
        play() { if (this.n > 1) this.t = setInterval(() => this.go(this.i + 1), 8000) },
        init() { this.play() } }"
         @mouseenter="clearInterval(t)" @mouseleave="play()" @focusin="clearInterval(t)" @keydown.left="go(i - 1)" @keydown.right="go(i + 1)"
         aria-roledescription="carousel" aria-label="Featured">
    <div class="grid">
        <?php foreach ($banners as $k => $b): $tag = $k === 0 ? 'h1' : 'p'; $link = $b['cta_url'] ?: ($b['cta_label'] ? route('shop') : null); ?>
            <div class="hero-slide <?= $k === 0 ? 'is-active' : '' ?> col-start-1 row-start-1 transition-opacity duration-700"
                 :class="{ 'is-active': i === <?= $k ?>, 'opacity-100': i === <?= $k ?>, 'opacity-0 pointer-events-none': i !== <?= $k ?> }" :aria-hidden="i !== <?= $k ?>"
                 role="group" aria-roledescription="slide" aria-label="<?= $k + 1 ?> of <?= $n ?>">
                <?php if ($b['image']): ?>
                    <div class="relative bg-ivory [&_picture]:block">
                        <?php if (!$b['show_text']): ?><<?= $tag ?> class="sr-only"><?= e($b['title']) ?></<?= $tag ?>><?php endif; ?>
                        <div class="hero-media">
                        <?php if (!$b['show_text'] && $link): ?><a href="<?= e(url($link)) ?>" class="block" aria-label="<?= e($b['cta_label'] ?: $b['title']) ?>"><?php endif; ?>
                            <?= Img::picture($b['image'], $b['show_text'] ? '' : (string) $b['title'], '100vw', false, $k === 0, ['class' => 'w-full h-auto max-sm:aspect-[4/3] max-sm:object-cover max-sm:object-[72%_50%]']) ?>
                        <?php if (!$b['show_text'] && $link): ?></a><?php endif; ?>
                        </div>

                        <?php if ($b['show_text']): ?>
                            <div class="xl:absolute xl:inset-y-0 xl:left-0 xl:flex xl:items-center xl:pl-8 2xl:pl-[4%] xl:pointer-events-none">
                                <div class="hero-depth xl:w-[clamp(19.5rem,22vw,34rem)] max-xl:relative max-xl:z-10 max-xl:-mt-10 max-xl:mx-4 sm:max-xl:mx-8 max-xl:mb-6 xl:pointer-events-auto">
                                <div class="hero-card">
                                    <?php if ($b['eyebrow']): ?><p class="hero-eyebrow hx" style="--d:180ms"><?= e($b['eyebrow']) ?></p><?php endif; ?>
                                    <<?= $tag ?> class="hero-title hx" style="--d:320ms"><?= e($b['title']) ?></<?= $tag ?>>
                                    <hr class="hero-rule hx-rule" style="--d:520ms">
                                    <?php if ($b['subtitle']): ?><p class="hero-sub hx" style="--d:640ms"><?= e($b['subtitle']) ?></p><?php endif; ?>
                                    <?php if ($link && $b['cta_label']): ?><div class="hx" style="--d:800ms"><a href="<?= e(url($link)) ?>" class="home-btn"><?= e($b['cta_label']) ?> <?= icon('arrow-right', 18) ?></a></div><?php endif; ?>
                                    <?php if ($b['is_sample'] && $sample): ?><span class="sample-flag mt-4">SAMPLE BANNER</span><?php endif; ?>
                                </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php if ($link && $b['cta_label']): ?><span class="home-btn home-btn--light hx pointer-events-none absolute left-3 bottom-3 sm:left-10 sm:bottom-10 max-sm:!min-h-[40px] max-sm:!px-5 max-sm:!text-xs" style="--d:500ms"><?= e($b['cta_label']) ?></span><?php endif; ?>
                            <?php if ($b['is_sample'] && $sample): ?><span class="sample-flag absolute right-3 top-3">SAMPLE BANNER</span><?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="hero-plain relative isolate flex items-end sm:items-center bg-ink text-white min-h-[440px] sm:min-h-[520px] lg:min-h-[640px]">
                        <div class="container py-14 sm:py-20">
                            <div class="max-w-xl">
                                <?php if ($b['eyebrow']): ?><p class="hero-eyebrow hx" style="--d:120ms"><?= e($b['eyebrow']) ?></p><?php endif; ?>
                                <<?= $tag ?> class="hero-title hx" style="--d:260ms"><?= e($b['title']) ?></<?= $tag ?>>
                                <hr class="hero-rule hx-rule" style="--d:460ms">
                                <?php if ($b['subtitle']): ?><p class="hero-sub hx" style="--d:580ms"><?= e($b['subtitle']) ?></p><?php endif; ?>
                                <?php if ($b['cta_label']): ?><div class="hx" style="--d:740ms"><a href="<?= e($b['cta_url'] ?: route('shop')) ?>" class="home-btn home-btn--light"><?= e($b['cta_label']) ?> <?= icon('arrow-right', 18) ?></a></div><?php endif; ?>
                                <?php if ($b['is_sample'] && $sample): ?><span class="sample-flag mt-4">SAMPLE BANNER</span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($n > 1): ?>
        <button type="button" class="home-arrow absolute left-3 top-1/2 -translate-y-1/2 hidden sm:grid" @click="go(i - 1)" aria-label="Previous slide"><?= icon('chevron-left', 22) ?></button>
        <button type="button" class="home-arrow absolute right-3 top-1/2 -translate-y-1/2 hidden sm:grid" @click="go(i + 1)" aria-label="Next slide"><?= icon('chevron-right', 22) ?></button>
        <div class="absolute inset-x-0 bottom-4 flex justify-center gap-2" role="tablist" aria-label="Choose slide">
            <?php foreach ($banners as $k => $b): ?>
                <button type="button" role="tab" class="size-2.5 rounded-full border border-white ring-1 ring-black/20" :class="i === <?= $k ?> ? 'bg-white' : 'bg-transparent'" :aria-selected="i === <?= $k ?>" @click="go(<?= $k ?>)" aria-label="Slide <?= $k + 1 ?>"></button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
