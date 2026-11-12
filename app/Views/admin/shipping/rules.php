<?php
/** Included under the shipping-method edit form (existing methods only). Port of admin/shipping/rules.blade.php. @var array $model */
use App\Controllers\Admin\ShippingRuleController;
use App\Core\Database;

$cur = (string) cfg('abiha.currency.symbol');
$rules = Database::get()->all('SELECT * FROM shipping_rules WHERE shipping_method_id = ? ORDER BY city IS NULL, province, city', [(int) $model['id']]);
?>
<section class="card mt-6 max-w-3xl space-y-4" aria-labelledby="rules-h">
    <h2 class="!text-lg !mb-0" id="rules-h">City &amp; province rules</h2>
    <p class="help">Charge a different rate, or turn this method off, for specific destinations. The most specific rule wins: <strong>city</strong>, then <strong>province</strong>, then the default rate above. Matching ignores upper/lower case.</p>

    <?php if ($rules): ?>
        <div class="overflow-x-auto"><table class="table"><thead><tr><th>Destination</th><th class="num">Cost</th><th class="num">Free over</th><th>Delivery time</th><th>Available</th><th class="num"></th></tr></thead><tbody>
            <?php foreach ($rules as $r): ?>
                <tr><td><?= e(ShippingRuleController::destination($r)) ?></td><td class="num"><?= $r['is_available'] ? e($cur . ' ' . number_format((int) $r['cost'])) : '-' ?></td>
                    <td class="num"><?= $r['free_over'] !== null ? e(number_format((int) $r['free_over'])) : 'default' ?></td>
                    <td><?= e($r['eta'] ?: 'default') ?></td>
                    <td><span class="badge <?= $r['is_available'] ? 'badge--success' : 'badge--danger' ?>"><?= $r['is_available'] ? 'Yes' : 'Not offered' ?></span></td>
                    <td class="num"><form method="POST" action="<?= e(route('admin.shipping.rules.destroy', ['id' => $r['id']])) ?>" data-confirm="Remove this rule?"><?= csrf_field() ?><?= method_field('DELETE') ?><button class="underline text-danger">Remove</button></form></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
    <?php else: ?>
        <p class="muted text-sm">No rules yet: every destination uses the default rate.</p>
    <?php endif; ?>

    <form method="POST" action="<?= e(route('admin.shipping.rules.store', ['id' => $model['id']])) ?>" class="grid gap-3 sm:grid-cols-2 border-t border-line pt-4" novalidate>
        <?= csrf_field() ?>
        <div class="field"><label class="label text-xs" for="r-province">Province</label>
            <select class="select" id="r-province" name="province"><option value="">Any</option><?php foreach ((array) cfg('abiha.provinces', []) as $pv): ?><option value="<?= e($pv) ?>"<?= old('province') === $pv ? ' selected' : '' ?>><?= e($pv) ?></option><?php endforeach; ?></select><?php if ($m = error('province')): ?><span class="error-text"><?= e($m) ?></span><?php endif; ?></div>
        <div class="field"><label class="label text-xs" for="r-city">City</label><input class="input" id="r-city" name="city" value="<?= e(old('city')) ?>" placeholder="e.g. Lahore"><?php if ($m = error('city')): ?><span class="error-text"><?= e($m) ?></span><?php endif; ?></div>
        <div class="field"><label class="label text-xs" for="r-cost">Cost for this destination (PKR)</label><input class="input" type="number" min="0" id="r-cost" name="cost" value="<?= e(old('cost')) ?>" required><?php if ($m = error('cost')): ?><span class="error-text"><?= e($m) ?></span><?php endif; ?></div>
        <div class="field"><label class="label text-xs" for="r-free">Free over (PKR, optional)</label><input class="input" type="number" min="0" id="r-free" name="free_over" value="<?= e(old('free_over')) ?>"><span class="help">Blank = use the method's threshold.</span></div>
        <div class="field sm:col-span-2"><label class="label text-xs" for="r-eta">Delivery time for this destination (optional)</label><input class="input" id="r-eta" name="eta" maxlength="80" value="<?= e(old('eta')) ?>" placeholder="e.g. 2-3 working days"><?php if ($m = error('eta')): ?><span class="error-text"><?= e($m) ?></span><?php endif; ?><span class="help">Blank = use the method's estimate.</span></div>
        <label class="check sm:col-span-2"><input type="checkbox" name="is_available" value="1" checked> This method is offered to this destination (untick to block it)</label>
        <div class="sm:col-span-2"><button class="btn btn--sm">Add rule</button></div>
    </form>
</section>
