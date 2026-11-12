<?php
/**
 * Product photo for cart lines (<x-picture> with the first product image) or the neutral <x-placeholder :label="false">.
 * Vars: $product (row with 'images'), $sizes ("120px").
 */
$path = $product['images'][0]['path'] ?? null;
if ($path): ?><?= App\Support\Img::picture($path, '', $sizes, false, false, ['class' => 'size-full object-contain']) ?><?php else: ?><div class="absolute inset-0 grid place-content-center text-grey-text" aria-hidden="true">
    <span class="grid place-items-center gap-2 opacity-60">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/></svg>
    </span>
</div><?php endif;
