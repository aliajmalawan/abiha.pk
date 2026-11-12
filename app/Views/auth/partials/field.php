<?php
/**
 * Form field (port of the old <x-field> component). Include with View::partial('auth/partials/field', [...]):
 *   name, label, type ('text'), value (null), required (false), help (null), autocomplete (null),
 *   class (extra wrapper classes), attrs (extra input attributes, e.g. ['autofocus' => 'autofocus', 'minlength' => 10]).
 * The previous input (old()) wins over value; password fields are never refilled.
 */
$type = $type ?? 'text';
$required = !empty($required);
$help = $help ?? null;
$autocomplete = $autocomplete ?? null;
$err = error($name);
$shown = old($name, $value ?? null);
$shown = $type === 'password' || !is_scalar($shown) ? '' : $shown;
$extra = '';
foreach (($attrs ?? []) as $k => $v) {
    $extra .= ' ' . e($k) . '="' . e($v) . '"';
}
?>
<div class="<?= e(trim('field ' . ($class ?? ''))) ?>">
    <label class="label" for="f-<?= e($name) ?>"><?= e($label) ?> <?php if ($required): ?><span class="req" aria-hidden="true">*</span><?php endif; ?></label>
    <input class="input" id="f-<?= e($name) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>" value="<?= e($shown) ?>"<?= $required ? ' required' : '' ?><?= $autocomplete ? ' autocomplete="' . e($autocomplete) . '"' : '' ?><?php if ($err): ?> aria-invalid="true" aria-describedby="e-<?= e($name) ?>"<?php elseif ($help): ?> aria-describedby="h-<?= e($name) ?>"<?php endif; ?><?= $extra ?>>
    <?php if ($err): ?><span class="error-text" id="e-<?= e($name) ?>"><?= e($err) ?></span><?php elseif ($help): ?><span class="help" id="h-<?= e($name) ?>"><?= e($help) ?></span><?php endif; ?>
</div>
