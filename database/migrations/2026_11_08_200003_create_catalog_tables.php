<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Money = unsigned integers in whole PKR (no floats). Stock = signed int, guarded in InventoryService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $t->string('name');
            $t->string('slug')->unique();
            $t->text('description')->nullable();
            $t->string('image')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_featured')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->string('meta_title')->nullable();
            $t->string('meta_description')->nullable();
            $t->timestamps();
            $t->index(['is_active', 'parent_id', 'sort_order']);
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('sku')->unique();
            $t->string('short_description', 500)->nullable();
            $t->longText('description')->nullable();
            $t->unsignedInteger('price');                       // selling price
            $t->unsignedInteger('compare_price')->nullable();   // original price, struck through when > price
            $t->integer('stock')->default(0);                   // on hand (simple products); variants hold their own
            $t->unsignedInteger('low_stock_threshold')->default(3);
            $t->boolean('has_variants')->default(false);
            $t->boolean('is_active')->default(true);
            $t->boolean('is_featured')->default(false);
            $t->boolean('is_new')->default(false);
            $t->boolean('is_bestseller')->default(false);
            $t->boolean('is_trending')->default(false);
            $t->string('badge', 40)->nullable();
            $t->unsignedInteger('sold_count')->default(0);
            $t->boolean('is_sample')->default(false);          // flags placeholder seed data
            $t->string('meta_title')->nullable();
            $t->string('meta_description')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['is_active', 'category_id']);
            $t->index(['is_active', 'is_featured']);
            $t->index('sold_count');
        });

        Schema::create('product_images', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->string('alt')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index(['product_id', 'sort_order']);
        });

        // Variant vocabulary: attribute "Size" / "Colour" and their allowed values.
        Schema::create('attributes', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60);
            $t->string('slug', 60)->unique();                   // 'size', 'color' drive storefront filters
            $t->string('type', 10)->default('select');          // select | color
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('attribute_values', function (Blueprint $t) {
            $t->id();
            $t->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $t->string('value', 60);
            $t->string('color_hex', 7)->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['attribute_id', 'value']);
        });

        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('sku')->unique();
            $t->unsignedInteger('price')->nullable();           // null = inherit product price
            $t->unsignedInteger('compare_price')->nullable();
            $t->integer('stock')->default(0);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index(['product_id', 'is_active', 'sort_order']);
        });

        // Which attribute values make up a variant (e.g. Size=M + Colour=Black)
        Schema::create('attribute_value_product_variant', function (Blueprint $t) {
            $t->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('attribute_value_id')->constrained()->restrictOnDelete();
            $t->primary(['product_variant_id', 'attribute_value_id']);
            $t->index('attribute_value_id');
        });

        Schema::create('collections', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->text('description')->nullable();
            $t->string('image')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_trending')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('collection_product', function (Blueprint $t) {
            $t->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('sort_order')->default(0);
            $t->primary(['collection_id', 'product_id']);
        });
    }

    public function down(): void
    {
        foreach (['collection_product', 'collections', 'attribute_value_product_variant', 'product_variants', 'attribute_values', 'attributes', 'product_images', 'products', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
