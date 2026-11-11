<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One open cart per signed-in user; guests are identified by an opaque token kept in their session.
        Schema::create('carts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $t->string('token', 64)->nullable()->unique();
            $t->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index('updated_at');                                  // pruning abandoned guest carts
        });

        Schema::create('cart_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $t->unsignedSmallInteger('quantity');
            $t->timestamps();
            $t->index(['cart_id', 'product_id', 'variant_id']);
            // Prices are deliberately NOT stored: they are always read live from the catalogue.
        });

        Schema::create('wishlists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->timestamps();
        });

        Schema::create('wishlist_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('wishlist_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['wishlist_id', 'product_id']);
        });
    }

    public function down(): void
    {
        foreach (['wishlist_items', 'wishlists', 'cart_items', 'carts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
