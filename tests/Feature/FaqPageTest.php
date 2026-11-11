<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaqPageTest extends TestCase
{
    use RefreshDatabase;

    private function faq(array $over = []): Page
    {
        return Page::create($over + ['title' => 'FAQ', 'slug' => 'faq', 'body' => '', 'is_published' => true, 'is_sample' => false]);
    }

    public function test_the_faq_page_shows_grouped_questions_and_is_indexable_with_faq_markup(): void
    {
        $this->faq();
        $this->app->detectEnvironment(fn () => 'production');          // outside production every page is noindex anyway
        $html = $this->get(route('page', 'faq'))->assertOk()->getContent();

        foreach (['Ordering', 'Payment', 'Shipping &amp; delivery', 'Returns &amp; refunds', 'Your account', 'Help &amp; contact'] as $group) {
            $this->assertStringContainsString('>'.$group.'</h2>', $html);
        }
        $this->assertStringContainsString('How can I track my order?', $html);
        $this->assertSame(1, preg_match_all('/<h1[\s>]/', $html));
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertStringNotContainsString('noindex', $html, 'real answers, so the page may be indexed');
        $this->assertDoesNotMatchRegularExpression('/\{(payments|shipping|track|contact|login|register|forgot|privacy|phone|email)\}/', $html, 'every token is replaced');
        $this->assertStringContainsString('href="'.route('track').'"', $html);
        $this->assertStringContainsString('href="'.route('page.shipping').'"', $html);
    }

    public function test_the_payment_answer_lists_only_the_methods_that_are_switched_on(): void
    {
        $this->faq();
        Setting::put('payments.enabled', ['cod']);
        $html = $this->get(route('page', 'faq'))->getContent();
        $this->assertStringContainsString('Right now: Cash on Delivery.', $html);
        $this->assertStringNotContainsString('Right now: Cash on Delivery and', $html);
    }

    public function test_the_shop_phone_and_email_are_used_and_left_out_when_empty(): void
    {
        $this->faq();
        $html = $this->get(route('page', 'faq'))->getContent();
        $this->assertStringNotContainsString('You can also call us on', $html);

        Setting::put('contact.phone', '+92 300 1234567');
        Setting::put('contact.email', 'help@example.com');
        $html = $this->get(route('page', 'faq'))->getContent();
        $this->assertStringContainsString('tel:+923001234567', $html);
        $this->assertStringContainsString('mailto:help@example.com', $html);
    }

    public function test_text_written_in_the_admin_replaces_the_built_in_questions(): void
    {
        $this->faq(['body' => '<p>Our own words.</p>']);
        $html = $this->get(route('page', 'faq'))->assertOk()->getContent();
        $this->assertStringContainsString('Our own words.', $html);
        $this->assertStringNotContainsString('How can I track my order?', $html);
    }

    public function test_other_pages_are_not_affected(): void
    {
        Page::create(['title' => 'About us', 'slug' => 'about-us', 'body' => '<p>About text</p>', 'is_published' => true, 'is_sample' => false]);
        $this->get(route('page', 'about-us'))->assertOk()->assertSee('About text', false)->assertDontSee('Frequently asked questions');
    }
}
