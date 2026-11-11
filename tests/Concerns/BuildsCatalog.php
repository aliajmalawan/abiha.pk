<?php

namespace Tests\Concerns;

use App\Models\Attribute;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use Illuminate\Support\Str;

trait BuildsCatalog
{
    protected function product(array $attrs = []): Product
    {
        $name = $attrs['name'] ?? 'Test Product '.Str::random(4);

        return Product::create(array_merge([
            'name' => $name, 'slug' => Str::slug($name).'-'.Str::random(4), 'sku' => 'T-'.Str::upper(Str::random(6)),
            'price' => 1000, 'stock' => 5, 'is_active' => true,
        ], $attrs));
    }

    /** Product with S/M/L variants; L has its own price. Returns [product, [size => variant]]. */
    protected function variantProduct(): array
    {
        $p = $this->product(['price' => 2000, 'has_variants' => true, 'stock' => 0]);
        $sizeAttr = Attribute::firstOrCreate(['slug' => 'size'], ['name' => 'Size']);
        $colorAttr = Attribute::firstOrCreate(['slug' => 'color'], ['name' => 'Colour', 'type' => 'color']);
        $black = $colorAttr->values()->firstOrCreate(['value' => 'Black'], ['color_hex' => '#101010']);
        $v = [];
        foreach (['S' => [null, 3], 'M' => [null, 0], 'L' => [2500, 4]] as $size => [$price, $stock]) {
            $v[$size] = ProductVariant::create(['product_id' => $p->id, 'sku' => $p->sku.'-'.$size, 'price' => $price, 'stock' => $stock]);
            $v[$size]->attributeValues()->sync([$sizeAttr->values()->firstOrCreate(['value' => $size])->id, $black->id]);
        }

        return [$p, $v];
    }

    protected function shipping(int $cost = 250, ?int $freeOver = null): ShippingMethod
    {
        return ShippingMethod::updateOrCreate(['code' => 'standard'], ['name' => 'Standard', 'cost' => $cost, 'free_over' => $freeOver, 'is_active' => true]);
    }

    protected function coupon(array $attrs = []): Coupon
    {
        return Coupon::create(array_merge(['code' => 'TEST10', 'type' => 'percent', 'value' => 10, 'min_subtotal' => 0], $attrs));
    }

    /** A coupon whose total-usage limit is already exhausted (one recorded redemption). */
    protected function exhaustedCoupon(string $code = 'USED'): \App\Models\Coupon
    {
        $coupon = $this->coupon(['code' => $code, 'usage_limit' => 1]);
        $order = \App\Models\Order::create([
            'number' => 'ABH-OLD-'.Str::upper(Str::random(4)), 'email' => 'old@example.com', 'payment_method' => 'cod', 'shipping_method_name' => 'Standard',
            'subtotal' => 1000, 'total' => 900, 'access_token' => Str::random(40),
        ]);
        \App\Models\CouponUsage::create(['coupon_id' => $coupon->id, 'order_id' => $order->id, 'email' => 'old@example.com', 'discount_amount' => 100]);

        return $coupon;
    }

    /** Place a guest order through the real checkout. Returns [Order, Product]. */
    protected function placeGuestOrder(string $method = 'cod', int $price = 1000, int $qty = 2, array $over = []): array
    {
        $this->shipping(250);
        $p = $this->product(['price' => $price, 'stock' => 10]);
        $this->addToCart($p, null, $qty);
        $this->post(route('checkout.store'), $this->checkoutPayload(['payment_method' => $method] + $over));

        return [\App\Models\Order::latest('id')->firstOrFail(), $p];
    }

    protected function addToCart(Product $p, ?int $variantId = null, int $qty = 1)
    {
        return $this->postJson(route('cart.add'), ['product_id' => $p->id, 'variant_id' => $variantId, 'quantity' => $qty]);
    }

    protected function checkoutPayload(array $over = []): array
    {
        // Bank transfer is only offered once the owner has saved account details; tests that pay by transfer set some (clearly fake) ones.
        if (($over['payment_method'] ?? null) === 'bank_transfer') {
            \App\Models\Setting::put('bank.details', \App\Models\Setting::get('bank.details') ?: 'TEST BANK, account 0000-0000 (test data)');
        }

        return array_merge([
            'name' => 'Test Buyer', 'phone' => '03001234567', 'email' => 'buyer@example.com', 'province' => 'Punjab', 'city' => 'Lahore',
            'address' => 'House 1, Street 2, Sample Town', 'shipping_method' => 'standard', 'payment_method' => 'cod',
        ], $over);
    }
}
