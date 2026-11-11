<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Grant / change / revoke admin-area access for existing accounts. Requires staff.manage. */
class StaffController extends Controller
{
    public function index(): View
    {
        return view('admin.staff.index', [
            'staff' => User::query()->with('role')->whereHas('role', fn ($r) => $r->where('is_staff', true))->orderBy('name')->get(),
            'roles' => Role::query()->where('is_staff', true)->with('permissions')->orderBy('id')->get(),
        ]);
    }

    /** Give an existing account a staff role (looked up by email). */
    public function grant(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'role_id' => ['required', 'integer']]);
        $role = Role::query()->where('is_staff', true)->findOrFail($data['role_id']);
        $user = User::query()->where('email', mb_strtolower($data['email']))->first();

        if (! $user) {
            return back()->withErrors(['email' => 'No account with that email. Ask the person to register on the website first.']);
        }
        $user->role_id = $role->id;
        $user->save();
        ActivityLogger::log('staff.granted', $user, ['role' => $role->slug]);

        return back()->with('status', "{$user->name} is now {$role->name}.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['role_id' => ['required', 'integer']]);
        $role = Role::query()->findOrFail($data['role_id']);

        if ($user->id === $request->user()->id) {
            return back()->withErrors(['role' => 'You cannot change your own role.']);
        }
        if ($user->isSuperAdmin() && $role->slug !== Role::ADMIN && $this->activeAdmins() <= 1) {
            return back()->withErrors(['role' => 'At least one active administrator must remain.']);
        }

        $before = $user->role?->slug;
        $user->role_id = $role->id;
        $user->save();
        ActivityLogger::log('staff.role_changed', $user, ['from' => $before, 'to' => $role->slug]);

        return back()->with('status', 'Role updated.');
    }

    private function activeAdmins(): int
    {
        return User::query()->where('is_active', true)->whereHas('role', fn ($r) => $r->where('slug', Role::ADMIN))->count();
    }
}
