# ABIHA.PK: Architecture

## Stack
| Layer | Choice |
|---|---|
| Backend | PHP 8.2+, Laravel 12, Eloquent ORM, MySQL/MariaDB |
| Views | Blade + anonymous/class components |
| CSS | Tailwind CSS v4 (via Vite), ABIHA design tokens in `resources/css/tokens.css` |
| JS | Alpine.js only (about 20 KB gzipped) |
| Icons | Lucide, inlined SVG via `<x-icon name="…">` (files in `resources/svg/icons`) |
| Sessions / cache / queue | `database` drivers (no Redis needed, which suits cPanel) |

No Node, Vite or Composer is needed on the server. Assets are built locally (`npm run build`) and `public/build` is uploaded.

## Folder map
```
app/
  Http/Controllers/{Storefront,Admin,Auth}   thin controllers
  Http/Middleware                            role / admin guards
  Http/Requests                              FormRequest validation
  Models/                                    Eloquent models
  Policies/                                  authorization
  Services/                                  business logic (cart, pricing, orders, shipping, payments)
  View/Components/                           class-based Blade components (Icon)
config/abiha.php                             brand defaults (no invented business data)
database/{migrations,seeders,factories}
resources/
  css/ (tokens, base, components, app)       design system
  js/app.js                                  Alpine entry
  views/{layouts,partials,components,storefront,admin,auth}
  svg/icons/                                 Lucide subset
routes/{web.php,admin.php}                   storefront / admin (admin.php loaded in bootstrap/app.php)
public/{assets/brand,build}                  web root: logo files, compiled assets
```
Rule of thumb: controllers validate with a FormRequest, call a Service, return a view. Services hold the business rules. Models only describe data and relations.

## Queue-ready
`QUEUE_CONNECTION=database`. Emails (order confirmation, reset) will be queued Jobs/Mailables. On cPanel run one cron every minute:
`* * * * * cd /home/USER/abiha.pk && php artisan schedule:run >> /dev/null 2>&1`
with `queue:work --stop-when-empty` scheduled in `routes/console.php`. Until then jobs can run with `QUEUE_CONNECTION=sync`.

## Local development (XAMPP)
```
php artisan serve --port=8100     # or browse via Apache
npm run dev                       # Vite with HMR (optional); npm run build for production assets
php artisan migrate
```
Database `abiha_pk` (utf8mb4_unicode_ci). Credentials live in `.env` (never committed).

## cPanel deployment (summary; full guide in the final phase)
1. Requires PHP 8.2+ with `mbstring, openssl, pdo_mysql, tokenizer, xml, ctype, fileinfo, curl`.
2. Upload the project **outside** `public_html` and point the domain's document root at `…/abiha.pk/public`.
   If the host forces the document root to the project folder, the root `.htaccess` rewrites to `public/` and blocks `.env`, `vendor` and other internals.
3. Run `composer install --no-dev -o` locally and upload `vendor/` if the host has no SSH. Upload `public/build/`.
4. Set `.env`: `APP_ENV=production`, `APP_DEBUG=false`, real `APP_URL`, DB credentials. Run `php artisan key:generate`, `migrate --force`, `storage:link`, `config:cache`, `route:cache`, `view:cache`.
5. Set `ABIHA_SAMPLE_LABELS=false` at launch.

## Conventions
- All design values come from tokens (`var(--…)` or Tailwind theme names such as `bg-ivory`, `text-gold-text`). No hard-coded hex values in views.
- The logo is only ever rendered through `<x-logo>`, which uses the supplied artwork.
- Placeholder content is flagged with `.sample-flag` and is controlled by `ABIHA_SAMPLE_LABELS`.

