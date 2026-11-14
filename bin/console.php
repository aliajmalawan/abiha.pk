<?php

declare(strict_types=1);

/*
 * ABIHA console (plain PHP, no Composer).
 *
 *   php bin/console.php setup                       Create .env from .env.example with a random APP_KEY (never overwrites)
 *   php bin/console.php migrate                     Apply pending database/migrations/*.sql (safe on an existing database)
 *   php bin/console.php seed:production             Base data only (roles, permissions, sizes/colours, home layout) = migrate
 *   php bin/console.php admin:create <email> [--name="Administrator"] [--role=admin|manager|staff|...] [--password=...]
 *                                                   Create or promote a staff account (no default password ever)
 *   php bin/console.php cron                        Send queued email, prune old rate-limit counters (run every 5 minutes)
 *   php bin/console.php mail:test <email>           Send a test email with the current mail settings and show the real error if it fails
 *   php bin/console.php media:optimize [--force]    Make the responsive WebP versions of every stored photo that lacks them (--force: redo all)
 *   php bin/console.php assets:build                One small CSS file (abiha.css + theme.css, minified)
 *   php bin/console.php backup                      mysqldump + uploaded photos to storage/backups (keeps 14)
 *   php bin/console.php preflight                   Can this server run ABIHA? (PHP, extensions, .env, DB, folders, web root)
 *   php bin/console.php storage:link                Create public/storage -> storage/app/public (symlink or Windows junction)
 *   php bin/console.php pages:legal                 Add the Privacy policy and Terms & conditions pages (only if missing)
 *   php bin/console.php cache:clear                 Drop settings and catalogue caches
 *   php bin/console.php check                       Lint every PHP file and run the test suite
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$cmd = $argv[1] ?? 'help';
$root = dirname(__DIR__);

/** @return array<string,string> --key=value options */
function options(array $argv): array
{
    $o = [];
    foreach (array_slice($argv, 2) as $a) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
            $o[$m[1]] = $m[2] ?? '1';
        }
    }
    return $o;
}

function out(string $s): void
{
    fwrite(STDOUT, $s . PHP_EOL);
}

if ($cmd === 'setup') {
    if (is_file($root . '/.env')) {
        out('.env already exists: leaving it untouched.');
        exit(0);
    }
    $env = (string) file_get_contents($root . '/.env.example');
    $env = (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . bin2hex(random_bytes(32)), $env);
    // Local XAMPP defaults (development only; production refuses root and an empty password).
    foreach ([
        'APP_ENV' => 'development', 'APP_DEBUG' => 'true', 'APP_URL' => 'http://localhost/abiha.pk', 'ABIHA_SAMPLE_LABELS' => 'true',
        'DB_HOST' => '127.0.0.1', 'DB_DATABASE' => 'abiha_pk', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '',
        'MAIL_DRIVER' => 'outbox', 'MAIL_FROM_ADDRESS' => 'no-reply@example.com', 'SMTP_HOST' => '', 'SMTP_USERNAME' => '', 'SMTP_PASSWORD' => '',
    ] as $k => $v) {
        $env = (string) preg_replace('/^' . $k . '=.*$/m', $k . '=' . $v, $env);
    }
    file_put_contents($root . '/.env', $env);
    @chmod($root . '/.env', 0600);
    out('Created .env with a random APP_KEY (development defaults; edit it for production).');
    exit(0);
}

require $root . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

function migrate(Database $db): void
{
    $m = $db->raw();
    $m->query('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done = array_flip($db->column('SELECT version FROM schema_migrations'));
    $files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
    sort($files);
    $applied = 0;
    foreach ($files as $file) {
        $version = basename($file, '.sql');
        if (isset($done[$version])) {
            continue;
        }
        // Every migration is idempotent (CREATE TABLE IF NOT EXISTS / INSERT IGNORE), so an existing database
        // (e.g. the live Laravel one) is left as it is.
        $m->multi_query((string) file_get_contents($file));
        do {
            if ($r = $m->store_result()) {
                $r->free();
            }
        } while ($m->more_results() && $m->next_result());
        $db->exec('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
        out("Migrated: {$version}");
        $applied++;
    }
    out($applied ? "Done ({$applied} applied)." : 'Nothing to migrate.');
}

function random_password(int $len = 16): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    do {
        $p = '';
        for ($i = 0; $i < $len; $i++) {
            $p .= $chars[random_int(0, strlen($chars) - 1)];
        }
    } while (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p));
    return $p;
}

