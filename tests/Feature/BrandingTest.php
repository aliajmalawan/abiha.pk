<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** The brand brief, as executable checks: colours, typography, icons, and the admin analytics library. */
class BrandingTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private function tokens(): string
    {
        return File::get(resource_path('css/tokens.css'));
    }

    public function test_the_brand_colours_are_exactly_the_specified_values(): void
    {
        $css = strtolower($this->tokens());

        foreach (['--abiha-black:#101010', '--abiha-gold:#c99a35', '--abiha-champagne:#e5c77b', '--abiha-ivory:#f8f6f1', '--abiha-grey:#777777', '--abiha-border:#eaeaea'] as $token) {
            $this->assertStringContainsString($token, $css, "brand token {$token}");
        }
        $this->assertMatchesRegularExpression('/--abiha-white:\s*#fff(fff)?/', $css);
    }

    public function test_headings_use_cormorant_garamond_and_everything_else_uses_inter(): void
    {
        $tokens = $this->tokens();
        $base = File::get(resource_path('css/base.css'));
        $components = File::get(resource_path('css/components.css'));

        $this->assertStringContainsString('font-family:"Cormorant Garamond"', $tokens);
        $this->assertStringContainsString('font-family:"Inter"', $tokens);
        $this->assertMatchesRegularExpression('/--font-heading:\s*"Cormorant Garamond"/', $tokens);
        $this->assertMatchesRegularExpression('/--font-body:\s*"Inter"/', $tokens);
        $this->assertMatchesRegularExpression('/h1,h2,h3,h4\{font-family:var\(--font-heading\)/', $base, 'headings use the heading font');
        $this->assertMatchesRegularExpression('/\.btn\s*\{[^}]*font-family:var\(--font-body\)[^}]*font-weight:var\(--fw-semibold\)/s', $components, 'buttons are Inter SemiBold');
        $this->assertMatchesRegularExpression('/\.price\{font-family:var\(--font-display\)/', $base, 'prices stay in Inter (Cormorant has old-style numerals)');
        $this->assertStringContainsString('--font-display:"Inter"', $tokens);
        $this->assertStringNotContainsString('Manrope', $tokens.File::get(resource_path('css/app.css')), 'the previous heading font is gone');
    }

    public function test_the_brand_fonts_are_self_hosted_real_woff2_files_and_preloaded(): void
    {
        foreach (['cormorant-garamond-latin-var.woff2', 'inter-latin-var.woff2'] as $file) {
            $path = resource_path('fonts/'.$file);
            $this->assertFileExists($path);
            $this->assertSame('wOF2', substr(File::get($path), 0, 4), "{$file} is a genuine WOFF2 file");
            $this->assertLessThan(120_000, filesize($path), "{$file} stays small");
        }
        $this->assertFileDoesNotExist(resource_path('fonts/manrope-latin-var.woff2'));

        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('cormorant-garamond-latin-var', $html, 'the heading font is preloaded');
        $this->assertStringContainsString('inter-latin-var', $html);
        $this->assertDoesNotMatchRegularExpression('#fonts\.(googleapis|gstatic)\.com#', $html, 'no third-party font requests');
    }

    public function test_the_built_stylesheet_ships_the_brand_fonts(): void
    {
        $css = collect(glob(public_path('build/assets/app-*.css')))->map(fn ($f) => File::get($f))->implode('');
        if ($css === '') {
            $this->markTestSkipped('Run `npm run build` first.');
        }

        $this->assertStringContainsString('Cormorant Garamond', $css);
        $this->assertStringContainsString('Inter', $css);
        $this->assertStringNotContainsString('Manrope', $css);
    }

    public function test_lucide_icons_are_the_icon_set(): void
    {
        $this->assertStringContainsString('lucide-static', File::get(base_path('package.json')));
        $this->assertStringContainsString('<svg', $this->get('/')->getContent());
    }

    /* ---------------- Chart.js for admin analytics ---------------- */

    public function test_chartjs_is_a_dependency_with_its_own_admin_only_bundle(): void
    {
        $this->assertStringContainsString('"chart.js"', File::get(base_path('package.json')));
        $this->assertStringContainsString('admin-charts.js', File::get(base_path('vite.config.js')));
        $this->assertStringContainsString("from 'chart.js'", File::get(resource_path('js/admin-charts.js')));
        $this->assertStringNotContainsString('chart.js', File::get(resource_path('js/app.js')), 'the storefront bundle must not include the chart library');
        $this->assertStringNotContainsString('chart.js/auto', File::get(resource_path('js/admin-charts.js')), 'only the bar chart is imported (tree-shaken)');
    }

    public function test_the_admin_dashboard_draws_chartjs_canvases_with_data_a_table_and_a_no_js_fallback(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('admin');
        [$order] = $this->placeGuestOrder();
        $this->flushSession();

        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        preg_match_all('/<canvas data-chart="([^"]+)"[^>]*role="img" aria-label="([^"]+)"/', $html, $m);
        $this->assertCount(2, $m[1], 'monthly revenue and daily sales');
        foreach ($m[1] as $json) {
            $d = json_decode(html_entity_decode($json), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(count($d['labels']), count($d['values']));
            $this->assertSame(count($d['labels']), count($d['titles']));
            $this->assertTrue($d['currency']);
            $this->assertSame('PKR', $d['symbol']);
        }
        $this->assertSame(12, count(json_decode(html_entity_decode($m[1][0]), true)['values']), '12 months');
        $this->assertSame(30, count(json_decode(html_entity_decode($m[1][1]), true)['values']), '30 days');
        $this->assertGreaterThan(0, array_sum(json_decode(html_entity_decode($m[1][0]), true)['values']), 'today\'s order is in the chart');
        $this->assertStringContainsString('View as table', $html, 'screen-reader and keyboard users get the numbers as a table');
        $this->assertMatchesRegularExpression('#<noscript>\s*<svg#', $html, 'visitors without JavaScript still see the bars');
        $this->assertSame(1, preg_match_all('#<script[^>]+admin-charts#', $html), 'the chart script is included once, not once per chart');
    }

    public function test_reports_use_the_same_chart_and_the_storefront_never_loads_the_chart_library(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('admin');
        $this->placeGuestOrder();
        $this->flushSession();

        $this->actingAs($admin)->get(route('admin.reports.index'))->assertOk()->assertSee('data-chart', false);
        $this->flushSession();
        \Illuminate\Support\Facades\Auth::forgetGuards();

        foreach (['/', '/shop', '/cart', '/contact'] as $url) {
            $this->assertStringNotContainsString('admin-charts', $this->get($url)->getContent(), "{$url} must not load Chart.js");
        }
    }

    public function test_the_admin_chart_bundle_needs_no_unsafe_inline_or_eval(): void
    {
        $this->seedRoles();
        $csp = $this->actingAs($this->userWithRole('admin'))->get(route('admin.dashboard'))->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("'nonce-", $csp, 'the chart script is loaded with the page nonce');
    }
}
