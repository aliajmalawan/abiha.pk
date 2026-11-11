<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('description')->nullable();
            $t->string('type', 10);                                  // percent | fixed
            $t->unsignedInteger('value');
            $t->unsignedInteger('min_subtotal')->default(0);
            $t->unsignedInteger('max_discount')->nullable();
            $t->unsignedInteger('usage_limit')->nullable();          // total redemptions
            $t->unsignedInteger('per_customer_limit')->nullable();   // redemptions per customer (account or email)
            $t->dateTime('starts_at')->nullable();
            $t->dateTime('expires_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('shipping_methods', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name');
            $t->string('description')->nullable();
            $t->unsignedInteger('cost')->default(0);
            $t->unsignedInteger('free_over')->nullable();            // free when (subtotal - discount) >= this
            $t->string('eta')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_sample')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_methods');
        Schema::dropIfExists('coupons');
    }
};
