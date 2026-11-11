<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\HomeSection;
use App\Services\ActivityLogger;
use App\Services\ImageStore;
use App\Support\Img;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Homepage layout manager: add, remove, show/hide and reorder blocks, and edit each block's copy and options. */
class HomeSectionController extends Controller
{
    private const TYPES = [
        'hero' => 'Hero banner (edit under Banners)', 'campaign' => 'Main campaign banner (edit under Banners)', 'categories' => 'Shop by category',
        'products' => 'Product row', 'collections' => 'Featured collections', 'promo' => 'Promotional banner (edit under Banners)', 'brand' => 'Brand story',
        'testimonials' => 'Customer testimonials', 'newsletter' => 'Newsletter signup', 'social' => 'Social media showcase', 'account' => 'Account sign-up banner',
    ];

    /** These draw a banner chosen by placement, so a second one would just repeat the first. */
    private const SINGLE = ['hero', 'campaign', 'promo'];

    private const PRODUCT_LAYOUTS = ['carousel' => 'Carousel (swipe / arrows)', 'grid' => 'Grid', 'lookbook' => 'Shop the look (tall pictures)'];

    private const PRODUCT_SOURCES = ['bestsellers' => 'Best sellers', 'new' => 'New arrivals', 'featured' => 'Featured', 'trending' => 'Trending', 'collection' => 'A specific collection', 'latest' => 'Latest products'];

    /** Sensible starting options for a block the owner adds. */
    private const DEFAULTS = [
        'categories' => ['limit' => 4], 'collections' => ['limit' => 3], 'testimonials' => ['limit' => 3],
        'products' => ['source' => 'new', 'limit' => 8, 'link' => '/shop', 'layout' => 'carousel'],
    ];

    public function index(): View
    {
        return view('admin.home.index', [
            'sections' => HomeSection::query()->orderBy('sort_order')->orderBy('id')->get(), 'types' => self::TYPES, 'single' => self::SINGLE,
        ]);
    }

    /** Add a block. Starts switched off at the bottom so the live page does not change until the owner is ready. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(self::TYPES))], 'title' => ['nullable', 'string', 'max:160']]);

        if (in_array($data['type'], self::SINGLE, true) && HomeSection::query()->where('type', $data['type'])->exists()) {
            return back()->withErrors(['type' => 'That block is already on the page. Show it from the list below, or edit its banner under Banners.'])->withInput();
        }

        $section = HomeSection::create([
            'type' => $data['type'], 'title' => ($data['title'] ?? null) ?: null, 'settings' => self::DEFAULTS[$data['type']] ?? [],
            'sort_order' => (int) HomeSection::query()->max('sort_order') + 1, 'is_active' => false,
        ]);
        ActivityLogger::log('home.section_added', $section, ['type' => $section->type]);

        $editable = ! in_array($section->type, self::SINGLE, true);

        return ($editable ? redirect()->route('admin.home.edit', $section) : redirect()->route('admin.home.index'))
            ->with('status', 'Block added (hidden). Fill it in, then tick Show and save the layout.');
    }

    public function destroy(HomeSection $section): RedirectResponse
    {
        Img::delete($section->setting('image'));
        foreach ((array) $section->setting('images', []) as $path) {
            Img::delete($path);
        }
        $section->delete();
        ActivityLogger::log('home.section_removed', null, ['type' => $section->type]);

        return back()->with('status', 'Block removed from the homepage.');
    }

    /** Bulk save of visibility + order from the index table. */
    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate(['sections' => ['required', 'array'], 'sections.*.sort_order' => ['required', 'integer', 'min:0', 'max:999'], 'sections.*.is_active' => ['nullable', 'boolean']]);

        foreach ($data['sections'] as $id => $row) {
            HomeSection::query()->whereKey($id)->update(['sort_order' => $row['sort_order'], 'is_active' => ! empty($row['is_active'])]);
        }
        \App\Support\CatalogCache::bump();     // bulk query updates fire no model events, so tell the storefront cache explicitly
        ActivityLogger::log('home.reordered');

        return back()->with('status', 'Homepage layout saved.');
    }

    public function edit(HomeSection $section): View
    {
        return view('admin.home.edit', [
            'section' => $section, 'types' => self::TYPES, 'sources' => self::PRODUCT_SOURCES, 'layouts' => self::PRODUCT_LAYOUTS,
            'collections' => Collection::query()->orderBy('name')->pluck('name', 'slug'),
        ]);
    }

    public function update(Request $request, HomeSection $section): RedirectResponse
    {
        $rules = [
            'title' => ['nullable', 'string', 'max:160'], 'subtitle' => ['nullable', 'string', 'max:500'],
            'limit' => ['nullable', 'integer', 'between:1,12'],
        ];
        if ($section->type === 'products') {
            $rules += ['layout' => ['nullable', Rule::in(array_keys(self::PRODUCT_LAYOUTS))], 'tabs' => ['nullable', 'boolean'], 'source' => ['required', Rule::in(array_keys(self::PRODUCT_SOURCES))], 'collection' => ['nullable', 'exists:collections,slug'], 'link' => ['nullable', 'string', 'max:255', 'regex:#^(/|https?://)#']];
        }
        if ($section->type === 'brand') {
            $rules += [
                'body' => ['nullable', 'string', 'max:2000'], 'cta_label' => ['nullable', 'string', 'max:60'], 'cta_url' => ['nullable', 'string', 'max:255', 'regex:#^(/|https?://|\#)#'],
                'image' => ['nullable', 'file', 'max:4096'], 'remove_image' => ['nullable', 'boolean'],
            ];
        }
        if ($section->type === 'social') {
            $rules += ['images' => ['nullable', 'array', 'max:6'], 'images.*' => ['file', 'max:4096'], 'remove_images' => ['nullable', 'array'], 'remove_images.*' => ['integer']];
        }
        $data = $request->validate($rules);

        $settings = collect($data)->only(['limit', 'source', 'collection', 'link', 'body', 'cta_label', 'cta_url', 'layout'])->filter(fn ($v) => $v !== null && $v !== '')->all();
        if ($section->type === 'products') {
            $settings['tabs'] = $request->boolean('tabs');       // an unticked box is simply absent from the request
        }

        if ($section->type === 'brand') {
            $current = $section->setting('image');
            if ($request->hasFile('image')) {
                Img::delete($current);
                $current = app(ImageStore::class)->store($request->file('image'), 'home', 'image');   // re-encoded, metadata stripped, random name
            } elseif ($request->boolean('remove_image')) {
                Img::delete($current);
                $current = null;
            }
            if ($current) {
                $settings['image'] = $current;
            }
        }

        if ($section->type === 'social') {
            // Showcase pictures are uploaded by staff (never scraped): keep the ones not ticked for removal, then add new ones up to six.
            $keep = [];
            $remove = array_map('intval', (array) $request->input('remove_images', []));
            foreach (array_values((array) $section->setting('images', [])) as $i => $path) {
                if (in_array($i, $remove, true)) {
                    Img::delete($path);
                } else {
                    $keep[] = $path;
                }
            }
            foreach ((array) $request->file('images', []) as $file) {
                if (count($keep) >= 6) {
                    break;
                }
                $keep[] = app(ImageStore::class)->store($file, 'home', 'images');
            }
            if ($keep) {
                $settings['images'] = $keep;
            }
        }

        $section->update(['title' => $data['title'] ?? null, 'subtitle' => $data['subtitle'] ?? null, 'settings' => $settings]);
        ActivityLogger::log('home.section_updated', $section, ['type' => $section->type]);

        return redirect()->route('admin.home.index')->with('status', 'Section saved.');
    }
}
