<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Support\CatalogCache;
use App\Support\Img;
use App\Support\Seo;

/** /sitemap.xml and /robots.txt, generated from the live catalogue (port of the old SeoController). */
final class SeoController extends Controller
{
    public function sitemap(Request $request): Response
    {
        // Rebuilt whenever the catalogue changes (CatalogCache) and at most hourly regardless.
        $xml = (string) CatalogCache::remember('sitemap.' . md5(url('/')), 3600, fn () => $this->build());
        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function robots(Request $request): Response
    {
        if (Seo::indexingDisabled()) {
            // Staging, local and "indexing off": keep every crawler out entirely.
            $body = "User-agent: *\nDisallow: /\n";
        } else {
            $lines = ['User-agent: *'];
            foreach (Seo::PRIVATE_PREFIXES as $p) {
                $lines[] = 'Disallow: /' . $p;
            }
            $lines[] = 'Disallow: /*?*q=';           // internal search results are never useful in a search index
            $lines[] = 'Allow: /assets/';            // the old site's /build/ (compiled CSS/JS) now lives in /assets/
            $lines[] = 'Allow: /storage/';
            $lines[] = '';
            $lines[] = 'Sitemap: ' . route('sitemap');
            $body = implode("\n", $lines) . "\n";
        }
        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** /site.webmanifest: the name, colours and icons the phone uses when the shop is added to the home screen. */
    public function manifest(Request $request): Response
    {
        $name = (string) cfg('abiha.name');
        $json = [
            'name' => $name . '®', 'short_name' => mb_strtoupper((string) strtok($name, ' ')),
            'description' => 'Premium sportswear and apparel.',
            'start_url' => path_url('/'), 'scope' => path_url('/'), 'display' => 'standalone',
            'background_color' => '#F8F6F1', 'theme_color' => '#101010',
            'icons' => [
                ['src' => path_url('assets/brand/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => path_url('assets/brand/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ];
        return new Response((string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 200,
            ['Content-Type' => 'application/manifest+json; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    private function build(): string
    {
        $db = Database::get();
        $urls = [];
        $add = static function (string $loc, ?string $lastmod = null, string $freq = 'weekly', string $priority = '0.5', array $images = []) use (&$urls): void {
            $urls[] = compact('loc', 'lastmod', 'freq', 'priority', 'images');
        };

        $add(url('/'), null, 'daily', '1.0');
        $add(route('shop'), null, 'daily', '0.9');
        $add(route('page.shipping'), null, 'monthly', '0.3');
        $add(route('contact'), null, 'monthly', '0.3');

        foreach ($db->all('SELECT slug, updated_at FROM categories WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id') as $c) {
            $add(route('category', ['slug' => $c['slug']]), $c['updated_at'], 'weekly', '0.8');
        }
        foreach ($db->all('SELECT slug, updated_at FROM collections WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id') as $c) {
            $add(route('collection', ['slug' => $c['slug']]), $c['updated_at'], 'weekly', '0.7');
        }
        foreach ($db->all('SELECT slug, updated_at FROM pages WHERE is_published = 1 AND is_sample = 0 ORDER BY id') as $p) {
            $add(route('page', ['slug' => $p['slug']]), $p['updated_at'], 'monthly', '0.3');
        }

        // Demo products never appear; only real, active ones. Read in chunks so a large catalogue stays within memory.
        $lastId = 0;
        do {
            $products = $db->all('SELECT id, name, slug, updated_at FROM products WHERE is_active = 1 AND is_sample = 0 AND deleted_at IS NULL AND id > ? ORDER BY id LIMIT 500', [$lastId]);
            $images = [];
            if ($products) {
                $ids = array_column($products, 'id');
                $marks = implode(',', array_fill(0, count($ids), '?'));
                foreach ($db->all("SELECT product_id, path, alt FROM product_images WHERE product_id IN ({$marks}) ORDER BY sort_order, id", $ids) as $i) {
                    if (count($images[$i['product_id']] ?? []) < 5) {
                        $images[$i['product_id']][] = $i;
                    }
                }
            }
            foreach ($products as $p) {
                $add(route('product', ['slug' => $p['slug']]), $p['updated_at'], 'weekly', '0.8', array_map(
                    static fn (array $i): array => ['loc' => Seo::absolute(Img::publicUrl((string) $i['path'])), 'title' => $i['alt'] ?: $p['name']],
                    $images[$p['id']] ?? []
                ));
                $lastId = (int) $p['id'];
            }
        } while (count($products) === 500);

        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        foreach (array_slice($urls, 0, 50000) as $u) {      // protocol limit per file
            $out .= '<url><loc>' . $e($u['loc']) . '</loc>';
            if ($u['lastmod'] && ($t = strtotime((string) $u['lastmod'])) !== false) {
                $out .= '<lastmod>' . date(DATE_ATOM, $t) . '</lastmod>';
            }
            $out .= '<changefreq>' . $u['freq'] . '</changefreq><priority>' . $u['priority'] . '</priority>';
            foreach ($u['images'] as $img) {
                $out .= '<image:image><image:loc>' . $e($img['loc']) . '</image:loc><image:title>' . $e((string) $img['title']) . '</image:title></image:image>';
            }
            $out .= "</url>\n";
        }
        return $out . '</urlset>' . "\n";
    }
}
