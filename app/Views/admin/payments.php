<?php
/**
 * Payment methods (port of admin/payments.blade.php).
 * @var array<string,App\Services\Payments\PaymentGateway> $gateways @var list<string> $enabled @var ?string $codNote @var ?string $bankDetails
 */
$checked = old('enabled', $enabled);
$checked = is_array($checked) ? array_map('strval', $checked) : [];
$enabledErr = null;
foreach (errors() as $k => $msg) {
    if (str_starts_with((string) $k, 'enabled.')) {
        $enabledErr = is_array($msg) ? (string) reset($msg) : (string) $msg;
        break;
    }
}
$card = $gateways['card'] ?? null;
?>
<h1 class="!text-2xl">Payment methods</h1>
<form method="POST" action="<?= e(route('admin.payments.update')) ?>" class="max-w-3xl space-y-6 mt-4" novalidate>
    <?= csrf_field() ?><?= method_field('PUT') ?>
    <section class="card space-y-4">
        <h2 class="!text-lg !mb-0">Available at checkout</h2>
        <?php foreach ($gateways as $code => $g): ?>
            <?php $off = $code === 'card' && !$g->isAvailable(); ?>
            <label class="check"><input type="checkbox" name="enabled[]" value="<?= e($code) ?>"<?= in_array($code, $checked, true) && !$off ? ' checked' : '' ?><?= $off ? ' disabled' : '' ?>> <span><strong><?= e($g->label()) ?></strong><?php if ($off): ?> <span class="badge badge--ivory">Not set up</span><?php endif; ?><br><span class="text-grey-text text-sm"><?= e($g->description()) ?></span></span></label>
        <?php endforeach; ?>
        <?php if ($enabledErr): ?><span class="error-text"><?= e($enabledErr) ?></span><?php endif; ?>
        <?php if ($m = error('enabled')): ?><span class="error-text" role="alert"><?= e($m) ?></span><?php endif; ?>
        <p class="help">Customers only see ticked methods. If none are ticked, nobody can check out.</p>
    </section>

    <section class="card space-y-3"><h2 class="!text-lg !mb-0">Cash on Delivery</h2>
        <div class="field"><label class="label" for="cod_note">Message shown at checkout</label>
            <textarea class="textarea !min-h-[80px]" id="cod_note" name="cod_note" maxlength="300"><?= e(old('cod_note', $codNote)) ?></textarea>
            <span class="help">e.g. "Pay the rider in cash. Please keep the exact amount ready."</span></div>
        <p class="help">COD orders are marked paid by staff after the cash is collected (Orders → Mark as paid).</p>
    </section>

    <section class="card space-y-3"><h2 class="!text-lg !mb-0">Bank transfer</h2>
        <div class="field"><label class="label" for="bank_details">Account details shown to customers after ordering</label>
            <textarea class="textarea" id="bank_details" name="bank_details" rows="5" maxlength="1000"><?= e(old('bank_details', $bankDetails)) ?></textarea>
            <?php if ($m = error('bank_details')): ?><span class="error-text"><?= e($m) ?></span><?php else: ?><span class="help">Bank, account title, account number / IBAN. Required to enable Bank Transfer.</span><?php endif; ?></div>
        <p class="help">Customers send a transfer reference and/or a photo or PDF of the receipt (kept privately, only staff can open it); staff verify it against the bank statement before marking the order paid.</p>
    </section>

    <section class="card space-y-3"><h2 class="!text-lg !mb-0">Card payments</h2>
        <?php if ($card && $card->isAvailable()): ?>
            <p class="text-sm"><span class="badge badge--success">Ready</span> Provider driver and credentials are configured. Tick "Debit / Credit Card" above to offer it at checkout. Customers pay on the provider's own page; this site never sees or stores card numbers or CVV.</p>
        <?php else: ?>
            <p class="text-sm"><span class="badge badge--ivory">Disabled</span> Card payments are switched off because the following is not set up yet:</p>
            <ul class="text-sm list-disc pl-5"><?php foreach (($card && method_exists($card, 'missing') ? $card->missing() : []) as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul>
            <p class="help">Provider keys and secrets are <strong>not stored in the admin</strong>: they go in the server's <code>.env</code> file. Setup steps are in <code>docs/PAYMENTS.md</code>. Until then nothing can be paid by card and no payment is ever simulated.</p>
        <?php endif; ?>
        <p class="help">Webhook address for your provider's dashboard: <code><?= e(url('/webhooks/payments/card')) ?></code></p>
    </section>
    <button class="btn">Save payment settings</button>
</form>
