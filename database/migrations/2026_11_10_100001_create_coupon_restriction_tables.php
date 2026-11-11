<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A coupon with rows here only discounts matching items. No rows in either table means it applies to the whole bag.
        Schema::create('coupon_category', function (Blueprint $t) {
            $t->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $t->foreignId('category_id')->constrained()->cascadeOnDelete();
            $t->primary(['coupon_id', 'category_id']);
            $t->index('category_id');
        });
        Schema::create('coupon_product', function (Blueprint $t) {
            $t->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->primary(['coupon_id', 'product_id']);
            $t->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_product');
        Schema::dropIfExists('coupon_category');
    }
};
