# Payments

ABIHA supports three ways to pay. Money is **never** marked received unless it was genuinely verified: by staff for cash and bank transfer, by the payment provider for cards. Nothing in the app simulates a payment, and no merchant credential is built in.

| Method | Code | Offered at checkout when | Who marks it paid |
|---|---|---|---|
| Cash on Delivery | `cod` | enabled in Admin → Payments | Staff, after the rider returns the cash |
| Bank Transfer | `bank_transfer` | enabled **and** account details are saved | Staff, after checking the bank statement |
| Debit / Credit Card | `card` | a provider driver and its credentials are configured **and** it is enabled | The provider's verified webhook |

Order and payment states: an order starts `pending` / `unpaid`. Payment status is one of `unpaid`, `pending` (card, waiting for the provider), `awaiting_verification` (bank transfer reported), `paid`, `failed`, `refunded`. An order becomes `confirmed` when its payment is verified.

## Cash on Delivery
The order is saved as `pending`, payment method `cod`, a `pending` payment row. Admin → Orders → open the order → **Mark as paid** once the cash is collected (amount must equal the order total). The message customers see at checkout is edited in Admin → Payments.

## Bank Transfer
1. Admin → Payments: enter your real account details (bank, account title, number/IBAN). Bank transfer stays hidden at checkout until you do.
2. After ordering, the customer sees those details and can send a **bank reference**, a **receipt photo/PDF** (JPG, PNG or PDF, up to 4 MB), or both.
3. The order shows **Awaiting verification**. It is *not* paid.
4. Staff open the order (Orders → the order → Payment), click **View receipt**, compare it with the bank statement, then **Mark as paid** (exact amount) or **Payment not received**.

Receipts are stored on the private disk (`storage/app/private/payment-proofs/<order id>/<random name>`), outside the web root. They can only be opened by staff holding the *verify payments* permission, through a protected route. The file type is read from the file's content, not its name. A reference already used on another order is refused.

## Card payments (provider independent)
Card details are typed **on the provider's hosted page**. The shop never receives, stores or logs a card number or CVV (it has no field for them).

Card payments are **disabled** until all of this exists. Admin → Payments → *Card payments* lists whatever is still missing.

1. A merchant account with a card gateway of your choice (needs business paperwork; the app cannot create one for you).
2. A **driver class**: a small class implementing `App\Services\Payments\CardProviderDriver`, written against that gateway's documentation:
   - `createCheckout(Order $order, string $returnUrl): CardCheckout`: creates the hosted checkout using `$order->total` (server side) and returns the redirect URL plus the provider's transaction id.
   - `parseWebhook(Request $request): ?VerifiedPayment`: **must** verify the signature with the webhook secret (`WebhookSignature::verifyHmac` is provided) **and** confirm the transaction with the provider's API; return `null` for anything unverifiable. Report `paid`, `failed`, or `pending` through `VerifiedPayment`.
3. Put these in the server's `.env` (never in code, never in the admin):
   ```
   CARD_PAYMENT_DRIVER=App\Payments\YourProviderDriver
   CARD_API_KEY=...
   CARD_API_SECRET=...
   CARD_WEBHOOK_SECRET=...
   ```
   then `php artisan config:clear` (or re-cache).
4. In the provider's dashboard, set the webhook address to `https://YOUR-DOMAIN/webhooks/payments/card`.
5. Admin → Payments: the Card section now says **Ready**. Tick *Debit / Credit Card* and save.
6. Test in the provider's **sandbox** first, with their test cards, before going live.

How a card payment flows: checkout creates the order (`pending`) → the customer is sent to the provider → a `pending` payment row is saved with the provider's transaction id → the provider calls the webhook → the server verifies it → the order becomes `paid` / `failed` / stays `pending`. The browser returning to the shop never marks anything paid. A paid order cannot be downgraded by a late or repeated notification, an amount that differs from the order total is rejected, and a failed payment can be retried from the confirmation page.

## What is verified, and what is not
Automated tests cover the whole flow with a **test double** driver (never registered in the app). A real provider has not been connected, so a live card payment has **not** been tested here. Test with the provider's sandbox before taking real payments.
