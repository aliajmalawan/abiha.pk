<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Collection;
use App\Models\Page;
use App\Models\Product;
use App\Support\CatalogCache;
use App\Support\Seo;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/** /sitemap.xml and /robots.txt, generated from the live catalogue (a static robots.txt cannot know the environment). */
class SeoController extends Controller
{
    public function sitemap(): Response
    {
        // Rebuilt whenever the catalogue changes (CatalogCache) and at most hourly regardless.
        $xml = CatalogCache::remember('sitemap.'.md5(url('/')), 3600, fn () => $this->build());

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function robots(): Response
    {
        if (Seo::indexingDisabled()) {
            // Staging, local and "indexing off": keep every crawler out entirely.
            $body = "User-agent: *\nDisallow: /\n";
        } else {
            $lines = ['User-agent: *'];
            foreach (Seo::PRIVATE_PREFIXES as $p) {
                $lines[] = 'Disallow: /'.$p;
            }
            $lines[] = 'Disallow: /*?*q=';           // internal search results are never useful in a search index
            $lines[] = 'Allow: /build/';
            $lines[] = 'Allow: /storage/';
            $lines[] = '';
            $lines[] = 'Sitemap: '.route('sitemap');
            $body = implode("\n", $lines)."\n";
        }

        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    private function build(): string
    {
        $urls = [];
        $add = function (string $loc, $lastmod = null, string $freq = 'weekly', string $priority = '0.5', array $images = []) use (&$urls) {
            $urls[] = compact('loc', 'lastmod', 'freq', 'priority', 'images');
        };

        $add(url('/'), null, 'daily', '1.0');
        $add(route('shop'), null, 'daily', '0.9');
        $add(route('page.shipping'), null, 'monthly', '0.3');
        $add(route('contact'), null, 'monthly', '0.3');

        Category::query()->active()->get()->each(fn ($c) => $add(route('category', $c), $c->updated_at, 'weekly', '0.8'));
        Collection::query()->active()->get()->each(fn ($c) => $add(route('collection', $c), $c->updated_at, 'weekly', '0.7'));
        Page::query()->where('is_published', true)->where('is_sample', false)->get()->each(fn ($p) => $add(route('page', $p), $p->updated_at, 'monthly', '0.3'));

        // Demo products never appear; only real, active ones. Chunked so a large catalogue stays within memory.
        Product::query()->active()->where('is_sample', false)->with('images:id,product_id,path,alt,sort_order')->orderBy('id')
            ->chunk(500, function ($products) use ($add) {
                foreach ($products as $p) {
                    $add(route('product', $p), $p->updated_at, 'weekly', '0.8', $p->images->take(5)->map(fn ($i) => [
                        'loc' => Seo::absolute(Storage::disk('public')->url($i->path)), 'title' => $i->alt ?: $p->name,
                    ])->all());
                }
            });

        $e = fn (string $v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";
        foreach (array_slice($urls, 0, 50000) as $u) {      // protocol limit per file
            $out .= '<url><loc>'.$e($u['loc']).'</loc>';
            if ($u['lastmod']) {
                $out .= '<lastmod>'.$u['lastmod']->toAtomString().'</lastmod>';
            }
            $out .= '<changefreq>'.$u['freq'].'</changefreq><priority>'.$u['priority'].'</priority>';
            foreach ($u['images'] as $img) {
                $out .= '<image:image><image:loc>'.$e($img['loc']).'</image:loc><image:title>'.$e($img['title']).'</image:title></image:image>';
            }
            $out .= "</url>\n";
        }

        return $out.'</urlset>'."\n";
    }
}
