<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\HomeSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The hero words are a styled card whose parts arrive one after another; motion is opt-in and the first slide animates on load. */
class HeroAnimationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_hero_card_marks_its_parts_for_animation_and_the_first_slide_is_active_in_the_html(): void
    {
        \Illuminate\Support\Facades\Storage::fake("public");
        \Illuminate\Support\Facades\Storage::disk("public")->put("banners/a.png", (function () { $im = imagecreatetruecolor(1600, 700); ob_start(); imagepng($im); return ob_get_clean(); })());
        HomeSection::query()->delete();
        HomeSection::create(['type' => 'hero', 'title' => 'Hero', 'settings' => [], 'sort_order' => 1, 'is_active' => true]);
        Banner::create(['placement' => 'hero', 'title' => 'Animated Words', 'subtitle' => 'Sub', 'eyebrow' => 'New', 'cta_label' => 'Shop', 'cta_url' => '/shop', 'image' => 'banners/a.png', 'is_active' => true, 'sort_order' => 1]);

        $html = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringContainsString('hero-slide is-active', $html);
        foreach (['hero-card', 'hero-eyebrow hx', 'hero-title hx', 'hero-rule hx-rule', 'hero-sub hx'] as $part) {
            $this->assertStringContainsString($part, $html);
        }
    }

    public function test_every_animation_rule_sits_inside_the_no_preference_motion_block(): void
    {
        $css = file_get_contents(resource_path('css/home.css'));
        $this->assertSame(1, preg_match('/@media \(prefers-reduced-motion:\s*no-preference\)\s*\{/', $css, $m, PREG_OFFSET_CAPTURE));
        $start = $m[0][1];
        preg_match_all('/[^-a-z]animation\s*:/', $css, $all, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($all[0]);
        foreach ($all[0] as [, $pos]) {
            $this->assertGreaterThan($start, $pos, 'an animation outside the no-preference block would ignore reduced-motion');
        }
    }
}
