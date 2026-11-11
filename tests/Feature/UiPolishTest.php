<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/**
 * Server-side guarantees for the UI polish phase. Real browser behaviour (focus trap, escape, reveal,
 * reduced motion) is covered by tests/browser; these tests protect the markup and rules it depends on.
 */
class UiPolishTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    /* ---------------- cart drawer ---------------- */

    public function test_empty_drawer_shows_the_shared_empty_state(): void
    {
        $this->get(route('cart.drawer'))->assertOk()
            ->assertSee('Your bag is empty')->assertSee('empty__icon', false)->assertSee(route('shop'), false)
            ->assertDontSee('Subtotal');
    }

    public function test_drawer_lists_lines_with_controls_and_server_side_totals(): void
    {
        $this->shipping();
        $a = $this->product(['name' => 'Drawer Tee', 'price' => 1500, 'stock' => 9]);
        $this->addToCart($a, null, 2);

        $res = $this->get(route('cart.drawer'))->assertOk();
        $res->assertSee('Drawer Tee')->assertSee('3,000')->assertSee('Subtotal')
            ->assertSee('aria-label="Increase quantity"', false)->assertSee('aria-label="Decrease quantity"', false)
            ->assertSee('Remove Drawer Tee from your bag')->assertSee(route('checkout'), false);
    }

    public function test_drawer_flags_items_that_lost_stock(): void
    {
        $p = $this->product(['name' => 'Fading Stock', 'stock' => 5]);
        $this->addToCart($p, null, 5);
        $p->update(['stock' => 2]);

        $this->get(route('cart.drawer'))->assertSee('Quantity reduced to 2');
    }

    public function test_drawer_quantity_and_remove_use_the_same_validated_endpoints(): void
    {
        $p = $this->product(['stock' => 3]);
        $this->addToCart($p, null, 1);
        $key = $p->id.'-0';

        $this->patchJson(route('cart.update', $key), ['quantity' => 3])->assertOk()->assertJson(['count' => 3]);
        $this->patchJson(route('cart.update', $key), ['quantity' => 4])->assertStatus(422);       // over stock: refused
        $this->deleteJson(route('cart.remove', $key))->assertOk()->assertJson(['count' => 0]);
        $this->get(route('cart.drawer'))->assertSee('Your bag is empty');
    }

    public function test_the_drawer_is_wired_into_the_page_without_breaking_the_plain_link(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('"cartDrawerUrl":"'.str_replace('/', '\/', route('cart.drawer')).'"', $html);
        $this->assertMatchesRegularExpression('#<a[^>]+href="'.preg_quote(route('cart'), '#').'"[^>]+aria-label="Shopping bag"[^>]+aria-haspopup="dialog"#', $html);   // still a real link without JS
    }

    /* ---------------- accessible dialogs ---------------- */

    public function test_every_dialog_is_labelled_modal_and_trapped(): void
    {
        $html = $this->get(route('home'))->getContent();

        foreach (['quick-title' => 'dialog', 'drawer-title' => 'dialog', 'confirm-title' => 'alertdialog'] as $id => $role) {
            $this->assertStringContainsString('role="'.$role.'"', $html);
            $this->assertStringContainsString('aria-labelledby="'.$id.'"', $html);
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
        $this->assertSame(1, preg_match('/aria-describedby="confirm-text"/', $html));
        $this->assertGreaterThanOrEqual(5, substr_count($html, 'x-trap='));                       // quick view, drawer, confirm, menu, search
        $this->assertSame(substr_count($html, 'aria-modal="true"'), substr_count($html, 'aria-modal="true"'));
        $this->assertStringContainsString('role="status"', $html);                                // toast live region
    }

    public function test_storefront_never_uses_native_browser_dialogs(): void
    {
        $offenders = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/views/admin/') || str_contains($path, 'design-system') || ! str_ends_with($path, '.blade.php')) {
                continue;
            }
            $code = preg_replace('/\{\{--.*?--\}\}/s', '', File::get($path));          // comments may mention the word
            if (preg_match('/\b(confirm|alert|prompt)\s*\(/', $code)) {
                $offenders[] = basename($path);
            }
        }
        $this->assertSame([], $offenders, 'Use $store.confirm / toast instead of window dialogs: '.implode(', ', $offenders));
    }

    public function test_destructive_account_actions_ask_through_the_accessible_confirm_dialog(): void
    {
        $user = User::factory()->create();
        $user->addresses()->create(['name' => 'A', 'phone' => '03001234567', 'province' => 'Punjab', 'city' => 'Lahore', 'address' => 'House 1, Street 2']);

        $this->actingAs($user)->get(route('account.addresses'))->assertOk()->assertSee('$store.confirm.ask', false)->assertSee('Remove this address?', false);
    }

    /* ---------------- empty states ---------------- */

    public function test_empty_pages_use_one_consistent_component(): void
    {
        $this->get(route('wishlist'))->assertSee('Nothing saved yet')->assertSee('empty__icon', false);
        $this->get(route('cart'))->assertSee('Your bag is empty')->assertSee('empty__icon', false);
        $this->get(route('shop', ['q' => 'zzz-no-such-product']))->assertSee('No products found')->assertSee('empty__icon', false);
        $this->actingAs(User::factory()->create())->get(route('account.orders'))->assertSee('No orders yet')->assertSee('empty__icon', false);
    }

    /* ---------------- form validation messages ---------------- */

    public function test_forms_opt_in_to_plain_language_validation(): void
    {
        foreach (['login', 'register', 'password.request', 'contact', 'track'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('novalidate data-validate', false);
        }
    }

    public function test_failed_submit_shows_a_focusable_error_summary_linking_to_fields(): void
    {
        $res = $this->from(route('register'))->followingRedirects()->post(route('register'), ['name' => '', 'email' => 'nope', 'password' => 'x']);

        $res->assertSee('data-error-summary', false)->assertSee('role="alert"', false)->assertSee('problems to fix')
            ->assertSee('href="#f-email"', false)->assertSee('href="#f-name"', false);
        $res->assertSee('id="f-email"', false);                                                  // the link target exists
        $res->assertSee('aria-invalid="true"', false);
    }

    public function test_page_level_errors_do_not_pollute_the_field_summary(): void
    {
        $this->shipping();
        $p = $this->product(['stock' => 1]);
        $this->addToCart($p);
        $p->update(['stock' => 0]);
        $res = $this->from(route('checkout'))->followingRedirects()->post(route('checkout.store'), $this->checkoutPayload());

        $res->assertDontSee('data-error-summary', false);                                        // 'cart' error shows as a normal alert instead
    }

    /* ---------------- motion rules ---------------- */

    public function test_built_css_respects_reduced_motion_and_uses_no_decorative_effects(): void
    {
        $manifest = json_decode(File::get(public_path('build/manifest.json')), true);
        $css = File::get(public_path('build/'.$manifest['resources/css/app.css']['file']));

        $this->assertStringContainsString('prefers-reduced-motion:reduce', preg_replace('/\s+/', '', $css));
        $this->assertStringContainsString('view-transition', $css);                                // page transitions are present…
        $this->assertMatchesRegularExpression('/prefers-reduced-motion:\s*no-preference[^}]*view-transition/s', $css);   // …and opt-in only
        $this->assertDoesNotMatchRegularExpression('/\.backdrop-blur|backdrop-filter:\s*blur/', $css, 'no glassmorphism');   // Tailwind's .transition lists backdrop-filter harmlessly
    }

    public function test_source_stylesheet_animations_are_opt_in(): void
    {
        $motion = File::get(resource_path('css/motion.css'));

        // every keyframe-driven rule (reveal, skeleton pulse, toast bar, page-in) must sit inside a no-preference block
        preg_match_all('/@media \(prefers-reduced-motion:no-preference\)\{(.*?)\n\}/s', $motion, $blocks);
        $optIn = implode("\n", $blocks[1]);
        foreach (['animation:page-in', 'animation:skeleton-pulse', 'animation:toast-run', '[data-reveal]{opacity:0'] as $needle) {
            $this->assertStringContainsString($needle, $optIn, "$needle must only run for users who allow motion");
        }
        $code = preg_replace('#/\*.*?\*/#s', '', $motion);                       // comments may mention the word
        $this->assertStringNotContainsString('gradient', $code, 'skeletons pulse, they do not shimmer with gradients');
        $this->assertStringContainsString('@media (prefers-reduced-motion:reduce)', $motion);
    }

    /** The home page theme (home.css) deliberately uses gold gradients, as the owner asked. This guards the markup of the shop and cart pages, and glass effects everywhere. */
    public function test_storefront_markup_has_no_glass_or_gradient_decoration(): void
    {
        foreach (['home', 'shop', 'cart'] as $route) {
            $html = $this->get(route($route))->getContent();
            $this->assertStringNotContainsString('backdrop-blur', $html, "$route: no glassmorphism");
            $this->assertStringNotContainsString('bg-gradient', $html, "$route: no gradients");
            $this->assertStringNotContainsString('border-gold', $html, "$route: no decorative gold borders");
        }
    }

    public function test_js_never_hides_content_when_scripting_is_unavailable(): void
    {
        $css = File::get(resource_path('css/motion.css'));

        // reveal-hiding is scoped to html.js, which only an executing script can add
        $this->assertMatchesRegularExpression('/\.js \[data-reveal\]\{opacity:0/', $css);
        $this->assertDoesNotMatchRegularExpression('/(^|\})\s*\[data-reveal\]\{opacity:0/', $css);
        $this->assertStringContainsString("classList.add('js')", $this->get(route('home'))->getContent());
    }

    public function test_home_sections_reveal_but_the_hero_never_hides(): void
    {
        $this->seed(\Database\Seeders\AttributeSeeder::class);
        $this->seed(\Database\Seeders\SampleCatalogSeeder::class);
        $this->seed(\Database\Seeders\SampleCmsSeeder::class);

        $html = $this->get(route('home'))->getContent();
        $this->assertStringContainsString('data-reveal', $html);
        $this->assertStringContainsString('data-reveal-group', $html);                             // product rows stagger card by card
        // the first section (hero) must not carry data-reveal: it is above the fold
        $firstSection = strpos($html, '<section');
        $this->assertStringNotContainsString('data-reveal', substr($html, strrpos(substr($html, 0, $firstSection), '<main'), $firstSection - strrpos(substr($html, 0, $firstSection), '<main')));
    }
}
