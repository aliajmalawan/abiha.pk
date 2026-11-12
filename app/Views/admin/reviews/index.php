<?php
/** Port of admin/reviews/index.blade.php. @var array $reviews (Pager) @var string $status */
use App\Core\View;
?>
<h1 class="!text-2xl">Reviews</h1>
<div class="flex gap-2 my-4 text-sm">
    <a href="<?= e(route('admin.reviews.index', ['status' => 'pending'])) ?>" class="badge <?= $status === 'pending' ? '' : 'badge--ivory' ?> no-underline">Awaiting approval</a>
    <a href="<?= e(route('admin.reviews.index', ['status' => 'approved'])) ?>" class="badge <?= $status === 'approved' ? '' : 'badge--ivory' ?> no-underline">Published</a>
</div>
<div class="space-y-3">
    <?php foreach ($reviews['items'] as $r): ?>
        <?php $rating = (int) $r['rating']; ?>
        <article class="card">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-3"><span class="inline-flex items-center gap-0.5 text-gold" role="img" aria-label="<?= $rating ?> out of 5 stars"><?php for ($i = 1; $i <= 5; $i++): ?><svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" fill="<?= $i <= $rating ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"><path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.9L12 17.8 5.8 21.100 7 14.200 2 9.300l6.900-1z"/></svg><?php endfor; ?></span> <strong><?= e($r['name']) ?></strong>
                    <?php if ($r['is_verified_purchase']): ?><span class="badge badge--success">Verified purchase</span><?php endif; ?> <?php if ($r['is_sample']): ?><span class="sample-flag">SAMPLE</span><?php endif; ?></div>
                <span class="text-xs text-grey-text"><?= e(dt((string) $r['created_at'], 'j M Y')) ?> · <?php if ($r['product_slug'] !== null): ?><a class="link" href="<?= e(route('product', ['slug' => $r['product_slug']])) ?>" target="_blank" rel="noopener"><?= e($r['product_name']) ?></a><?php endif; ?></span>
            </div>
            <?php if ($r['title']): ?><h2 class="!text-base !mt-2 !mb-1"><?= e($r['title']) ?></h2><?php endif; ?>
            <p class="text-sm mt-1"><?= e($r['body']) ?></p>
            <div class="flex gap-3 mt-3">
                <?php if (!$r['is_approved']): ?><form method="POST" action="<?= e(route('admin.reviews.approve', ['id' => $r['id']])) ?>"><?= csrf_field() ?><?= method_field('PATCH') ?><button class="btn btn--sm">Publish</button></form>
                <?php else: ?><form method="POST" action="<?= e(route('admin.reviews.unapprove', ['id' => $r['id']])) ?>"><?= csrf_field() ?><?= method_field('PATCH') ?><button class="btn btn--sm btn--outline">Hide</button></form><?php endif; ?>
                <form method="POST" action="<?= e(route('admin.reviews.destroy', ['id' => $r['id']])) ?>" data-confirm="Delete this review permanently?"><?= csrf_field() ?><?= method_field('DELETE') ?><button type="submit" class="act act--del"><?= icon('trash-2', 14) ?> Delete</button></form>
            </div>
        </article>
    <?php endforeach; ?>
    <?php if (!$reviews['items']): ?><p class="muted py-10 text-center">Nothing to show.</p><?php endif; ?>
</div>
<div class="mt-6"><?= View::partial('admin/partials/pagination', ['pager' => $reviews]) ?></div>
