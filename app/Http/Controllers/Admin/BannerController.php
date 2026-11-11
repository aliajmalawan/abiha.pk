<?php

namespace App\Http\Controllers\Admin;

use App\Models\Banner;
use Illuminate\Database\Eloquent\Model;

class BannerController extends ResourceController
{
    protected function config(): array
    {
        return [
            'model' => Banner::class, 'label' => 'Banner', 'plural' => 'Banners', 'route' => 'admin.banners', 'imageDir' => 'banners',
            'search' => ['title'], 'order' => ['sort_order', 'asc'],
            'defaults' => ['placement' => 'hero', 'is_active' => true, 'sort_order' => 0, 'show_text' => true],
            'columns' => [['title', 'Title'], ['placement', 'Placement'], ['sort_order', 'Order'], ['is_active', 'Active', 'bool']],
            'fields' => [
                ['name' => 'placement', 'label' => 'Placement', 'type' => 'select', 'options' => ['hero' => 'Homepage hero', 'campaign' => 'Main campaign banner (dark)', 'promo' => 'Promotional banner (light strip)']],
                ['name' => 'title', 'label' => 'Headline'],
                ['name' => 'eyebrow', 'label' => 'Small label above headline'],
                ['name' => 'subtitle', 'label' => 'Supporting text', 'type' => 'textarea', 'rows' => 3],
                ['name' => 'image', 'label' => 'Image', 'type' => 'image', 'help' => 'JPG, PNG or WebP, up to 4 MB, at most 8000×8000 px. Hero banner: about 1920×830 px (wide) works best; the whole picture is shown.'],
                ['name' => 'cta_label', 'label' => 'Button text'],
                ['name' => 'cta_url', 'label' => 'Button link', 'help' => 'Site path (/shop) or full https:// address.'],
                ['name' => 'show_text', 'label' => 'Show the headline, text and button on top of the picture', 'type' => 'checkbox', 'help' => 'Homepage hero only. Ticked: the eyebrow, headline, text and button are shown in a white card over the picture (below it on phones). Unticked: use this when the picture already has its own words; only the button is shown over it, and the headline is kept for search engines and screen readers. The picture itself is always shown whole.'],
                ['name' => 'sort_order', 'label' => 'Display order', 'type' => 'number'],
                ['name' => 'is_active', 'label' => 'Show on the website', 'type' => 'checkbox'],
            ],
            'rules' => fn (?Model $m) => [
                'placement' => ['required', 'in:hero,campaign,promo'],
                'title' => ['required', 'string', 'max:160'],
                'eyebrow' => ['nullable', 'string', 'max:80'],
                'subtitle' => ['nullable', 'string', 'max:500'],
                'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=8000,max_height=8000'],
                'cta_label' => ['nullable', 'string', 'max:60'],
                'cta_url' => ['nullable', 'string', 'max:255', 'regex:#^(/|https?://)#'],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
                'is_active' => ['nullable', 'boolean'],
                'show_text' => ['nullable', 'boolean'],
            ],
        ];
    }

    protected function beforeSave(array &$data, Model $model): void
    {
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_sample'] = false;                    // once an admin edits it, it is real content
    }
}
