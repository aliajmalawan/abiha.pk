<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;
use App\Services\Admin\Rules;
use App\Services\ImageStore;
use App\Support\CatalogCache;
use App\Support\Img;

/** Homepage layout manager: add, remove, show/hide and reorder blocks, and edit each block's copy and options. */
final class HomeSectionController extends AdminController
{
    public const TYPES = [
        'hero' => 'Hero banner (edit under Banners)', 'campaign' => 'Main campaign banner (edit under Banners)', 'categories' => 'Shop by category',
        'products' => 'Product row', 'collections' => 'Featured collections', 'promo' => 'Promotional banner (edit under Banners)', 'brand' => 'Brand story',
        'testimonials' => 'Customer testimonials', 'newsletter' => 'Newsletter signup', 'social' => 'Social media showcase', 'account' => 'Account sign-up banner',
    ];

    /** These draw a banner chosen by placement, so a second one would just repeat the first. */
    public const SINGLE = ['hero', 'campaign', 'promo'];

    private const PRODUCT_LAYOUTS = ['carousel' => 'Carousel (swipe / arrows)', 'grid' => 'Grid', 'lookbook' => 'Shop the look (tall pictures)'];
    private const PRODUCT_SOURCES = ['bestsellers' => 'Best sellers', 'new' => 'New arrivals', 'featured' => 'Featured', 'trending' => 'Trending', 'collection' => 'A specific collection', 'latest' => 'Latest products'];

    /** Sensible starting options for a block the owner adds. */
    private const DEFAULTS = [
        'categories' => ['limit' => 4], 'collections' => ['limit' => 3], 'testimonials' => ['limit' => 3],
        'products' => ['source' => 'new', 'limit' => 8, 'link' => '/shop', 'layout' => 'carousel'],
    ];

    public function index(Request $request): Response
    {
        $sections = array_map([self::class, 'decode'], Database::get()->all('SELECT * FROM home_sections ORDER BY sort_order, id'));
        return $this->adminView('admin/home/index', ['sections' => $sections, 'types' => self::TYPES, 'single' => self::SINGLE], 'Homepage');
    }

    /** Add a block. Starts switched off at the bottom so the live page does not change until the owner is ready. */
    public function store(Request $request): Response
    {
        $data = $this->validate($request->post, ['type' => 'required|in:' . implode(',', array_keys(self::TYPES)), 'title' => 'nullable|string|max:160']);
        $db = Database::get();
        if (in_array($data['type'], self::SINGLE, true) && (int) $db->value('SELECT COUNT(*) FROM home_sections WHERE type = ?', [$data['type']]) > 0) {
            return $this->backWithErrors(['type' => 'That block is already on the page. Show it from the list below, or edit its banner under Banners.'], route('admin.home.index'), true, $request->post);
        }
        $id = $db->insert('INSERT INTO home_sections (type, title, settings, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?, ?)', [
            $data['type'], ($data['title'] ?? null) ?: null, json_encode(self::DEFAULTS[$data['type']] ?? []),
            (int) $db->value('SELECT COALESCE(MAX(sort_order), 0) FROM home_sections') + 1, now(), now(),
        ]);
        ActivityLogger::log('home.section_added', 'HomeSection', $id, ['type' => $data['type']]);
        CatalogCache::bump();
        $editable = !in_array($data['type'], self::SINGLE, true);
        return $this->redirect($editable ? route('admin.home.edit', ['id' => $id]) : route('admin.home.index'), 'Block added (hidden). Fill it in, then tick Show and save the layout.');
    }

    public function destroy(Request $request): Response
    {
        $section = $this->find((int) $request->params['id']);
        Img::delete(is_string($section['settings']['image'] ?? null) ? $section['settings']['image'] : null);
        foreach ((array) ($section['settings']['images'] ?? []) as $path) {
            Img::delete(is_string($path) ? $path : null);
        }
        Database::get()->exec('DELETE FROM home_sections WHERE id = ?', [(int) $section['id']]);
        ActivityLogger::log('home.section_removed', null, null, ['type' => $section['type']]);
        CatalogCache::bump();
        return $this->back(route('admin.home.index'), 'Block removed from the homepage.');
    }

    /** Bulk save of visibility + order from the index table. */
    public function reorder(Request $request): Response
    {
        $in = $request->post;
        Rules::throwIf(Rules::run($in, ['sections' => 'required|array', 'sections.*.sort_order' => 'required|integer|min:0|max:999', 'sections.*.is_active' => 'nullable|boolean']));
        $db = Database::get();
        foreach ($in['sections'] as $id => $row) {
            $db->exec('UPDATE home_sections SET sort_order = ?, is_active = ?, updated_at = ? WHERE id = ?', [(int) $row['sort_order'], !empty($row['is_active']) ? 1 : 0, now(), (int) $id]);
        }
        CatalogCache::bump();
        ActivityLogger::log('home.reordered');
        return $this->back(route('admin.home.index'), 'Homepage layout saved.');
    }

