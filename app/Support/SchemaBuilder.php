<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Services\Catalog;

/**
 * schema.org JSON-LD (port of the old App\Support\SchemaBuilder). Only facts that exist in the store: nothing is
 * invented (no brand, no ratings without reviews). organization()/website() live in Seo (foundation) and are proxied.
 *
 *   Seo::schema(SchemaBuilder::product($card));
 *   Seo::breadcrumbs(array_merge([['Home', route('home')]], SchemaBuilder::categoryTrail($category)));
 */
final class SchemaBuilder
{
    public static function organization(): array
    {
        return Seo::organization();
    }

    public static function website(): array
    {
        return Seo::website();
    }

    /** @param array $p a Catalog card (images, variants, category, review stats are read here) */
    public static function product(array $p): array
    {
        $currency = Config::get('abiha.currency.code');
        $images = array_map(static fn ($i) => Seo::absolute(Img::publicUrl($i['path'])), $p['images']);
        $availability = $p['in_stock'] ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
        $variants = $p['has_variants'] ? $p['variants'] : [];
        $url = route('product', $p);

        if (count($variants) > 1) {
            $prices = array_map(static fn ($v) => $v['effective_price'], $variants);
            $offer = ['@type' => 'AggregateOffer', 'priceCurrency' => $currency, 'lowPrice' => (int) min($prices), 'highPrice' => (int) max($prices), 'offerCount' => count($variants), 'availability' => $availability, 'url' => $url];
        } else {
            $offer = ['@type' => 'Offer', 'priceCurrency' => $currency, 'price' => (int) $p['display_price'], 'availability' => $availability, 'itemCondition' => 'https://schema.org/NewCondition', 'url' => $url];
        }

        $stats = $p['review_stats'] ?? Catalog::reviewStats((int) $p['id']);
        $data = array_filter([
            '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $p['name'], 'sku' => $p['sku'],
            'description' => Seo::plain($p['short_description'] ?: $p['description'], 500) ?: null,
            'image' => $images ?: null, 'category' => $p['category']['name'] ?? null, 'url' => $url, 'offers' => $offer,
        ]);
        if (!empty($p['specifications'])) {
            $data['additionalProperty'] = array_values(array_map(static fn ($r) => ['@type' => 'PropertyValue', 'name' => $r['label'], 'value' => $r['value']], $p['specifications']));
        }
        if ($stats['count'] > 0) {
            $data['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => (float) $stats['average'], 'reviewCount' => $stats['count']];
        }
        return $data;
    }

    /**
     * Home > parent > ... > category, as [name, url] pairs (without "Home").
     *
     * @param array|null $category a categories row (needs id, name, slug, parent_id)
     */
    public static function categoryTrail(?array $category): array
    {
        $trail = [];
        for ($c = $category, $guard = 0; $c && $guard < 6; $c = Catalog::parentOf($c), $guard++) {
            array_unshift($trail, [$c['name'], route('category', ['slug' => $c['slug']])]);
        }
        return $trail;
    }
}
