<?php
/** Product form: Photos card (ordering, alt text, removal, dropzone upload) and the optional product video. @var bool $exists @var array $imgData @var callable $firstMatch @var ?array $product */
use App\Services\Admin\Form;
use App\Support\Video;

$curVideo = isset($product['video']) && $product['video'] !== null ? Video::parse((string) $product['video']) : null;
?>
<section class="card pf-card space-y-4">
    <div class="pf-card__head"><span class="pf-card__icon"><?= icon('eye', 18) ?></span><div><h2 class="!text-lg !mb-0">Photos</h2><p class="help">The first photo is the main one shown in the shop.</p></div></div>
    <?php if ($exists && $imgData): ?>
        <div x-data="imageOrder(<?= Form::js($imgData) ?>)">
            <p class="help mb-2">The <strong>first</strong> photo is the main one shown in the shop; the second shows on hover. Use the arrows to reorder, then save.</p>
            <ol class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3" x-ref="list">
                <template x-for="(img, idx) in items" :key="img.id">
                    <li class="text-center text-xs rounded border border-line p-2 bg-white">
                        <input type="hidden" name="image_order[]" :value="img.id">
                        <div class="relative">
                            <img :src="img.url" :alt="img.alt" class="aspect-square object-cover rounded border border-line w-full" :class="img.remove ? 'opacity-30' : ''">
                            <span class="badge badge--gold absolute left-1 top-1" x-show="idx === 0 && !img.remove">Main</span>
                            <span class="badge badge--ivory absolute left-1 top-1" x-show="idx !== 0" x-text="idx + 1"></span>
                        </div>
                        <input class="input !min-h-9 !text-xs mt-2" type="text" :name="`image_alts[${img.id}]`" x-model="img.alt" maxlength="160" :aria-label="`Alt text for photo ${idx + 1}`" placeholder="Describe the image">
                        <div class="flex flex-wrap items-center justify-center gap-1 mt-2">
                            <button type="button" class="btn btn--sm btn--outline !px-2" data-focus-move @click="move(idx, -1)" :disabled="idx === 0" :aria-label="`Move photo ${idx + 1} earlier`">&larr;</button>
                            <button type="button" class="btn btn--sm btn--outline !px-2" data-focus-move @click="move(idx, 1)" :disabled="idx === items.length - 1" :aria-label="`Move photo ${idx + 1} later`">&rarr;</button>
                            <button type="button" class="btn btn--sm btn--ghost !px-2 underline" @click="makeMain(idx)" x-show="idx !== 0" :aria-label="`Make photo ${idx + 1} the main photo`">Make main</button>
                        </div>
                        <label class="check justify-center mt-2"><input type="checkbox" name="remove_images[]" :value="img.id" x-model="img.remove"> Remove</label>
                    </li>
                </template>
            </ol>
        </div>
    <?php endif; ?>
    <div class="field" x-data="uploadCheck(4, 12)">
        <label class="pf-drop" for="images" :class="over && 'pf-drop--over'" @dragover.prevent="over = true" @dragleave.prevent="over = false" @drop.prevent="over = false; $refs.file.files = $event.dataTransfer.files; check({ target: $refs.file })">
            <span class="pf-drop__icon"><?= icon('plus', 22) ?></span>
            <span class="pf-drop__title">Click to choose photos or drag them here</span>
            <span class="pf-drop__hint">JPG, PNG or WebP · up to 4 MB each · at most 12 photos</span>
            <input class="sr-only" type="file" id="images" name="images[]" multiple accept="image/jpeg,image/png,image/webp" x-ref="file" @change="check($event)">
        </label>
        <div class="pf-size" role="note">
            <?= icon('info', 18) ?>
            <div>
                <p><strong>Recommended size: 1200 × 1500 px</strong> (portrait, ratio 4:5, the same shape as the shop cards and product page).</p>
                <p>Smallest that still looks sharp: 800 × 1000 px. Very large photos are reduced automatically. Keep the whole item inside the frame and use a plain background; the full photo is always shown, nothing is cropped. Use the same size for every photo of a product.</p>
            </div>
        </div>
        <ul class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3 mt-3" x-show="files.length" x-cloak>
            <template x-for="f in files" :key="f.name + f.mb">
                <li class="text-xs rounded border p-2" :class="f.problem ? 'border-danger' : 'border-line'">
                    <img x-show="f.url" :src="f.url" alt="" class="aspect-square object-cover rounded w-full">
                    <span class="block truncate mt-1" x-text="f.name"></span><span class="text-grey-text" x-text="f.mb + ' MB'"></span>
                    <span class="block text-danger" x-show="f.problem" x-text="f.problem" role="alert"></span>
                </li>
            </template>
        </ul>
        <p class="error-text" x-show="tooMany" x-cloak role="alert">Choose at most 12 photos.</p>
        <?php if ($m = error('images')): ?><span class="error-text"><?= e($m) ?></span><?php endif; ?> <?php if ($m = $firstMatch('images.')): ?><span class="error-text"><?= e($m) ?></span><?php endif; ?></div>
    <div class="field border-t border-line pt-4" x-data="{ pick: '' }">
        <h3 class="!text-base !mb-1">Product video <span class="text-grey-text text-sm font-normal">(optional)</span></h3>
        <p class="help">A short clip (a model wearing it, fabric close-up). It appears in the product gallery under the photos. Paste a <strong>YouTube or Vimeo link</strong>, or upload an <strong>MP4 or WebM file up to 25 MB</strong>.</p>
        <?php if ($curVideo): ?>
            <p class="text-sm mt-2">Current video: <?php if ($curVideo['type'] === 'file' && Video::isStored((string) $product['video'])): ?><a class="link" href="<?= e($curVideo['src']) ?>" target="_blank" rel="noopener">uploaded file</a><?php else: ?><a class="link" href="<?= e($curVideo['src']) ?>" target="_blank" rel="noopener"><?= e($curVideo['type'] === 'file' ? 'video link' : ucfirst($curVideo['type'])) ?> link</a><?php endif; ?>
                <label class="check mt-2"><input type="checkbox" name="remove_video" value="1"> Remove this video</label></p>
        <?php endif; ?>
        <label class="label mt-3" for="video_url">Video link</label>
        <input class="input" type="url" id="video_url" name="video_url" maxlength="255" placeholder="https://www.youtube.com/watch?v=..."
               value="<?= e((string) old('video_url', ($curVideo && !Video::isStored((string) ($product['video'] ?? ''))) ? (string) $product['video'] : '')) ?>">
        <?php if ($m = error('video_url')): ?><span class="error-text"><?= e($m) ?></span><?php endif; ?>
        <label class="label mt-3" for="video_file">Or upload a video file</label>
        <input class="input !pt-2.5" type="file" id="video_file" name="video_file" accept="video/mp4,video/webm">
        <span class="help">MP4 (H.264) or WebM, up to 25 MB. Uploading a file replaces the current video.</span>
        <?php if ($m = error('video_file')): ?><span class="error-text"><?= e($m) ?></span><?php endif; ?>
    </div>
</section>
