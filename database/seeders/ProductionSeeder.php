<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * What a LIVE database starts with:  php artisan db:seed --class=ProductionSeeder --force
 *
 * Only real configuration: roles and permissions, the size/colour attribute definitions, and the empty home-page layout.
 * It adds NO sample products, categories, pages, banners, reviews, coupons or shipping rates, and NO user accounts or
 * default passwords. (The development-only sample data lives in DatabaseSeeder and must never be run on the live site.)
 * Create the first administrator with:  php artisan abiha:create-admin you@yourdomain.com
 * Everything else (products, shipping, bank details, policies) is entered by staff in the admin dashboard.
 * Safe to run again: it only restores the built-in roles to their standard permissions, adds any missing attribute values, and
 * leaves the home layout alone once it exists. It never touches products, orders, customers or settings.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            AttributeSeeder::class,
            HomeLayoutSeeder::class,
        ]);
    }
}
