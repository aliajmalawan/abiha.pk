<?php

declare(strict_types=1);

/** @var App\Core\Router $router */

// The routes live in files under app/routes/: storefront, cart, account, admin_catalog, admin_sales.
$__routeFiles = glob(__DIR__ . '/routes/*.php') ?: [];
sort($__routeFiles);
foreach ($__routeFiles as $__routeFile) {
    require $__routeFile;
}
unset($__routeFiles, $__routeFile);
