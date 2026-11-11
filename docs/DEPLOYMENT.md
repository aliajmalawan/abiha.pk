# Deploying ABIHA.PK to cPanel

This guide takes a finished build from your computer to a live, secure shop on cPanel shared hosting. Follow it in order. Every command in it was run against a simulated cPanel layout on the development machine; the few things that can only be confirmed on your real hosting are listed in [section 17](#17-go-live-checklist) under "Confirm on the live server".

**Contents:** 1 Overview · 2 What you need · 3 Build the release · 4 PHP settings · 5 Database · 6 Upload · 7 `.env` · 8 Set up the database · 9 Storage · 10 Cache · 11 Domain · 12 SSL · 13 Error logging · 14 Backups · 15 Troubleshooting · 16 Updating · 17 Go-live checklist

---

## 1. Overview: the one rule that keeps your secrets safe

A Laravel application has two kinds of files:

| Kind | Examples | Who may reach it |
|---|---|---|
| **Private**: code and secrets | `.env` (database password, `APP_KEY`), `app/`, `vendor/`, `storage/logs/`, `database/` | **Nobody over the web** |
| **Public**: what the browser needs | `index.php`, `build/` (CSS/JS), `assets/` (logo), `storage/` (uploaded photos), `.htaccess` | Everyone |

cPanel serves everything inside `public_html`. So the application must **not** live there. Use this layout:

```
/home/CPANELUSER/
├── abiha/            ← the application (PRIVATE, above the web root)
│   ├── app/  bootstrap/  config/  database/  deploy/  docs/  lang/  resources/  routes/
│   ├── storage/      (logs, cache, sessions)
│   ├── vendor/
│   ├── .env          ← your real settings (permissions 600)
│   └── artisan
└── public_html/      ← the web root (PUBLIC): only these files
    ├── index.php     ← a special version that finds ../abiha
    ├── .htaccess
    ├── build/  assets/  favicon.ico
    └── storage/      ← uploaded product photos
```

`php deploy/package-release.php` builds exactly these two folders for you ([section 3](#3-build-the-release-on-your-computer)).

**Two supported ways to wire them up:**

* **Layout A, "split" (works on every cPanel).** Application in `~/abiha`, public files in `~/public_html`, with the special `index.php` and `PUBLIC_PATH` in `.env`. This is what the rest of this guide describes.
* **Layout B, "document root" (cleanest, when cPanel lets you).** Upload the whole application to `~/abiha` and, in **cPanel → Domains → Manage**, change the domain's **Document Root** to `/home/CPANELUSER/abiha/public`. Then use the normal `public/index.php`, skip `PUBLIC_PATH`, and `php artisan storage:link` works as usual. Many hosts allow this for subdomains and add-on domains but not for the main domain. If you cannot change it, use Layout A.

> **Never** upload the whole project into `public_html`. Anyone could then download your `.env`. `php artisan abiha:preflight` refuses (FAIL) an installation laid out that way.

---

## 2. What you need

* A cPanel hosting account with **PHP 8.2 or newer** available, MySQL/MariaDB, and ideally **Terminal** (cPanel → Terminal) or SSH. If you have neither, see [8.4](#84-no-terminal-or-ssh-the-fallback).
* Your domain pointing at the hosting ([section 11](#11-domain-configuration)).
* A mailbox on your domain for password-reset emails (cPanel → Email Accounts).
* On **your computer**: PHP 8.2+, Composer, Node.js 20+, and this project. The server does **not** need Node.

---

## 3. Build the release on your computer

```bash
composer install              # once, if you have not already
npm install                   # once
npm run build                 # builds public/build (minified, hashed CSS/JS)
php artisan test              # the whole suite must be green
php deploy/package-release.php
```

This creates `dist/abiha-release/` containing `abiha/` and `public_html/`, plus `dist/abiha-release.zip`. It leaves out your `.env`, tests, `node_modules`, development uploads, logs, SQLite files and caches, and puts the cPanel `index.php` into `public_html/`.

**Production dependencies.** If Composer is installed on the machine, the packager runs `composer install --no-dev --optimize-autoloader` inside the release (no test tools on the server). If it cannot find Composer it copies your current `vendor/` and tells you so; in that case, run this once on the server after uploading, from `~/abiha`:

```bash
composer install --no-dev --optimize-autoloader
```

(If `composer` is not on the server, download `composer.phar` from getcomposer.org and run `php composer.phar install --no-dev -o`.) Do **not** copy `bootstrap/cache/*.php` between machines; the packager already leaves them out.

> **Verified vs not:** the folder layout, exclusions, the zip, and a full run of the app from that layout were verified. The `composer --no-dev` step was **not** run during preparation (Composer was not installed on the development machine); it is the standard command, but check `php artisan about` works after you run it.

---

## 4. PHP settings in cPanel

1. **cPanel → MultiPHP Manager**: set your domain to **PHP 8.2** (or newer).
2. **cPanel → Select PHP Version → Extensions**: make sure these are ticked: `ctype curl dom fileinfo gd json mbstring openssl pdo_mysql tokenizer xml exif` (and `opcache`, which makes the site much faster).
3. **cPanel → MultiPHP INI Editor** (Basic mode), for your domain:

   | Setting | Value | Why |
   |---|---|---|
   | `memory_limit` | `256M` | image processing |
   | `upload_max_filesize` | `8M` | product photos can be 4 MB each |
   | `post_max_size` | `24M` | several photos in one save |
   | `max_execution_time` | `60` | image variants for large photos |
   | `display_errors` | `Off` | never print errors to visitors |

4. **The command-line PHP is often older than the website's.** In Terminal run `php -v`. If it is below 8.2, use the versioned binary for every `artisan` command below: `ea-php82 artisan …` (or `/opt/cpanel/ea-php82/root/usr/bin/php artisan …`).

---

## 5. Create the database

1. **cPanel → MySQL® Database Wizard** (or MySQL® Databases).
2. Create a database, e.g. `abiha` → cPanel names it **`CPANELUSER_abiha`** (the account prefix is part of every name).
3. Create a user with a **long random password** (use the generator) → **`CPANELUSER_abiha_u`**.
4. Add the user to the database with **ALL PRIVILEGES**.
5. Write down the three values; they go into `.env` ([section 7](#7-configure-env)). The host is `localhost`.

The database must use `utf8mb4` (cPanel's default on current versions). Never use the account's main cPanel password for the application.

---

## 6. Upload the files

**Using File Manager (or FTP/SFTP):**

1. Upload `abiha-release.zip` to your home folder (`/home/CPANELUSER/`, one level **above** `public_html`) and **Extract** it. You now have `abiha/` and `public_html/` inside a folder (e.g. `abiha-release/`).
2. Move the `abiha` folder to `/home/CPANELUSER/abiha`.
3. Move the **contents** of `public_html` into `/home/CPANELUSER/public_html/` (if the domain already shows a cPanel placeholder page there, replace it). Show hidden files in File Manager (Settings → Show Hidden Files) so `.htaccess` comes across.
4. Delete the leftover empty release folder and the zip.

**Check the result:** `public_html` contains `index.php`, `.htaccess`, `build/`, `assets/`, `favicon.ico`, and **nothing else** (no `.env`, no `vendor`, no `app`). `abiha/` contains `app`, `vendor`, `storage`, `artisan`, and so on.

**If you named the private folder differently** from `abiha`, edit one line in `public_html/index.php`: `$appPath = __DIR__.'/../abiha';`.

**Permissions** (File Manager → right-click → Change Permissions, or in Terminal):

```bash
cd ~/abiha
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

(`755`/`644` also works where PHP runs as your own user, which is the cPanel default.) Never use `777`.

---

## 7. Configure `.env`

The `.env` file is the **production environment configuration**: every setting that differs between your computer and the live server (database, address, mail, logging, security switches) lives here, outside the code and outside the web root. In Terminal (or File Manager → create a file named `.env` in `~/abiha`):

```bash
cd ~/abiha
cp .env.production.example .env
chmod 600 .env
nano .env            # or edit it in File Manager
php artisan key:generate --force
```

Fill in at least:

```ini
APP_URL=https://yourdomain.pk                 # exact public address, https, no trailing slash
PUBLIC_PATH=/home/CPANELUSER/public_html      # Layout A only (split layout); omit in Layout B

DB_DATABASE=CPANELUSER_abiha
DB_USERNAME=CPANELUSER_abiha_u
DB_PASSWORD=the-long-random-password

MAIL_SCHEME=smtps                             # port 465 → smtps; port 587 → smtp
MAIL_HOST=mail.yourdomain.pk
MAIL_PORT=465
MAIL_USERNAME=shop@yourdomain.pk
MAIL_PASSWORD=the-mailbox-password
MAIL_FROM_ADDRESS=shop@yourdomain.pk
```

**Order emails.** With the `MAIL_*` values above the website sends: an order confirmation, a payment-received notice, and shipped / delivered / cancelled / returned notices to the customer; and, if you fill **Order notification email** in Admin → General settings, a short new-order email to you. They are sent at once (no queue worker or cron is needed). A mail failure is written to `storage/logs` and never stops an order. The sender name and address, and the reply-to address, come from Admin → General settings (sender must be an address your mailbox may send from). Test it: place a cash-on-delivery order with your own email and check the inbox and the spam folder. While `MAIL_MAILER=log` (the default) emails are only written to the log file, nothing is sent.

Everything else in the template is already production-safe: `APP_ENV=production`, `APP_DEBUG=false`, encrypted HTTPS-only sessions, daily error logs, file cache, `ABIHA_SAMPLE_LABELS=false`. The template contains **no secrets**; your real `.env` must never be copied into `public_html`, committed, e-mailed or pasted into a chat.

Values containing spaces or `#` need double quotes. After **any** `.env` change run `php artisan optimize` (config is cached; edits are not seen until you do).

---

## 8. Set up the database

### 8.1 Migrations and the clean production data

```bash
cd ~/abiha
php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force
```

`ProductionSeeder` adds only real configuration (roles and permissions, size/colour definitions, the empty home-page layout). It adds **no** products, pages, banners, shipping rates or accounts. **Do not run plain `php artisan db:seed` on the live database**; that is the development sample data.

### 8.2 The first administrator

```bash
php artisan abiha:create-admin owner@yourdomain.pk --name="Your Name"
```

A strong random password is generated and printed **once**; save it in a password manager and change it after signing in (staff passwords need 12+ characters). There are no default credentials. Sign in at `https://yourdomain.pk/admin/login`.

### 8.3 Storage, caches and the readiness check

```bash
php artisan abiha:prepare-storage      # folders, uploads protection, public/storage ([section 9](#9-storage-configuration))
php artisan optimize                   # caches config, routes, events and views ([section 10](#10-cache-configuration))
php artisan abiha:preflight            # must end with "Ready"
php artisan abiha:security-check --probe   # must show no FAIL lines
```

Read any `FAIL`/`WARN` line: each one says exactly what to change.

### 8.4 No Terminal or SSH: the fallback

If your plan has neither, prepare the database on your own computer and import it:

1. On your computer, create an **empty local database**, point a throw-away `.env` at it, then run `php artisan migrate --force`, `php artisan db:seed --class=ProductionSeeder --force`, and `php artisan abiha:create-admin owner@yourdomain.pk --password="your-long-password"`.
2. Export it (phpMyAdmin → Export, or `mysqldump`), and import the file on the server with **cPanel → phpMyAdmin → Import** into `CPANELUSER_abiha`.
3. Create `.env` in File Manager and set `APP_KEY` to a value made locally with `php artisan key:generate --show`.
4. Create the folder `public_html/storage` and the file `.htaccess` inside it with the contents of `resources/stubs/uploads.htaccess`, and set `PUBLIC_DISK_ROOT=/home/CPANELUSER/public_html/storage` in `.env`.

This route skips `optimize` (the site works, just slightly slower) and the preflight command, so double-check by hand against the [checklist](#17-go-live-checklist). A Terminal is strongly recommended.

---

## 9. Storage configuration

Uploaded product photos are stored in `storage/app/public` and must be reachable at `https://yourdomain.pk/storage/...`.

* **Normal case:** `php artisan abiha:prepare-storage` creates a symbolic link `public_html/storage → ~/abiha/storage/app/public`.
* **If the host forbids symlinks** (the command prints `FAIL could not create the public/storage symlink`): set

  ```ini
  PUBLIC_DISK_ROOT=/home/CPANELUSER/public_html/storage
  ```

  in `.env`, run `php artisan optimize`, then `php artisan abiha:prepare-storage` again. Uploads are now written straight into `public_html/storage`; no link is needed. (Both variants were exercised on the development machine; Windows blocked the symlink there, which is exactly how the fallback was verified.)
* **Uploads cannot run code.** `abiha:prepare-storage` puts an `.htaccess` in the uploads folder that disables PHP and denies script files, and every uploaded image is re-encoded server-side (metadata and hidden payloads removed) before it is saved.
* **WebP versions** are created automatically for new uploads. For images uploaded earlier, run `php artisan abiha:images-optimize` once.
* Uploaded photos live **outside** the release folders, so they are never overwritten by an update. They **are** included in the backup ([section 14](#14-backups)).

---

## 10. Cache configuration

| What | Setting | Notes |
|---|---|---|
| Application cache | `CACHE_STORE=file` (already in the template) | Needs `storage/framework/cache` writable. Do **not** use `database` (one SQL query per cache read). |
| Sessions | `SESSION_DRIVER=database`, encrypted | Table created by the migrations. |
| Queue | `QUEUE_CONNECTION=sync` | Nothing in the app is queued, so **no queue worker and no scheduler cron are needed**. |
| Framework caches | `php artisan optimize` | Caches config, routes, events, views. Run it after **every** deploy and `.env` change. Undo with `php artisan optimize:clear`. |
| PHP OPcache | cPanel → Select PHP Version → tick `opcache` | The biggest single speed-up on shared hosting. |
| Compression and browser caching | already in `public/.htaccess` | gzip for text, 1-year immutable caching for `build/`, 30 days for uploads. Needs Apache `mod_deflate`/`mod_headers` (standard on cPanel). |

The shop also keeps a short-lived catalogue cache (menus, home page, filters, sitemap). It is **cleared automatically** whenever you save a product, category, banner, page, review and so on, or when stock changes through an order, so you never need to clear it by hand. Carts, wishlists and checkout prices are never cached.

To clear everything (e.g. after restoring a backup): `php artisan optimize:clear && php artisan optimize`.

---

## 11. Domain configuration

1. **Point the domain at the server.** At your registrar, either switch the nameservers to the ones your host gave you, or create DNS records: an **A record** for `yourdomain.pk` → your server's IP and a **CNAME** (or A) for `www` → `yourdomain.pk`. Propagation can take up to a few hours.
2. **Add the domain in cPanel** if it is not the account's main domain: **Domains → Create A New Domain** (set its document root to `public_html`, or, for Layout B, to `abiha/public`).
3. **Choose one address** for the shop, either `yourdomain.pk` or `www.yourdomain.pk`, and put exactly that in `APP_URL`. The site's canonical links, sitemap, emails and the "trusted hosts" list all follow `APP_URL`.
   * In production the application **only answers on the `APP_URL` host and its subdomains**; a request for any other hostname gets `400 Bad Request` (this blocks Host-header attacks). If you have an old or alternate domain, point it at the *same* address with a redirect in cPanel (**Domains → Redirects**) rather than at the site directly.
4. **Optional: send `www` to the bare domain** (so only one address is ever indexed). Add these three lines to `public_html/.htaccess`, just **above** the line `# Send every real domain to HTTPS`:

   ```apache
   RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]
   RewriteCond %{HTTP_HOST} !^www\.(localhost|127\.0\.0\.1)(:[0-9]+)?$ [NC]
   RewriteRule ^ https://%1%{REQUEST_URI} [L,R=301]
   ```

   (Tested: `http://www.example.com/shop?x=1` → `https://example.com/shop?x=1` in one step. If you prefer the `www` address, do not add this; instead redirect the bare domain to `www` and set `APP_URL=https://www.yourdomain.pk`.)
5. **Email deliverability** (password-reset mails): cPanel → **Email Deliverability** → make sure SPF and DKIM show as valid for the domain, otherwise mail may land in spam.
6. **Search engines:** the live site is indexable by default. Under **Admin → Settings → SEO** you can switch indexing off (it is also always off on any non-production `APP_ENV`, e.g. a `staging` copy). When live, submit `https://yourdomain.pk/sitemap.xml` in Google Search Console.

**A staging copy** (recommended before big changes): create a subdomain such as `staging.yourdomain.pk` with its own database and its own `.env`, set `APP_ENV=staging` (search engines are then blocked automatically) and `APP_URL=https://staging.yourdomain.pk`. Never point staging at the live database.

---

## 12. SSL configuration

1. **cPanel → SSL/TLS Status → Run AutoSSL.** This issues a free certificate for the domain and `www` once the DNS points at the server. Wait until both show a green padlock status.
2. The shipped `public/.htaccess` already **redirects every `http://` request to `https://`** (301). It deliberately leaves `localhost` alone (development) and `/.well-known/` reachable over http (certificate validation), and it respects a proxy's `X-Forwarded-Proto`.
3. In production the application additionally forces `https` URLs, sets **Secure** session cookies, and sends **HSTS** (`max-age=31536000; includeSubDomains`) on https responses.

   > HSTS is remembered by browsers for a year. Make sure `https://` works for the domain **and every subdomain you use** before going live.
4. Verify: open `http://yourdomain.pk`; it must land on `https://yourdomain.pk` with a padlock. Then run `php artisan abiha:security-check --probe`, and test the site with <https://www.ssllabs.com/ssltest/>.
5. **Behind Cloudflare:** use SSL mode **Full (strict)**. **Flexible** causes a redirect loop because Cloudflare talks to your server over http.
6. Certificates renew automatically (AutoSSL). If a certificate is ever reported expired, run AutoSSL again.

---

## 13. Error logging

* **Where:** `~/abiha/storage/logs/laravel-YYYY-MM-DD.log`: one file per day, the **last 30 days kept automatically** (`LOG_DAILY_DAYS`), only **errors and worse** (`LOG_LEVEL=error`). Visitors only ever see the branded error page (404, 403, 419, 429, 500, 503); technical details go to the log, never to the screen (`APP_DEBUG=false`).
* **Read the newest errors** (Terminal):

  ```bash
  tail -n 80 ~/abiha/storage/logs/laravel-$(date +%F).log
  ```

  or open the file in cPanel File Manager. Each entry starts with a timestamp and `production.ERROR` followed by the message and a stack trace.
* **Apache/web-server errors** (before Laravel even starts, e.g. a bad `.htaccess`): cPanel → **Metrics → Errors**.
* **To investigate something tricky temporarily**, set `LOG_LEVEL=debug`, `php artisan optimize`, reproduce it, read the log, then set it back to `error` and `optimize` again. **Never** set `APP_DEBUG=true` on the live site: it would print your configuration and credentials to visitors.
* **Privacy:** logs can contain customer email addresses. Keep `storage/` private (it is, in this layout), do not paste logs into public places, and do not include them in public bug reports without removing personal data.
* **Alerts (optional, not set up):** to be messaged when an error happens, Laravel's `slack` log channel is available in `config/logging.php`; set `LOG_SLACK_WEBHOOK_URL` and `LOG_STACK=daily,slack`. This was not exercised during preparation.
* **Security events** (failed admin logins and rate limits) return generic messages to the visitor; use `php artisan abiha:security-check` periodically.

---

## 14. Backups

A backup has three parts: the **database**, the **uploaded photos**, and a copy of **`.env`** (it holds `APP_KEY`; without it, encrypted cookies and sessions cannot be read after a restore). `deploy/backup.sh` makes all three, verifies them, and removes old ones.

### 14.1 One-off backup

```bash
bash ~/abiha/deploy/backup.sh
```

It writes to `~/backups/abiha/` (outside `public_html`, readable only by you):

```
db-20261109-021500.sql.gz        ← the database
uploads-20261109-021500.tar.gz   ← product/banner/category photos
env-20261109-021500.txt          ← your .env (contains secrets: keep it private)
backup.log
```

The database password is read from `.env` and given to `mysqldump` through a private temporary file, never on the command line. Settings (all optional): `BACKUP_DIR=/path`, `KEEP_DAYS=14`, `APP_DIR=/home/CPANELUSER/abiha`.

### 14.2 Automatic nightly backup

**cPanel → Cron Jobs → Add New Cron Job**, "Once Per Day" at `2:00`, command:

```
/bin/bash /home/CPANELUSER/abiha/deploy/backup.sh >/dev/null 2>&1
```

Check `~/backups/abiha/backup.log` the next morning.

### 14.3 Keep a copy somewhere else (important)

A backup stored only on the server is lost if the server is. At least weekly, **download** the newest three files (cPanel File Manager → Download, or SFTP) to your computer or cloud storage, and also use **cPanel → Backup Wizard / JetBackup** for a whole-account backup if your host provides it. Take a manual backup (14.1) **before every update** and before any bulk change.

### 14.4 Restore

```bash
cd ~/backups/abiha

# 1. the database (this REPLACES the current data: take a fresh backup first!)
gunzip -c db-YYYYMMDD-HHMMSS.sql.gz | mysql -u CPANELUSER_abiha_u -p CPANELUSER_abiha

# 2. the photos
tar -xzf uploads-YYYYMMDD-HHMMSS.tar.gz -C ~/abiha/storage/app/public      # or into PUBLIC_DISK_ROOT

# 3. only if .env was lost: copy env-YYYYMMDD-HHMMSS.txt back to ~/abiha/.env and chmod 600

cd ~/abiha && php artisan optimize:clear && php artisan optimize && php artisan abiha:preflight
```

(Or restore the `.sql.gz` through phpMyAdmin → Import.) The development machine proved this cycle: a dump was restored into a scratch database and came back with the same 45 tables and the same product rows. **Test a restore into a separate database every few months**; an untested backup is only a hope.

---

## 15. Troubleshooting (deployment)

| Symptom | Cause and fix |
|---|---|
| **Site shows "500 Internal Server Error", nothing in `storage/logs`** | The error happens before Laravel starts. Check cPanel → Metrics → Errors. Usual causes: PHP below 8.2 (section 4), a broken `.htaccess` (re-upload the shipped one), `vendor/` missing/incomplete (section 3), or `index.php` cannot find the app (section 6: is `abiha` next to `public_html`?). |
| **500 and a log entry** | Read the log (section 13). `No application encryption key` → `php artisan key:generate --force`. `Permission denied` on `storage/logs` → fix permissions (section 6). `Could not open input file: artisan`/wrong PHP → use `ea-php82 artisan …`. |
| **`index.php` says "Application folder not found"** | The private folder is not at `../abiha`. Edit `$appPath` in `public_html/index.php`. |
| **Unstyled page, `Vite manifest not found`** | `build/` is missing from `public_html`. Upload the whole `build` folder. In Layout A `PUBLIC_PATH` must be set in `.env` (then `php artisan optimize`) so `artisan` and the website agree where `build/` lives. |
| **Product photos broken (404 on `/storage/...`)** | Run `php artisan abiha:prepare-storage`; if it reports the symlink FAIL, use `PUBLIC_DISK_ROOT` (section 9). |
| **I can browse to `https://yourdomain.pk/.env` and see my settings!** | **Stop and fix immediately.** The application folder is inside the web root. Move `abiha` above `public_html` (section 1), change the database password and run `php artisan key:generate --force`, because the old secrets must be treated as exposed. Then `php artisan abiha:security-check --probe`. |
| **`400 Bad Request` for everyone** | The host in the address bar is not the `APP_URL` host/subdomain (section 11). Correct `APP_URL`, `php artisan optimize`. |
| **`419 Page Expired` when logging in** | `APP_URL` mismatch, a stale browser cookie, or visiting over `http`. Open the https address, clear the site's cookies. |
| **Redirect loop (`ERR_TOO_MANY_REDIRECTS`)** | Cloudflare **Flexible** SSL, or a second redirect rule in cPanel (Domains → "Force HTTPS Redirect") fighting `.htaccess`. Use Full (strict) and keep only one of them. |
| **`SQLSTATE[HY000] [1045] Access denied`** | Wrong `DB_USERNAME`/`DB_PASSWORD`, or the user was not added to the database with all privileges (section 5). Remember the `CPANELUSER_` prefix. After editing `.env`: `php artisan optimize`. |
| **`SQLSTATE[HY000] [2002]`** | `DB_HOST` should be `localhost` on cPanel. |
| **`Specified key was too long` during migrate** | An old MySQL without utf8mb4 defaults: recreate the database with collation `utf8mb4_unicode_ci`. |
| **Changes to `.env` ignored** | `php artisan optimize` (config is cached). |
| **Changes to a Blade template ignored** | `php artisan view:clear` (or `optimize:clear` then `optimize`). |
| **Admin: "These credentials do not match" for the new admin** | Run `php artisan abiha:create-admin the@same.email` again: for an existing address it **resets the password, re-activates the account and restores the role**, and prints a new password once. Staff passwords need 12+ characters. |
| **No emails (password reset)** | `MAIL_MAILER` must be `smtp`, with the mailbox settings (section 7). Port `465` needs `MAIL_SCHEME=smtps`; port `587` needs `MAIL_SCHEME=smtp`. Test by requesting a reset and read `storage/logs` for the SMTP error. Check SPF/DKIM (section 11). (No real mail server was available during preparation, so this is the one setting to confirm on the live server.) |
| **Checkout shows no payment method** | Cash on delivery is on by default; bank transfer appears only after account details are saved (Admin → Payments). If nothing shows, no payment method is enabled. |
| **`abiha:preflight` says "INSIDE the public web root"** | See "I can browse to .env". Fix the layout before anything else. |
| **Everything slow** | Enable OPcache (section 10), run `php artisan optimize`, confirm `CACHE_STORE=file`, confirm gzip works (the response should have `Content-Encoding: gzip`). |
| **Site shows "Service Unavailable"** | The site is in maintenance mode (`php artisan down`). Bring it back with `php artisan up`. |

---

## 16. Updating the site (and rolling back)

1. **Back up** (14.1) and note the current date/time.
2. Build the new release on your computer ([section 3](#3-build-the-release-on-your-computer)); run the tests.
3. Put the site in maintenance mode: `cd ~/abiha && php artisan down --retry=60`.
4. Upload the new files **over** the old ones, but **do not overwrite or delete**: `.env`, `storage/app/public/` (your uploads, or `public_html/storage/`), `storage/logs/`. Easiest: rename the current `~/abiha` to `~/abiha-previous`, upload the new `abiha`, then copy `.env` (and `storage/app/public` if you do not use `PUBLIC_DISK_ROOT`) across from the previous folder. Replace `public_html/build/` and `public_html/index.php` if they changed.
5. Run, from `~/abiha`:

   ```bash
   php artisan migrate --force          # only changes the database if there are new migrations
   php artisan db:seed --class=RolesAndPermissionsSeeder --force   # when a release changes roles or permissions (safe to repeat; it never touches products or orders)
   php artisan abiha:prepare-storage
   php artisan optimize:clear && php artisan optimize
   php artisan abiha:preflight
   php artisan up
   ```

6. Click through the home page, a product, the bag, checkout (cash on delivery), and the admin; then watch `storage/logs` for a few minutes.

**Rolling back:** `php artisan down`; rename `~/abiha` to `~/abiha-broken` and `~/abiha-previous` back to `~/abiha`; if the update ran migrations, restore the database backup from step 1 (section 14.4); `php artisan optimize:clear && php artisan optimize && php artisan up`. Keep the previous release folder for at least a week.

---

## 17. Go-live checklist

Tick every line. Items marked ★ can only be confirmed on the real server.

**Server and files**
- [ ] ★ PHP 8.2+ selected for the domain **and** the command-line `php` (or `ea-php82`) is 8.2+
- [ ] ★ Extensions enabled, OPcache on; `upload_max_filesize 8M`, `post_max_size 24M`, `memory_limit 256M`
- [ ] Application is in `~/abiha`, **outside** `public_html`; `public_html` holds only `index.php`, `.htaccess`, `build/`, `assets/`, `favicon.ico`, `storage/`
- [ ] `https://yourdomain.pk/.env`, `/vendor/autoload.php`, `/artisan`, `/composer.json`, `/storage/logs/laravel.log` all return **404/403** (`php artisan abiha:security-check --probe`)
- [ ] `.env` has permissions `600` and exists only in `~/abiha`
- [ ] `storage/` and `bootstrap/cache/` are writable (775 or 755), no `777` anywhere

**Configuration**
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…` (exact address), `APP_KEY` generated
- [ ] `PUBLIC_PATH` set (Layout A) or document root set to `abiha/public` (Layout B)
- [ ] Database credentials correct; user has all privileges
- [ ] `ABIHA_SAMPLE_LABELS=false`; **no sample data** in the database (only `ProductionSeeder` was run)
- [ ] SMTP mailbox configured; a password-reset email arrives (and not in spam)
- [ ] `php artisan optimize` has been run after the last `.env` change

**Database and accounts**
- [ ] `php artisan migrate --force` done; `db:seed --class=ProductionSeeder --force` done
- [ ] First admin created with `abiha:create-admin`; password stored safely and changed; no default accounts exist
- [ ] `php artisan abiha:preflight` → **Ready**; `php artisan abiha:security-check --probe` → no FAIL

**Content (entered by you, nothing is pre-filled)**
- [ ] Admin → Settings: site name, contact details, delivery and returns text, social links
- [ ] Admin → Shipping: at least one method with real rates; Payments: bank details saved (or bank transfer left off)
- [ ] Categories, products with photos and alt text, collections, banners
- [ ] Admin → Pages: **write and publish your own** Privacy policy, Terms, About and any others you need (a clean install has none; the Shipping & Returns page is built in and shows the delivery and returns text from Settings)
- [ ] Home page blocks switched on/off as wanted; brand-story/social blocks only after you wrote the text
- [ ] A test order placed (cash on delivery) and then cancelled; stock returned; order visible in the admin

**Domain and SSL**
- [ ] ★ DNS points at the server; `www` handled (redirect or both resolve)
- [ ] ★ AutoSSL certificate valid for the domain and `www`; `http://` redirects to `https://` with a padlock; SSL Labs grade A/A+
- [ ] ★ HSTS understood (every subdomain you use works over https)

**Operations**
- [ ] Nightly backup cron added; `backup.log` shows OK; the newest files are **downloaded off the server**
- [ ] A restore into a separate database has been tested at least once
- [ ] Error logs reachable (`storage/logs/laravel-DATE.log`) and you know how to read them (section 13)
- [ ] Search Console: sitemap `https://yourdomain.pk/sitemap.xml` submitted; indexing allowed in Admin → Settings → SEO

**Confirm on the live server (not verifiable beforehand):** the checks marked ★ above, that cPanel's Terminal runs `artisan`, that Apache honours the shipped `.htaccess` (the HTTPS redirect and gzip), that your host allows `symlink()` (otherwise use `PUBLIC_DISK_ROOT`), and that `composer install --no-dev` completes within your host's memory limit (otherwise upload a locally built `vendor/`).
