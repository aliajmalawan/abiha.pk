# ABIHA - online store

The ABIHA storefront and admin, in **plain PHP 8.1+ with MySQLi** (no framework, no Composer, no Node on the server).
It replaces the earlier Laravel version page for page: same URLs, same design (the compiled CSS/JS in
`public/assets/abiha/`), same database schema, so an existing ABIHA database keeps working unchanged.

## Requirements

- PHP 8.1 or newer with `mysqli`, `gd` (with WebP), `mbstring`, `fileinfo`, `openssl`, `json`, `dom`
  (`exif` optional: phone photos keep their orientation; `zip` optional: photo backups)
- MySQL 8 or MariaDB 10.4+
- Apache with `mod_rewrite` (the `.htaccess` files do the routing) or any server that sends every non-file request to `public/index.php`

## Local setup (XAMPP on Windows)

```powershell
cd C:\xampp\htdocs\abiha.pk
C:\xampp\php\php.exe bin\console.php setup          # creates .env (random APP_KEY, root DB user for local use only)
C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE IF NOT EXISTS abiha_pk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
C:\xampp\php\php.exe bin\console.php migrate        # tables + base data (roles, permissions, sizes/colours, home layout)
C:\xampp\php\php.exe bin\console.php storage:link   # public/storage -> storage/app/public (uploaded photos)
C:\xampp\php\php.exe bin\console.php admin:create you@example.com   # prints a generated password once
```

Open http://localhost/abiha.pk/ (the project-root `.htaccess` routes into `public/`). The admin is at `/admin`.

## Console

`php bin/console.php <command>`: `setup`, `migrate`, `seed:production` (= migrate; base data only, never products,
prices or accounts), `admin:create <email> [--name=] [--role=admin|manager|staff|product_manager|inventory_manager|content_manager] [--password=]`,
`cron` (send queued email; run every 5 minutes), `backup` (database dump + photos to `storage/backups`, keeps 14),
`preflight` (can this server run ABIHA?), `storage:link`, `cache:clear`, `check` (lint + tests).

## Tests

```powershell
C:\xampp\php\php.exe tests\run.php           # all suites; uses the separate database DB_TEST_DATABASE (wiped every run)
C:\xampp\php\php.exe tests\run.php unit      # one suite (file tests\UnitTest.php)
```

## Layout of the code

| Folder | What |
|---|---|
| `app/Core` | Runtime: Router, Request/Response, Session, Csrf, Middleware, Kernel, View, Database (MySQLi), Validator (Laravel rules and messages), RateLimiter, Logger, ErrorHandler, Security headers, Uploads, Money |
| `app/Config` | Business defaults (`abiha`, `payments`, `shipping`, `faq`), read with `cfg('abiha.name')` |
| `app/Auth` | Sign-in, roles and permissions on the `users / roles / permissions` tables |
| `app/Support` | Settings, SiteSettings, Menus, Seo, Img (responsive images), HtmlSanitizer, CatalogCache, logo/favicon cut-outs |
| `app/Services` | Domain services (ImageStore, ActivityLogger, Cart, Wishlist, Navigation, ...) |
| `app/Controllers`, `app/routes/*.php` | Pages; each part of the site registers its routes in its own file |
| `app/Views` | PHP templates (`layouts/store.php`, `layouts/admin.php`, `partials/*`, `errors/*`, `emails/*`) |
| `database/migrations` | `001_schema.sql` (the ABIHA tables), `002_base_data.sql`, `003_runtime.sql` |
| `public/` | Web root: `index.php`, compiled CSS/JS/fonts (`assets/abiha`), icons, brand artwork, `storage` link to uploads |
| `storage/` | Private: uploads (`app/public`), payment proofs (`app/private`), sessions, cache, logs, mail, backups |

Deployment (cPanel and others): see [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). Admin how-to: [docs/ADMIN_GUIDE.md](docs/ADMIN_GUIDE.md).

## Ground rules

- No invented business data: contact details, policies, shipping rates and payment details come from the admin.
- Online payments are never simulated: an order is marked paid only after the provider's signed webhook AND the provider's API confirm it.
- The logo is the supplied artwork (or the owner's upload), never redrawn.
