<?php
/** Port of admin/settings.blade.php. @var array $groups @var array $values */
use App\Controllers\Admin\SettingController;
use App\Support\Img;

$gid = static fn (string $h): string => 'g-' . trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($h)), '-');
?>
<h1 class="!text-2xl">General settings</h1>
<p class="help mb-4"><?php if (route_exists('admin.payments.edit')): ?>Payment methods and bank details are under <a class="link" href="<?= e(route('admin.payments.edit')) ?>">Payments</a><?php else: ?>Payment methods and bank details are under Payments<?php endif; ?>; shipping charges under <?php if (route_exists('admin.shipping.index')): ?><a class="link" href="<?= e(route('admin.shipping.index')) ?>">Shipping</a><?php else: ?>Shipping<?php endif; ?>.</p>
<form method="POST" action="<?= e(route('admin.settings.update')) ?>" enctype="multipart/form-data" class="max-w-3xl space-y-6" novalidate>
    <?= csrf_field() ?><?= method_field('PUT') ?>
    <?php foreach ($groups as $heading => $fields): ?>
        <section class="card space-y-5" aria-labelledby="<?= e($gid($heading)) ?>">
            <h2 class="!text-lg !mb-0" id="<?= e($gid($heading)) ?>"><?= e($heading) ?></h2>
            <?php $hasImage = false; ?>
            <?php foreach ($fields as $key => [$label, $type, $rules, $help]): ?>
                <?php $name = SettingController::formKey($key); $err = error($name); $hasImage = $hasImage || $type === 'image'; $oldVal = old($name, $values[$key]); ?>
                <div class="field">
                    <?php if ($type === 'checkbox'): ?>
                        <label class="check"><input type="checkbox" name="<?= e($name) ?>" value="1"<?= $oldVal ? ' checked' : '' ?>> <?= e($label) ?></label>
                    <?php else: ?>
                        <label class="label" for="<?= e($name) ?>"><?= e($label) ?></label>
                        <?php if ($type === 'textarea'): ?><textarea class="textarea" id="<?= e($name) ?>" name="<?= e($name) ?>" rows="4"><?= e(is_scalar($oldVal) ? $oldVal : '') ?></textarea>
                        <?php elseif ($type === 'image'): ?>
                            <?php $isIcon = $key === 'site.favicon'; $current = is_string($values[$key]) && $values[$key] !== '' ? Img::url($values[$key]) : null; ?>
                            <div class="brandbox" x-data="{ url: null, file: '' }">
                                <div class="brandbox__stage" :class="url && 'brandbox__stage--new'">
                                    <img x-show="url" x-cloak :src="url" alt="Preview of the new file" class="brandbox__img <?= $isIcon ? 'brandbox__img--icon' : '' ?>">
                                    <div x-show="!url">
                                        <?php if ($current): ?><img src="<?= e($current) ?>" alt="Current <?= $isIcon ? 'favicon' : 'logo' ?>" class="brandbox__img <?= $isIcon ? 'brandbox__img--icon' : '' ?>">
                                        <?php elseif ($isIcon): ?><img src="<?= e(asset('assets/brand/favicon-64.png')) ?>" alt="Default favicon" class="brandbox__img brandbox__img--icon">
                                        <?php else: ?><?= logo(150) ?><?php endif; ?>
                                    </div>
                                </div>
                                <p class="brandbox__state"><span x-show="!url"><?= e($current ? 'Current ' . ($isIcon ? 'favicon' : 'logo') : 'Using the default ' . ($isIcon ? 'favicon' : 'logo')) ?></span><span x-show="url" x-cloak>New file: <strong x-text="file"></strong> (saved when you press Save)</span></p>
                                <?php if ($isIcon): ?>
                                    <div class="brandbox__tab" aria-hidden="true">
                                        <img :src="url || '<?= e($current ?: asset('assets/brand/favicon-32.png')) ?>'" alt="" width="16" height="16">
                                        <span><?= e(cfg('abiha.name')) ?></span>
                                    </div>
                                <?php endif; ?>
                                <input class="input !pt-2.5" type="file" id="<?= e($name) ?>" name="<?= e($name) ?>" accept="<?= $isIcon ? 'image/png,.ico' : 'image/png,image/jpeg,image/webp' ?>"
                                       @change="const f = $event.target.files[0]; if (url) URL.revokeObjectURL(url); url = f && f.type !== '' && f.type.startsWith('image/') ? URL.createObjectURL(f) : null; file = f ? f.name : ''">
                                <?php if ($current): ?><label class="check"><input type="checkbox" name="remove_<?= e($name) ?>" value="1"> Remove (go back to the default)</label><?php endif; ?>
                            </div>
                        <?php else: ?><input class="input" id="<?= e($name) ?>" name="<?= e($name) ?>" type="<?= $type === 'number' ? 'number' : e($type) ?>"<?= $type === 'number' ? ' step="0.01" min="0" max="100"' : '' ?> value="<?= e(is_scalar($oldVal) ? $oldVal : '') ?>"><?php endif; ?>
                    <?php endif; ?>
                    <?php if ($err): ?><span class="error-text"><?= e($err) ?></span><?php elseif ($help): ?><span class="help"><?= e($help) ?></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($hasImage): ?>
                <div class="pt-4 border-t border-line"><button class="btn btn--sm">Save <?= e(strtolower($heading)) ?></button></div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    <button class="btn">Save settings</button>
</form>
