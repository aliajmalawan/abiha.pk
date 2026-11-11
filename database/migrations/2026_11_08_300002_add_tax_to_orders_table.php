<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prices are tax-INCLUSIVE: tax is the portion of (subtotal - discount) already inside the price.
        // It is recorded for invoices/reports and does not change the total the customer pays.
        Schema::table('orders', function (Blueprint $t) {
            $t->unsignedInteger('tax_amount')->default(0)->after('shipping_cost');
            $t->decimal('tax_rate', 5, 2)->nullable()->after('tax_amount');
            $t->string('tax_label', 30)->nullable()->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['tax_amount', 'tax_rate', 'tax_label']));
    }
};
