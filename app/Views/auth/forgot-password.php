<?php
/** Forgot password (port of resources/views/auth/forgot-password.blade.php). */
use App\Core\View;
?>
<div class="container py-12 lg:py-20 max-w-md">
    <h1 class="!text-3xl text-center">Forgot your password?</h1>
    <p class="lead mx-auto text-center">Enter your email and we'll send you a link to reset it.</p>
    <?php if (flash('status')): ?><div class="alert alert--success mt-4" role="status"><?= e(flash('status')) ?></div><?php endif; ?>
    <form method="POST" action="<?= e(route('password.email')) ?>" class="card mt-6 space-y-4" novalidate data-validate>
        <?= csrf_field() ?>
        <?= View::partial('auth/partials/form-errors') ?>
        <?= View::partial('auth/partials/field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'autocomplete' => 'email', 'attrs' => ['autofocus' => 'autofocus']]) ?>
        <button class="btn btn--block">Send reset link</button>
    </form>
    <p class="text-center text-sm mt-6"><a class="link" href="<?= e(route('login')) ?>">Back to sign in</a></p>
</div>
