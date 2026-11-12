<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\Csrf;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * In-process HTTP requests through App\Core\Kernel (global middleware, router, CSRF, controllers, error pages,
 * security headers). The session persists between calls like a browser cookie jar; flash data ages per request.
 *
 *   $r = http('GET', '/');                                     // guest
 *   $r = http('POST', '/cart/items', ['product_id' => 1], $user);  // as $user, CSRF token added automatically
 *   $r = http('PATCH', '/x', [...], null, ['csrf' => false, 'json' => true]);
 *   $r->status, $r->headers['Location'], $r->body
 *
 * $user: a users row to act as, null = keep the current session user, false = sign out first.
 */
function http(string $method, string $path, array $input = [], array|false|null $user = null, array $opts = []): Response
{
    if ($user === false) {
        Auth::actingAs(null);
    } elseif (is_array($user)) {
        Auth::actingAs($user);
    }
    Auth::forget();
    Session::ageFlash();
    $method = strtoupper($method);
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => ($opts['json'] ?? false) ? 'application/json' : 'text/html'];
    foreach ($opts['headers'] ?? [] as $k => $v) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
    }
    if ($method !== 'GET' && ($opts['csrf'] ?? true)) {
        $input['_token'] ??= Csrf::token();
    }
    [$path, $qs] = array_pad(explode('?', $path, 2), 2, '');
    $req = Request::create($method, $path, $input, $server);
    if ($qs !== '') {
        parse_str($qs, $q);
        $req = new Request($req->method, $req->path, $q + $req->query, $req->post, [], $req->server, []);
    }
    return Kernel::handle($req);
}