## Storefront data flow (Phase 3)
- **Homepage is data-driven.** `home_sections` rows (type, title, subtitle, JSON `settings`, sort order, active flag) are resolved by `HomePageService`; each type renders `resources/views/storefront/sections/{type}.blade.php`. Banners, testimonials, collections and featured categories come from their own tables. The admin phase only needs CRUD forms over these tables.
- **Cart** is session-backed (`CartService`). It stores only `{product, variant, qty}`; prices, stock and availability are re-read from the DB on every call, so the browser can never set a price.
- **Orders** (`OrderService::place`) run in one transaction: stock rows are locked and re-checked, stock decremented, coupon use counted, prices and names snapshotted into `order_items`.
- **Variants:** a product with `has_variants` sells only through `product_variants` (size/colour, own SKU, optional price override, own stock). `Product::variantPayload()` feeds one Alpine component that updates price, stock and SKU.
- **Money** is stored as whole-PKR unsigned integers.

## Data model, integrity and the required-table map
Every table the brief lists exists, with these names and notes:

| Brief | Table | Note |
|---|---|---|
| users, roles, permissions | `users`, `roles`, `permissions` (+ `permission_role`) | customers and staff share `users`; the role decides access |
| categories, products, product_images, product_variants, attributes, attribute_values | same names (+ `attribute_value_product_variant`) | a variant has its own **SKU, stock and optional price**; size and colour are attribute values |
| inventory | the `stock` column on `products` / `product_variants` | stock lives on the sellable row so it can be locked and changed atomically; a separate `inventory` table would be a second copy of the same number that could drift. Every change is logged in the ledger below |
| stock_movements | `stock_movements` | append-only ledger (change, balance after, reason, order, staff member) written in the same transaction as the stock change |
| carts, cart_items, wishlists, wishlist_items, addresses | same names | guest carts are stored by an opaque token and merged on sign-in |
| orders, order_items, order_addresses | same names | prices, names and addresses are **snapshots**; they never change when the catalogue does |
| payments, payment_transactions | same names | `payments` = current state per attempt (unique provider+reference); `payment_transactions` = append-only history of every step (initiated, provider event, transfer reported, verified, rejected, refund recorded). No card data anywhere |
| shipping_methods, shipments, shipment_events | same names (+ `shipping_rules`) | tracking number, courier and link live on `shipments`; `shipment_events` records only things that really happened (staff action, status change, or a courier API answer) |
| coupons, coupon_usages, reviews, banners, collections, pages, settings, newsletters, contact_messages, activity_logs | same names | |
| notifications | `notifications` | in-app alerts for staff (new order, bank transfer to verify) under the bell in the admin header |

**Foreign keys and indexes.** Every relationship is a real foreign key. Order history uses `nullOnDelete` for the customer, product, variant and coupon, and the order rows carry their own snapshots, so history survives even a hard delete. Unique keys: user email, product slug and SKU, variant SKU, order number, coupon code, shipping-method code, payment (provider, reference), one coupon use per order.

**Soft deletes.** `users`, `products`, `categories`, `collections` and `coupons` are archived, not erased (an archived customer cannot sign in, their orders still resolve). A category with products, or a coupon that has been used, cannot be deleted at all. Orders are never deleted.

**Orders and stock.** `OrderService::place` runs in one transaction: it locks the stock rows (in key order, to avoid deadlocks), decrements through `InventoryService` (which refuses to go below zero), locks and re-checks the coupon, writes the order, its items, address, payment and history, and empties the bag. Any failure rolls all of it back. Two buyers of the last unit: the second is refused.

## Payments
See [PAYMENTS.md](PAYMENTS.md) for the full flow and the card setup steps. Summary: methods are `cod`, `bank_transfer` and `card` (`config/payments.php`, switched on in Admin → Payments). `card` is provider independent (`CardGateway` + a `CardProviderDriver` you write) and **disabled** until a driver and `CARD_*` credentials exist. `POST /webhooks/payments/{provider}` only serves registered online gateways; an order is marked paid only after the gateway verifies the signature and confirms with the provider's API, the amount equals the order total, and the event is idempotent (a paid order is never downgraded; pending and failed are recorded). COD and bank transfer never mark an order paid automatically. Bank-transfer receipts are stored on the private disk and served only to staff.

## Shipping
See [SHIPPING.md](SHIPPING.md). `ShippingService` resolves charge, free threshold and delivery estimate (city rule, then province rule, then the method default); the order saves the charge and the estimate it showed. Courier APIs plug in through `App\Services\Shipping\CourierProvider` (registry in `config/shipping.php`, empty by default); until then staff enter courier, tracking number and link by hand.

