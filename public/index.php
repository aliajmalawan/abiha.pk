<?php

declare(strict_types=1);

/*
 * Front controller. Every request that is not an existing file under public/ ends up here
 * (public/.htaccess; the project-root .htaccess routes everything into public/).
 */

require dirname(__DIR__) . '/app/bootstrap.php';

// Visitor statistics are written AFTER the page has been sent, so counting a visit never makes it slower.
App\Services\Analytics::$deferred = true;
App\Core\Kernel::handle(App\Core\Request::capture())->send();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @flush();
}
App\Services\Analytics::runDeferred();
