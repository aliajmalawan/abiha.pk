<?php
/** Port of admin/resource/index.blade.php. @var array $c @var array $rows (Pager) @var string $term */
use App\Core\View;
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <h1 class="!text-2xl !mb-0"><?= e($c['plural']) ?></h1>
    <a href="<?= e(route($c['route'] . '.create')) ?>" class="btn btn--sm">Add <?= e(strtolower($c['label'])) ?></a>
</div>

<?php if (!empty($c['search'])): ?>
    <form method="GET" class="mb-4 flex gap-2 max-w-md" role="search">
        <label for="q" class="sr-only">Search</label>
        <input id="q" name="q" value="<?= e($term) ?>" class="input !min-h-[40px]" placeholder="Search <?= e(strtolower($c['plural'])) ?>">
        <button class="btn btn--sm">Search</button>
    </form>
<?php endif; ?>

<div class="card !p-0 overflow-x-auto">
    <table class="table">
        <thead><tr><?php foreach ($c['columns'] as $col): ?><th><?= e($col[1]) ?></th><?php endforeach; ?><th class="num">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows['items'] as $row): ?>
            <tr>
                <?php foreach ($c['columns'] as $col): ?>
                    <?php $v = $row[str_replace('.', '_', $col[0])] ?? null; $type = $col[2] ?? 'text'; ?>
                    <td>
                        <?php if ($type === 'bool'): ?><span class="badge <?= $v ? 'badge--success' : 'badge--ivory' ?>"><?= $v ? 'Yes' : 'No' ?></span>
                        <?php elseif ($type === 'money'): ?><?= $v === null ? '-' : e(number_format((int) $v)) ?>
                        <?php elseif ($type === 'date'): ?><?= $v ? e(dt((string) $v, 'j M Y')) : '-' ?>
                        <?php else: ?><?= e($v ?? '-') ?><?php endif; ?>
                    </td>
                <?php endforeach; ?>
                <td class="num whitespace-nowrap">
                    <span class="act-group"><a class="act act--edit" href="<?= e(route($c['route'] . '.edit', ['id' => $row['id']])) ?>"><?= icon('pencil', 14) ?> Edit</a>
                    <form method="POST" action="<?= e(route($c['route'] . '.destroy', ['id' => $row['id']])) ?>" class="inline" data-confirm="Delete this <?= e(strtolower($c['label'])) ?>?"><?= csrf_field() ?><?= method_field('DELETE') ?>
                        <button type="submit" class="act act--del"><?= icon('trash-2', 14) ?> Delete</button></form></span>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows['items']): ?>
            <tr><td colspan="<?= count($c['columns']) + 1 ?>" class="text-center py-10 muted">Nothing here yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<div class="mt-6"><?= View::partial('admin/partials/pagination', ['pager' => $rows]) ?></div>
