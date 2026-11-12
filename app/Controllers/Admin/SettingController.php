<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;
use App\Services\Admin\Rules;
use App\Services\ImageStore;
use App\Support\Img;
use App\Support\Settings;
use App\Support\SiteSettings;

/**
 * General settings. Each field: key => [label, type, rules, help]. Types: text, email, url, number, textarea, checkbox, image.
 * Form field names replace dots with "__" and prefix "s__" (e.g. s__site__name).
 */
final class SettingController extends AdminController
{
    public static function groups(): array
    {
        $path = ['nullable', 'string', 'max:255', 'regex:#^(/|https?://)#'];
        return [
            'Brand logo' => [
                'site.logo' => ['Brand logo (header, footer, emails)', 'image', ['image' => true, 'mimes' => ['png', 'jpg', 'jpeg', 'webp'], 'maxKb' => 2048, 'maxDim' => 6000], 'Replaces the supplied ABIHA® artwork everywhere. Leave empty to keep the original. PNG, JPG or WebP, up to 2 MB, at most 6000×6000 px. A solid black background is removed automatically; a transparent PNG is still best.'],
            ],
            'Favicon icon' => [
                'site.favicon' => ['Favicon icon (browser tab)', 'image', ['image' => false, 'mimes' => ['png', 'ico'], 'maxKb' => 512, 'maxDim' => null], 'Square PNG or ICO, at least 64×64 px, up to 512 KB. A plain white background is removed automatically; a transparent PNG is still best.'],
            ],
            'Website' => [
                'site.name' => ['Website name', 'text', ['nullable', 'string', 'max:60'], 'Used in page titles. Blank = ABIHA.'],
                'footer.text' => ['Footer introduction', 'textarea', ['nullable', 'string', 'max:300'], 'A short line shown under the logo in the footer.'],
            ],
            'Contact information' => [
                'contact.email' => ['Contact email', 'email', ['nullable', 'email', 'max:190'], 'Shown in the footer and on the Contact page.'],
                'contact.phone' => ['Phone', 'text', ['nullable', 'string', 'max:40', 'regex:/^[\d+\-\s()]+$/'], null],
                'contact.whatsapp' => ['WhatsApp number', 'text', ['nullable', 'string', 'max:40', 'regex:/^[\d+\-\s()]+$/'], 'Digits with country code, e.g. 92300…'],
                'contact.address' => ['Address', 'textarea', ['nullable', 'string', 'max:300'], null],
                'contact.hours' => ['Opening hours', 'text', ['nullable', 'string', 'max:120'], null],
            ],
            'Social media' => [
                'social.instagram' => ['Instagram URL', 'url', ['nullable', 'url', 'max:255'], null],
                'social.facebook' => ['Facebook URL', 'url', ['nullable', 'url', 'max:255'], null],
                'social.tiktok' => ['TikTok URL', 'url', ['nullable', 'url', 'max:255'], null],
                'social.youtube' => ['YouTube URL', 'url', ['nullable', 'url', 'max:255'], null],
            ],
            'Currency & tax' => [
                'currency.code' => ['Currency code', 'text', ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'], 'Three letters, e.g. PKR.'],
                'currency.symbol' => ['Currency symbol / label', 'text', ['nullable', 'string', 'max:8'], 'Shown before prices, e.g. PKR or Rs.'],
                'tax.enabled' => ['Record tax on orders', 'checkbox', ['nullable', 'boolean'], 'Prices are treated as TAX-INCLUSIVE: the tax inside each order is calculated and shown on invoices and reports. Customer totals do not change.'],
                'tax.label' => ['Tax name', 'text', ['nullable', 'string', 'max:30'], 'e.g. GST. Confirm the correct rate and rules with your accountant.'],
                'tax.rate' => ['Tax rate (%)', 'number', ['nullable', 'numeric', 'min:0', 'max:100'], 'Applied to (subtotal − discount); shipping is excluded.'],
            ],
            'Email' => [
                'mail.from_name' => ['Sender name', 'text', ['nullable', 'string', 'max:80'], 'The "from" name on emails the website sends.'],
                'mail.from_address' => ['Sender email', 'email', ['nullable', 'email', 'max:190'], 'Must be an address your mail provider allows you to send from.'],
                'mail.notify' => ['Order notification email', 'email', ['nullable', 'email', 'max:190'], 'Where new-order alerts will go once order emails are switched on. SMTP host and password stay in the server .env file, never here.'],
            ],
            'SEO' => [
                'seo.title' => ['Default page title', 'text', ['nullable', 'string', 'max:70'], 'Used on pages that do not set their own, e.g. "ABIHA® | Premium Ecommerce".'],
                'seo.description' => ['Default meta description', 'textarea', ['nullable', 'string', 'max:300'], 'About 150 characters shown in search results.'],
                'seo.allow_indexing' => ['Allow search engines to index the site', 'checkbox', ['nullable', 'boolean'], 'Untick while building. Non-production environments are always hidden from search engines.'],
            ],
            'Announcement' => [
                'announcement.text' => ['Announcement bar text', 'text', ['nullable', 'string', 'max:160'], 'Shown above the header. Blank hides the bar.'],
                'announcement.url' => ['Announcement link', 'text', $path, 'Optional site path or https:// address.'],
            ],
            'Policies' => [
                'delivery.info' => ['Shipping policy', 'textarea', ['nullable', 'string', 'max:5000'], 'Shown on product pages and the Shipping & returns page.'],
                'returns.policy' => ['Return policy', 'textarea', ['nullable', 'string', 'max:5000'], 'Shown on product pages and the Shipping & returns page.'],
                'size.guide' => ['Size guide', 'textarea', ['nullable', 'string', 'max:20000'], 'Opens in a pop-up from the "Size guide" link beside the size selector. Simple HTML is allowed, for example a <table> of your real measurements. Leave empty to hide the link; nothing is pre-filled.'],
            ],
        ];
    }

    public function edit(Request $request): Response
    {
        $values = [];
        foreach (self::groups() as $fields) {
            foreach ($fields as $key => $_) {
                $values[$key] = Settings::get($key);
            }
        }
        $values['seo.allow_indexing'] = Settings::get('seo.allow_indexing', true);
        return $this->adminView('admin/settings', ['groups' => self::groups(), 'values' => $values], 'Settings');
    }

    public function update(Request $request): Response
    {
        $rules = [];
        $errors = [];
        foreach (self::groups() as $fields) {
            foreach ($fields as $key => [$_l, $type, $r]) {
                $name = self::formKey($key);
                if ($type === 'image') {
                    $file = $request->file($name);
                    if ($file !== null && ($m = Rules::file($file, $name, $r['image'], $r['mimes'], $r['maxKb'], $r['maxDim'])) !== null) {
                        $errors[$name] = $m;
                    }
                    continue;
                }
                $rules[$name] = $r;
            }
        }
        $errors = Rules::run($request->post, $rules) + $errors;
        Rules::throwIf($errors);
        $data = \App\Core\Validator::make($request->post, $rules)->validated();

        $changed = [];
        foreach (self::groups() as $fields) {
            foreach ($fields as $key => [$_l, $type]) {
                $name = self::formKey($key);
                if ($type === 'image') {
                    $old = Settings::get($key);
                    if ($file = $request->file($name)) {
                        $new = $key === 'site.favicon' ? ImageStore::storeIcon($file, 'branding', $name) : ImageStore::store($file, 'branding', $name, 1600, $key === 'site.logo');
                    } elseif ($request->bool('remove_' . $name) && $old) {
                        $new = null;
                    } else {
                        continue;
                    }
                    if (is_string($old) && $old !== '') {
                        Img::delete($old);
                    }
                } elseif ($type === 'checkbox') {
                    $new = $request->bool($name);
                } else {
                    $new = $data[$name] ?? null;
                    $new = ($new === '' || $new === null) ? null : ($key === 'currency.code' ? strtoupper((string) $new) : $new);
                    if ($type === 'number' && $new !== null) {
                        $new = (float) $new;
                    }
                }
                if ($new !== Settings::get($key)) {
                    Settings::put($key, $new);
                    $changed[] = $key;
                }
            }
        }
        ActivityLogger::log('settings.updated', null, null, ['keys' => $changed]);
        SiteSettings::apply();          // take effect immediately, not only on the next request
        \App\Support\CatalogCache::bump();
        return $this->back(route('admin.settings.edit'), 'Settings saved.');
    }

    public static function formKey(string $key): string
    {
        return 's__' . str_replace('.', '__', $key);
    }
}