function storage_link(): string
{
    $link = PUBLIC_PATH . '/storage';
    $target = (string) Config::get('filesystems.public_root');
    if (!is_dir($target)) {
        mkdir($target, 0775, true);
    }
    if (file_exists($link)) {
        return 'public/storage already exists.';
    }
    if (str_starts_with(realpath($target) ?: $target, realpath(PUBLIC_PATH) ?: PUBLIC_PATH)) {
        return 'PUBLIC_DISK_ROOT is inside the web root: no link needed.';
    }
    if (@symlink($target, $link)) {
        return 'Linked public/storage -> ' . $target;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        exec('cmd /c mklink /J ' . escapeshellarg($link) . ' ' . escapeshellarg($target), $o, $code);
        if ($code === 0) {
            return 'Junction public/storage -> ' . $target;
        }
    }
    return 'Could not create public/storage. Set PUBLIC_DISK_ROOT to a folder inside the web root instead (see docs/DEPLOYMENT.md).';
}

if ($cmd === 'help' || $cmd === '--help') {
    out((string) preg_replace('/^\s*\* ?/m', '', explode('*/', explode('/*', (string) file_get_contents(__FILE__), 2)[1])[0]));
    exit(0);
}

if ($cmd === 'preflight') {
    exit(require BASE_PATH . '/bin/preflight.php');
}

if ($cmd === 'storage:link') {
    out(storage_link());
    exit(0);
}

$db = Database::get();
App\Support\SiteSettings::apply();

