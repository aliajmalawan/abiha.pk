<?php

namespace Database\Seeders;

use App\Models\HomeSection;
use Illuminate\Database\Seeder;

/**
 * The STRUCTURE of the home page (announcement bar, hero, main campaign, categories, new arrivals, best sellers, featured collections,
 * promotional banner, trending products, brand story, testimonials, newsletter, social showcase; the footer is built in), with no content. Real configuration, safe for
 * production: it never adds products, banners, testimonials or invented brand copy. A block with nothing to show (no
 * banner, no featured products, no testimonials …) simply does not appear, so an empty shop shows a clean page.
 *
 * Staff fill the blocks from the admin (Banners, Products → Featured, Testimonials …) and can reorder or hide them under
 * Home page. The brand-story and social blocks start switched OFF because they need real wording that only the owner can write.
 * Idempotent: if the table already has sections (they have been customised) nothing is touched.
 */
class HomeLayoutSeeder extends Seeder
{
    public function run(): void
    {
        if (HomeSection::query()->exists()) {
            return;
        }

        $sections = [
            // type, title, subtitle, settings, active
            ['hero', 'Hero', null, [], true],
            ['campaign', 'Main campaign', null, [], true],
            ['categories', 'Shop by category', null, ['limit' => 4], true],
            ['products', 'New arrivals', null, ['source' => 'new', 'limit' => 4, 'link' => '/shop?sort=newest'], true],
            ['products', 'Best sellers', null, ['source' => 'bestsellers', 'limit' => 4, 'link' => '/shop?sort=popular'], true],
            ['collections', 'Featured collections', null, ['limit' => 3], true],
            ['promo', 'Promotional banner', null, [], true],
            ['products', 'Trending products', null, ['source' => 'trending', 'limit' => 4, 'link' => '/shop'], true],
            ['brand', 'Brand story', null, ['body' => ''], false],
            ['testimonials', 'What customers say', null, ['limit' => 3], true],
            ['account', 'Create your ABIHA account', null, [], true],
            ['newsletter', 'Join the list', null, [], true],
            ['social', 'Follow us', null, [], false],
        ];

        foreach ($sections as $i => [$type, $title, $subtitle, $settings, $active]) {
            HomeSection::create(['type' => $type, 'title' => $title, 'subtitle' => $subtitle, 'settings' => $settings, 'sort_order' => $i + 1, 'is_active' => $active]);
        }
    }
}
