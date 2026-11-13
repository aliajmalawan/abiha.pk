# Deployment (cPanel and other PHP hosts)

ABIHA is plain PHP + MySQL. There is **nothing to build on the server**: no Composer, no npm, no queue worker.
Upload the files, create `.env`, run `migrate`, done.

## 1. Server requirements

- PHP 8.1+ (cPanel -> Select PHP Version) with `mysqli`, `gd` (with WebP), `mbstring`, `fileinfo`, `openssl`, `json`, `dom`.
  Optional: `exif` (phone photos keep their orientation), `zip` (photo backups).
- PHP settings: `memory_limit` 256M, `upload_max_filesize` 8M or more, `post_max_size` 24M or more (several photos per upload).
- MySQL 8 / MariaDB 10.4+ (utf8mb4).
- HTTPS (cPanel AutoSSL is fine). `public/.htaccess` redirects every real domain to https.

`php bin/console.php preflight` checks all of this on the server (exit code 1 when something must be fixed).

## 2. Web root: one of two layouts

**A. Document root = the `public` folder (recommended).** In cPanel -> Domains, set the domain's document root to
`<project>/public` (e.g. `/home/CPANELUSER/abiha/public`). Only `public/` is reachable.

**B. Document root = the project folder** (e.g. the project uploaded straight into `public_html`). The project-root
`.htaccess` routes every request into `public/` and denies `app/`, `bin/`, `database/`, `storage/`, `tests/`, `docs/`,
`resources/` and dotfiles; `storage/.htaccess` serves only uploaded images. **Check after upload** that
`https://your-domain/.env`, `/app/bootstrap.php` and `/storage/logs/` answer 403 or 404.

No split layout (`PUBLIC_PATH`, a second `index.php` in `public_html`) is needed any more.

Nginx (document root = `public/`):

```nginx
root /home/abiha/abiha/public;
index index.php;
location / { try_files $uri /index.php?$query_string; }
location ~ ^/storage/.*\.(php|phtml|phar)$ { deny all; }
location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php8.2-fpm.sock; }
location ~ /\. { deny all; }
client_max_body_size 40m;
```

## 3. Uploaded photos (the "public disk")

Uploads live in `storage/app/public/` (`products/`, `banners/`, `branding/`, `home/`) and are served at
`https://your-domain/storage/...` through the link `public/storage -> storage/app/public`:

```bash
php bin/console.php storage:link
```

Hosts that forbid symlinks: set `PUBLIC_DISK_ROOT` in `.env` to a folder **inside** the web root
(e.g. `/home/CPANELUSER/abiha/public/storage`), move the photos there, and copy `storage/app/public/.htaccess` into it
(it stops scripts from running in the upload folder). `PUBLIC_DISK_URL` stays empty (= `APP_URL/storage`).

Moving from the Laravel site: copy the old `storage/app/public/*` (photos, logo) to the new `storage/app/public/`, and
`storage/app/private/*` (bank-transfer proofs) to `storage/app/private/`. Paths in the database stay the same.

## 4. Database

Create the database and a user in cPanel -> MySQL Databases (the cPanel prefix is part of both names), give the user
ALL PRIVILEGES on that database only. **Moving from the Laravel site: point `.env` at the existing database.** The
schema is identical; `migrate` only adds the two new runtime tables (`rate_limits`, `email_outbox`) and never changes
or deletes existing data (every statement is `CREATE TABLE IF NOT EXISTS` / `INSERT IGNORE`). Back it up first anyway.

Times are stored in Asia/Karachi business time exactly as the Laravel site stored them (`BUSINESS_TIMEZONE`), so old
and new rows read the same.

## 5. `.env`

