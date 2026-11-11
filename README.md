# ABIHA.PK

A premium online store for ABIHA®, built with **Laravel 12**, PHP 8.2+, MySQL, Blade, Tailwind CSS v4, Alpine.js, Lucide icons and Chart.js (admin analytics only). Brand type: Cormorant Garamond headings, Inter body. It has a customer storefront (catalogue, search and filters, wishlist, bag, checkout, accounts, order tracking) and a staff admin dashboard at `/admin` (with in-app alerts for new orders and bank transfers to verify) (products, inventory, orders, payments, coupons, shipping, content, reports), and it is designed to run on ordinary **cPanel shared hosting**.

> **Status.** Cash on delivery and bank transfer (with reference and receipt upload) work. **Card payments are built but disabled: no payment provider is connected** (see [docs/PAYMENTS.md](docs/PAYMENTS.md)). Shipping is configurable and manual; courier APIs have a ready integration layer but none is connected ([docs/SHIPPING.md](docs/SHIPPING.md)); an order is only ever marked paid after a payment is genuinely verified, never simulated. Order emails (confirmation, payment received, shipped / delivered / cancelled / returned) and password-reset emails are sent through your SMTP mailbox once `MAIL_*` is set in `.env` (until then they only go to the log; see [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)). Guides: [docs/ADMIN_GUIDE.md](docs/ADMIN_GUIDE.md) (using the admin), [IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md) (what is built, tested and still pending), [docs/QA_REPORT.md](docs/QA_REPORT.md) (test results). All development content is flagged as **SAMPLE**; no real business details (products, prices, policies, contact details) are invented anywhere.

| | |
|---|---|
| Documentation | [Deployment guide (cPanel)](docs/DEPLOYMENT.md) · [Architecture](docs/ARCHITECTURE.md) · [Browser tests](tests/browser/README.md) |
| Admin sign-in | `/admin/login` (staff accounts only; customers sign in at `/login`) |

---

## 1. Requirements

| | Local development | Production server |
|---|---|---|
| PHP | 8.2 or newer | 8.2 or newer |
| PHP extensions | `ctype curl dom fileinfo gd json mbstring openssl pdo_mysql tokenizer xml` (+ `exif`; GD with **WebP**) | the same |
| Database | MySQL 5.7+/8 or MariaDB 10.3+ | the same |
| Composer | 2.x | needed once (or build the release locally) |
| Node.js | 20+ (builds the CSS/JS) | **not needed**: you upload the built `public/build` |

---

## 2. Installation (local)

```bash
# 1. Get the code into a folder (for XAMPP: C:\xampp\htdocs\abiha.pk), then:
composer install
cp .env.example .env            # Windows: copy .env.example .env
php artisan key:generate

# 2. Create an empty database (see "Database setup" below), put its name/user/password in .env

# 3. Create the tables and load SAMPLE data for development
php artisan migrate
php artisan db:seed             # development only: sample products, banners, pages…

# 4. Build the CSS/JS and expose uploaded images
npm install
npm run build
php artisan abiha:prepare-storage

# 5. Create your admin account (see "Admin setup")
php artisan abiha:create-admin you@example.com

# 6. Run it
php artisan serve               # http://127.0.0.1:8000
```

With XAMPP you can instead browse to `http://localhost/abiha.pk/public` (set `APP_URL` to that address). Sample records carry a visible **SAMPLE** flag so they cannot be mistaken for real content.

---

## 3. Local development

| Task | Command |
|---|---|
| Run the site | `php artisan serve` |
| Rebuild CSS/JS once | `npm run build` |
| Rebuild on every change | `npm run dev` (Vite dev server; the strict security headers are relaxed while `public/hot` exists) |
| Run the PHP tests (SQLite, in memory) | `php artisan test` |
| Run them against MySQL too (do both before a release) | `DB_CONNECTION=mysql DB_DATABASE=abiha_pk_test DB_USERNAME=root php artisan test` (create the empty `abiha_pk_test` database first) |
| Real-browser tests (needs Edge/Chrome and a running site) | see [tests/browser/README.md](tests/browser/README.md) |
| Clear caches while developing | `php artisan optimize:clear` |

