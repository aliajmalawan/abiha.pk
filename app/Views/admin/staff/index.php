<?php
/** Staff & roles (port of admin/staff/index.blade.php). @var array $staff @var array $roles (staff roles + permission_names) @var array $allRoles */
use App\Auth\Auth;
use App\Services\Admin\Form;
?>
<h1 class="!text-2xl">Staff &amp; roles</h1>
<div class="grid gap-6 lg:grid-cols-[1fr_340px] mt-4 items-start">
    <section class="card !p-0 overflow-x-auto">
        <table class="table"><thead><tr><th>Person</th><th>Role</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($staff as $u): ?>
                <tr><td><?= e($u['name']) ?><br><span class="text-xs text-grey-text"><?= e($u['email']) ?></span></td>
                    <td><?php if ((int) $u['id'] === Auth::id()): ?><span class="badge"><?= e($u['role_name']) ?></span> <span class="text-xs text-grey-text">(you)</span>
                        <?php else: ?><form method="POST" action="<?= e(route('admin.staff.update', ['id' => $u['id']])) ?>" class="flex gap-2"><?= csrf_field() ?><?= method_field('PATCH') ?>
                            <select name="role_id" class="select !min-h-[36px] !w-auto text-sm" aria-label="Role for <?= e($u['name']) ?>">
                                <?php foreach ($allRoles as $r): ?><option value="<?= (int) $r['id'] ?>"<?= (int) $u['role_id'] === (int) $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?><?= (int) $r['is_staff'] === 1 ? '' : ' (revoke access)' ?></option><?php endforeach; ?></select>
                            <button class="btn btn--sm !min-h-[36px]">Save</button></form><?php endif; ?></td>
                    <td><span class="badge <?= (int) $u['is_active'] === 1 ? 'badge--success' : 'badge--danger' ?>"><?= (int) $u['is_active'] === 1 ? 'Active' : 'Blocked' ?></span></td></tr>
            <?php endforeach; ?>
        </tbody></table>
    </section>
    <aside class="space-y-6">
        <form method="POST" action="<?= e(route('admin.staff.grant')) ?>" class="card space-y-3"><?= csrf_field() ?>
            <h2 class="!text-lg">Give someone access</h2>
            <p class="help">They must already have an account on the website.</p>
            <?= Form::field('email', 'Their account email', null, ['type' => 'email', 'required' => true]) ?>
            <div class="field"><label class="label" for="role_id">Role</label><select class="select" id="role_id" name="role_id"><?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
            <button class="btn btn--block">Grant access</button>
        </form>
        <section class="card text-sm"><h2 class="!text-lg">What each role can do</h2>
            <?php foreach ($roles as $r): ?><details class="mb-2"><summary class="font-semibold cursor-pointer"><?= e($r['name']) ?></summary>
                <p class="text-grey-text mt-1"><?= e($r['description']) ?></p>
                <ul class="list-disc pl-5 mt-1"><?php if ($r['slug'] === 'admin'): ?><li>Everything</li><?php else: ?><?php foreach ($r['permission_names'] as $p): ?><li><?= e($p) ?></li><?php endforeach; ?><?php endif; ?></ul></details><?php endforeach; ?>
        </section>
    </aside>
</div>
