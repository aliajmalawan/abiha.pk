<?php

namespace Database\Seeders;

use App\Models\Coupon;
use App\Models\ShippingMethod;
use Illuminate\Database\Seeder;

/** SAMPLE shipping rates and coupons. The business must confirm real values. */
class SampleCommerceSeeder extends Seeder
{
    public function run(): void
    {
        ShippingMethod::updateOrCreate(['code' => 'standard'], [
            'name' => 'Standard delivery', 'description' => 'Sample rate. Replace with the real rate card.',
            'cost' => 250, 'free_over' => 10000, 'eta' => 'Sample: 3-5 working days', 'is_sample' => true, 'sort_order' => 1,
        ]);
        ShippingMethod::updateOrCreate(['code' => 'express'], [
            'name' => 'Express delivery', 'description' => 'Sample rate. Replace with the real rate card.',
            'cost' => 500, 'free_over' => null, 'eta' => 'Sample: 1-2 working days', 'is_sample' => true, 'sort_order' => 2,
        ]);

        Coupon::updateOrCreate(['code' => 'SAMPLE10'], ['description' => 'Sample: 10% off', 'type' => 'percent', 'value' => 10, 'min_subtotal' => 3000, 'max_discount' => 2000]);
        Coupon::updateOrCreate(['code' => 'SAMPLE500'], ['description' => 'Sample: PKR 500 off', 'type' => 'fixed', 'value' => 500, 'min_subtotal' => 5000]);
    }
}
