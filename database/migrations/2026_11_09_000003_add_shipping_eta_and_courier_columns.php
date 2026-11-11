<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_rules', function (Blueprint $t) {
            $t->string('eta', 80)->nullable()->after('free_over');          // delivery estimate for this destination (blank = use the method's)
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->string('shipping_eta', 80)->nullable()->after('shipping_method_name');   // snapshot of the estimate the customer was shown
            $t->string('courier', 80)->nullable()->after('tracking_url');                // courier name, typed by staff (or set by an integration)
            $t->string('courier_reference', 120)->nullable()->after('courier');          // booking id returned by a courier integration
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['shipping_eta', 'courier', 'courier_reference']));
        Schema::table('shipping_rules', fn (Blueprint $t) => $t->dropColumn('eta'));
    }
};
