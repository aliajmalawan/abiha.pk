<?php

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

trait BuildsUsers
{
    protected function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** Create a user holding one of the seeded roles: admin | manager | staff | customer. */
    protected function userWithRole(string $slug, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->role_id = Role::query()->where('slug', $slug)->value('id');
        $user->save();

        return $user->fresh();
    }
}
