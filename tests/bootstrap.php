<?php

declare(strict_types=1);

/*
 * Test bootstrap: points the app at the dedicated test database (DB_TEST_DATABASE, default "abiha_pk_test"),
 * recreates it from database/migrations/*.sql and provides a tiny assertion framework (no Composer required).
 *
 * Suites (tests/run.php): UnitTest.php (no database writes), IntegrationTest.php, HttpTest.php (in-process requests
 * through App\Core\Kernel: router, middleware, CSRF, controllers, error pages, security headers).
 * Each slice may add its own files named tests/<Slice>*Test.php; run.php picks up every *Test.php file.
 */

putenv('APP_ENV=testing');
putenv('ABIHA_SAMPLE_LABELS=true');   // tests check the development placeholder labels, whatever the local .env says
putenv('APP_DEBUG=false');
foreach (['CARD_PAYMENT_DRIVER', 'CARD_API_KEY', 'CARD_API_SECRET', 'CARD_WEBHOOK_SECRET'] as $cardVar) {
    putenv($cardVar . '=');   // the tests set card options themselves, whatever the local .env holds
}
putenv('PAYMENTS_ENABLED=cod,bank_transfer');
putenv('MAIL_DRIVER=array');
putenv('APP_URL=http://localhost/abiha.pk');

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;

$testDb = (string) (Env::get('DB_TEST_DATABASE') ?: 'abiha_pk_test');
if ($testDb === Config::get('db.name') || !preg_match('/test/i', $testDb)) {
    fwrite(STDERR, "Refusing to run: DB_TEST_DATABASE must be a separate database whose name contains 'test'.\n");
    exit(2);
}
Config::set('key', str_repeat('k', 64));

// Uploads of the test run go to a scratch folder, never to the real storage/app/public.
$scratch = BASE_PATH . '/tests/.tmp';
if (is_dir($scratch)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
}
@mkdir($scratch . '/public', 0775, true);
@mkdir($scratch . '/private', 0775, true);
Config::set('filesystems.public_root', $scratch . '/public');
Config::set('filesystems.private_root', $scratch . '/private');

// Create a fresh schema.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$cfg = Config::get('db');
$root = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], '', $cfg['port']);
$root->query('DROP DATABASE IF EXISTS `' . $root->real_escape_string($testDb) . '`');
$root->query('CREATE DATABASE `' . $root->real_escape_string($testDb) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root->close();
Config::set('db.name', $testDb);
Database::swap(null);
$files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
sort($files);
$m = Database::get()->raw();
foreach ($files as $file) {
    $m->multi_query((string) file_get_contents($file));
    do {
        if ($r = $m->store_result()) {
            $r->free();
        }
    } while ($m->more_results() && $m->next_result());
}

final class T
{
    public static int $pass = 0;
    public static array $failures = [];
    public static string $current = '';

    public static function ok(bool $cond, string $msg): void
    {
        if ($cond) {
            self::$pass++;
        } else {
            self::$failures[] = self::$current . ': ' . $msg;
        }
    }

    public static function eq(mixed $expected, mixed $actual, string $msg): void
    {
        self::ok($expected === $actual, $msg . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }

    public static function contains(string $needle, string $haystack, string $msg): void
    {
        self::ok(str_contains($haystack, $needle), $msg . ' (missing: ' . mb_substr($needle, 0, 120) . ')');
    }

    /** Asserts that $fn throws $class (optionally with a message containing $contains). */
    public static function throws(string $class, callable $fn, string $msg, ?string $contains = null): void
    {
        try {
            $fn();
            self::ok(false, $msg . ' (no exception)');
        } catch (\Throwable $e) {
            $okClass = $e instanceof $class;
            $text = $e instanceof \App\Core\ValidationException ? $e->getMessage() . ' ' . implode(' ', $e->errors) : $e->getMessage();
            self::ok($okClass && ($contains === null || str_contains($text, $contains)), $msg . ($okClass ? '' : ' (got ' . $e::class . ': ' . $e->getMessage() . ')') . ($contains !== null && $okClass && !str_contains($text, $contains) ? " (message: {$text})" : ''));
        }
    }
}

/** Wipes all data tables (keeps roles/permissions/attributes/home layout from 002_base_data) and every static cache. */
function reset_db(bool $keepBaseData = true): void
{
    $db = Database::get();
    $keep = $keepBaseData ? ['roles', 'permissions', 'permission_role', 'attributes', 'attribute_values', 'home_sections', 'schema_migrations'] : [];
    $db->raw()->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($db->column("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'") as $t) {
        if (!in_array($t, $keep, true)) {
            $db->raw()->query('TRUNCATE TABLE `' . $t . '`');
        }
    }
    $db->raw()->query('SET FOREIGN_KEY_CHECKS = 1');
    reset_state();
}

/** Clears per-request static state (what a new PHP request would start with), keeping the database. */
function reset_state(): void
{
    App\Support\Settings::flush();
    App\Support\CatalogCache::bump();
    App\Support\CatalogCache::reset();
    App\Support\Img::forget();
    App\Support\Seo::reset();
    App\Core\View::reset();
    App\Auth\Auth::actingAs(null);
    App\Core\Session::resetMemory();
    App\Mail\Mailer::$sent = [];
}
