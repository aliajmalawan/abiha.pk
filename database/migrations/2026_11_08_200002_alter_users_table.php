<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('phone', 32)->nullable()->after('email');
            // Role is a real foreign key; RESTRICT so a role in use cannot be deleted out from under users.
            $t->foreignId('role_id')->nullable()->after('password')->constrained('roles')->restrictOnDelete();
            $t->boolean('is_active')->default(true)->after('role_id');
            $t->timestamp('last_login_at')->nullable()->after('is_active');
            $t->index('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('role_id');
            $t->dropIndex(['phone']);
            $t->dropColumn(['phone', 'is_active', 'last_login_at']);
        });
    }
};
