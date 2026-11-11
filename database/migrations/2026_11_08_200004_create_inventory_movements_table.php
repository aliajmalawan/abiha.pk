<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Append-only stock ledger. products.stock / product_variants.stock are the running balance;
 * every change to them is written here in the same transaction (InventoryService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $t->integer('change');                                  // +restock / -sale
            $t->integer('balance_after');
            $t->string('reason', 30);                               // sale|cancellation|restock|adjustment|return|initial
            $t->unsignedBigInteger('order_id')->nullable()->index();   // no FK: ledger must outlive orders
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // who did it
            $t->string('note')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['product_id', 'variant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