Copy `.env.example` to `.env` in the project folder (never inside `public/`), permissions 600, and fill in:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
APP_KEY=<64 random hex characters: php -r "echo bin2hex(random_bytes(32));">
DB_HOST=localhost
DB_DATABASE=cpaneluser_abiha
DB_USERNAME=cpaneluser_abiha
DB_PASSWORD=<the database user's password>
MAIL_DRIVER=smtp
MAIL_FROM_ADDRESS=orders@your-domain.example
SMTP_HOST=mail.your-domain.example  SMTP_PORT=465  SMTP_ENCRYPTION=ssl  SMTP_USERNAME=...  SMTP_PASSWORD=...
PAYMENTS_ENABLED=cod,bank_transfer
```

The site **refuses to start** in production when `APP_KEY` is weak or a placeholder, `APP_URL` is not https, the
database user is `root` or has no password, mail is not SMTP, or card payments are enabled without a webhook secret
(the reason is written to the PHP error log). Card payments stay off until a provider driver exists and its
`CARD_*` values are set. Configure SPF/DKIM for the sending domain so order emails reach inboxes.

## 6. First deploy

```bash
cd /home/CPANELUSER/abiha
php bin/console.php preflight
php bin/console.php migrate
php bin/console.php storage:link                       # skip when PUBLIC_DISK_ROOT is used
php bin/console.php admin:create you@your-domain.example   # prints a generated password once (no default credentials exist)
```

Writable by PHP: `storage/` and all its subfolders (and `PUBLIC_DISK_ROOT` if used).

Updates: upload the changed files (never overwrite `.env` or `storage/`), then `php bin/console.php migrate` and
`php bin/console.php cache:clear`.

## 7. Cron jobs (cPanel -> Cron Jobs)

```cron
*/5 * * * * cd /home/CPANELUSER/abiha && php bin/console.php cron >> storage/logs/cron.log 2>&1
15 2 * * *  cd /home/CPANELUSER/abiha && php bin/console.php backup >> storage/logs/backup.log 2>&1
```

`cron` delivers queued emails (with retries) and prunes old rate-limit counters. `backup` writes a gzipped
`mysqldump` and a zip of the uploaded photos to `storage/backups/` and keeps the latest 14 of each; download them
regularly to another place (set `MYSQLDUMP_PATH` if `mysqldump` is not on the PATH).

Restore: create an empty database, `gunzip -c backup.sql.gz | mysql -u USER -p DBNAME`, unzip the photos into
`storage/app/public/`.

## 8. After going live

- `https://your-domain/.env` -> 403/404; `/admin` -> sign-in page; product photos load.
- Admin -> Settings: contact details, bank details (bank transfer stays hidden at checkout until they are saved),
  "Allow search engines" (pages are `noindex` until the site runs with `APP_ENV=production` and indexing is allowed).

## 9. Traffic analytics (Admin -> Analytics)

The shop counts its own visitors, with no cookies, no IP addresses and no third-party service: one row per page view in
the table `page_views` (created by `php bin/console.php migrate`, migration `004_analytics`). Staff visits, robots,
prefetches, background requests and visitors who send "Do Not Track" are never counted. The page is under Admin ->
Analytics (permission `reports.view`); the on/off switch needs `settings.manage`. The cron job (`php bin/console.php cron`,
section 7) removes page views older than 400 days. After updating an existing site run `migrate` once, otherwise the
dashboard simply hides its traffic strip and the Analytics page shows an error.

## 10. Photos and videos (Admin -> Products)

- **Photos:** every upload is re-encoded (metadata stripped), kept at most 2400 px on the long edge, and small WebP versions
  (320 to 1600 px) are made so phones download a small file. After copying photos by hand (for example moving from the old
  site) run `php bin/console.php media:optimize` once: it creates the missing small versions for every product, category,
  collection, banner and homepage photo (`--force` redoes all). Recommended product photo: 1200 x 1500 px.
- **Product page gallery:** thumbnails, hover zoom on desktop, and a full-screen photo viewer (the enlarge button, or a tap on
  phones): arrows, swipe, pinch-style double tap, keyboard (Esc, arrow keys, + / - / 0).
- **Product video (optional):** Admin -> Products -> edit -> Photos card. Paste a YouTube or Vimeo link, a direct https link
  to an .mp4 / .webm file, or upload an MP4 / WebM file up to 25 MB (checked by its real content, stored as
  `storage/app/public/videos/<random>.mp4`). Make sure PHP's `upload_max_filesize` and `post_max_size` are at least 26M.
  YouTube and Vimeo players only load after the visitor presses play (YouTube through youtube-nocookie.com), uploaded videos
  never autoplay. The security policy allows exactly these sources: `frame-src` YouTube (no-cookie) and Vimeo, `media-src`
  the shop and https links.

## 11. Speed

What makes the shop fast, and what to run:

- **Compression:** pages and JSON are gzip-compressed by the application itself when the host's Apache does not do it (5 to 8 times smaller:
  a 120 KB home page is about 15 KB on the wire). CSS and JS are sent from pre-compressed `.gz` copies by `public/.htaccess`.
- **One small stylesheet:** `php bin/console.php assets:build` merges `abiha.css` and `theme.css` into `app.min.css` (+ `.gz` copies of the CSS and
  JS files). Run it after every change to `public/assets/abiha/theme.css` or any script there. The pages use `app.min.css` only while it is newer
  than its sources, so a forgotten build can never show stale styling (it just falls back to the two source files).
- **Caching:** CSS, JS, fonts and photos are cached by browsers for 30 days (their addresses carry a version, so a change is picked up at once).
- **Photos:** responsive WebP versions (see section 10); photos below the first screen load lazily; every photo has its size set so nothing jumps.
- **Visitor counting** is written after the page has been sent, so it never slows a page down.
- **Database:** every frequent query uses an index; the category menu, settings and the sitemap are cached (`php bin/console.php cache:clear` empties them).
- **Check it:** outside production every response carries a `Server-Timing` header (time spent in PHP and the number of queries); open the browser's
  Network panel to see it. `php bin/console.php preflight` has a "Speed" section (OPcache, compression, built assets).
- **Ask the host for:** PHP 8.1+ with **OPcache enabled** (cPanel -> Select PHP Version -> Extensions), HTTPS (AutoSSL) and, if offered, HTTP/2 or HTTP/3.