Project layout: `app/Services` holds the business rules (cart, orders, inventory, coupons, payments), `app/Http/Controllers/Admin` the dashboard, `resources/views` the Blade templates (`components/` for reusable pieces), `resources/css` the design tokens and components, `database/seeders` the seed data. [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) explains the security model, caching, images, SEO and the rules that must keep holding (prices and totals are always computed on the server; stock changes only through the inventory service; payment is verified, never assumed).

Useful commands for this project:

| Command | What it does |
|---|---|
| `php artisan abiha:create-admin <email>` | Creates a staff account (never with a default password) |
| `php artisan abiha:preflight` | "Can this server run the shop?" PHP, extensions, folders, web-root layout, database, caches |
| `php artisan abiha:security-check [--probe]` | "Is it configured safely?" `--probe` also requests `/.env` etc. over HTTP to prove they are not served |
| `php artisan abiha:prepare-storage` | Creates required folders, protects the uploads folder, links `public/storage` |
| `php artisan abiha:images-optimize` | Builds responsive WebP versions for images uploaded earlier |

---

## 4. Database setup

Create an empty database with the `utf8mb4` character set:

```sql
CREATE DATABASE abiha_pk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

(On cPanel use **MySQL® Databases**; see the deployment guide.) Then set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` in `.env` and run:

| Goal | Commands |
|---|---|
| **Local development** (with sample content) | `php artisan migrate` then `php artisan db:seed` |
| **A live site** (clean, no sample content) | `php artisan migrate --force` then `php artisan db:seed --class=ProductionSeeder --force` |
| Apply new migrations after an update | `php artisan migrate --force` |
| Start over locally (**destroys all data**) | `php artisan migrate:fresh --seed` |

`ProductionSeeder` adds only real configuration: roles and permissions, the size/colour definitions and the empty home-page layout. It creates **no products, pages, banners, shipping rates or user accounts**. Never run `php artisan db:seed` (the sample seeder) on a live database.

---

## 5. Admin setup

1. Create the first administrator, **after** the database is migrated and seeded:

   ```bash
   php artisan abiha:create-admin owner@yourdomain.com --name="Your Name"
   ```

   With no `--password` a strong random password is generated and shown **once**; change it after signing in. Passwords need at least 12 characters. There are no default credentials.
