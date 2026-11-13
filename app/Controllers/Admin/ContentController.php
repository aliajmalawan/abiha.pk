<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Support\Settings;

/**
 * Admin → Content overview: one page that shows every piece of content the shop owner can change, where to change it, and what
 * still needs writing. Every status is read from the database; nothing here is invented.
 */
final class ContentController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->adminView('admin/content', self::overview(), 'Content overview');
    }

    /**
     * @return array{sections: list<array{title:string,note:string,items:list<array<string,mixed>>}>, done:int, total:int}
     */
    public static function overview(): array
    {
        $db = Database::get();
        $filled = static fn (string $key): bool => trim((string) Settings::get($key, '')) !== '';
        $item = static fn (string $label, bool $ok, string $detail, string $url, string $cta = 'Edit', bool $info = false): array
            => ['label' => $label, 'status' => $info ? 'info' : ($ok ? 'ok' : 'todo'), 'detail' => $detail, 'url' => $url, 'cta' => $cta];

        // ---- pages
        $pages = [];
        foreach ($db->all('SELECT slug, title, is_published, body, show_in_footer FROM pages') as $p) {
            $pages[(string) $p['slug']] = $p;
        }
        $published = static fn (string $slug): bool => isset($pages[$slug]) && (int) $pages[$slug]['is_published'] === 1;
        $essential = static function (string $slug, string $title, string $why) use ($pages, $published, $item): array {
            if ($published($slug)) {
                return $item($title, true, 'Published' . ((int) $pages[$slug]['show_in_footer'] === 1 ? ' and linked in the footer.' : ' (not linked in the footer).'), route('admin.pages.edit', ['id' => $pages[$slug]['id'] ?? 0]) , 'Edit');
            }
            return $item($title, false, $why, route('admin.pages.create', ['title' => $title, 'slug' => $slug, 'published' => 1]), 'Write it');
        };
        $pageIds = [];
        foreach ($db->all('SELECT id, slug FROM pages') as $r) {
            $pageIds[(string) $r['slug']] = (int) $r['id'];
        }
        foreach ($pages as $slug => &$p) {
            $p['id'] = $pageIds[$slug] ?? 0;
        }
        unset($p);

        $faqCustom = is_array(Settings::get('faq.groups')) && Settings::get('faq.groups') !== [];
        $aboutBody = isset($pages['about-us']) && trim(strip_tags((string) $pages['about-us']['body'])) !== '';

        $sections = [];
        $sections[] = ['title' => 'Pages customers read', 'note' => 'Policies and information pages. Customers expect to find these before they buy.', 'items' => [
            $essential('privacy-policy', 'Privacy policy', 'Missing. Write how you handle customers\' personal data (name, phone, address, email).'),
            $essential('terms-and-conditions', 'Terms & conditions', 'Missing. Write your terms of sale.'),
            $item('Shipping & returns text', $filled('delivery.info') && $filled('returns.policy'),
                $filled('delivery.info') && $filled('returns.policy') ? 'Shipping and return policy written.' : 'The Shipping & returns page says "will be published soon" until you write both texts.',
                route('admin.settings.edit') . '#g-policies', 'Write'),
            $item('Size guide', $filled('size.guide'), $filled('size.guide') ? 'Size guide written.' : 'Optional. Opens in a pop-up on product pages.', route('admin.settings.edit') . '#g-policies', $filled('size.guide') ? 'Edit' : 'Write', !$filled('size.guide')),
            $item('FAQ', true, $faqCustom ? 'Your own questions are showing.' : 'The built-in questions are showing. Edit them to match your shop.', route('admin.faq.edit'), 'Edit questions'),
            $item('About us', true, $aboutBody ? 'Your own text is showing.' : 'A built-in page made from your real categories is showing. Write your own story under Pages.',
                isset($pages['about-us']) ? route('admin.pages.edit', ['id' => $pages['about-us']['id']]) : route('admin.pages.create', ['title' => 'About us', 'slug' => 'about-us', 'published' => 1]), $aboutBody ? 'Edit' : 'Write your own'),
            $item('All pages', true, count($pages) . ' page' . (count($pages) === 1 ? '' : 's') . ' in total.', route('admin.pages.index'), 'Manage pages', true),
        ]];

        // ---- home page
        $blocks = $db->all('SELECT type, title, subtitle, settings, is_active FROM home_sections');
        $active = array_filter($blocks, static fn ($b) => (int) $b['is_active'] === 1);
        $hero = (int) $db->value("SELECT COUNT(*) FROM banners WHERE placement = 'hero' AND is_active = 1");
        $brand = null;
        foreach ($blocks as $b) {
            if ($b['type'] === 'brand') {
                $brand = $b;
            }
        }
        $brandBody = $brand ? trim((string) (json_decode((string) $brand['settings'], true)['body'] ?? '')) : '';
        $brandSample = $brand && (int) $brand['is_active'] === 1 && ($brandBody === '' || stripos($brandBody, 'sample') !== false);
        $sections[] = ['title' => 'Home page', 'note' => 'What visitors see first.', 'items' => [
            $item('Home page blocks', count($active) > 0, count($active) . ' of ' . count($blocks) . ' blocks are showing. Reorder, hide or edit each one.', route('admin.home.index'), 'Open the builder'),
            $item('Main banner (hero)', $hero > 0, $hero > 0 ? $hero . ' active hero banner' . ($hero === 1 ? '' : 's') . '.' : 'No active hero banner: the home page opens without a big picture.', route('admin.banners.index'), $hero > 0 ? 'Edit banners' : 'Add a banner'),
            $item('"Our story" text', !$brandSample, $brand === null ? 'The story block is not on the home page.' : ($brandSample ? 'Still the sample sentence. Write your own brand story.' : 'Your own text is showing.'), $brand ? route('admin.home.edit', ['id' => (int) ($db->value("SELECT id FROM home_sections WHERE type = 'brand' LIMIT 1") ?? 0)]) : route('admin.home.index'), $brandSample ? 'Write it' : 'Edit', $brand === null),
            $item('Customer testimonials', (int) $db->value('SELECT COUNT(*) FROM testimonials') > 0, (int) $db->value('SELECT COUNT(*) FROM testimonials') . ' testimonial(s). Only add real customer words.', route('admin.testimonials.index'), 'Manage', (int) $db->value('SELECT COUNT(*) FROM testimonials') === 0),
        ]];

        // ---- shop details
        $social = count(array_filter(['instagram', 'facebook', 'tiktok', 'youtube'], static fn ($k) => $filled('social.' . $k)));
        $sections[] = ['title' => 'Shop details', 'note' => 'Shown in the header, footer, Contact page and emails.', 'items' => [
            $item('Contact details', $filled('contact.email') && $filled('contact.phone'), ($filled('contact.email') ? 'Email set. ' : 'Email missing. ') . ($filled('contact.phone') ? 'Phone set. ' : 'Phone missing. ') . ($filled('contact.address') ? 'Address set.' : 'Address not set (optional).'), route('admin.settings.edit') . '#g-contact-information', 'Edit'),
            $item('Social links', $social > 0, $social > 0 ? $social . ' of 4 links set.' : 'None yet: the footer shows no social icons.', route('admin.settings.edit') . '#g-social-media', 'Edit', $social === 0),
            $item('Announcement bar', true, $filled('announcement.text') ? 'Showing: "' . mb_substr((string) Settings::get('announcement.text'), 0, 70) . '".' : 'Off. Use it for a real offer or notice.', route('admin.settings.edit') . '#g-announcement', 'Edit', !$filled('announcement.text')),
            $item('Footer introduction', $filled('footer.text'), $filled('footer.text') ? 'Set.' : 'Optional short line under the footer logo.', route('admin.settings.edit') . '#g-website', 'Edit', !$filled('footer.text')),
            $item('Logo and favicon', $filled('site.logo'), $filled('site.logo') ? 'Your logo is uploaded.' : 'The supplied ABIHA logo is in use.', route('admin.settings.edit') . '#g-brand-logo', 'Edit', !$filled('site.logo')),
            $item('Menus', true, Settings::get('menu.header') !== null ? 'Your own header and footer links.' : 'The default header and footer links are showing.', route('admin.menus.edit'), 'Edit links', Settings::get('menu.header') === null),
            $item('Search engines (SEO)', $filled('seo.title') && $filled('seo.description'), ($filled('seo.title') ? 'Default title set. ' : 'No default title. ') . ($filled('seo.description') ? 'Default description set.' : 'No default description.'), route('admin.settings.edit') . '#g-seo', 'Edit'),
        ]];

        // ---- selling
        $bankOn = in_array('bank_transfer', array_map('strval', (array) Settings::get('payments.enabled', (array) \App\Core\Config::get('payments.enabled', []))), true);
        $ship = $db->all('SELECT name, cost, is_active FROM shipping_methods WHERE is_active = 1');
        $free = array_filter($ship, static fn ($m) => (int) $m['cost'] === 0);
        $noPrice = (int) $db->value('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND is_active = 1 AND price = 0');
        $sections[] = ['title' => 'Selling', 'note' => 'What customers pay and how it is delivered.', 'items' => [
            $item('Product prices', $noPrice === 0, $noPrice === 0 ? 'Every visible product has a price.' : $noPrice . ' visible product(s) have a price of 0, so customers could order them for free.', route('admin.products.index'), 'Fix prices'),
            $item('Shipping methods and rates', count($ship) > 0 && count($free) === 0, count($ship) === 0 ? 'No active shipping method: customers cannot check out.' : (count($free) > 0 ? count($free) . ' active method(s) cost 0 (free). Set their real rates.' : count($ship) . ' active method(s) with a rate.'), route('admin.shipping.index'), 'Edit'),
            $item('Bank transfer details', !$bankOn || $filled('bank.details'), !$bankOn ? 'Bank transfer is off.' : ($filled('bank.details') ? 'Account details written.' : 'Bank transfer is on but no account details are written.'), route('admin.payments.edit'), 'Edit', !$bankOn),
        ]];

        $done = 0;
        $total = 0;
        foreach ($sections as $sec) {
            foreach ($sec['items'] as $i) {
                if ($i['status'] !== 'info') {
                    $total++;
                    $done += $i['status'] === 'ok' ? 1 : 0;
                }
            }
        }
        return ['sections' => $sections, 'done' => $done, 'total' => $total];
    }
}
