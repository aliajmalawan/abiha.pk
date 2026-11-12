<?php
/** Track your order (port of storefront/track.blade.php). Vars: $order (OrderService::load row) or null. */
use App\Core\View;

$request = View::shared('request');
$num = $request instanceof App\Core\Request ? ($request->post['number'] ?? $request->query['number'] ?? null) : null;
?>
<div class="container py-12 lg:py-16 max-w-2xl">
    <h1 class="!text-3xl lg:!text-4xl">Track your order</h1>
    <p class="lead">Enter your order number and the email you used at checkout.</p>

    <form method="POST" action="<?= e(route('track.lookup')) ?>" class="card mt-6 space-y-4" novalidate data-validate>
        <?= csrf_field() ?>
        <?= View::partial('store/cart/_form-errors') ?>
        <?= View::partial('store/cart/_field', ['name' => 'number', 'label' => 'Order number', 'required' => true, 'value' => is_string($num) ? $num : null, 'extra' => ['placeholder' => 'ABH-000000-XXXXX']]) ?>
        <?= View::partial('store/cart/_field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'autocomplete' => 'email']) ?>
        <button class="btn">Track order</button>
    </form>

    <?php if ($order): ?>
        <?= View::partial('store/partials/order-tracking', ['order' => $order]) ?>
    <?php endif; ?>
</div>