2. Sign in at **`/admin/login`**. Staff are signed out after 30 idle minutes (`ADMIN_IDLE_MINUTES`).
3. Roles: **Administrator** (everything), **Manager** (everything except staff management), **Staff** (orders, inventory, reviews, messages). Staff accounts are managed under **Staff**.
4. Before launch, enter your real information (nothing is pre-filled):
   - **Settings**: site name, contact details, social links, delivery and returns text, **size guide** (your own measurements, shown in a pop-up beside the size selector; hidden until you write one), announcement bar, SEO (allow search engines).
   - **Shipping**: at least one shipping method, otherwise customers cannot check out. Add city/province rules if rates differ.
   - **Payments**: cash on delivery is available by default. **Bank transfer is hidden at checkout until you save your account details.**
   - **Categories, Attributes, Products** (with photos and alt text; tick New arrival / Best seller / Trending / Featured to fill the homepage rows; add **Specifications** as label/value rows such as Fabric and Care; set each variant's own SKU, price and stock), **Collections**, **Banners** (hero, main campaign and promotional banner are separate placements), **Pages** (a clean install has none: write and publish your Privacy policy, Terms, About…).
   - **Home page**: every homepage block is managed here. You can **add** blocks (as many product rows as you like), **remove** them, **hide/show** them, **reorder** them and edit their text and options. Blocks are: hero, main campaign, shop by category, product rows (new arrivals, best sellers, trending, featured, a specific collection), featured collections, promotional banner, brand story (text and image), testimonials, newsletter and a social showcase (up to six of your own pictures). A block with nothing to show stays hidden. The announcement bar, social links, contact details and footer text are in Settings.
5. Orders arrive under **Orders**; verify bank transfers under the order's payment panel.

---

## 6. Deployment

Production runs on cPanel with the application **outside** the public web folder:

```
/home/CPANELUSER/abiha/         ← the application: code, vendor, .env, storage   (PRIVATE)
/home/CPANELUSER/public_html/   ← only public files + a small index.php          (PUBLIC)
```

Build an upload-ready release and follow the step-by-step guide:

```bash
npm run build
php deploy/package-release.php        # → dist/abiha-release/{abiha, public_html} and a .zip
```

**Full instructions: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** (PHP version, database, `.env`, migrations, storage, caching, domain, SSL, backups, error logging, updates and a go-live checklist). After uploading, run on the server:

```bash
php artisan abiha:preflight              # must say "Ready"
php artisan abiha:security-check --probe # must have no FAIL lines
```

Never upload the whole project into `public_html`, never commit or upload your real `.env` to a public folder, and never run the sample seeder on the live database.

---

## 7. Troubleshooting

| Symptom | Likely cause and fix |
|---|---|
| **500 error / blank page on the live site** | Look at the newest file in `storage/logs/` for the real message (the page itself never shows it). Common causes: missing `.env` or `APP_KEY` (`php artisan key:generate --force`), `storage`/`bootstrap/cache` not writable (`chmod -R 775`), wrong PHP version (needs 8.2+). |
| **Page appears unstyled / "Vite manifest not found"** | `public/build` was not uploaded. Run `npm run build` locally and upload the whole `public/build` folder next to `index.php`. |
| **Product images are broken** | `public/storage` is missing. Run `php artisan abiha:prepare-storage`. If the host forbids symlinks, set `PUBLIC_DISK_ROOT=/home/CPANELUSER/public_html/storage` in `.env` and run it again. |
| **My `.env` change has no effect** | Configuration is cached. Run `php artisan optimize` (or `optimize:clear` while developing). |
| **"419 Page expired" on forms** | Session/cookie problem: wrong `APP_URL`/domain, `SESSION_DOMAIN` set to another domain, or the site is opened over `http` while `SESSION_SECURE_COOKIE=true`. Use the https address. |
| **"400 Bad Request" or "Untrusted host"** | The address in the browser is not the `APP_URL` host (or its subdomains). Fix `APP_URL`, then `php artisan optimize`. |
| **Redirect loop after enabling SSL** | A proxy/CDN terminates TLS and the origin sees http. The shipped `.htaccess` honours `X-Forwarded-Proto`; with Cloudflare use **Full (strict)**, not Flexible. |
| **`SQLSTATE[HY000] [1045]` / `[2002]`** | Wrong DB credentials or host. On cPanel the database and user names carry your account prefix (e.g. `acct_abiha`), and the user must be added to the database with **all privileges**. |
| **`Base table or view not found`** | Migrations were not run: `php artisan migrate --force`. |
| **Password-reset email never arrives** | `MAIL_MAILER` is still `log`. Configure SMTP with a mailbox created in cPanel → Email Accounts. |
| **Admin login says the account is disabled / no access** | The user has no staff role or is disabled. Re-run `php artisan abiha:create-admin the@same.email`: it restores the role, re-activates the account and sets a new password. |
| **"Class … not found" right after an update** | Stale autoload/cache. Upload `vendor/` completely, then `php artisan optimize:clear && php artisan optimize`. |
| **Checkout button is disabled** | No shipping method exists yet (Admin → Shipping), or no payment method is available. |
| **`/sitemap.xml` or `/robots.txt` give 404 on PHP's built-in test server** | A quirk of `php -S` (it never routes URLs with file extensions to Laravel). Apache/LiteSpeed with the shipped `.htaccess` serves them correctly. |
| **Uploads fail silently with several photos** | `post_max_size` / `upload_max_filesize` too small. Use at least 24M / 8M (cPanel → MultiPHP INI Editor). `abiha:preflight` warns about it. |

More deployment-specific problems and their fixes are in [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md#15-troubleshooting-deployment).

---

## Principles this project follows

- **No invented business data.** Anything not supplied by the owner is a clearly labelled sample and is never indexed by search engines.
- **No simulated payments.** Online payments are only marked paid after the provider's signature and API confirm them; provider keys live in `.env`, never in the admin or in code.
- **The server decides prices.** Totals, discounts, shipping and stock are recomputed from the database on every order.
- **Secrets stay private.** `.env` and the application code live outside the web root; `abiha:security-check --probe` proves it.
