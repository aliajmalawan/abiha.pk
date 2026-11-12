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
