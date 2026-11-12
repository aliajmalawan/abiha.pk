<?php $nlUrl = route('newsletter'); ?>
<section class="section <?= e($toneClass ?? '') ?>">
    <div class="container max-w-2xl text-center" x-data="newsletter('<?= e($nlUrl) ?>')">
        <p class="eyebrow">Stay in touch</p>
        <h2><?= e($section['title']) ?></h2>
        <?php if ($section['subtitle']): ?><p class="lead mx-auto"><?= e($section['subtitle']) ?></p><?php endif; ?>
        <form method="POST" action="<?= e($nlUrl) ?>" @submit.prevent="submit()" class="mt-6" novalidate>
            <?= csrf_field() ?>
            <div class="input-group">
                <label for="nl-email" class="sr-only">Email address</label>
                <input id="nl-email" type="email" name="email" x-model="email" class="input !bg-white" placeholder="Your email address" required autocomplete="email">
                <button class="btn" :class="state === 'busy' && 'is-loading'">Subscribe</button>
            </div>
            <p class="mt-3 text-sm font-semibold" x-show="message" :class="state === 'error' ? 'text-danger' : 'text-success'" x-text="message" role="status"></p>
            <?php if (flash('newsletter')): ?><p class="mt-3 text-sm text-success" role="status"><?= e(flash('newsletter')) ?></p><?php endif; ?>
            <?php if (error('email')): ?><p class="mt-3 text-sm text-danger" role="alert"><?= e(error('email')) ?></p><?php endif; ?>
        </form>
    </div>
</section>
