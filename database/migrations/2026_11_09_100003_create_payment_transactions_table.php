<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only history of everything that happened to an order's money: attempts, provider events,
        // transfer reports, staff verification/rejection, refunds. The payments table holds the current state per attempt.
        Schema::create('payment_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $t->string('provider', 30);                           // cod | bank_transfer | card | ...
            $t->string('type', 24);                               // initiated | provider_event | transfer_reported | verified | rejected | refund_recorded
            $t->string('status', 20);                             // pending | paid | failed | refunded
            $t->string('reference')->nullable();                  // provider transaction id / bank reference
            $t->unsignedInteger('amount')->default(0);
            $t->json('payload')->nullable();                      // verified provider data. NEVER card numbers or CVV.
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // staff member, when a person did it
            $t->timestamp('created_at')->useCurrent();
            $t->index(['order_id', 'created_at']);
            $t->index(['provider', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