    public function edit(Request $request): Response
    {
        return $this->adminView('admin/home/edit', [
            'section' => $this->find((int) $request->params['id']), 'types' => self::TYPES, 'sources' => self::PRODUCT_SOURCES, 'layouts' => self::PRODUCT_LAYOUTS,
            'collections' => array_column(Database::get()->all('SELECT slug, name FROM collections WHERE deleted_at IS NULL ORDER BY name'), 'name', 'slug'),
        ], 'Edit section');
    }

    public function update(Request $request): Response
    {
        $section = $this->find((int) $request->params['id']);
        $type = $section['type'];
        $in = $request->post;
        $rules = ['title' => 'nullable|string|max:160', 'subtitle' => 'nullable|string|max:500', 'limit' => 'nullable|integer|between:1,12'];
        if ($type === 'products') {
            $rules += [
                'layout' => 'nullable|in:' . implode(',', array_keys(self::PRODUCT_LAYOUTS)), 'tabs' => 'nullable|boolean',
                'source' => 'required|in:' . implode(',', array_keys(self::PRODUCT_SOURCES)), 'collection' => 'nullable|exists:collections,slug',
                'link' => ['nullable', 'string', 'max:255', 'regex:#^(/|https?://)#'],
            ];
        }
        if ($type === 'brand') {
            $rules += ['body' => 'nullable|string|max:2000', 'cta_label' => 'nullable|string|max:60', 'cta_url' => ['nullable', 'string', 'max:255', 'regex:#^(/|https?://|\#)#'], 'remove_image' => 'nullable|boolean'];
        }
        if ($type === 'social') {
            $rules += ['remove_images' => 'nullable|array', 'remove_images.*' => 'integer'];
        }
        $errors = Rules::run($in, $rules);
        $image = $type === 'brand' ? $request->file('image') : null;
        if ($image !== null && ($m = Rules::file($image, 'image', maxKb: 4096)) !== null) {
            $errors['image'] = $m;
        }
        $files = $type === 'social' ? $request->fileList('images') : [];
        if (count($files) > 6) {
            $errors['images'] = 'The images field must not have more than 6 items.';
        }
        foreach ($files as $i => $file) {
            if (($m = Rules::file($file, "images.$i", maxKb: 4096)) !== null) {
                $errors["images.$i"] = $m;
            }
        }
        Rules::throwIf($errors);
        $data = \App\Core\Validator::make($in, Rules::expand($in, $rules))->validated();

        $settings = [];
        foreach (['limit', 'source', 'collection', 'link', 'body', 'cta_label', 'cta_url', 'layout'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] !== null && $data[$k] !== '') {
                $settings[$k] = $data[$k];
            }
        }
        if ($type === 'products') {
            $settings['tabs'] = $request->bool('tabs');      // an unticked box is simply absent from the request
        }
        if ($type === 'brand') {
            $current = $section['settings']['image'] ?? null;
            if ($image !== null) {
                $new = ImageStore::store($image, 'home', 'image');   // re-encoded, metadata stripped, random name
                Img::delete(is_string($current) ? $current : null);
                $current = $new;
            } elseif ($request->bool('remove_image')) {
                Img::delete(is_string($current) ? $current : null);
                $current = null;
            }
            if ($current) {
                $settings['image'] = $current;
            }
        }
        if ($type === 'social') {
            // Showcase pictures are uploaded by staff (never scraped): keep the ones not ticked for removal, then add new ones up to six.
            $keep = [];
            $remove = array_map('intval', array_filter((array) ($in['remove_images'] ?? []), 'is_scalar'));
            foreach (array_values((array) ($section['settings']['images'] ?? [])) as $i => $path) {
                if (in_array($i, $remove, true)) {
                    Img::delete(is_string($path) ? $path : null);
                } else {
                    $keep[] = $path;
                }
            }
            foreach ($files as $file) {
                if (count($keep) >= 6) {
                    break;
                }
                $keep[] = ImageStore::store($file, 'home', 'images');
            }
            if ($keep) {
                $settings['images'] = $keep;
            }
        }

        Database::get()->exec('UPDATE home_sections SET title = ?, subtitle = ?, settings = ?, updated_at = ? WHERE id = ?', [
            $data['title'] ?? null, $data['subtitle'] ?? null, json_encode($settings ?: [], JSON_UNESCAPED_UNICODE), now(), (int) $section['id'],
        ]);
        ActivityLogger::log('home.section_updated', 'HomeSection', (int) $section['id'], ['type' => $type]);
        CatalogCache::bump();
        return $this->redirect(route('admin.home.index'), 'Section saved.');
    }

    private function find(int $id): array
    {
        $row = Database::get()->one('SELECT * FROM home_sections WHERE id = ?', [$id]);
        if ($row === null) {
            throw new HttpException(404);
        }
        return self::decode($row);
    }

    private static function decode(array $row): array
    {
        $s = $row['settings'] !== null ? json_decode((string) $row['settings'], true) : [];
        $row['settings'] = is_array($s) ? $s : [];
        return $row;
    }
}
