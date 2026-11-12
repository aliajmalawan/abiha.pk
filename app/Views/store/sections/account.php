<?php
/* Account sign-up banner. Only shown to visitors who are not signed in. The heading and text come from Admin -> Homepage; the four tiles
   describe what an account really does on this site (wishlist, order tracking, saved addresses, reviews). Nothing here promises a discount. */
if (App\Auth\Auth::check()) {
    return;
}
?>
<section class="section <?= e($toneClass ?? '') ?>">
    <div class="container">
        <div class="acct">
            <div class="acct__copy">
                <p class="acct__eyebrow">Free to join</p>
                <h2 class="acct__title"><?= e($section['title'] ?: 'Create your ABIHA account') ?></h2>
                <p class="acct__text"><?= e($section['subtitle'] ?: 'Save your favourites, track your orders and check out faster with your delivery details saved.') ?></p>
                <div class="acct__actions">
                    <a href="<?= e(route('register')) ?>" class="acct__btn acct__btn--gold">Sign up</a>
                    <a href="<?= e(route('login')) ?>" class="acct__btn acct__btn--line">Sign in</a>
                </div>
            </div>
            <ul class="acct__tiles">
                <li class="acct__tile"><span class="acct__icon"><?= icon('heart', 22) ?></span><div><h3>Save favourites</h3><p>Keep a wishlist of the pieces you love.</p></div></li>
                <li class="acct__tile"><span class="acct__icon"><?= icon('package', 22) ?></span><div><h3>Track every order</h3><p>Live status and tracking details.</p></div></li>
                <li class="acct__tile"><span class="acct__icon"><?= icon('map-pin', 22) ?></span><div><h3>Faster checkout</h3><p>Save your delivery addresses.</p></div></li>
                <li class="acct__tile"><span class="acct__icon"><?= icon('star', 22) ?></span><div><h3>Share your review</h3><p>Rate the pieces you have bought.</p></div></li>
            </ul>
        </div>
    </div>
</section>
