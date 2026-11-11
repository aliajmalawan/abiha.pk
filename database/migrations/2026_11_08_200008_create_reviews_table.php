<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->unsignedTinyInteger('rating');                      // 1-5, validated in ReviewRequest
            $t->string('title')->nullable();
            $t->text('body');
            $t->boolean('is_approved')->default(false);
            $t->boolean('is_verified_purchase')->default(false);
            $t->boolean('is_sample')->default(false);
            $t->timestamps();
            $t->unique(['product_id', 'user_id']);
            $t->index(['product_id', 'is_approved', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
