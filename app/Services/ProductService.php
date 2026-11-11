<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductService
{
    private const FLAGS = ['is_active', 'is_featured', 'is_new', 'is_bestseller', 'is_trending', 'has_variants'];

    public function __construct(private readonly InventoryService $inventory)
    {
    }

    /**
     * Create or update a product with its images and variants in ONE transaction.
     * Stock levels are written through the inventory ledger (never by direct column updates).
     *
     * @param  array<string,mixed>  $data  validated product fields
     * @param  array<int,array<string,mixed>>  $variants  validated variant rows (id?, sku, price, compare_price, stock, is_active, options[])
     * @param  array<int,UploadedFile>  $newImages
     * @param  array<int,int>  $removeImageIds
     */
    public function save(?Product $product, array $data, array $variants = [], array $newImages = [], array $removeImageIds = [], ?User $by = null, array $imageOrder = []): Product
    {
        return DB::transaction(function () use ($product, $data, $variants, $newImages, $removeImageIds, $by, $imageOrder) {
            $creating = ! $product;
            $product ??= new Product;
            $hasVariants = (bool) ($data['has_variants'] ?? false);
            $wantedStock = $hasVariants ? 0 : (int) ($data['stock'] ?? 0);

            $fill = collect($data)->only(['name', 'slug', 'sku', 'category_id', 'short_description', 'description', 'price', 'compare_price', 'low_stock_threshold', 'badge', 'meta_title', 'meta_description'])->all();
            foreach (self::FLAGS as $flag) {
                $fill[$flag] = (bool) ($data[$flag] ?? false);
            }
            $fill['description'] = isset($fill['description']) ? \App\Support\HtmlSanitizer::clean($fill['description']) : null;
            $fill['specifications'] = $this->cleanSpecifications($data['specifications'] ?? []);
            $fill['slug'] = $this->uniqueSlug($fill['slug'] ?? null, $fill['name'], $product);
            $fill['low_stock_threshold'] = $fill['low_stock_threshold'] ?? 3;
            $fill['is_sample'] = false;                       // edited by an admin = real content
            if ($creating) {
                $fill['stock'] = 0;                           // opening stock goes through the ledger below
            }

            $product->fill($fill)->save();

            // Simple product stock → ledger
            if (! $hasVariants) {
                $this->inventory->setLevel($product, $wantedStock, $by, $creating ? 'Opening stock' : 'Edited in product form', $creating ? 'initial' : 'adjustment');
            } elseif ($product->stock !== 0) {
                $this->inventory->setLevel($product, 0, $by, 'Product now uses variants (stock is tracked per variant)');
            }

            $this->syncImages($product, $newImages, $removeImageIds, $imageOrder);
            foreach ((array) ($data['image_alts'] ?? []) as $imageId => $alt) {
                // Plain text only; an empty box falls back to the product name so no image is left without alt text.
                $product->images()->whereKey((int) $imageId)->update(['alt' => trim(strip_tags((string) $alt)) ?: $product->name]);
            }
            if (! empty($data['image_alts'])) {
                \App\Support\CatalogCache::bump();           // the update above bypasses model events; cards show this alt text
            }
            $hasVariants ? $this->syncVariants($product, $variants, $by) : $this->dropVariants($product);

            ActivityLogger::log($creating ? 'product.created' : 'product.updated', $product, $creating ? ['sku' => $product->sku] : ActivityLogger::diff($product));

            return $product->fresh(['images', 'allVariants']);
        });
    }

    /** Soft-delete (keeps order history intact). */
    public function delete(Product $product): void
    {
        $product->update(['is_active' => false]);
        $product->delete();
        ActivityLogger::log('product.deleted', $product, ['sku' => $product->sku]);
    }

    /** Label/value rows as plain text: blank or half-filled rows are dropped, markup is stripped, order is kept. @return array<int,array{label:string,value:string}>|null */
    private function cleanSpecifications(mixed $rows): ?array
    {
        $clean = collect(is_array($rows) ? $rows : [])
            ->map(fn ($r) => ['label' => trim(strip_tags((string) ($r['label'] ?? ''))), 'value' => trim(strip_tags((string) ($r['value'] ?? '')))])
            ->filter(fn ($r) => $r['label'] !== '' && $r['value'] !== '')
            ->values()->take(25)->all();

        return $clean ?: null;
    }

    private function syncImages(Product $product, array $newImages, array $removeIds, array $imageOrder = []): void
    {
        if ($removeIds) {
            $product->images()->whereIn('id', $removeIds)->get()->each(function ($img) {
                \App\Support\Img::delete($img->path);
                $img->delete();
            });
        }

        // Re-number what is left: the order chosen in the form first (ids that are not this product's are ignored),
        // anything not mentioned keeps its place after them. Image 1 is the main photo.
        $remaining = $product->images()->get()->keyBy('id');
        $wanted = collect($imageOrder)->map(fn ($id) => (int) $id)->unique()->filter(fn ($id) => $remaining->has($id));
        $sequence = $wanted->merge($remaining->keys()->diff($wanted))->values();
        foreach ($sequence as $i => $id) {
            if ($remaining[$id]->sort_order !== $i + 1) {
                $remaining[$id]->update(['sort_order' => $i + 1]);
                \App\Support\CatalogCache::bump();       // storefront cards show the first photo
            }
        }

        $order = $sequence->count();
        foreach ($newImages as $file) {
            $product->images()->create([
                'path' => app(ImageStore::class)->store($file, 'products', 'images'),   // re-encoded, metadata stripped, random name
                'alt' => $product->name,
                'sort_order' => ++$order,
            ]);
        }
    }

    private function syncVariants(Product $product, array $rows, ?User $by): void
    {
        $keep = [];
        foreach (array_values($rows) as $i => $row) {
            $variant = ! empty($row['id']) ? ProductVariant::query()->where('product_id', $product->id)->find($row['id']) : null;
            $isNew = ! $variant;
            $variant ??= new ProductVariant(['product_id' => $product->id, 'stock' => 0]);

            $variant->fill([
                'sku' => $row['sku'],
                'price' => ($row['price'] ?? '') === '' ? null : (int) $row['price'],
                'compare_price' => ($row['compare_price'] ?? '') === '' ? null : (int) $row['compare_price'],
                'is_active' => (bool) ($row['is_active'] ?? false),
                'sort_order' => $i,
            ])->save();
            $variant->attributeValues()->sync($row['options']);

            $this->inventory->setLevel($variant, (int) $row['stock'], $by, $isNew ? 'Opening stock' : 'Edited in product form', $isNew ? 'initial' : 'adjustment');
            $keep[] = $variant->id;
        }

        // Variants removed from the form are deleted (order lines keep their own snapshot).
        $product->allVariants()->whereNotIn('id', $keep)->get()->each->delete();
    }

    private function dropVariants(Product $product): void
    {
        $product->allVariants()->get()->each->delete();
    }

    private function uniqueSlug(?string $wanted, string $name, Product $product): string
    {
        $base = Str::slug($wanted ?: $name) ?: 'product';
        $slug = $base;
        for ($i = 2; Product::withTrashed()->where('slug', $slug)->where('id', '!=', $product->id ?? 0)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
