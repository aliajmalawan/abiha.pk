<?php

namespace Database\Seeders;

use App\Models\Banner;
use App\Models\HomeSection;
use App\Models\Page;
use App\Models\Setting;
use App\Models\Testimonial;
use Illuminate\Database\Seeder;

/** SAMPLE homepage layout + site settings. Everything here is editable from the admin dashboard. */
class SampleCmsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::put('announcement.text', 'Sample announcement: replace with a real offer');
        Setting::put('announcement.url', null);
        Setting::put('delivery.info', 'SAMPLE delivery information. Delivery times and charges will be confirmed by ABIHA before launch.');
        Setting::put('returns.policy', 'SAMPLE return policy. The real return and exchange policy will be supplied by ABIHA before launch.');
        Setting::put('bank.details', "SAMPLE bank details\nBank: ________\nAccount title: ________\nAccount no / IBAN: ________");
        Setting::put('social.instagram', null);
        Setting::put('social.facebook', null);
        Setting::put('contact.email', null);
        Setting::put('contact.phone', null);

        Banner::query()->delete();
        Banner::create([
            'placement' => 'hero', 'eyebrow' => 'New season', 'title' => 'Considered pieces, quietly luxurious.',
            'subtitle' => 'Sample hero copy for layout only.', 'cta_label' => 'Shop now', 'cta_url' => '/shop', 'is_sample' => true,
        ]);
        Banner::create([
            'placement' => 'campaign', 'eyebrow' => 'Limited time', 'title' => 'Sample campaign banner',
            'subtitle' => 'Promotional message placeholder.', 'cta_label' => 'Discover', 'cta_url' => '/shop?sale=1', 'is_sample' => true,
        ]);
        Banner::create([
            'placement' => 'promo', 'eyebrow' => 'Sample promotion', 'title' => 'Sample promotional banner',
            'subtitle' => 'Placeholder for a real offer. Nothing here is a real promotion.', 'cta_label' => 'Shop now', 'cta_url' => '/shop', 'is_sample' => true,
        ]);

        Testimonial::query()->delete();
        foreach ([1, 2, 3] as $i) {
            Testimonial::create(['name' => "Sample Customer $i", 'location' => 'Sample City', 'rating' => 5,
                'body' => 'Sample testimonial text for layout only. Replace with genuine customer feedback.', 'is_sample' => true, 'sort_order' => $i]);
        }

        foreach (['about-us' => 'About us', 'faq' => 'FAQ', 'privacy-policy' => 'Privacy policy', 'terms-and-conditions' => 'Terms & conditions'] as $slug => $title) {
            Page::updateOrCreate(['slug' => $slug], ['title' => $title, 'is_published' => true, 'show_in_footer' => true, 'is_sample' => true,
                'body' => "<p><strong>SAMPLE CONTENT.</strong> The real text for \"{$title}\" will be supplied by ABIHA and entered from the admin dashboard.</p>"]);
        }

        // The FAQ shows the built-in questions (config/faq.php): real answers about how this site works, so it is not a placeholder.
        Page::whereIn('slug', ['faq', 'about-us'])->update(['is_sample' => false, 'body' => '']);

        HomeSection::query()->delete();
        $sections = [
            ['hero', 'Hero', null, []],
            ['campaign', 'Main campaign', null, []],
            ['categories', 'Shop by category', 'Browse the collection', ['limit' => 4]],
            ['products', 'New arrivals', 'Just landed', ['source' => 'new', 'limit' => 4, 'link' => '/shop?sort=newest']],
            ['products', 'Best sellers', 'Our most-loved pieces', ['source' => 'bestsellers', 'limit' => 4, 'link' => '/shop?sort=popular']],
            ['collections', 'Featured collections', 'What everyone is exploring', ['limit' => 3]],
            ['promo', 'Promotional banner', null, []],
            ['products', 'Trending products', 'Popular right now', ['source' => 'trending', 'limit' => 4, 'link' => '/shop']],
            ['products', 'Featured collection', 'Sample Signature Collection', ['source' => 'collection', 'collection' => 'sample-signature-collection', 'limit' => 4]],
            ['brand', 'The ABIHA® story', null, ['body' => 'Sample brand introduction. The real brand story will be supplied by ABIHA.', 'cta_label' => 'Learn more', 'cta_url' => '#']],
            ['testimonials', 'What customers say', null, ['limit' => 3]],
            ['newsletter', 'Join the list', 'Be first to hear about new arrivals and private offers.', []],
            ['social', 'Follow ABIHA', null, ['handle' => null]],
        ];
        foreach ($sections as $i => [$type, $title, $sub, $settings]) {
            HomeSection::create(['type' => $type, 'title' => $title, 'subtitle' => $sub, 'settings' => $settings, 'sort_order' => $i + 1]);
        }
    }
}
