<?php

/*
 * ABIHA.PK front controller for the cPanel "split" layout.
 *
 * This file REPLACES public/index.php inside public_html. The application itself (app/, vendor/, .env, storage/ …) lives
 * one level ABOVE the web root, where the web server can never serve it:
 *
 *     /home/CPANELUSER/
 *         abiha/          <- the application (private)
 *         public_html/    <- the contents of public/ + this index.php (public)
 *
 * If you named the private folder differently, change $appPath below. Nothing else needs editing.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$appPath = __DIR__.'/../abiha';

if (! is_file($appPath.'/vendor/autoload.php')) {
    http_response_code(500);
    exit('Application folder not found. Edit $appPath in public_html/index.php (see docs/DEPLOYMENT.md).');
}

// Maintenance mode (php artisan down) ...
if (file_exists($maintenance = $appPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appPath.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once $appPath.'/bootstrap/app.php';

// Laravel assumes the public files sit in <app>/public. Here they sit in public_html, so say so. Without this line the
// Vite manifest (CSS/JS), public/storage and the HTTPS/dotfile checks would all look in the wrong folder.
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