## Tests
`php artisan test`: cart/stock/variant/coupon/shipping rules, checkout transaction and snapshots, order ownership and guest-tracking access control, wishlist merge, registration role safety, and the webhook pipeline (using a test double, since no provider exists).

## Phase 4: database & back end
**Schema (normalized, FK-constrained, indexed).** `roles`, `permissions`, `permission_role`, `users.role_id`; `categories`, `products`, `product_images`, `attributes`, `attribute_values`, `product_variants` (+ `attribute_value_product_variant`), `collections`; `stock_movements`; `carts`, `cart_items`, `wishlists`, `wishlist_items`; `coupons`, `coupon_usages`, `shipping_methods`; `orders`, `order_addresses`, `order_items`, `order_status_history`, `payments`; `reviews`; `settings`, `home_sections`, `banners`, `testimonials`, `pages`, `newsletters`, `contact_messages`; `activity_logs`. The stock ledger is `stock_movements`.

**Money & trust.** Prices are integers (whole PKR). Nothing the browser sends is used for money: `CartService::summary()` and `OrderService::place()` read prices, stock, coupons and shipping from the database and snapshot them onto the order.

**Transactions.** `OrderService::place` (stock locks + ledger + coupon lock/usage + order rows), `OrderService::changeStatus` (state machine, restock on cancel), `ProductService::save` (product + images + variants + opening stock), `PaymentService::verifyManually` and `InventoryService::adjust` all run in DB transactions with `lockForUpdate`. Stock only ever changes through `InventoryService`, which writes the ledger in the same transaction.

**Access control.** `admin` middleware (active staff role) + `can:<permission>` on every admin route. Roles: admin (all), manager (all but staff management), staff (orders, inventory, reviews, messages), customer (none). `php artisan abiha:create-admin email` creates the first admin; no default credentials exist. Every staff mutation is written to `activity_logs`.

**Admin.** Generic `ResourceController` (banners, pages, testimonials, coupons, shipping, categories, collections) plus custom controllers for products/variants, attributes, inventory, orders, customers, staff, reviews, messages, newsletter, homepage layout, settings, activity log.

**Run the tests on MySQL too.** SQLite ignores column lengths and locking; CI-equivalent run: create a `abiha_pk_test` database, then
`DB_CONNECTION=mysql DB_DATABASE=abiha_pk_test DB_USERNAME=root php artisan test`.

