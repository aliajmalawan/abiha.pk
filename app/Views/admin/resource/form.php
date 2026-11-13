<?php
/**
 * Port of admin/resource/form.blade.php.
 * @var array $c @var ?array $model (null = new) @var array $values (row or defaults) @var array $selected
 */
use App\Core\View;
use App\Support\Img;

$exists = $model !== null;
?>
<a href="<?= e(route($c['route'] . '.index')) ?>" class="link text-sm inline-flex items-center gap-1 mb-4"><?= icon('chevron-left', 14) ?> <?= e($c['plural']) ?></a>
<h1 class="!text-2xl"><?= $exists ? 'Edit' : 'New' ?> <?= e(strtolower($c['label'])) ?></h1>

<form method="POST" enctype="multipart/form-data" novalidate
      action="<?= e($exists ? route($c['route'] . '.update', ['id' => $model['id']]) : route($c['route'] . '.store')) ?>" class="card mt-4 max-w-3xl space-y-5">
    <?= csrf_field() ?>
    <?php if ($exists): ?><?= method_field('PUT') ?><?php endif; ?>

    <?php foreach ($c['fields'] as $f): ?>
        <?php
        $name = $f['name'];
        $type = $f['type'] ?? 'text';
        $err = error($name);
        $current = $values[$name] ?? null;
        if ($type === 'datetime' && $current) {
            $current = dt((string) $current, 'Y-m-d\TH:i');
        }
        $val = old($name, $current);
        $opts = isset($f['options']) ? (is_callable($f['options']) ? ($f['options'])($model) : $f['options']) : [];
        ?>
        <div class="field">
            <?php if ($type === 'checkbox'): ?>
                <label class="check"><input type="checkbox" name="<?= e($name) ?>" value="1"<?= old($name, $current) ? ' checked' : '' ?>> <?= e($f['label']) ?></label>
            <?php else: ?>
                <label class="label" for="f-<?= e($name) ?>"><?= e($f['label']) ?></label>
                <?php if ($type === 'textarea'): ?>
                    <textarea class="textarea" id="f-<?= e($name) ?>" name="<?= e($name) ?>" rows="<?= (int) ($f['rows'] ?? 4) ?>"<?= !empty($f['rich']) ? ' data-rich' : '' ?><?= $err ? ' aria-invalid="true"' : '' ?>><?= e(is_scalar($val) ? $val : '') ?></textarea>
                <?php elseif ($type === 'select'): ?>
                    <select class="select" id="f-<?= e($name) ?>" name="<?= e($name) ?>"<?= $err ? ' aria-invalid="true"' : '' ?>>
                        <?php if (isset($f['blank'])): ?><option value=""><?= e($f['blank']) ?></option><?php endif; ?>
                        <?php foreach ($opts as $k => $label): ?><option value="<?= e($k) ?>"<?= is_scalar($val) && (string) $val === (string) $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                <?php elseif ($type === 'multiselect'): ?>
                    <?php $sel = (array) old($name, $selected[$name] ?? []); ?>
                    <select class="select !h-56" id="f-<?= e($name) ?>" name="<?= e($name) ?>[]" multiple>
                        <?php foreach ($opts as $k => $label): ?><option value="<?= e($k) ?>"<?= in_array((string) $k, array_map('strval', array_filter($sel, 'is_scalar')), true) ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select><span class="help">Hold Ctrl (Cmd on Mac) to select several.</span>
                <?php elseif ($type === 'image'): ?>
                    <?php if ($exists && !empty($model[$name])): ?><img src="<?= e(Img::url((string) $model[$name])) ?>" alt="" class="h-24 rounded border border-line object-cover mb-2">
                        <label class="check"><input type="checkbox" name="remove_<?= e($name) ?>" value="1"> Remove current image</label><?php endif; ?>
                    <input class="input !pt-2.5" type="file" id="f-<?= e($name) ?>" name="<?= e($name) ?>" accept="image/jpeg,image/png,image/webp">
                <?php else: ?>
                    <input class="input" type="<?= e($type === 'datetime' ? 'datetime-local' : $type) ?>" id="f-<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e(is_scalar($val) ? $val : '') ?>"<?= $err ? ' aria-invalid="true"' : '' ?>>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($err): ?><span class="error-text"><?= e($err) ?></span><?php elseif (!empty($f['help'])): ?><span class="help"><?= e($f['help']) ?></span><?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="flex gap-3"><button class="btn">Save</button><a href="<?= e(route($c['route'] . '.index')) ?>" class="btn btn--outline">Cancel</a></div>
</form>
<?php if (!empty($c['partial']) && $exists): ?><?= View::partial($c['partial'], ['model' => $model]) ?><?php endif; ?>
