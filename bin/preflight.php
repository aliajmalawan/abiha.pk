<?php

declare(strict_types=1);

/*
 * "Can this server run ABIHA correctly?" (port of the old `abiha:preflight`). Read-only.
 * Run via: php bin/console.php preflight   (exit code 1 when anything FAILS)
 */

use App\Core\Config;
use App\Core\Database;

$fails = 0;
$warns = 0;
$check = static function (bool $ok, string $pass, string $fail, bool $warnOnly = false) use (&$fails, &$warns): void {
    if ($ok) {
        if ($pass !== '') {
            fwrite(STDOUT, "  PASS  {$pass}\n");
        }
    } elseif ($warnOnly) {
        $warns++;
        fwrite(STDOUT, "  WARN  {$fail}\n");
    } else {
        $fails++;
        fwrite(STDOUT, "  FAIL  {$fail}\n");
    }
};
$section = static fn (string $t) => fwrite(STDOUT, "\n{$t}\n");
$bytes = static function (string $ini): int {
    $ini = trim($ini);
    $n = (int) $ini;
    return match (strtolower(substr($ini, -1))) { 'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n };
};

$section('PHP');
$check(PHP_VERSION_ID >= 80100, 'PHP ' . PHP_VERSION, 'PHP ' . PHP_VERSION . ' is too old: ABIHA needs PHP 8.1 or newer (cPanel -> Select PHP Version)');
$missing = array_values(array_filter(['mysqli', 'gd', 'mbstring', 'fileinfo', 'openssl', 'json', 'dom'], static fn ($e) => !extension_loaded($e)));
$check($missing === [], 'Required extensions are loaded (mysqli, gd, mbstring, fileinfo, openssl, json, dom)', 'Missing PHP extensions: ' . implode(', ', $missing) . ' (cPanel -> Select PHP Version -> Extensions)');
$check(function_exists('imagewebp'), 'GD can write WebP images (responsive product photos)', 'GD has no WebP support: uploads still work, but pages serve larger JPEG/PNG files', true);
$check(extension_loaded('exif'), 'exif is loaded (phone photos keep their orientation)', 'The exif extension is missing: photos taken on phones may appear rotated', true);
$check(class_exists('ZipArchive'), 'zip is loaded (photo backups)', 'The zip extension is missing: `backup` cannot pack the photos', true);
$mem = (string) ini_get('memory_limit');
$check((int) $mem === -1 || $bytes($mem) >= 128 * 1024 * 1024, 'memory_limit is at least 128M', "memory_limit is {$mem}: image processing may fail; raise it to 256M", true);
$check($bytes((string) ini_get('upload_max_filesize')) >= 8 * 1024 * 1024, 'upload_max_filesize allows product photos (' . ini_get('upload_max_filesize') . ')', 'upload_max_filesize is ' . ini_get('upload_max_filesize') . ': raise it to at least 8M', true);
$check($bytes((string) ini_get('post_max_size')) >= 24 * 1024 * 1024, 'post_max_size allows several photos at once (' . ini_get('post_max_size') . ')', 'post_max_size is ' . ini_get('post_max_size') . ': raise it to at least 24M or multi-image uploads will silently fail', true);

$section('Web-root layout (keeps secrets out of public view)');
foreach (['.env', 'app', 'bin', 'database', 'storage/logs', '.git', 'composer.json'] as $secret) {
    if (file_exists(PUBLIC_PATH . '/' . $secret)) {
        $check(false, '', "'{$secret}' exists inside public/: remove it at once");
    }
}
$check(is_file(PUBLIC_PATH . '/index.php'), 'public/index.php is present', 'public/index.php is missing: the upload is incomplete');
$check(is_file(PUBLIC_PATH . '/.htaccess'), 'public/.htaccess is present', 'public/.htaccess is missing (Apache: front controller and dotfile protection)');
$check(is_file(BASE_PATH . '/.htaccess'), 'Project-root .htaccess is present (protects the code if the document root is the project folder)', 'The project-root .htaccess is missing: if the domain points at the project folder, code and .env could be downloaded', true);
$check(is_file(BASE_PATH . '/storage/.htaccess'), 'storage/.htaccess denies direct access', 'storage/.htaccess is missing');
$check(is_file(PUBLIC_PATH . '/assets/abiha/abiha.css') && is_file(PUBLIC_PATH . '/assets/abiha/abiha.js'), 'Compiled CSS/JS are present (public/assets/abiha)', 'public/assets/abiha/abiha.css or abiha.js is missing');

$section('Environment');
$check(is_file(BASE_PATH . '/.env'), '.env exists', '.env is missing: copy .env.example to .env (or run `php bin/console.php setup`) and fill it in');
$key = (string) Config::get('key');
$check(strlen($key) >= 32 && !str_contains($key, 'CHANGE_ME'), 'APP_KEY is set', 'APP_KEY is missing or a placeholder: put 64 random hex characters in .env');
$check(Config::isProduction(), 'APP_ENV is production', 'APP_ENV is "' . Config::get('env') . '": set APP_ENV=production on the live site', true);
if (Config::isProduction()) {
    foreach (Config::productionProblems() as $p) {
        $check(false, '', $p);
    }
}
$check(Config::get('timezone') === 'Asia/Karachi', 'Timezone is Asia/Karachi (same as the old site; times in the database are business time)', 'BUSINESS_TIMEZONE is ' . Config::get('timezone') . ': the stored times and daily reports assume Asia/Karachi', true);
$check(Config::get('mail.driver') === 'smtp', 'SMTP mail is configured', 'MAIL_DRIVER is "' . Config::get('mail.driver') . '": password-reset and order emails are only written to storage/mail, never delivered', true);

$section('Database');
try {
    $db = Database::get();
    $check(true, 'Connected to database "' . Config::get('db.name') . '"', '');
    $tables = $db->column('SHOW TABLES');
    $check(in_array('users', $tables, true) && in_array('products', $tables, true), 'ABIHA tables exist', 'The ABIHA tables are missing: run `php bin/console.php migrate`');
    $check(in_array('rate_limits', $tables, true) && in_array('email_outbox', $tables, true), 'Runtime tables exist (rate_limits, email_outbox)', 'Runtime tables are missing: run `php bin/console.php migrate`');
    if (in_array('roles', $tables, true)) {
        $check((bool) $db->value("SELECT COUNT(*) FROM roles WHERE slug = 'admin'"), 'Roles and permissions are present', 'No roles yet: run `php bin/console.php migrate`');
        $check((bool) $db->value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'admin' AND u.is_active = 1 AND u.deleted_at IS NULL"),
            'An active administrator exists', 'No administrator yet: run `php bin/console.php admin:create you@yourdomain.com`', true);
    }
} catch (\Throwable $e) {
    $check(false, '', 'Cannot connect to the database (' . (new \ReflectionClass($e))->getShortName() . '): check DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD in .env');
}

$section('Storage');
foreach (['storage/cache', 'storage/sessions', 'storage/logs', 'storage/mail', 'storage/backups', 'storage/app/private'] as $dir) {
    $abs = BASE_PATH . '/' . $dir;
    if (!is_dir($abs)) {
        @mkdir($abs, 0775, true);
    }
    $check(is_dir($abs) && is_writable($abs), "{$dir} is writable", "{$abs} is missing or not writable: set permissions to 775");
}
$disk = (string) Config::get('filesystems.public_root');
$check(is_dir($disk) && is_writable($disk), 'Uploads folder is writable (' . $disk . ')', "{$disk} is missing or not writable");
$check(is_file($disk . '/.htaccess'), 'Uploads folder blocks script execution', 'The uploads folder has no .htaccess: copy storage/app/public/.htaccess from the release');
$reachable = file_exists(PUBLIC_PATH . '/storage') || str_starts_with(realpath($disk) ?: $disk, realpath(PUBLIC_PATH) ?: PUBLIC_PATH);
$check($reachable, 'Uploaded images are reachable at /storage', 'public/storage is missing, so uploaded images will not display: run `php bin/console.php storage:link` (or set PUBLIC_DISK_ROOT if symlinks are disabled)');
$free = @disk_free_space(BASE_PATH);
$check($free === false || $free > 200 * 1024 * 1024, 'Disk space is fine', 'Less than 200 MB of disk space is free', true);

fwrite(STDOUT, "\n" . ($fails ? "{$fails} failed, {$warns} warning(s). Fix the FAIL lines before going live.\n" : "Ready, {$warns} warning(s).\n"));
return $fails ? 1 : 0;
