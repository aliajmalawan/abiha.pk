<?php

namespace App\Services;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Collection;
use App\Models\HomeSection;
use App\Models\Product;
use App\Models\Testimonial;
use Illuminate\Support\Collection as Rows;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the admin-managed homepage (home_sections table) into renderable sections.
 * Each entry: ['section' => HomeSection, 'data' => array]. Sections with nothing to show are skipped.
 */
class HomePageService
{
    /** @return Rows<int, array{section: HomeSection, data: array}> */
    public function sections(): Rows
    {
        // Built from ~10 queries; cached until the admin changes the catalogue or an order changes stock (CatalogCache).
        return \App\Support\CatalogCache::remember('home.sections', 600, fn () => HomeSection::query()->visible()->get()
            ->map(fn (HomeSection $s) => ['section' => $s, 'data' => $this->dataFor($s)])
            ->filter(fn ($row) => $row['data'] !== null)
            ->values());
    }

    private function dataFor(HomeSection $s): ?array
    {
        $limit = (int) $s->setting('limit', 4);

        return match ($s->type) {
            'hero' => ($b = Banner::placement('hero')->get())->isEmpty() ? null : ['banners' => $b, 'showcase' => $this->showcase(), 'styleCount' => Product::query()->active()->count()],
            'campaign' => ($b = Banner::placement('campaign')->first()) ? ['banner' => $b] : null,
            'promo' => ($b = Banner::placement('promo')->first()) ? ['banner' => $b, 'deal' => $this->bestDeal()] : null,
            'categories' => ($c = Category::query()->active()->where('is_featured', true)->roots()->withCount(['products as active_products_count' => fn ($q) => $q->where('is_active', true)])->orderBy('sort_order')->limit($limit)->get())->isEmpty() ? null : ['categories' => $c],
            'collections' => ($c = Collection::query()->active()->where('is_trending', true)->withCount(['products as active_products_count' => fn ($q) => $q->where('is_active', true)])->orderBy('sort_order')->limit($limit)->get())->isEmpty() ? null : ['collections' => $c],
            'products' => $this->products($s, $limit),
            'testimonials' => ($t = Testimonial::query()->where('is_active', true)->orderBy('sort_order')->limit($limit)->get())->isEmpty() ? null : ['testimonials' => $t],
            'brand' => trim((string) $s->setting('body')) !== '' ? ['stats' => $this->stats(), 'showcase' => $this->showcase()] : null,       // no text yet: the block stays hidden instead of showing an empty story
            'newsletter', 'social', 'account' => [],
            default => null,
        };
    }

    /** The products shown in the hero and story pictures: featured ones first, then the newest. Real products only. */
    private function showcase(): Rows
    {
        return Product::query()->active()->forCard()->orderByDesc('is_featured')->latest()->limit(3)->get();
    }

    /** The on-sale product with the biggest percentage off (the "featured deal"), or null when nothing is discounted. */
    private function bestDeal(): ?Product
    {
        return Product::query()->active()->forCard()->whereNotNull('compare_price')->whereColumn('compare_price', '>', 'price')
            ->orderByRaw('(compare_price - price) * 1.0 / compare_price desc')->first();
    }

    /** Figures counted from the database. A figure that is zero is left out by the view. */
    private function stats(): array
    {
        return [
            'Products' => Product::query()->active()->count(),
            'Categories' => Category::query()->active()->count(),
            'Colours' => (int) DB::table('attribute_value_product_variant as j')
                ->join('attribute_values as v', 'v.id', '=', 'j.attribute_value_id')->join('attributes as a', 'a.id', '=', 'v.attribute_id')
                ->join('product_variants as pv', 'pv.id', '=', 'j.product_variant_id')->join('products as p', 'p.id', '=', 'pv.product_id')
                ->where('a.slug', 'color')->where('p.is_active', true)->whereNull('p.deleted_at')->distinct()->count('v.id'),
        ];
    }

    private function products(HomeSection $s, int $limit): ?array
    {
        $q = Product::query()->active()->forCard();
        match ($s->setting('source')) {
            'bestsellers' => $q->where('is_bestseller', true)->orderByDesc('sold_count'),
            'new' => $q->where('is_new', true)->latest(),
            'featured' => $q->where('is_featured', true)->latest(),
            'trending' => $q->where('is_trending', true)->latest(),
            'collection' => $q->whereHas('collections', fn ($c) => $c->where('slug', $s->setting('collection')))->latest(),
            default => $q->latest(),
        };
        $products = (clone $q)->limit($limit)->get();
        if ($products->isEmpty()) {
            return null;
        }

        // Optional tabs: "All" plus one tab for each active collection that has some of these products.
        $tabs = [];
        if ($s->setting('tabs') && $s->setting('source') !== 'collection') {
            foreach (Collection::query()->active()->orderBy('sort_order')->limit(6)->get(['id', 'name', 'slug']) as $col) {
                $rows = (clone $q)->whereHas('collections', fn ($c) => $c->whereKey($col->id))->limit($limit)->get();
                if ($rows->isNotEmpty()) {
                    $tabs[] = ['label' => $col->name, 'products' => $rows, 'link' => route('collection', $col)];
                }
            }
            array_unshift($tabs, ['label' => 'All', 'products' => $products, 'link' => $s->setting('link')]);
            if (count($tabs) < 2) {
                $tabs = [];
            }
        }

        return ['products' => $products, 'tabs' => $tabs];
    }
}
