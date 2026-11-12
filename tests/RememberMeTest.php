<?php

declare(strict_types=1);

/* "Remember me" on the customer login: a long-lived cookie signs the user in again after the session is gone. */

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Session;

T::$current = 'Remember me';
reset_db();
$u = Fx::user('customer');
$name = Auth::rememberCookieName();
unset($_COOKIE[$name]);
$token = static fn () => Database::get()->value('SELECT remember_token FROM users WHERE id = ?', [$u['id']]);
$dropSession = static function (): void {
    Session::forget('auth_id');
    Session::forget('auth_pw');
    Auth::forget();
};

http('POST', '/login', ['email' => $u['email'], 'password' => Fx::PASSWORD], false);
T::ok(!isset($_COOKIE[$name]), 'no remember cookie without the tick');
T::eq(null, $token(), 'no token stored without the tick');

$r = http('POST', '/login', ['email' => $u['email'], 'password' => Fx::PASSWORD, 'remember' => '1'], false);
T::ok($r->status >= 300 && $r->status < 400, 'login with the tick redirects');
$cookie = $_COOKIE[$name] ?? null;
T::ok(is_string($cookie) && preg_match('/^\d+\|[a-f0-9]{60}$/', $cookie) === 1, 'remember cookie is "id|token"');
T::ok(is_string($token()) && strlen((string) $token()) === 60 && str_ends_with((string) $cookie, '|' . $token()), 'the token is stored on the user');
T::ok(Auth::$lastRememberCookie[2] > time() + 60 * 60 * 24 * 365 * 4, 'the cookie lives for years');

$dropSession();
$r = http('GET', '/account');
T::eq(200, $r->status, 'a request with only the cookie is signed in again');
T::eq((int) $u['id'], Auth::id(), 'as the same user');
T::eq($cookie, $_COOKIE[$name] ?? null, 'the cookie is kept');

$dropSession();
$_COOKIE[$name] = $u['id'] . '|' . str_repeat('0', 60);
$r = http('GET', '/account');
T::ok($r->status >= 300 && $r->status < 400, 'a wrong token is not signed in');
T::ok(!isset($_COOKIE[$name]), 'a wrong token clears the cookie');

$dropSession();
$_COOKIE[$name] = $cookie;
Database::get()->exec('UPDATE users SET is_active = 0 WHERE id = ?', [$u['id']]);
T::ok(http('GET', '/account')->status !== 200, 'a disabled account is not signed in by the cookie');
Database::get()->exec('UPDATE users SET is_active = 1 WHERE id = ?', [$u['id']]);

$dropSession();
$_COOKIE[$name] = $cookie;
T::eq(200, http('GET', '/account')->status, 'signed in again by the cookie');
http('POST', '/logout');
T::ok(!isset($_COOKIE[$name]), 'logout clears the cookie');
T::eq(null, $token(), 'logout clears the stored token');
$dropSession();
T::ok(http('GET', '/account')->status !== 200, 'after logout the old cookie value is useless');
unset($_COOKIE[$name]);