switch ($cmd) {
    case 'migrate':
    case 'seed:production':
        migrate($db);
        break;

    case 'admin:create':
        $email = mb_strtolower(trim((string) ($argv[2] ?? '')));
        $o = options($argv);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            out('Usage: php bin/console.php admin:create <email> [--name="Administrator"] [--role=admin] [--password=...]');
            exit(1);
        }
        $roleSlug = $o['role'] ?? 'admin';
        $role = $db->one('SELECT id, name FROM roles WHERE slug = ? AND is_staff = 1', [$roleSlug]);
        if (!$role) {
            out('Unknown staff role. Run `php bin/console.php migrate` first, and use one of: '
                . implode(', ', $db->column('SELECT slug FROM roles WHERE is_staff = 1 ORDER BY id')) . '.');
            exit(1);
        }
        $given = $o['password'] ?? null;
        $password = $given ?: random_password(16);
        if (strlen($password) < 12) {
            out('Staff passwords must be at least 12 characters.');
            exit(1);
        }
        $existing = $db->one('SELECT id, name FROM users WHERE email = ?', [$email]);
        $hash = App\Auth\Auth::hashPassword($password);
        if ($existing) {
            $db->exec('UPDATE users SET name = ?, password = ?, role_id = ?, is_active = 1, deleted_at = NULL, updated_at = ? WHERE id = ?',
                [$existing['name'] ?: ($o['name'] ?? 'Administrator'), $hash, $role['id'], now(), $existing['id']]);
        } else {
            $db->insert('INSERT INTO users (name, email, password, role_id, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)',
                [$o['name'] ?? 'Administrator', $email, $hash, $role['id'], now(), now()]);
        }
        out(($existing ? 'Updated' : 'Created') . ": {$email} ({$role['name']})");
        if (!$given) {
            out("Generated password (shown once, store it safely): {$password}");
        }
        break;

    case 'cron':
        $sent = App\Mail\Mailer::flush(50);
        $db->exec('DELETE FROM rate_limits WHERE window_start < ?', [time() - 172800]);
        $pruned = App\Services\Analytics::prune(400);   // visitor statistics older than 400 days
        out("Cron: {$sent} queued emails sent, old rate-limit counters pruned, {$pruned} old page views removed.");
        break;

    case 'backup':
        $dump = getenv('MYSQLDUMP_PATH') ?: (PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe' : 'mysqldump');
        $cfg = Config::get('db');
        $dir = STORAGE_PATH . '/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $stamp = gmdate('Ymd-His');
        $file = $dir . '/' . $cfg['name'] . '-' . $stamp . '.sql';
        $cnf = tempnam(sys_get_temp_dir(), 'abh');
        file_put_contents($cnf, "[client]\nuser={$cfg['user']}\npassword=\"{$cfg['pass']}\"\nhost={$cfg['host']}\nport={$cfg['port']}\n");
        $cmdline = escapeshellarg($dump) . ' --defaults-extra-file=' . escapeshellarg($cnf)
            . ' --single-transaction --routines --triggers --default-character-set=utf8mb4 ' . escapeshellarg($cfg['name'])
            . ' > ' . escapeshellarg($file);
        exec($cmdline, $o, $code);
        unlink($cnf);
        if ($code !== 0 || !is_file($file) || filesize($file) === 0) {
            out('Database backup failed (exit ' . $code . '). Set MYSQLDUMP_PATH if mysqldump is not on PATH.');
            exit(1);
        }
        if (function_exists('gzencode')) {
            file_put_contents($file . '.gz', gzencode((string) file_get_contents($file), 9));
            unlink($file);
            $file .= '.gz';
        }
        out('Database: ' . $file);
        // Uploaded photos (public disk) as a zip, when the zip extension is available.
        $photos = (string) Config::get('filesystems.public_root');
        if (class_exists('ZipArchive') && is_dir($photos)) {
            $zipFile = $dir . '/photos-' . $stamp . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE) === true) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($photos, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile()) {
                        $zip->addFile($f->getPathname(), ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($photos))), '/'));
                    }
                }
                $zip->close();
                out('Photos: ' . $zipFile);
            }
        } else {
            out('Photos: skipped (no zip extension); copy ' . $photos . ' yourself.');
        }
        foreach (['/*.sql*', '/photos-*.zip'] as $pattern) {
            $all = glob($dir . $pattern) ?: [];
            rsort($all);
            foreach (array_slice($all, 14) as $old) {
                unlink($old);
            }
        }
        break;

    case 'mail:test':
        $to = (string) ($argv[2] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            out('Usage: php bin/console.php mail:test you@example.com');
            exit(1);
        }
        out(sprintf('Sending a test email to %s with driver "%s", from %s%s ...', $to, Config::get('mail.driver'), Config::get('mail.from_address'),
            Config::get('mail.driver') === 'smtp' ? ' via ' . Config::get('mail.smtp_host') . ':' . Config::get('mail.smtp_port') . ' (' . Config::get('mail.smtp_encryption') . ') as ' . Config::get('mail.smtp_user') : ''));
        try {
            App\Mail\Mailer::sendTest($to);
        } catch (Throwable $e) {
            out('FAILED: ' . $e->getMessage());
            out('Check SMTP_HOST (usually mail.yourdomain), SMTP_PORT (465 with SSL, or 587 with TLS), SMTP_USERNAME (the FULL email address) and SMTP_PASSWORD in .env, then run `php bin/console.php cache:clear`.');
            exit(1);
        }
        out(Config::get('mail.driver') === 'smtp' ? 'Sent. Check the inbox (and the spam folder) of ' . $to . '.' : 'Done (driver "' . Config::get('mail.driver') . '": see storage/mail for the message; nothing was delivered).');
        break;

    case 'assets:build':
        // Run after changing public/assets/abiha/theme.css or any script there. The pages use app.min.css only while it is newer
        // than its sources, so a forgotten build never shows stale styling.
        $dir = PUBLIC_PATH . '/assets/abiha';
        $min = static function (string $css): string {
            $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);                  // comments
            $css = (string) preg_replace('/\s+/', ' ', $css);                         // whitespace runs
            $css = (string) preg_replace('/\s*([{};,>])\s*/', '$1', $css);           // around structure
            $css = (string) preg_replace('/;}/', '}', $css);
            return trim($css);
        };
        $base = (string) file_get_contents($dir . '/abiha.css');
        $theme = $min((string) file_get_contents($dir . '/theme.css'));
        file_put_contents($dir . '/app.min.css', $base . "
" . $theme . "
");
        out(sprintf('Assets built: app.min.css %d KB (the web server compresses it for visitors).', round(filesize($dir . '/app.min.css') / 1024)));
        break;

    case 'media:optimize':
        // Every photo the shop shows (products, categories, collections, banners, homepage blocks): create the small WebP
        // versions the pages choose from, so phones download a 30 KB picture instead of the full original.
        $force = in_array('--force', $argv, true);
        $paths = [];
        foreach (['SELECT path FROM product_images', 'SELECT image AS path FROM categories', 'SELECT image AS path FROM collections', 'SELECT image AS path FROM banners'] as $q) {
            foreach ($db->column($q) as $pth) {
                $paths[(string) $pth] = true;
            }
        }
        foreach ($db->column("SELECT settings FROM home_sections WHERE settings IS NOT NULL") as $json) {
            $cfgRow = json_decode((string) $json, true);
            foreach (array_merge([(string) ($cfgRow['image'] ?? '')], array_map('strval', (array) ($cfgRow['images'] ?? []))) as $pth) {
                if ($pth !== '') {
                    $paths[$pth] = true;
                }
            }
        }
        unset($paths['']);
        [$done, $skipped, $missing, $before, $after] = [0, 0, 0, 0, 0];
        foreach (array_keys($paths) as $rel) {
            if (!App\Support\Img::exists($rel)) {
                $missing++;
                continue;
            }
            $before += (int) filesize(App\Support\Img::path($rel));
            if (!$force && App\Support\Img::widths($rel) !== []) {
                $skipped++;
                foreach (App\Support\Img::widths($rel) as $w) {
                    $after += (int) @filesize(App\Support\Img::path(App\Support\Img::variantPath($rel, $w)));
                }
                continue;
            }
            $made = App\Support\Img::generate($rel);
            $done++;
            foreach ($made as $w) {
                $after += (int) @filesize(App\Support\Img::path(App\Support\Img::variantPath($rel, $w)));
            }
        }
        App\Support\CatalogCache::bump();
        out(sprintf('Photos checked: %d. Optimised now: %d, already optimised: %d, file missing: %d. Originals %.1f MB, responsive versions %.1f MB.', count($paths), $done, $skipped, $missing, $before / 1048576, $after / 1048576));
        break;

    case 'pages:legal':
        // Adds the Privacy policy and Terms & conditions pages if they do not exist yet. Never replaces a page that already exists.
        $before = (int) $db->value("SELECT COUNT(*) FROM pages WHERE slug IN ('privacy-policy', 'terms-and-conditions')");
        $raw = $db->raw();
        $raw->multi_query((string) file_get_contents(BASE_PATH . '/database/content/legal_pages.sql'));
        do {
            if ($r = $raw->store_result()) {
                $r->free();
            }
        } while ($raw->more_results() && $raw->next_result());
        $after = (int) $db->value("SELECT COUNT(*) FROM pages WHERE slug IN ('privacy-policy', 'terms-and-conditions')");
        App\Support\Settings::flush();
        App\Support\CatalogCache::bump();
        out($after > $before ? 'Added ' . ($after - $before) . ' page(s). Edit them in Admin -> Pages.' : 'Both pages already exist; nothing changed.');
        break;

    case 'cache:clear':
        App\Support\Settings::flush();
        App\Support\CatalogCache::bump();
        out('Settings and catalogue caches cleared.');
        break;

    case 'check':
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH, FilesystemIterator::SKIP_DOTS));
        $bad = 0;
        $count = 0;
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php' || preg_match('#[\\\\/](storage|node_modules|\.git|live-import)[\\\\/]#', $path)) {
                continue;
            }
            $count++;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $o, $code);
            if ($code !== 0) {
                $bad++;
                out(implode("\n", $o));
            }
            $o = [];
        }
        out("Lint: {$count} files, {$bad} with errors.");
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/tests/run.php'), $testCode);
        exit($bad || $testCode ? 1 : 0);

    default:
        out("Unknown command: {$cmd}. Run `php bin/console.php help`.");
        exit(1);
}
