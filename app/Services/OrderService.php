<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Cart\CartException;
use App\Services\Cart\CartService;
use App\Services\Inventory\InsufficientStockException;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        private readonly CartService $cart,
        private readonly CouponService $coupons,
        private readonly InventoryService $inventory,
    ) {
    }

    /**
     * Turn the current cart into an order, atomically. Money is computed ONLY from the database:
     * nothing the browser submits (prices, totals, discount) is read here.
     *
     * Inside ONE transaction: stock rows are locked and decremented through the inventory ledger,
     * the coupon is locked, re-validated and recorded, and prices/names/address are snapshotted.
     * Any failure rolls everything back (stock, coupon use, order rows).
     *
     * @param  array{name:string,phone:string,email:string,province:string,city:string,address:string,postal_code?:?string,notes?:?string}  $customer
     *
     * @throws CartException
     */
    public function place(array $customer, ShippingMethod $shipping, string $paymentMethod, ?User $user = null): Order
    {
        try {
            return DB::transaction(function () use ($customer, $shipping, $paymentMethod, $user) {
                $summary = $this->cart->summary($shipping, $customer['email'], ['province' => $customer['province'], 'city' => $customer['city']]);
                if ($summary->isEmpty()) {
                    throw new CartException('Your bag is empty.');
                }
                if (! $summary->shippingAvailable) {
                    throw new CartException(''.$shipping->name.' is not available to '.$customer['city'].'. Please choose another delivery method.');
                }
                if ($summary->notices()) {
                    throw new CartException('Some items in your bag changed. Please review your bag and try again.');
                }

                // Coupon: lock the row so limited coupons cannot be over-redeemed by concurrent checkouts.
                $coupon = null;
                if ($summary->coupon) {
                    $coupon = Coupon::query()->lockForUpdate()->findOrFail($summary->coupon->id);
                    $this->coupons->check($coupon, $summary->subtotal, $user, $customer['email'], $summary->lines);
                }

                $order = Order::create([
                    'number' => $this->makeNumber(),
                    'user_id' => $user?->id,
                    'email' => mb_strtolower($customer['email']),
                    'status' => 'pending',
                    'payment_status' => 'unpaid',
                    'payment_method' => $paymentMethod,
                    'shipping_method_id' => $shipping->id,
                    'shipping_method_name' => $shipping->name,
                    'shipping_eta' => app(ShippingService::class)->etaFor($shipping, $customer['province'], $customer['city']),
                    'subtotal' => $summary->subtotal,
                    'discount' => $summary->discount,
                    'shipping_cost' => $summary->shipping,
                    'total' => $summary->total,
                    ...$this->taxFor($summary->subtotal - $summary->discount),
                    'coupon_id' => $coupon?->id,
                    'coupon_code' => $coupon?->code,
                    'notes' => $customer['notes'] ?? null,
                    'access_token' => Str::random(40),
                    'ip_address' => request()?->ip(),
                ]);

                $order->shippingAddress()->create(['type' => 'shipping'] + collect($customer)->only(['name', 'phone', 'province', 'city', 'address', 'postal_code'])->all());

                // Stable lock order (by key) prevents deadlocks between concurrent orders.
                foreach ($summary->lines->sortBy('key') as $line) {
                    $this->inventory->adjust($line->variant ?? $line->product, -$line->quantity, 'sale', $order, $user, 'Order '.$order->number);
                    Product::query()->whereKey($line->product->id)->increment('sold_count', $line->quantity);

                    $order->items()->create([
                        'product_id' => $line->product->id, 'variant_id' => $line->variant?->id,
                        'name' => $line->product->name, 'sku' => $line->sku(), 'options' => $line->options(),
                        'image' => $line->product->images->first()?->path,
                        'unit_price' => $line->unitPrice, 'quantity' => $line->quantity, 'total' => $line->total(),
                    ]);
                }

                if ($coupon) {
                    CouponUsage::create(['coupon_id' => $coupon->id, 'order_id' => $order->id, 'user_id' => $user?->id, 'email' => $order->email, 'discount_amount' => $summary->discount]);
                }

                $payment = Payment::create(['order_id' => $order->id, 'provider' => $paymentMethod, 'status' => 'pending', 'amount' => $order->total]);
                \App\Models\PaymentTransaction::record($order, $paymentMethod, 'initiated', 'pending', null, (int) $order->total, $payment);
                OrderStatusHistory::create(['order_id' => $order->id, 'status' => 'pending', 'note' => 'Order placed', 'user_id' => $user?->id]);

                $this->cart->clear();

                return $order;
            });
        } catch (InsufficientStockException $e) {
            throw new CartException('Sorry, an item in your bag just sold out or has limited stock. Please review your bag.');
        }
    }

    /**
     * Move an order to a new status, enforcing the allowed transitions. Cancelling returns stock to
     * inventory and releases the coupon redemption.
     *
     * @throws CartException
     */
    public function changeStatus(Order $order, string $status, ?User $by = null, ?string $note = null, bool $restock = false): Order
    {
        return DB::transaction(function () use ($order, $status, $by, $note, $restock) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $order->canTransitionTo($status)) {
                throw new CartException("An order that is {$order->status} cannot be changed to {$status}.");
            }

            if ($status === 'cancelled') {
                $this->restock($order, $by);
            } elseif ($status === 'returned' && $restock) {
                $this->restock($order, $by, 'return', 'returned', false);   // goods are back on the shelf; the coupon redemption stays used
            }

            $before = $order->status;
            $order->update(['status' => $status]);
            OrderStatusHistory::create(['order_id' => $order->id, 'status' => $status, 'note' => $note, 'user_id' => $by?->id]);
            $this->recordShipmentStatus($order, $status, $by);
            ActivityLogger::log('order.status_changed', $order, ['from' => $before, 'to' => $status, 'note' => $note]);

            return $order;
        });
    }

    /** Customer self-service cancellation (unpaid, unshipped orders only). */
    public function cancel(Order $order, ?User $by = null, ?string $note = null): Order
    {
        if (! $order->isCancellable()) {
            throw new CartException('This order can no longer be cancelled online. Please contact us.');
        }

        return $this->changeStatus($order, 'cancelled', $by, $note ?? 'Cancelled by customer');
    }

    /** Keep the shipment and its timeline in step with the order. Only real status changes are recorded; nothing is guessed. */
    private function recordShipmentStatus(Order $order, string $status, ?User $by): void
    {
        if (! in_array($status, ['shipped', 'delivered', 'returned'], true)) {
            return;
        }
        $shipment = $order->saveShipment(['status' => $status] + ($status === 'shipped' ? ['shipped_at' => now()] : []) + ($status === 'delivered' ? ['delivered_at' => now()] : []));
        $shipment->logEvent($status, 'Order marked as '.$status.' by '.($by ? 'staff' : 'the system'), $by ? 'staff' : 'system', $by?->id);
    }

    public function setTracking(Order $order, ?string $number, ?string $url, ?User $by = null, ?string $courier = null): void
    {
        $number = $number ?: null;
        $url = $url ?: null;
        $courier = $courier ?: null;
        if (! $order->shipment && ! $number && ! $url && ! $courier) {
            return;
        }
        $shipment = $order->saveShipment(['tracking_number' => $number, 'tracking_url' => $url, 'courier' => $courier]);
        $shipment->logEvent('details_updated', 'Courier details saved by staff'.($courier ? ": {$courier}" : '').($number ? ", tracking {$number}" : ''), 'staff', $by?->id);
        ActivityLogger::log('order.tracking_updated', $order, ['tracking_number' => $number, 'courier' => $courier]);
    }

    /**
     * Tax snapshot for the order. Prices are tax-INCLUSIVE, so tax is the portion already contained in
     * (subtotal - discount): amount × rate / (100 + rate). Shipping is excluded. Does not change the total.
     *
     * @return array{tax_amount:int, tax_rate:?float, tax_label:?string}
     */
    private function taxFor(int $taxable): array
    {
        $rate = (float) Setting::get('tax.rate', 0);
        if (! Setting::get('tax.enabled') || $rate <= 0) {
            return ['tax_amount' => 0, 'tax_rate' => null, 'tax_label' => null];
        }

        return ['tax_amount' => (int) round($taxable * $rate / (100 + $rate)), 'tax_rate' => $rate, 'tax_label' => (string) Setting::get('tax.label', 'Tax')];
    }

    private function restock(Order $order, ?User $by, string $reason = 'cancellation', string $word = 'cancelled', bool $freeCoupon = true): void
    {
        foreach ($order->items()->get() as $item) {
            $target = $item->variant_id ? \App\Models\ProductVariant::query()->find($item->variant_id) : Product::query()->find($item->product_id);
            if ($target) {
                $this->inventory->adjust($target, $item->quantity, $reason, $order, $by, 'Order '.$order->number." {$word}");
                Product::query()->whereKey($item->product_id)->where('sold_count', '>=', $item->quantity)->decrement('sold_count', $item->quantity);
            }
        }
        if ($freeCoupon) {
            CouponUsage::query()->where('order_id', $order->id)->delete();   // frees the redemption
        }
    }

    private function makeNumber(): string
    {
        do {
            $number = 'ABH-'.now()->format('ymd').'-'.strtoupper(Str::random(5));
        } while (Order::query()->where('number', $number)->exists());

        return $number;
    }
}
