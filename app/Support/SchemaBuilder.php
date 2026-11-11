<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;

/**
 * schema.org JSON-LD. Only facts that exist in the store: nothing is invented (no brand, no ratings without reviews,
 * no contact details the owner has not entered in the admin).
 */
class SchemaBuilder
{
    public static function organization(): array
    {
        $logo = SiteSettings::asset('site.logo') ?? asset('assets/brand/abiha-logo-full-600.png');
        $sameAs = collect(['instagram', 'facebook', 'tiktok', 'youtube'])->map(fn ($k) => Setting::get('social.'.$k))->filter()->values()->all();
        $email = Setting::get('contact.email', config('abiha.contact.email'));
        $phone = Setting::get('contact.phone', config('abiha.contact.phone'));

        return array_filter([
            '@context' => 'https://schema.org', '@type' => 'Organization',
            'name' => config('abiha.name'), 'url' => url('/'), 'logo' => Seo::absolute($logo),
            'sameAs' => $sameAs ?: null,
            'contactPoint' => ($email || $phone) ? array_filter(['@type' => 'ContactPoint', 'contactType' => 'customer service', 'email' => $email ?: null, 'telephone' => $phone ?: null]) : null,
        ]);
    }

    public static function website(): array
    {
        return [
            '@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => config('abiha.name'), 'url' => url('/'),
            'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => route('shop').'?q={search_term_string}'], 'query-input' => 'required name=search_term_string'],
        ];
    }

    public static function product(Product $p): array
    {
        $currency = config('abiha.currency.code');
        $images = $p->images->map(fn ($i) => Seo::absolute(\Illuminate\Support\Facades\Storage::disk('public')->url($i->path)))->values()->all();
        $availability = $p->in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
        $variants = $p->has_variants ? $p->variants : collect();

        if ($variants->count() > 1) {
            $prices = $variants->map(fn ($v) => $v->effective_price);
            $offer = ['@type' => 'AggregateOffer', 'priceCurrency' => $currency, 'lowPrice' => (int) $prices->min(), 'highPrice' => (int) $prices->max(), 'offerCount' => $variants->count(), 'availability' => $availability, 'url' => route('product', $p)];
        } else {
            $offer = ['@type' => 'Offer', 'priceCurrency' => $currency, 'price' => (int) $p->display_price, 'availability' => $availability, 'itemCondition' => 'https://schema.org/NewCondition', 'url' => route('product', $p)];
        }

        $reviewCount = $p->review_count;
        $data = array_filter([
            '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $p->name, 'sku' => $p->sku,
            'description' => Seo::plain($p->short_description ?: $p->description, 500) ?: null,
            'image' => $images ?: null, 'category' => $p->category?->name, 'url' => route('product', $p), 'offers' => $offer,
        ]);
        if ($p->specifications) {
            $data['additionalProperty'] = collect($p->specifications)->map(fn ($r) => ['@type' => 'PropertyValue', 'name' => $r['label'], 'value' => $r['value']])->values()->all();
        }
        if ($reviewCount > 0) {
            $data['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => (float) $p->average_rating, 'reviewCount' => $reviewCount];
        }

        return $data;
    }

    /** Home > parent > … > category, as [name, url] pairs. */
    public static function categoryTrail(?Category $category): array
    {
        $trail = [];
        for ($c = $category, $guard = 0; $c && $guard < 6; $c = $c->parent, $guard++) {
            array_unshift($trail, [$c->name, route('category', $c)]);
        }

        return $trail;
    }
}
