<?php

declare(strict_types=1);

/*
 * Application bootstrap shared by the web front controller (public/index.php),
 * the CLI console (bin/console.php) and the test runner (tests/run.php).
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('PUBLIC_PATH', BASE_PATH . '/public');

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('ABIHA requires PHP 8.1 or newer.');
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_PATH . '/helpers.php';

App\Core\Env::load(BASE_PATH . '/.env');
App\Core\Config::boot();

// Like the old Laravel app (config app.timezone = Asia/Karachi), times are stored and shown in business time:
// write them with now() (PHP), never NOW()/UTC_TIMESTAMP() in SQL.
date_default_timezone_set((string) App\Core\Config::get('timezone', 'Asia/Karachi'));
mb_internal_encoding('UTF-8');

App\Core\ErrorHandler::register();
