# Slice C services (cart, orders, payments) â€” signatures for other slices

All static. Rows are arrays straight from MySQL. `$user`/`$by`/`$staff` = a users row, a user id, or null.
Customer-safe failures throw `App\Services\Cart\CartException` (show `getMessage()`; old pages used the `cart`/`order`/`status`/`payment` error keys).

## Cart â€” `App\Services\Cart`
- `add(array|int $product, ?int $variantId, int $qty): void` (products row or id) â€” old `CartService::add` (stock caps, 20/line, variant required for variant products). Throws CartException.
- `update(string $key, int $qty)`, `remove(string $key)`, `clear()`, `applyCoupon(string $code)`, `removeCoupon()`
- `mergeGuest(int $userId): void` â€” call right after `Auth::login()` (login + register). Uses session `cart_token`.
- `count(): int`, `current(): ?array`, `lines(): array`, `summary(?array $method = null, ?string $email = null, ?array $destination = null): array`
  (keys: lines, subtotal, discount, shipping, total, coupon, shipping_method, coupon_error, shipping_available, count, notices, empty)
- `prices(array $product, ?array $variant): [unit, compare|null]`, `variantLabels(array $variantIds): [id => "Size: M / Colour: Black"]`
- Wishlist "move to cart": `Cart::add($product['id'], null, 1)` inside try/catch CartException.

## Orders â€” `App\Services\OrderService`
- Constants `STATUSES`, `PAYMENT_STATUSES`, `TRANSITIONS`
- `find(int $id): ?array`, `findByNumber(string): ?array`, `load(array $orderRow): array` â€” adds name/phone/province/city/address/postal_code, `items`, `history` (newest first), `shipment`, `tracking_number`, `tracking_url`, `courier`
- `statusLabel($s)`, `paymentStatusLabel($s)`, `statusTone($s)`, `canTransitionTo(array $order, string $s)`, `isCancellable(array $order)`
- `place(array $customer, array $shippingMethod, string $paymentCode, $user = null, ?string $ip = null): array`
- `changeStatus(array|int $order, string $status, $by = null, ?string $note = null, bool $restock = false): array` (cancel restocks + frees coupon; returned+restock puts goods back; writes history, shipment timeline, activity log `order.status_changed`)
- `cancel(array|int $order, $by = null, ?string $note = null): array` (customer self-service; unpaid & cancellable only)
- `setTracking(array|int $order, ?string $number, ?string $url, $by = null, ?string $courier = null): void`
- `saveShipment(int $orderId, array $attrs): int`, `logShipmentEvent(int $shipmentId, string $status, ?string $desc, string $source = 'system', ?int $userId = null, ?string $location = null): int`
- `addHistory(int $orderId, string $status, ?string $note = null, ?int $userId = null): void`
- After a status change from admin/account call `OrderMailer::statusChanged($order, $status)` (mails shipped/delivered/cancelled/returned only).

## Payments â€” `App\Services\Payments\*`
- `PaymentService::verifyManually($order, $staff, ?string $reference = null, ?int $amount = null, ?string $note = null)` then `OrderMailer::paid($order)`
- `PaymentService::rejectManually($order, $staff, ?string $note)`, `PaymentService::recordRefund($order, $staff, ?string $note)`
- `PaymentService::recordVerified(string $provider, VerifiedPayment $e): bool` (webhook only), `submitTransferReference(array $order, ?string $ref, ?array $file)`
- `PaymentService::record(array $order, $provider, $type, $status, ?$reference, ?$amount, ?int $paymentId, $by, ?array $payload): int` (payment_transactions)
- `PaymentService::PROOF_TYPES`, `PROOF_MAX_BYTES`; proofs live on the private disk: `Uploads::privatePath($payment['proof_path'])` (stream to staff only, with `X-Content-Type-Options: nosniff`)
- `PaymentManager::enabledCodes()`, `enabled()`, `all()` ([code => gateway object]), `find($code)`, `online($code)`, `label($code)`
- `(new CardGateway())->missing(): list<string>` / `isAvailable()` for the admin payments screen; `(new BankTransferGateway())->instructions()`
- Settings used: `payments.enabled` (array of codes), `bank.details`, `cod.note`, `tax.enabled|rate|label`, `mail.notify`

## Inventory â€” `App\Services\Inventory\InventoryService`
- `REASONS`; `adjust(array $productOrVariantRow, int $delta, string $reason, $order = null, $user = null, ?string $note = null): int`
- `adjustProduct(int $id, ...)`, `adjustVariant(int $id, ...)`, `setLevel(array $row, int $level, $user = null, ?string $note = null, string $reason = 'adjustment'): int`
- Throws `InsufficientStockException` below zero. A variant row is recognised by its `product_id` key.

## Coupons / shipping
- `CouponService::validate($code, $subtotal, $user, $email, $lines)`, `check(array $coupon, ...)`, `find(int)`, `findByCode`, `isRestricted`, `eligibleSubtotal($coupon, $lines)`, `customerUses`, `discountFor($coupon, $amount)`, `descendantIds($categoryId)`
- `ShippingService::methods()` (active, each with `rules`), `find($code)`, `default()`, `quote($method, $subtotal, $province, $city): ?int`, `etaFor(...)`, `ruleFor(...)`, `costFor($method, $subtotal)`
- Couriers: `App\Services\Shipping\CourierManager::all()/available()/find()`, interface `CourierProvider` (none registered).

## Mail / alerts
- `OrderMailer::placed($order)`, `paid($order)`, `statusChanged($order, $status)`; templates `emails/order`, `emails/staff-order`
- `StaffNotifier::newOrder(array $order)`, `transferReported(array $order)` (notifications table, StaffAlert format)

## Views for reuse
- `store/partials/order-tracking` (`['order' => OrderService::load(...)]`) â€” the account order page can include it.

