<?php

namespace Tests\Feature;

use App\Models\HomeSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** The "Create your account" banner on the home page: for visitors who are not signed in, edited from the homepage manager. */
class AccountBannerTest extends TestCase
{
    use BuildsUsers, RefreshDatabase;

    private function banner(array $over = []): HomeSection
    {
        HomeSection::query()->delete();

        return HomeSection::create($over + ['type' => 'account', 'title' => 'Join ABIHA today', 'subtitle' => 'Everything in one place.', 'settings' => [], 'sort_order' => 1, 'is_active' => true]);
    }

    public function test_a_visitor_sees_the_banner_with_sign_up_and_sign_in_and_the_admin_text(): void
    {
        $this->banner();
        $html = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringContainsString('Join ABIHA today', $html);
        $this->assertStringContainsString('Everything in one place.', $html);
        $this->assertStringContainsString('href="'.route('register').'"', $html);
        $this->assertStringContainsString('href="'.route('login').'"', $html);
        foreach (['Save favourites', 'Track every order', 'Faster checkout', 'Share your review'] as $tile) {
            $this->assertStringContainsString($tile, $html);
        }
        $this->assertStringNotContainsString('deal', strtolower(substr($html, strpos($html, 'class="acct"'), 2500)), 'it promises no discount');
    }

    public function test_a_signed_in_customer_does_not_see_it(): void
    {
        $this->banner();
        $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()->assertDontSee('Join ABIHA today');
    }

    public function test_a_hidden_banner_is_not_shown_and_the_admin_can_add_the_block(): void
    {
        $this->banner(['is_active' => false]);
        $this->get(route('home'))->assertDontSee('Join ABIHA today');

        $this->seedRoles();
        $this->actingAs($this->userWithRole('admin'))->post(route('admin.home.store'), ['type' => 'account', 'title' => 'Another'])->assertSessionHasNoErrors();
        $this->assertSame(1, HomeSection::where('title', 'Another')->count());
    }
}
