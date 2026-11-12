<?php
/**
 * Port of <x-field>: label + input + error/help. Vars: $name, $label, $type ('text'), $value, $required (bool),
 * $help, $autocomplete, $class (wrapper classes), $extra (array of extra input attributes, e.g. ['x-model' => 'city']).
 */
$type ??= 'text';
$err = error($name);
$shown = old($name, $value ?? null);
$shown = $type === 'password' || !is_scalar($shown) ? '' : (string) $shown;
$attrs = '';
foreach ($extra ?? [] as $k => $v) {
    $attrs .= ' ' . $k . '="' . e($v) . '"';
}
?>
<div class="<?= e(trim('field ' . ($class ?? ''))) ?>">
    <label class="label" for="f-<?= e($name) ?>"><?= e($label) ?> <?php if (!empty($required)): ?><span class="req" aria-hidden="true">*</span><?php endif; ?></label>
    <input class="input" id="f-<?= e($name) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>" value="<?= e($shown) ?>"
           <?php if (!empty($required)): ?> required <?php endif; ?><?php if (!empty($autocomplete)): ?> autocomplete="<?= e($autocomplete) ?>" <?php endif; ?>
           <?php if ($err): ?> aria-invalid="true" aria-describedby="e-<?= e($name) ?>" <?php elseif (!empty($help)): ?> aria-describedby="h-<?= e($name) ?>" <?php endif; ?>
          <?= $attrs ?>>
    <?php if ($err): ?><span class="error-text" id="e-<?= e($name) ?>"><?= e($err) ?></span><?php elseif (!empty($help)): ?><span class="help" id="h-<?= e($name) ?>"><?= e($help) ?></span><?php endif; ?>
</div>
