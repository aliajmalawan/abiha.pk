<?php

declare(strict_types=1);

/** @var App\Core\Router $router */

// Every slice keeps its routes in its own file under app/routes/ (see docs/PORTING.md).
// foundation.php is loaded LAST: it only registers fallbacks for route names no slice has defined yet.
$__routeFiles = glob(__DIR__ . '/routes/*.php') ?: [];
sort($__routeFiles);
usort($__routeFiles, static fn (string $a, string $b): int => (basename($a) === 'foundation.php') <=> (basename($b) === 'foundation.php'));
foreach ($__routeFiles as $__routeFile) {
    require $__routeFile;
}
unset($__routeFiles, $__routeFile);
