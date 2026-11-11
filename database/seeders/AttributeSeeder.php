<?php

namespace Database\Seeders;

use App\Models\Attribute;
use Illuminate\Database\Seeder;

/** Variant vocabulary. The slugs 'size' and 'color' drive the storefront filters. Edit/extend in admin. */
class AttributeSeeder extends Seeder
{
    public function run(): void
    {
        $size = Attribute::updateOrCreate(['slug' => 'size'], ['name' => 'Size', 'type' => 'select', 'sort_order' => 1]);
        foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL'] as $i => $v) {
            $size->values()->updateOrCreate(['value' => $v], ['sort_order' => $i]);
        }

        $color = Attribute::updateOrCreate(['slug' => 'color'], ['name' => 'Colour', 'type' => 'color', 'sort_order' => 2]);
        foreach ([['Black', '#101010'], ['Ivory', '#F8F6F1'], ['Gold', '#C99A35']] as $i => [$v, $hex]) {
            $color->values()->updateOrCreate(['value' => $v], ['color_hex' => $hex, 'sort_order' => $i]);
        }
    }
}
