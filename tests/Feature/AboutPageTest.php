<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Page;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class AboutPageTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private function about(array $over = []): Page
    {
        return Page::create($over + ['title' => 'About us', 'slug' => 'about-us', 'body' => '', 'is_published' => true, 'is_sample' => false]);
    }

    public function test_the_about_page_has_its_sections_and_one_heading(): void
    {
        $this->about();
        $html = $this->get(route('page', 'about-us'))->assertOk()->getContent();
        $this->assertSame(1, preg_match_all('/<h1[\s>]/', $html));
        foreach (['Performance', 'Comfort', 'Style', 'How shopping with us works', 'We would love to hear from you'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringContainsString('href="'.route('contact').'"', $html);
        $this->assertStringContainsString('href="'.route('track').'"', $html);
    }

    public function test_only_categories_that_have_products_are_listed(): void
    {
        $this->about();
        $with = Category::create(['name' => 'Kitwear', 'slug' => 'kitwear', 'is_active' => true]);
        Category::create(['name' => 'Emptywear', 'slug' => 'emptywear', 'is_active' => true]);
        $this->product(['category_id' => $with->id]);

        $html = $this->get(route('page', 'about-us'))->getContent();
        $grid = substr($html, strpos($html, 'about-cats'), 1500);          // the menu in the header lists every category; this is the page's own grid
        $this->assertStringContainsString('Kitwear', $grid);
        $this->assertStringNotContainsString('Emptywear', substr($html, strpos($html, 'Shop by collection')));
    }

    public function test_the_payment_line_follows_the_methods_that_are_on_and_contacts_come_from_settings(): void
    {
        $this->about();
        Setting::put('payments.enabled', ['cod']);
        Setting::put('contact.phone', '+92 300 1234567');
        Setting::put('contact.email', 'hello@example.com');
        $html = $this->get(route('page', 'about-us'))->getContent();
        $this->assertStringContainsString('Pay by Cash on Delivery.', $html);
        $this->assertStringContainsString('tel:+923001234567', $html);
        $this->assertStringContainsString('mailto:hello@example.com', $html);
    }

    public function test_text_written_in_the_admin_replaces_the_built_in_page(): void
    {
        $this->about(['body' => '<p>Our own story.</p>']);
        $html = $this->get(route('page', 'about-us'))->assertOk()->getContent();
        $this->assertStringContainsString('Our own story.', $html);
        $this->assertStringNotContainsString('How shopping with us works', $html);
    }
}
