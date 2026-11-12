<?php use App\Core\View; ?>
<div class="container py-10 lg:py-14">
    <h1 class="!text-3xl lg:!text-4xl">Wishlist</h1>
    <?php if (!App\Auth\Auth::check()): ?><p class="muted">Sign in to keep your wishlist across devices. <a class="link" href="<?= e(route('login')) ?>">Sign in</a></p><?php endif; ?>

    <?php if (!$products): ?>
        <?= View::partial('components/empty-state', ['icon' => 'heart', 'title' => 'Nothing saved yet', 'text' => 'Tap the heart on any product to keep it here for later.', 'action' => 'Explore the shop', 'href' => route('shop')]) ?>
    <?php else: ?>
        <div class="grid grid--4 grid--products mt-8">
            <?php foreach ($products as $product): ?><?= View::partial('store/partials/product-card', ['product' => $product, 'wishlistRemove' => true]) ?><?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
