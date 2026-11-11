# ABIHA.PK: final QA report

Date: 10 Nov 2026. Environment: Windows 11, XAMPP PHP 8.2, MySQL (MariaDB), Edge (Chromium) driven through the DevTools protocol. Every number below was produced by running the tests, not estimated.

## 1. Result in one line

All automated PHP tests pass on **SQLite and MySQL**, and the real-browser runner passes **100 of 100 checks at each of nine screen sizes (900 of 900)**, including the eight sizes the brief lists. Nothing is claimed that was not run. What was **not** run (and cannot be from a local computer) is in section 6.

## 2. Automated PHP tests

Command: `php artisan test` (SQLite, in memory) and the same with `DB_CONNECTION=mysql DB_DATABASE=abiha_pk_test DB_USERNAME=root` (MySQL).

| Database | Result |
|---|---|
| SQLite | **552 passed, 1 skipped** (4,700 assertions) |
| MySQL | **553 passed, 0 skipped** (4,705 assertions) |

The one skipped test is a database-specific check that does not apply to SQLite; it runs and passes on MySQL.

Coverage by area (test files in `tests/Feature`): authentication and the customer account · admin access, **six admin roles** and server-side permission checks · product and category CRUD · variants and inventory · image upload, validation, ordering and transparent logos · search, filters and sorting · wishlist · cart and persistence · coupons (percentage, fixed, limits, **product/category restrictions**) · checkout and Pakistani phone validation · order creation, stock locking, cancellation and restocking · payments (cash on delivery, bank transfer with receipt upload, card gateway disabled state, webhook signatures, idempotency, no downgrade of a paid order) · shipping rules, estimates, tracking, courier layer · **order emails** · notifications · menus, shipments and payment history · dashboard figures · homepage builder and layouts · SEO (sitemap, robots, structured data) · performance and image variants · security hardening (CSRF, XSS, injection, uploads, rate limits, secrets) · deployment checks.

## 3. Real-browser tests (every storefront and admin page)

Command: `node tests/browser/qa.mjs <url> --config qa.json --only <size>` against a scratch database. The 1440 and 360 px runs were repeated after the last two changes (hero, resizing) and stayed at 100 / 100. Each size runs about 100 checks: every public page and every admin page loads without errors or horizontal scrolling; header, mega menu, search, quick view, filters, sorting and pagination; product variants (SKU, price and stock follow the chosen size/colour; a sold-out variant cannot be chosen and the server refuses it); size guide; gallery and zoom; **sticky add-to-bag bar on phones**; cart quantities, stock limits, coupons, drawer; guest-to-account cart merge; checkout and order confirmation; order tracking; customer sign-in/out; admin product form, photo reordering and saving; homepage block editing.

| Size (px) | Device class | Result |
|---|---|---|
| 360 | small phone | 100 / 100 |
| 390 | phone | 100 / 100 |
| 430 | large phone | 100 / 100 |
| 768 | tablet | 100 / 100 |
| 820 | tablet | 100 / 100 |
| 1024 | small laptop | 100 / 100 |
| 1280 | laptop | 100 / 100 |
| 1440 | desktop | 100 / 100 |
| 1920 | wide desktop | 100 / 100 |

## 4. Problems found by this round of testing and fixed

1. **Roles**: only four roles existed; the brief asks for six admin roles. Added Super Admin, Admin, Order Manager, Product Manager, Inventory Manager, Content Manager, each tested (allowed pages and refused actions).
2. **No emails**: order confirmation, payment received and shipped / delivered / cancelled / returned emails now exist, with an optional new-order email to the owner. A mail failure never blocks an order (tested with an unreachable SMTP server).
3. **Coupon restrictions** (products / categories) were missing; added with real pivot tables, server-side checks and tests, including "the discount never exceeds the qualifying items".
4. **Mobile sticky add-to-bag** bar was missing; added and tested in a real browser (hidden on desktop).
5. **Admin menus**, **shipments list** and **payment history** were missing; added with permissions and tests.
6. **Admin product form at laptop sizes**: the Save button was in a column taller than the screen, and the photo-reorder buttons overlapped in narrow cards. Save is now a bar fixed to the bottom of the screen; the buttons wrap. (Found by the 1024 and 1280 runs; both re-run clean.)
7. **Email text**: payment method showed "Cod" instead of "Cash on Delivery" (found by a test).
8. **Footer "My account"** link for visitors who are signed out now goes straight to sign-in (found by the link-crawl test).
9. **Test hardening**: the cart quantity check waits for the page to reload before the next click instead of using fixed delays (it was timing-sensitive on slow machines).
10. **Hero banner**: a finished banner design (a picture that already has its own words) was darkened, cropped and covered by the headline. Banners now have the option "Show the headline, text and button on top of the picture"; unticked, the picture is shown whole and unveiled with only the button over it. On phones it is cropped to 4:3 towards the right so the people stay in view.
11. **Picture quality**: the resized WebP copies of photographs had coloured specks along sharp edges (hair, outlines) because of GD's bicubic resize. They are now resized with `imagecopyresampled`, and the existing copies were rebuilt. Checked by eye on a real banner; there is no automated test for the specks (a lossy-compression test could not separate the old and new results).
12. Earlier in the project (see git history/notes): transparent logo background haze fixed and image URLs versioned so browsers drop stale pictures; payments, shipping, account, dashboard and product-management gaps closed.

## 5. Known issues and limits

- Card payments are **disabled** (no provider connected): tested end to end with a clearly marked test double, never against a real gateway.
- No real courier is connected: the integration layer is tested with a test double.
- Emails were tested with Laravel's mail fake and a failing transport, **not** through a real SMTP mailbox.
- Photos are reordered with arrow buttons, not drag and drop.
- There is no separate `inventory` table (stock is on products/variants plus the `stock_movements` ledger), by design.
- The sample catalogue has no photos, so cards show "Image coming soon" until real photos are uploaded.

## 6. What was not and could not be verified here

- Behaviour on the **real cPanel server**: PHP version, `.htaccess`, permissions, cron, SSL, DNS and the live domain (see docs/DEPLOYMENT.md for the checklist and the preflight command).
- A live **card payment**, a live **courier booking** and a live **email** (need the owner's accounts).
- **Core Web Vitals on the live site** (measured in a local lab only; run Lighthouse after launch).
- Real devices and browsers other than Chromium (Safari, Firefox); the layouts use standard CSS and were checked at the sizes above.

## 7. Pending third-party credentials

| Item | What is needed | Where it goes |
|---|---|---|
| Card gateway | merchant account, a small provider driver, `CARD_*` keys | `.env` (docs/PAYMENTS.md) |
| SMTP mailbox | host, port, user, password, sender address | `.env` (docs/DEPLOYMENT.md §7) |
| Courier API (optional) | account and API documentation | `config/shipping.php` (docs/SHIPPING.md) |
| Bank details | real account title and number | Admin → Payments |

## 8. Production deployment requirements

PHP 8.2+ with the extensions in docs/DEPLOYMENT.md §2, MySQL, HTTPS, a mailbox, a document-root layout from §6 (split or single), `php artisan migrate --force`, `php artisan db:seed --class=ProductionSeeder --force`, `php artisan abiha:prepare-storage`, `php artisan optimize`, `php artisan abiha:preflight`, a first administrator (`php artisan abiha:create-admin`), nightly backups (§14). Do not run the development seeder on the live site.
