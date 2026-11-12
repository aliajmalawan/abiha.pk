<?php
/**
 * Address form fields (port of resources/views/account/partials/address-fields.blade.php).
 *
 * @var array|null $a       the address being edited, null for the "add" form
 * @var string     $prefix  id prefix ("new" or "e<id>")
 */
$a = $a ?? null;
$editing = $a && (string) old('_edit') === (string) $a['id'];
$showErr = $editing || !$a;
$v = static fn (string $k) => $editing ? old($k) : ($a !== null ? ($a[$k] ?? null) : old($k));
?>
<div class="grid gap-3 sm:grid-cols-2">
    <?php foreach ([['label', 'Label (e.g. Home)', 'text'], ['name', 'Full name', 'text'], ['phone', 'Phone', 'tel']] as [$k, $lbl, $type]): ?>
        <div class="field <?= $k === 'name' ? 'sm:col-span-1' : '' ?>">
            <label class="label" for="<?= e($prefix . $k) ?>"><?= e($lbl) ?></label>
            <input class="input" id="<?= e($prefix . $k) ?>" name="<?= e($k) ?>" type="<?= e($type) ?>" value="<?= e($v($k)) ?>"<?= $k !== 'label' ? ' required' : '' ?><?= has_error($k) && $showErr ? ' aria-invalid="true"' : '' ?>>
            <?php if ($showErr && has_error($k)): ?><span class="error-text"><?= e(error($k)) ?></span><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <div class="field">
        <label class="label" for="<?= e($prefix) ?>province">Province</label>
        <select class="select" id="<?= e($prefix) ?>province" name="province" required>
            <option value="">Select</option>
            <?php foreach ((array) cfg('abiha.provinces') as $pv): ?><option value="<?= e($pv) ?>"<?= $v('province') === $pv ? ' selected' : '' ?>><?= e($pv) ?></option><?php endforeach; ?>
        </select>
        <?php if ($showErr && has_error('province')): ?><span class="error-text"><?= e(error('province')) ?></span><?php endif; ?>
    </div>
    <div class="field"><label class="label" for="<?= e($prefix) ?>city">City</label><input class="input" id="<?= e($prefix) ?>city" name="city" value="<?= e($v('city')) ?>" required><?php if ($showErr && has_error('city')): ?><span class="error-text"><?= e(error('city')) ?></span><?php endif; ?></div>
    <div class="field"><label class="label" for="<?= e($prefix) ?>postal">Postal code</label><input class="input" id="<?= e($prefix) ?>postal" name="postal_code" value="<?= e($v('postal_code')) ?>"></div>
    <div class="field sm:col-span-2"><label class="label" for="<?= e($prefix) ?>address">Complete address</label><textarea class="textarea !min-h-[80px]" id="<?= e($prefix) ?>address" name="address" required><?= e($v('address')) ?></textarea><?php if ($showErr && has_error('address')): ?><span class="error-text"><?= e(error('address')) ?></span><?php endif; ?></div>
</div>
<label class="check"><input type="checkbox" name="is_default" value="1"<?= ($a ? (int) $a['is_default'] === 1 : (bool) old('is_default')) ? ' checked' : '' ?>> Make this my default address</label>
