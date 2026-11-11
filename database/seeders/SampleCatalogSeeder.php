<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** SAMPLE catalogue: generic names, no real products, no images. */
class SampleCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $tree = [
            'Sample Category A' => ['Sample A1', 'Sample A2', 'Sample A3'],
            'Sample Category B' => ['Sample B1', 'Sample B2'],
            'Sample Category C' => ['Sample C1', 'Sample C2'],
            'Sample Category D' => [],
        ];

        $leafIds = [];
        $order = 0;
        foreach ($tree as $parentName => $children) {
            $parent = Category::updateOrCreate(['slug' => Str::slug($parentName)], [
                'name' => $parentName, 'is_featured' => true, 'sort_order' => $order++,
                'description' => 'Sample category description.',
            ]);
            $leafIds[] = $parent->id;
            foreach ($children as $i => $childName) {
                $child = Category::updateOrCreate(['slug' => Str::slug($childName)], [
                    'name' => $childName, 'parent_id' => $parent->id, 'sort_order' => $i,
                ]);
                $leafIds[] = $child->id;
            }
        }

        $colors = [['Black', '#101010'], ['Ivory', '#F8F6F1'], ['Gold', '#C99A35']];
        $sizes = ['S', 'M', 'L'];

        for ($n = 1; $n <= 16; $n++) {
            $code = str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            $price = 2000 + ($n * 450);
            $variants = $n % 3 === 0;           // every 3rd product has size/colour variants
            $onSale = $n % 4 === 0;

            $product = Product::updateOrCreate(['sku' => "SMP-$code"], [
                'category_id' => $leafIds[$n % count($leafIds)],
                'name' => "Sample Product $code",
                'slug' => "sample-product-$code",
                'short_description' => 'Sample short description for layout purposes only.',
                'description' => "<p>This is <strong>sample</strong> product copy used during development. Replace with real product details.</p><ul><li>Sample detail one</li><li>Sample detail two</li></ul>",
                'price' => $price,
                'compare_price' => $onSale ? (int) round($price * 1.25, -1) : null,
                'stock' => $variants ? 0 : ($n === 5 ? 0 : 10 + $n),   // #5 is deliberately sold out
                'has_variants' => $variants,
                'is_featured' => $n <= 8,
                'is_new' => $n > 10,
                'is_bestseller' => in_array($n, [1, 2, 4, 6, 7, 9], true),
                'is_trending' => in_array($n, [3, 8, 12, 15], true),
                'sold_count' => 100 - ($n * 4),
                'is_sample' => true,
            ]);

            if ($variants) {
                $sizeAttr = Attribute::where('slug', 'size')->firstOrFail();
                $colorAttr = Attribute::where('slug', 'color')->firstOrFail();
                foreach (['Black', 'Ivory'] as $ci => $colorName) {
                    foreach (['S', 'M', 'L'] as $si => $sizeName) {
                        $variant = ProductVariant::updateOrCreate(
                            ['sku' => "SMP-$code-".strtoupper(substr($colorName, 0, 2)).'-'.$sizeName],
                            [
                                'product_id' => $product->id,
                                'price' => $sizeName === 'L' ? $price + 300 : null,   // L costs more: proves variant pricing
                                'stock' => ($ci === 1 && $sizeName === 'S') ? 0 : 5 + $si,
                                'sort_order' => $ci * 10 + $si,
                            ]
                        );
                        $variant->attributeValues()->sync([
                            $sizeAttr->values()->where('value', $sizeName)->value('id'),
                            $colorAttr->values()->where('value', $colorName)->value('id'),
                        ]);
                    }
                }
            }

            foreach ([5, 4] as $k => $rating) {
                Review::updateOrCreate(['product_id' => $product->id, 'name' => 'Sample Customer '.($k + 1)], [
                    'rating' => $rating, 'title' => 'Sample review', 'body' => 'Sample review text for layout only.',
                    'is_approved' => true, 'is_sample' => true,
                ]);
            }
        }

        $featured = Collection::updateOrCreate(['slug' => 'sample-signature-collection'], [
            'name' => 'Sample Signature Collection', 'description' => 'Sample collection description.', 'sort_order' => 1,
        ]);
        $featured->products()->syncWithPivotValues(Product::query()->where('is_featured', true)->limit(4)->pluck('id'), ['sort_order' => 0]);

        foreach (['Sample Collection One' => [1, 2, 3, 4], 'Sample Collection Two' => [5, 6, 7, 8], 'Sample Collection Three' => [9, 10, 11, 12]] as $name => $ids) {
            $c = Collection::updateOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'is_trending' => true, 'description' => 'Sample collection.', 'sort_order' => 2]);
            $c->products()->syncWithPivotValues(Product::query()->whereIn('sku', array_map(fn ($i) => 'SMP-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), $ids))->pluck('id'), ['sort_order' => 0]);
        }
    }
}
