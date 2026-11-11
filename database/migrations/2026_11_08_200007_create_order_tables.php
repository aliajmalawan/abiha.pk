<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer address book
        Schema::create('addresses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('label', 40)->nullable();
            $t->string('name');
            $t->string('phone', 32);
            $t->string('province', 60);
            $t->string('city', 80);
            $t->string('address', 500);
            $t->string('postal_code', 12)->nullable();
            $t->boolean('is_default')->default(false);
            $t->timestamps();
            $t->index(['user_id', 'is_default']);
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('number', 24)->unique();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('email');
            $t->string('status', 20)->default('pending');          // pending|confirmed|processing|shipped|delivered|cancelled
            $t->string('payment_status', 30)->default('unpaid');   // unpaid|awaiting_verification|paid|failed|refunded (21 chars: keep >= 30)
            $t->string('payment_method', 30);
            $t->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->nullOnDelete();
            $t->string('shipping_method_name');                    // snapshot
            $t->unsignedInteger('subtotal');
            $t->unsignedInteger('discount')->default(0);
            $t->unsignedInteger('shipping_cost')->default(0);
            $t->unsignedInteger('total');
            $t->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $t->string('coupon_code', 40)->nullable();             // snapshot
            $t->text('notes')->nullable();
            $t->string('tracking_number')->nullable();
            $t->string('tracking_url')->nullable();
            $t->string('access_token', 40)->unique();              // guest confirmation link
            $t->string('ip_address', 45)->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
            $t->index(['payment_status', 'created_at']);
            $t->index('email');
        });

        // Address as it was when the order was placed (never edited when the customer edits their address book)
        Schema::create('order_addresses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('type', 10)->default('shipping');           // shipping | billing
            $t->string('name');
            $t->string('phone', 32);
            $t->string('province', 60);
            $t->string('city', 80);
            $t->string('address', 500);
            $t->string('postal_code', 12)->nullable();
            $t->timestamps();
            $t->unique(['order_id', 'type']);
        });

        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            // Snapshot: order history must never change when the catalogue does
            $t->string('name');
            $t->string('sku');
            $t->string('options')->nullable();
            $t->string('image')->nullable();
            $t->unsignedInteger('unit_price');
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('total');
            $t->timestamps();
            $t->index('product_id');
        });

        Schema::create('order_status_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('status', 20);
            $t->string('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['order_id', 'created_at']);
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('provider', 30);                            // cod | bank_transfer | <online provider code>
            $t->string('reference')->nullable();                   // provider transaction id / bank reference
            $t->string('status', 20)->default('pending');          // pending|paid|failed|refunded
            $t->unsignedInteger('amount');
            $t->json('payload')->nullable();                       // raw verified provider response
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();   // staff who confirmed manually
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'reference']);
            $t->index(['order_id', 'status']);
        });

        Schema::create('coupon_usages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();   // one coupon per order
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('email');                                    // identifies guests for per-customer limits
            $t->unsignedInteger('discount_amount');
            $t->timestamps();
            $t->index(['coupon_id', 'user_id']);
            $t->index(['coupon_id', 'email']);
        });
    }

    public function down(): void
    {
        foreach (['coupon_usages', 'payments', 'order_status_history', 'order_items', 'order_addresses', 'orders', 'addresses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