## Phase 5: admin dashboard
**Entry points.** `/admin/login` is a separate staff sign-in (customers' credentials never open it; one generic error; rate-limited). Everything under `/admin` needs an active staff role plus a per-route permission (`reports.view` was added; run `php artisan db:seed --class=RolesAndPermissionsSeeder` after deploying).

**Modules → where they live.** Dashboard (`DashboardController`, SVG charts in `admin/partials/bar-chart`), Products/variants/images, Categories, Collections, Attributes, Inventory (+ ledger), Orders (status machine incl. *Returned*, manual payment verification, refund *recording*, invoices, CSV export), Customers (+ export), Reports (`ReportService`: sales by day/month/year, products, customers, revenue breakdown, orders; all with CSV), Marketing (Coupons, **Sale pricing** bulk tool, Featured flags, Banners, Newsletter), Website (Homepage layout, Pages, Testimonials), **Payments** (enable/disable methods, COD note, bank details), Shipping (+ **city/province rules**), **General settings** (name, logo, favicon, contact, social, currency, tax, email sender, SEO, policies), Staff & roles, Activity log.

**Rules worth knowing.**
- *Revenue* = orders not cancelled/returned; *collected* = the paid part. Reports use Pakistan time (`APP_TIMEZONE=Asia/Karachi`).
- *Shipping resolution*: city rule > province rule > method default; a rule can also mark a method "not offered" there. The server re-checks at order time; the checkout page only previews.
- *Tax* is tax-INCLUSIVE and informational: `orders.tax_amount = (subtotal − discount) × rate ÷ (100 + rate)`; totals never change. Confirm rate/rules with an accountant.
- *Refunds* are records only; no money moves from this app. *Online provider credentials* live in `.env`, never in the admin.
- *Sale pricing* never compounds (products already on sale are skipped); ending a sale restores the original price.
- Search engines are blocked on every non-production environment and whenever "Allow indexing" is off.
- CSV exports stream in chunks and neutralise spreadsheet formulas.

## Phase 6: UI/UX polish & motion
**Principles.** Subtle and professional: transform + opacity only, 150-600 ms, one easing curve. No glassmorphism (blur removed everywhere), no gradients (skeletons pulse instead of shimmering), no decorative gold borders, shadows only on floating surfaces (dialogs, toast). Everything lives in `resources/css/motion.css` plus the behaviours at the end of `resources/js/app.js`.

**Reduced motion.** Every animation is opt-in via `prefers-reduced-motion: no-preference`; a `reduce` block zeroes any remaining transition/animation. Scroll reveal is also skipped in JS. `html.js` gates hiding: with scripting unavailable or failing, nothing is ever hidden (3.5 s failsafe).

**What was added.** Cross-document page transitions (CSS `@view-transition`, ignored where unsupported, with a short fade fallback); scroll reveal (`data-reveal`, `data-reveal-group` for staggering); product-card image cross-fade and PDP gallery cross-fade; button press/success states (`.is-done`, `.is-loading`); skeleton loaders (quick view, cart drawer, search); toast (dismissible, pauses on hover/focus, progress bar); **cart drawer** (`GET /cart/drawer` returns an HTML fragment from `CartService`, so totals are always server-side); mobile menu / search / quick view / drawer as true modal dialogs; shared `<x-empty-state>`; `<x-form-errors>` summary with field links; plain-language inline validation for forms marked `data-validate`; global submit spinner that prevents double submits (bfcache-safe).

**Dialog behaviour (`x-trap`).** Focus moves in, Tab/Shift+Tab loop inside, the page behind stops scrolling, Escape closes, focus returns to the opener. Native `confirm()` is gone from the storefront: use `@submit="$store.confirm.ask($event, {title, message, label})"`.

**Testing.** `tests/Feature/UiPolishTest.php` guards markup and rules (labelled dialogs, no native dialogs, reduced-motion CSS, no blur/gradients, server-side drawer totals). `node tests/browser/run.mjs` drives a real browser (see `tests/browser/README.md`) for focus trap, Escape, scroll lock, drawer, reveal, validation and spinners, in normal and reduced-motion modes. Tailwind v4 scans the whole project, so `app.css` excludes `tests/`, `docs/`, `storage/` and `vendor/` from its source scan.

## Phase 7: security

**Layers, outermost first**
- `.htaccess` blocks dotfiles and `.env/.log/.sql/.bak/...`; `storage/app/public/.htaccess` stops script execution in uploads. The web root is `public/` only.
- `TrustHosts` (production only): anchored, escaped regex of the `APP_URL` host (+ subdomains). A bare host would be an unanchored regex.
- Production forces https and the `APP_URL` root URL (`AppServiceProvider::hardenProduction`).
- Web middleware: `EnsureAccountActive` (disabled users are signed out on their next request), `AuthenticateSession`, `SecurityHeaders`, `web-general` limiter.
- Admin: `auth` + `admin` (staff only, idle timeout `ADMIN_IDLE_MINUTES`, default 30) + `can:<permission>`. `Gate::before` resolves dotted abilities from `roles`/`permissions`.

**Security headers** (`SecurityHeaders`): CSP with a per-request nonce, `nosniff`, `X-Frame-Options`, Referrer-Policy, Permissions-Policy, COOP; HSTS only in production over https; `no-store` on account, admin, checkout, auth and cart pages. The CSP is skipped while `public/hot` exists (Vite dev server). `script-src` has no `unsafe-inline`; it keeps `unsafe-eval` because the standard Alpine build needs it. Views therefore have no inline handlers: use Alpine directives, `data-confirm`, or a script tag with `nonce="{{ Vite::cspNonce() }}"`.

**Input and output**
- Server-side validation everywhere (`FormRequest`s); password max 72 (bcrypt), changing email needs the current password.
- Eloquent/query builder bindings only; `orderBy` columns are allow-listed.
- Rich text goes through `HtmlSanitizer` (DOM allow-list) on save **and** on output.
- Uploads go through `ImageStore`: real image check, re-encode with GD (strips EXIF and appended payloads), pixel cap, random names; SVG refused. There is no `storage/{path}` route (`filesystems.local.serve = false`).

**Rate limits**: named limiters in `AppServiceProvider::defineRateLimiters`. Never use numeric `throttle:N,1`: all routes using it share one counter.

**Sessions**: encrypted, HttpOnly, Secure in production, Lax, id regenerated at login, other devices logged out on password change.

**Payment webhooks**: CSRF-exempt `webhooks/payments/*` only. `WebhookSignature::verifyHmac` is constant-time, supports hex/base64/`sha256=` and a signed-timestamp replay window; an empty secret never verifies. 64 KB cap, terse `Invalid` on failure, amount must match the order total. Credentials live in `.env` and are read only in `config/*.php` (a test enforces that `env()` appears nowhere else).

**Deployment check**: `php artisan abiha:security-check [--probe]`. `--probe` requests `/.env`, `/.git/config`, `/composer.json`, the log, `vendor/` and `artisan` over HTTP. Run it after every deploy. It also fails if bank transfer is enabled without account details.

**Tests**: `tests/Feature/Security/{Injection,Upload,AccessControl,Hardening}Test.php`; browser CSP checks in `tests/browser/ui-harness.html`. Run the suite on SQLite **and** MySQL.

**Not covered / by design**: no OTP or 2FA (not wanted); SEO and performance were not part of the Phase 7 brief.

## Phase 7 (part 2): SEO & performance

**SEO**
- `App\Support\Seo` (container singleton, reset per request) owns canonical, robots, Open Graph and JSON-LD; `layouts/app.blade.php` only renders it. Views call `$seo->breadcrumbs()/schema()/image()/noindex()`.
- Canonical = page URL without query string, except `?page=N` (N>1). Filtered/sorted/searched listings are `noindex,follow`; private pages (`Seo::PRIVATE_PREFIXES`) are `noindex,nofollow` as meta tag and `X-Robots-Tag`; sample products/pages are never indexable; nothing is indexable outside production or when Settings → `seo.allow_indexing` is off.
- Titles: `meta_title` or name, `| ABIHA®`, `(Page N)` on paginated listings. Descriptions are always plain text.
- Structured data (`SchemaBuilder`): Organization + WebSite(SearchAction) on home from admin settings only; Product (Offer or AggregateOffer, availability from real stock, aggregateRating only from approved reviews, no brand: none exists); BreadcrumbList (same data as the visible breadcrumb component); CollectionPage/ItemList on listings.
- `/sitemap.xml` (real active products with image entries, categories, collections, published non-sample pages; cached, rebuilt on catalogue change) and `/robots.txt` are generated by `SeoController`. `public/robots.txt` must not exist (it would shadow the route).
- `/shop?category=x` 301-redirects to `/category/x`. One `<h1>` per page.

**Images**: `ImageStore` re-encodes, then `Img::generate` writes WebP variants (320–1600 px, never upscaled) beside the original. `<x-picture>` renders `<picture>` with `srcset`/`sizes`, intrinsic width/height (no layout shift), `loading=lazy` by default, `eager` + `fetchpriority=high` for the one LCP image (hero banner, first gallery photo). Old uploads without variants fall back to the plain `<img>`; `php artisan abiha:images-optimize` backfills them. `Img::delete` removes variants with the original. Alt text: per-image field in the product form, defaulting to the product name. The logo is served from resized WebP files generated from the supplied artwork.

**Database & caching**: `forCard()` eager loading; variants are `chaperone('product')`d (removed an N+1 on every card); review totals come from one grouped query and reviews are paginated (10/page). A test asserts query counts do not grow with catalogue size. `CatalogCache` caches nav, home sections, filter options and the sitemap under a version key bumped on any save/delete of catalogue models (and therefore on every stock change through the ledger); carts, wishlists and prices at checkout are never cached. Cache store is `file` (`CACHE_STORE=file`), not `database`.

**Delivery**: Vite minifies and hashes assets; `public/.htaccess` adds mod_deflate and long immutable caching for `/build/assets`, month-long for uploads and brand files. Fonts are self-hosted and preloaded.

**Measure**: `node tests/browser/vitals.mjs <base-url> / /shop /products/<slug>` (LCP, CLS, long tasks, bytes, image formats; mobile throttled by default).

**Deploy checklist**: `composer install --no-dev -o`, `php artisan optimize`, `php artisan storage:link`, `php artisan abiha:images-optimize`, enable OPcache and mod_deflate (or brotli), set `CACHE_STORE=file`, submit `/sitemap.xml` in Search Console, run `abiha:security-check --probe`.

## Phase 8: testing & QA

**Layers.** (1) PHPUnit feature tests (`php artisan test`), always on **SQLite and MySQL** (`DB_CONNECTION=mysql DB_DATABASE=abiha_pk_test DB_USERNAME=root php artisan test`): the two engines disagree in small ways and each has caught bugs the other hid. (2) `tests/browser/run.mjs`: behaviour of dialogs, drawer, focus, motion. (3) `tests/browser/qa.mjs`: the whole UI at four screen sizes with real mouse/keyboard events (see `tests/browser/README.md`). (4) `tests/browser/vitals.mjs`: lab Core Web Vitals.

**What the QA suites cover.** `QaStorefrontTest` (every filter, sort, search form, pagination, nav crawl, 404s), `QaCommerceTest` (coupon maths and caps, free shipping after discount, per-customer limits by email, price/quantity/variant manipulation, double submit, guest and registered journeys to delivery, wishlist, login/logout, admin order handling, ledger vs shelf), `QaFuzzTest` (every route that changes data, as guest/customer/admin, with empty/oversized/typed/hostile/JSON input, plus every page with hostile query strings and every public form re-rendered after a failed submit; the only assertion is "never a 5xx"; new routes join the sweep automatically).

**Bugs this phase found and fixed** (each has a regression test): array-valued query/body fields caused 500s on search suggestions, order confirmation, track-order, reset-password and checkout; every public form crashed when re-rendered with array `old()` input (`ScalarOldInput` middleware); typed `%`/`_` in search only worked on MySQL (explicit `ESCAPE`); an unbranded default 404 (branded 403/404/419/429/500/503 pages added); wishlist accepted hidden products; registration email was case-sensitive on SQLite; staff signing in could land on a leftover customer URL; bank transfer was offered with empty account details (now hidden until details exist); a float JSON value could 500 a date rule (`bail` + `string`); tiny tap targets (`.hit` utility).

**Rules that keep it honest.** The browser QA places real orders: run it only against a scratch database, and restock between runs (an exhausted product correctly shows "Sold out"). The site scrolls smoothly, so harnesses must jump (`behavior: 'instant'`) before measuring. Lab numbers are not field data.

## Phase 9: deployment

**Layout.** Production runs with the application *outside* the web root. Layout A (any cPanel): `~/abiha` private, `~/public_html` public, using `deploy/cpanel/index.php` (it calls `usePublicPath(__DIR__)`) and `PUBLIC_PATH` in `.env` so `artisan` and the web app agree where `build/` and `storage/` are. Layout B: point the domain's document root at `abiha/public`. `abiha:preflight` fails an install whose code sits inside the web root or whose public folder contains `.env`, `vendor`, etc.

**Commands.** `abiha:preflight` (can this server run it), `abiha:security-check [--probe]` (is it safe), `abiha:prepare-storage` (folders, uploads protection, symlink), `abiha:images-optimize`, `abiha:create-admin` (also resets a staff password). **Seeding:** live databases use `ProductionSeeder` (roles, attribute definitions, empty home layout) and never `DatabaseSeeder` (sample data). **Storage:** `PUBLIC_DISK_ROOT` lets uploads be written straight into the web root on hosts without symlinks.

**Files.** `.env.production.example` (no secrets), `deploy/package-release.php` (builds `abiha/` + `public_html/` + zip; excludes `.env`, tests, dev uploads, logs, caches; uses `composer --no-dev` when Composer exists), `deploy/backup.sh` (DB + uploads + `.env`; password via a private temp file; verified with `gzip -t`; rotated), `public/.htaccess` (https redirect that spares localhost, `/.well-known/` and proxies; gzip; long caching), `resources/stubs/uploads.htaccess`. **Defaults that differ in production:** `ABIHA_SAMPLE_LABELS` is off, logs are daily/30 days/errors only, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync` (nothing is queued, so no worker or scheduler cron).

The full operator guide is `docs/DEPLOYMENT.md`; `DeploymentTest` checks that its links, anchors and `artisan` commands are real.

## Brand typography and admin analytics (reconciled with the brand brief)

* **Typography.** Headings use **Cormorant Garamond**; everything else, including navigation, prices, labels, tables and admin figures, uses **Inter**; buttons are **Inter SemiBold**. Both are self-hosted variable WOFF2 files in `resources/fonts` (no Google request at runtime), and both are preloaded. `--font-heading` is Cormorant; `--font-display` is Inter's bold UI voice. Prices deliberately stay in Inter because Cormorant's default old-style numerals ("2,450" with descending digits) read poorly for money. Cormorant has a small x-height, so heading sizes in `base.css` run about 12% above the Inter scale; small helper text inside a heading (`.text-sm`) is set back to Inter. `BrandingTest` pins the colours, fonts, file integrity and the absence of any third-party font request.
* **Admin analytics use Chart.js** (`resources/js/admin-charts.js`, its own Vite entry, tree-shaken to the bar chart, loaded only on admin pages that contain a chart; the storefront bundle does not contain it). Data goes in `data-chart` attributes (no inline script, so the strict CSP is unchanged). Every chart keeps a "View as table" data table for keyboard and screen-reader users and an SVG `<noscript>` fallback. Animation is off under `prefers-reduced-motion`.

## Homepage builder

The homepage is a list of `home_sections` rows rendered in `sort_order` by `HomePageService`; `storefront/sections/{type}.blade.php` draws each type. Types: `hero`, `campaign` (dark main campaign), `categories`, `products` (source: new / bestsellers / trending / featured / collection / latest), `collections`, `promo` (light promotional strip), `brand` (text + image), `testimonials`, `newsletter`, `social` (up to six uploaded pictures). Banner-driven blocks (`hero`, `campaign`, `promo`) take their content from `banners` by placement and may appear once each. **Admin → Home page** can add (hidden until shown), remove, show/hide, reorder and edit every block. A block with nothing to show renders nothing (no empty shells). `ProductionSeeder` creates the layout without content; `DatabaseSeeder` adds sample content. **Cache rule:** bulk `->update()` queries fire no model events, so any admin action that changes homepage data that way must call `CatalogCache::bump()` (the layout reorder, the Featured flags page and image alt-text updates do); `HomepageBuilderTest` proves the live page changes immediately.

## Product page

**Variants are database-driven.** `Product::variantPayload()` ships each active variant's id, SKU, size, colour, price (own price or the product's), compare price and stock to the Alpine `productForm` component; choosing a size and colour switches the displayed SKU, price, saving and stock label, disables size options with no stock for the chosen colour, and keeps Add to bag / Buy now disabled for an unavailable variant. The browser is never trusted: `CartService` re-checks the variant (belongs to the product, active, in stock) on add, on every bag read and at checkout, and refuses with 422. **Server-rendered first:** the price, old price, "Save n%" and "From" are drawn by Blade and only kept in sync by Alpine (`x-cloak` hides what does not apply), so there is no empty pill or layout jump before JavaScript runs and they exist with JavaScript off.

**Specifications** are `products.specifications` (JSON list of `{label, value}`, max 25 rows, plain text, blank/half rows dropped), edited as add/remove rows in the product form, shown as an accessible table in an accordion panel and as schema.org `additionalProperty`. **Size guide** is the setting `size.guide` (Admin → Settings → Policies): simple HTML, sanitised on output, shown in a focus-trapped modal opened from a link inside the size selector; it is absent until the owner writes one, and the compact quick view leaves it out (the modal only exists on the full page).
