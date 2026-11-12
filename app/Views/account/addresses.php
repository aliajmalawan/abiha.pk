<?php
/**
 * Address book (port of resources/views/account/addresses.blade.php).
 *
 * @var list<array> $addresses
 */
use App\Core\View;
?>
<div class="grid gap-4 md:grid-cols-2">
    <?php foreach ($addresses as $a): ?>
        <div class="card space-y-3" x-data="{ edit: <?= errors() && (string) old('_edit') === (string) $a['id'] ? 'true' : 'false' ?> }">
            <div class="flex items-start justify-between gap-2">
                <p class="font-display font-semibold !mb-0"><?= e($a['label'] ?: 'Address') ?> <?php if ((int) $a['is_default'] === 1): ?><span class="badge badge--gold ml-1">Default</span><?php endif; ?></p>
            </div>
            <address class="not-italic text-sm leading-relaxed" x-show="!edit"><?= e($a['name']) ?><br><?= e($a['address']) ?><br><?= e($a['city']) ?>, <?= e($a['province']) ?> <?= e($a['postal_code']) ?><br><?= e($a['phone']) ?></address>
            <div class="flex gap-4 text-sm" x-show="!edit">
                <button type="button" class="link" @click="edit = true">Edit</button>
                <form method="POST" action="<?= e(route('account.addresses.destroy', ['address' => $a['id']])) ?>" x-data @submit="$store.confirm.ask($event, { title: 'Remove this address?', message: 'It will be deleted from your address book. Past orders are not affected.', label: 'Remove address' })"><?= csrf_field() ?> <?= method_field('DELETE') ?><button class="underline text-grey-text">Remove</button></form>
            </div>
            <form x-show="edit" x-cloak method="POST" action="<?= e(route('account.addresses.update', ['address' => $a['id']])) ?>" class="space-y-3" novalidate data-validate>
                <?= csrf_field() ?> <?= method_field('PUT') ?><input type="hidden" name="_edit" value="<?= (int) $a['id'] ?>">
                <?= View::partial('account/partials/address-fields', ['a' => $a, 'prefix' => 'e' . $a['id']]) ?>
                <div class="flex gap-3"><button class="btn btn--sm">Save</button><button type="button" class="btn btn--sm btn--outline" @click="edit = false">Cancel</button></div>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<form method="POST" action="<?= e(route('account.addresses.store')) ?>" class="card mt-8 space-y-4 max-w-2xl" novalidate data-validate>
    <?= csrf_field() ?>
    <h2 class="!text-xl">Add a new address</h2>
    <?= View::partial('account/partials/address-fields', ['a' => null, 'prefix' => 'new']) ?>
    <button class="btn">Save address</button>
</form>
