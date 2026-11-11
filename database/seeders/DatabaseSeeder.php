<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * All content seeded here is SAMPLE data for development only. Replace via the admin dashboard.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,   // real configuration
            AttributeSeeder::class,             // real configuration (editable in admin)
            SampleCatalogSeeder::class,
            SampleCommerceSeeder::class,
            SampleCmsSeeder::class,
        ]);
    }
}
