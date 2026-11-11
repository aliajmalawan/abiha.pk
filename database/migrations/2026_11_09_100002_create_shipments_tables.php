<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('courier', 80)->nullable();              // courier name (typed by staff, or returned by an integration)
            $t->string('tracking_number', 120)->nullable();
            $t->string('tracking_url')->nullable();
            $t->string('courier_reference', 120)->nullable();    // booking id from a courier integration
            $t->string('status', 20)->default('pending');        // pending | shipped | delivered | returned
            $t->timestamp('shipped_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
            $t->index('order_id');
            $t->index('tracking_number');
        });

        Schema::create('shipment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $t->string('status', 40);
            $t->string('description')->nullable();
            $t->string('location', 120)->nullable();
            $t->timestamp('occurred_at');
            $t->string('source', 12)->default('system');         // staff | system | courier (courier events only ever come from a courier API)
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['shipment_id', 'occurred_at']);
        });

        // Move the tracking details that used to live on the order into shipments, then drop those columns.
        $rows = DB::table('orders')->where(fn ($q) => $q->whereNotNull('tracking_number')->orWhereNotNull('courier'))->get();
        foreach ($rows as $o) {
            DB::table('shipments')->insert([
                'order_id' => $o->id, 'courier' => $o->courier, 'tracking_number' => $o->tracking_number, 'tracking_url' => $o->tracking_url,
                'courier_reference' => $o->courier_reference,
                'status' => in_array($o->status, ['shipped', 'delivered', 'returned'], true) ? $o->status : 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['tracking_number', 'tracking_url', 'courier', 'courier_reference']));
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('tracking_number')->nullable();
            $t->string('tracking_url')->nullable();
            $t->string('courier', 80)->nullable();
            $t->string('courier_reference', 120)->nullable();
        });
        foreach (DB::table('shipments')->get() as $s) {
            DB::table('orders')->where('id', $s->order_id)->update(['tracking_number' => $s->tracking_number, 'tracking_url' => $s->tracking_url, 'courier' => $s->courier, 'courier_reference' => $s->courier_reference]);
        }
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
    }
};
