<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Destination-specific overrides of a shipping method. Most specific match wins: city > province > method default.
        Schema::create('shipping_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shipping_method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $t->string('province', 60)->nullable();
            $t->string('city', 80)->nullable();                     // matched case-insensitively
            $t->unsignedInteger('cost');                            // PKR for this destination
            $t->unsignedInteger('free_over')->nullable();           // null = inherit the method's free-shipping threshold
            $t->boolean('is_available')->default(true);             // false = method cannot be used for this destination
            $t->timestamps();
            $t->index(['shipping_method_id', 'province', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rules');
    }
};
