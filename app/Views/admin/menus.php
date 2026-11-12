<?php
/** Port of admin/menus.blade.php. @var array $lists @var array $rows */
use App\Services\Admin\Form;
?>
<h1 class="!text-2xl">Menus</h1>
<p class="help mt-1 max-w-2xl">Edit the links in the header and the footer. The <strong>Shop</strong> mega menu is built from your categories automatically. Use a site address such as <code>/shop?sale=1</code> or a full <code>https://</code> address.</p>

<form method="POST" action="<?= e(route('admin.menus.update')) ?>" class="max-w-3xl space-y-6 mt-4" novalidate>
    <?= csrf_field() ?><?= method_field('PUT') ?>
    <?php foreach ($lists as $key => $title): ?>
        <?php $seed = old($key, $rows[$key]); $seed = array_values(is_array($seed) ? $seed : []); ?>
        <section class="card space-y-3" x-data="{ rows: <?= Form::js($seed) ?>, add() { if (this.rows.length < 12) this.rows.push({ label: '', url: '/' }) },
                move(i, by) { const j = i + by; if (j < 0 || j >= this.rows.length) return; [this.rows[i], this.rows[j]] = [this.rows[j], this.rows[i]]; } }">
            <div class="flex items-center justify-between gap-3">
                <h2 class="!text-lg !mb-0"><?= e($title) ?></h2>
                <button type="button" class="btn btn--sm btn--outline" @click="add()" :disabled="rows.length >= 12">Add link</button>
            </div>
            <template x-for="(r, i) in rows" :key="i">
                <div class="grid gap-2 sm:grid-cols-[1fr_1.4fr_auto] items-start">
                    <div class="field"><label class="label text-xs" :for="'<?= e($key) ?>-l-' + i">Text</label><input class="input !min-h-[44px]" :id="'<?= e($key) ?>-l-' + i" :name="'<?= e($key) ?>[' + i + '][label]'" x-model="r.label" maxlength="40" required></div>
                    <div class="field"><label class="label text-xs" :for="'<?= e($key) ?>-u-' + i">Address</label><input class="input !min-h-[44px]" :id="'<?= e($key) ?>-u-' + i" :name="'<?= e($key) ?>[' + i + '][url]'" x-model="r.url" maxlength="255" required></div>
                    <div class="flex gap-1 sm:mt-6">
                        <button type="button" class="btn btn--sm btn--outline !px-2" @click="move(i, -1)" :disabled="i === 0" :aria-label="'Move ' + r.label + ' up'">&uarr;</button>
                        <button type="button" class="btn btn--sm btn--outline !px-2" @click="move(i, 1)" :disabled="i === rows.length - 1" :aria-label="'Move ' + r.label + ' down'">&darr;</button>
                        <button type="button" class="btn btn--sm btn--outline !px-2 text-danger" @click="rows.splice(i, 1)" :aria-label="'Remove ' + r.label">&times;</button>
                    </div>
                </div>
            </template>
            <p class="muted text-sm" x-show="rows.length === 0">No links: nothing is shown here.</p>
            <?php foreach (errors() as $field => $msg): ?><?php if ((string) $field === $key || str_starts_with((string) $field, $key . '.')): ?><p class="error-text"><?= e(is_array($msg) ? reset($msg) : $msg) ?></p><?php endif; ?><?php endforeach; ?>
            <button type="submit" name="restore" value="<?= e($key) ?>" class="text-sm underline" data-confirm="Restore the default links for this list?">Restore the default links</button>
        </section>
    <?php endforeach; ?>
    <button class="btn">Save menus</button>
</form>
