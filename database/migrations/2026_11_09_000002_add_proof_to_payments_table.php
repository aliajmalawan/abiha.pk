<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->string('proof_path')->nullable()->after('reference');        // bank-transfer proof, kept on the PRIVATE disk
            $t->timestamp('proof_submitted_at')->nullable()->after('proof_path');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropColumn(['proof_path', 'proof_submitted_at']);
        });
    }
};
