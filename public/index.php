<?php

declare(strict_types=1);

/*
 * Front controller. Every request that is not an existing file under public/ ends up here
 * (public/.htaccess; the project-root .htaccess routes everything into public/).
 */

require dirname(__DIR__) . '/app/bootstrap.php';

App\Core\Kernel::handle(App\Core\Request::capture())->send();
