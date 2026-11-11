<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAdmin extends Command
{
    protected $signature = 'abiha:create-admin {email : Login email} {--name=Administrator} {--role=admin : admin|manager|staff} {--password= : Omit to generate a strong random password}';

    protected $description = 'Create (or promote) a staff account. No default credentials exist in this application.';

    public function handle(): int
    {
        $role = Role::query()->where('slug', $this->option('role'))->where('is_staff', true)->first();
        if (! $role) {
            $this->error('Unknown staff role. Run `php artisan db:seed --class=RolesAndPermissionsSeeder` first, and use admin, manager or staff.');

            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::password(16, symbols: false);
        if (strlen($password) < 12) {
            $this->error('Staff passwords must be at least 12 characters.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => mb_strtolower($this->argument('email'))]);
        $created = ! $user->exists;
        $user->fill(['name' => $user->name ?: $this->option('name'), 'password' => $password]);
        $user->role_id = $role->id;
        $user->is_active = true;
        $user->save();

        $this->info(($created ? 'Created' : 'Updated').": {$user->email} ({$role->name})");
        if (! $this->option('password')) {
            $this->warn("Generated password (shown once, store it safely): {$password}");
        }

        return self::SUCCESS;
    }
}
