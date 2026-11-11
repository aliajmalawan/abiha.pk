<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

class CartPersistenceAndContentTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    public function test_a_guest_cart_is_stored_in_the_database_with_an_opaque_token(): void
    {
        $p = $this->product(['stock' => 5]);
        $this->addToCart($p, null, 2);

        $cart = Cart::firstOrFail();
        $this->assertNull($cart->user_id);
        $this->assertSame(40, strlen($cart->token));
        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id, 'product_id' => $p->id, 'quantity' => 2]);
        $this->assertSame(['cart_id', 'created_at', 'id', 'product_id', 'quantity', 'updated_at', 'variant_id'], collect(\Illuminate\Support\Facades\Schema::getColumnListing('cart_items'))->sort()->values()->all());   // no price columns
    }

    public function test_a_signed_in_customers_cart_survives_a_new_session(): void
    {
        $user = User::factory()->create();
        $p = $this->product(['stock' => 5]);

        $this->actingAs($user)->postJson(route('cart.add'), ['product_id' => $p->id, 'quantity' => 2])->assertOk();
        $this->flushSession();                                                         // "another device"

        $this->actingAs($user)->get(route('cart'))->assertOk()->assertSee($p->name);
        $this->assertSame(2, app(\App\Services\Cart\CartService::class)->count());
    }

    public function test_guest_cart_merges_into_the_account_on_login_with_stock_caps(): void
    {
        $this->seedRoles();
        $user = $this->userWithRole('customer', ['password' => 'Secret123']);
        $a = $this->product(['stock' => 3]);
        $b = $this->product(['stock' => 10]);

        // the customer already has 2 of A in their account cart
        $this->actingAs($user)->postJson(route('cart.add'), ['product_id' => $a->id, 'quantity' => 2]);
        auth()->logout();
        $this->flushSession();

        // as a guest they add 2 of A and 4 of B
        $this->postJson(route('cart.add'), ['product_id' => $a->id, 'quantity' => 2]);
        $this->postJson(route('cart.add'), ['product_id' => $b->id, 'quantity' => 4]);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'Secret123'])->assertRedirect();

        $lines = app(\App\Services\Cart\CartService::class)->lines()->keyBy(fn ($l) => $l->product->id);
        $this->assertSame(3, $lines[$a->id]->quantity);                                // 2 + 2 capped at stock 3
        $this->assertSame(4, $lines[$b->id]->quantity);
        $this->assertSame(0, Cart::whereNull('user_id')->count());                     // guest cart removed
    }

    public function test_cart_quantity_is_clamped_when_stock_drops(): void
    {
        $p = $this->product(['stock' => 5]);
        $this->addToCart($p, null, 5);
        $p->update(['stock' => 2]);

        $summary = app(\App\Services\Cart\CartService::class)->summary();
        $this->assertSame(2, $summary->lines->first()->quantity);
        $this->assertNotEmpty($summary->notices());
    }

    public function test_reviews_stay_hidden_until_approved(): void
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $p = $this->product();

        $this->actingAs($user)->post(route('reviews.store', $p), ['rating' => 5, 'title' => 'Great', 'body' => 'Really lovely piece, well made.'])->assertSessionHasNoErrors();
        $review = Review::firstOrFail();
        $this->assertFalse($review->is_approved);
        $this->get(route('product', $p))->assertDontSee('Really lovely piece');

        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)->patch(route('admin.reviews.approve', $review))->assertSessionHasNoErrors();
        $this->get(route('product', $p))->assertSee('Really lovely piece');

        $this->actingAs($admin)->delete(route('admin.reviews.destroy', $review));
        $this->assertSame(0, Review::count());
    }

    public function test_reviews_validate_rating_and_length(): void
    {
        $this->actingAs(User::factory()->create())->post(route('reviews.store', $this->product()), ['rating' => 9, 'body' => 'short'])->assertSessionHasErrors(['rating', 'body']);
    }

    public function test_contact_form_saves_messages_and_blocks_bots(): void
    {
        $ok = ['name' => 'Sam', 'email' => 'sam@example.com', 'message' => 'Where is my order please?'];

        $this->post(route('contact.send'), $ok)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contact_messages', ['email' => 'sam@example.com', 'status' => 'new']);

        $this->post(route('contact.send'), $ok + ['website' => 'http://spam.example'])->assertSessionHasErrors('website');   // honeypot
        $this->assertSame(1, \App\Models\ContactMessage::count());
    }

    public function test_messages_are_rendered_as_plain_text_in_admin(): void
    {
        $this->seedRoles();
        $this->post(route('contact.send'), ['name' => 'Xavier', 'email' => 'x@example.com', 'message' => '<script>alert("hi")</script> hello there']);
        $msg = \App\Models\ContactMessage::firstOrFail();

        $this->actingAs($this->userWithRole('admin'))->get(route('admin.messages.show', $msg))->assertOk()
            ->assertDontSee('<script>alert("hi")</script>', false)->assertSee('hello there');
        $this->assertSame('read', $msg->fresh()->status);
    }

    public function test_newsletter_resubscribe_and_export_neutralises_formulas(): void
    {
        $this->seedRoles();
        $this->postJson(route('newsletter'), ['email' => 'Fan@Example.com'])->assertOk();
        $this->postJson(route('newsletter'), ['email' => 'fan@example.com'])->assertOk();
        \App\Models\Newsletter::create(['email' => '=cmd@example.com']);
        $this->assertSame(2, \App\Models\Newsletter::count());                         // lower-cased, no duplicate

        $csv = $this->actingAs($this->userWithRole('admin'))->get(route('admin.newsletter.export'))->streamedContent();
        $this->assertStringContainsString('fan@example.com', $csv);
        $this->assertStringContainsString("'=cmd@example.com", $csv);                  // formula neutralised
    }
}
