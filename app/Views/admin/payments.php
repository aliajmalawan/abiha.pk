<?php
/**
 * Payment methods (port of admin/payments.blade.php).
 * @var array<string,App\Services\Payments\PaymentGateway> $gateways @var list<string> $enabled @var ?string $codNote @var ?string $bankDetails @var string $cardKey @var bool $cardHasSecret @var bool $cardHasWebhook @var string $cardEnvironment @var array $cardSaved
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
            <?php if ($code === 'bank_transfer') { continue; } ?>
            <?php $off = $code === 'card' && !$g->isAvailable(); ?>
            <label class="check"><input type="checkbox" name="enabled[]" value="<?= e($code) ?>"<?= in_array($code, $checked, true) && !$off ? ' checked' : '' ?><?= $off ? ' disabled' : '' ?>> <span><strong><?= e($g->label()) ?></strong><?php if ($off): ?> <span class="badge badge--ivory">Not set up</span><?php endif; ?><br><span class="text-grey-text text-sm"><?= e($g->description()) ?></span></span></label>
        <?php endforeach; ?>
        <?php if ($enabledErr): ?><span class="error-text"><?= e($enabledErr) ?></span><?php endif; ?>
        <?php if ($m = error('enabled')): ?><span class="error-text" role="alert"><?= e($m) ?></span><?php endif; ?>
        <p class="help">Bank transfer has its own switch in its section below. Customers only see ticked methods. If none are ticked, nobody can check out.</p>
    </section>

    <section class="card space-y-3"><h2 class="!text-lg !mb-0">Cash on Delivery</h2>
        <div class="field"><label class="label" for="cod_note">Message shown at checkout</label>
            <textarea class="textarea !min-h-[80px]" id="cod_note" name="cod_note" maxlength="300"><?= e(old('cod_note', $codNote)) ?></textarea>
            <span class="help">e.g. "Pay the rider in cash. Please keep the exact amount ready."</span></div>
        <p class="help">COD orders are marked paid by staff after the cash is collected (Orders → Mark as paid).</p>
    </section>

    <section class="card space-y-3"><h2 class="!text-lg !mb-0">Bank transfer</h2>
        <?php $bankOn = in_array('bank_transfer', $checked, true); $bankHas = trim((string) old('bank_details', $bankDetails)) !== ''; ?>
        <input type="hidden" name="bank_visible" value="0">
        <label class="check"><input type="checkbox" name="bank_visible" value="1"<?= $bankOn ? ' checked' : '' ?>> <span><strong>Show Bank Transfer at checkout</strong> <?php if ($bankOn && $bankHas): ?><span class="badge badge--success">Shown</span><?php elseif ($bankOn): ?><span class="badge badge--ivory">On, but no account details yet</span><?php else: ?><span class="badge badge--ivory">Hidden</span><?php endif; ?><br><span class="text-grey-text text-sm">Untick and press Save to hide it from customers. Your account details below are kept, so you can show it again any time.</span></span></label>
        <div class="field"><label class="label" for="bank_details">Account details shown to customers after ordering</label>
            <textarea class="textarea" id="bank_details" name="bank_details" rows="5" maxlength="1000"><?= e(old('bank_details', $bankDetails)) ?></textarea>
            <?php if ($m = error('bank_details')): ?><span class="error-text"><?= e($m) ?></span><?php else: ?><span class="help">Bank, account title, account number / IBAN. Required to enable Bank Transfer.</span><?php endif; ?></div>
        <p class="help">Customers send a transfer reference and/or a photo or PDF of the receipt (kept privately, only staff can open it); staff verify it against the bank statement before marking the order paid.</p>
    </section>

    <section class="card space-y-4" id="card-payments"><h2 class="!text-lg !mb-0">Debit / Credit Card (Safepay)</h2>
        <?php if ($card && $card->isAvailable()): ?>
            <p class="text-sm"><span class="badge badge--success">Connected</span> The keys are saved<?= $cardEnvironment === 'production' ? ' (live mode: real payments)' : ' (sandbox mode: test payments only)' ?>. Tick "Debit / Credit Card" above to offer it at checkout. Customers pay on Safepay's own page; this site never sees or stores card numbers or CVV.</p>
        <?php else: ?>
            <p class="text-sm"><span class="badge badge--ivory">Not connected</span> Enter the three keys from your Safepay dashboard below and press Save. Then tick "Debit / Credit Card" above and press Save again.</p>
        <?php endif; ?>
        <div class="grid gap-4 md:grid-cols-2">
            <div class="field"><label class="label" for="card_environment">Mode</label>
                <select class="select" id="card_environment" name="card_environment">
                    <option value="sandbox"<?= old('card_environment', $cardEnvironment) === 'sandbox' ? ' selected' : '' ?>>Sandbox (testing, no real money)</option>
                    <option value="production"<?= old('card_environment', $cardEnvironment) === 'production' ? ' selected' : '' ?>>Live (real payments)</option>
                </select>
                <span class="help">Start in Sandbox. Sandbox and Live have different keys in Safepay.</span></div>
            <div class="field"><label class="label" for="card_api_key">API key (public)</label>
                <input class="input" id="card_api_key" name="card_api_key" type="text" maxlength="200" autocomplete="off" spellcheck="false" value="<?= e(old('card_api_key', $cardKey)) ?>" placeholder="sec_...">
                <?php if ($m = error('card_api_key')): ?><span class="error-text"><?= e($m) ?></span><?php endif; ?></div>
            <div class="field"><label class="label" for="card_api_secret">Secret key <?php if ($cardHasSecret): ?><span class="badge badge--success ml-1">Saved</span><?php endif; ?></label>
                <input class="input" id="card_api_secret" name="card_api_secret" type="password" maxlength="300" autocomplete="new-password" spellcheck="false" placeholder="<?= $cardHasSecret ? 'Saved. Leave empty to keep it' : 'Paste the secret key' ?>">
                <?php if ($m = error('card_api_secret')): ?><span class="error-text"><?= e($m) ?></span><?php endif; ?></div>
            <div class="field"><label class="label" for="card_webhook_secret">Webhook secret <?php if ($cardHasWebhook): ?><span class="badge badge--success ml-1">Saved</span><?php endif; ?></label>
                <input class="input" id="card_webhook_secret" name="card_webhook_secret" type="password" maxlength="300" autocomplete="new-password" spellcheck="false" placeholder="<?= $cardHasWebhook ? 'Saved. Leave empty to keep it' : 'Paste the webhook shared secret' ?>">
                <?php if ($m = error('card_webhook_secret')): ?><span class="error-text"><?= e($m) ?></span><?php endif; ?></div>
        </div>
        <?php if (($card && method_exists($card, 'missing')) && !$card->isAvailable()): ?>
            <p class="help">Still missing: <?= e(implode(', ', array_map(static fn ($m) => preg_replace('/^A provider driver.*$/', 'payment provider', $m), $card->missing()))) ?>.</p>
        <?php endif; ?>
        <p class="help">The secret key and webhook secret are stored encrypted and are never shown again. In your Safepay dashboard add this webhook address: <code><?= e(url('/webhooks/payments/card')) ?></code></p>
        <?php if ($cardSaved['key'] !== '' || $cardSaved['secret'] !== '' || $cardSaved['webhook_secret'] !== ''): ?>
            <label class="check"><input type="checkbox" name="card_clear" value="1"> <span>Remove the saved card keys (card payments will stop working)</span></label>
        <?php endif; ?>
    </section>
    <button class="btn">Save payment settings</button>
</form>
