# ABIHA.PK: implementation status

Laravel 12 · PHP 8.2 · MySQL · Blade · Tailwind v4 · Alpine.js · Vite · Lucide · Chart.js (admin only).
Last updated: 10 Nov 2026. Test numbers are in [docs/QA_REPORT.md](docs/QA_REPORT.md).

**Short answer: the application is built and tested locally. What is left is not code but things only the owner can supply: real products, photos and policies; a card-payment provider account; SMTP mailbox details; and a check on the real cPanel server with the real domain and SSL.**

## Completed modules (in the order of the brief)

| # | Module | Status | Where to read more |
|---|---|---|---|
| 1 | Project setup, environment, storage, error handling | Done | README, docs/ARCHITECTURE.md |
| 2 | Migrations, models, relationships, seeders | Done (foreign keys, indexes, unique keys, timestamps, soft deletes on users/products/categories/collections/coupons) | docs/ARCHITECTURE.md → "Data model" |
| 3 | Authentication and permissions | Done: registration, login/logout, password reset, profile, **six admin roles** (Super Admin, Admin, Order Manager, Product Manager, Inventory Manager, Content Manager), permission checks on the server for every admin route | docs/ADMIN_GUIDE.md |
| 4 | Design system, reusable components | Done (brand tokens, Blade components, accessible dialogs, skeletons, toasts, reduced-motion) | `/design-system` (local only) |
| 5 | Storefront | Done: header + mega menu + mobile drawer, editable homepage blocks, shop with filters/sort/search/quick view, product page with DB-driven variants, size guide, reviews, related products, cart drawer, **sticky mobile add-to-bag bar** | |
| 6 | Product and category management | Done: CRUD, variants (size/colour/SKU/stock), image upload with validation and **ordering**, SEO fields, featured/new/visibility, category tree with images | |
| 7 | Cart and wishlist | Done: server-side prices, stock checks, coupons (**including product/category restrictions**), guest and account carts that merge | |
| 8 | Checkout and orders | Done: Pakistani phone validation, shipping rules, coupon validation, transaction-safe order creation, stock locking, order tracking | |
| 9 | Payments and shipping | Done: cash on delivery, bank transfer with reference and receipt upload and manual verification, provider-independent card gateway (**disabled until a provider is connected**), payment history, manual shipping with a courier-API integration layer, **shipments list** | docs/PAYMENTS.md, docs/SHIPPING.md |
| 10 | Admin dashboard | Done: real figures, charts, orders (filters, invoice, print, CSV), customers, inventory and stock history, coupons, reports | docs/ADMIN_GUIDE.md |
| 11 | CMS and settings | Done: homepage builder, banners, pages, **editable header/footer menus**, testimonials, newsletter, social links, general settings | |
| 12 | SEO and performance | Done: titles, meta, canonical, Open Graph, sitemap, robots, product and breadcrumb structured data, WebP and responsive images (with transparent logos kept transparent), caching | docs/ARCHITECTURE.md |
| 13 | Security and automated tests | Done: CSRF, validation, hashing, rate limits, secure uploads, webhook signatures, role checks; automated PHP tests and a real-browser test runner | docs/QA_REPORT.md |
| 14 | Deployment preparation | Done: cPanel guide (split and single layouts), `.env` template, preflight checks, backups, **order emails through SMTP** | docs/DEPLOYMENT.md |

## What needs something from the owner (not code)

1. **Real content**: products, photos, category and collection pictures, policies, contact details, a hero picture, a real announcement. Everything seeded for development is marked **SAMPLE**; run only `ProductionSeeder` on the live site.
2. **Card payments**: needs a merchant account with a payment provider and a small driver written for that provider (steps in docs/PAYMENTS.md). Until then card payment is shown as "not set up" and cannot be switched on. No payment is ever simulated.
3. **Order emails**: need the SMTP mailbox in `.env` (docs/DEPLOYMENT.md, section 7). Until then emails are only written to the log.
4. **Courier API**: optional. Shipping works by hand now; a courier integration needs the courier's account and API documentation.
5. **Real-server check**: DNS, SSL, the cPanel PHP version and the first live test order. This was prepared and documented but could not be verified from a local computer.

## Known limits (stated plainly)

- There is no separate `inventory` table. Stock lives on products and variants, and every change is written to the `stock_movements` ledger in the same transaction; a second copy of the same number could drift.
- Photos are reordered with arrow buttons and "Make main", not drag and drop.
- A shipment's status follows the order status (shipped, delivered, returned); staff edit courier, tracking number and link on the order.
- Gateway credentials live in `.env`, never in the admin or the database (by design).
- Core Web Vitals were measured in a local lab only; run Lighthouse on the live site after launch.
- The test data in the local database is not real business data.

## How to resume

Everything is in the project folder. To verify: `php artisan test` (SQLite) and the same against MySQL (docs/QA_REPORT.md lists the commands), then the browser runner `node tests/browser/qa.mjs`. For a fresh install: `php artisan migrate --seed` (development) or `php artisan migrate --force && php artisan db:seed --class=ProductionSeeder --force` (live).
