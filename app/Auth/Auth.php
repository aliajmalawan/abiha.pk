<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use App\Core\Session;

/**
 * Session authentication on the ABIHA tables (users, roles, permissions, permission_role), the port of the old
 * App\Models\User / Role / Permission rules:
 *
 *   Auth::user()     ?array  the signed-in user row (no password) + 'role' (slug|null), 'role_name', 'is_staff' (bool),
 *                            'permissions' (list of slugs; the Super Admin role "admin" implicitly holds every permission)
 *   Auth::check() / id() / isStaff() / isSuperAdmin() / can('orders.manage')
 *   Auth::attempt($email, $password)  ?array  the user when the credentials are right AND the account is active
 *   Auth::login($user) / logout()     session regeneration (prevents fixation), last_login_at
 *   Auth::hashPassword() / verifyPassword()  bcrypt ($2y$, cost 12), compatible with the Laravel hashes in the database
 *   Auth::actingAs($user|null)        tests
 *
 * Rules kept from Laravel:
 *  - a disabled (is_active = 0) or soft-deleted account loses access on its very next request (EnsureAccountActive);
 *  - changing the password signs out every other session (AuthenticateSession): the session stores a fingerprint of the
 *    password hash and is dropped when it no longer matches;
 *  - staff = a role with is_staff = 1 (admin, manager, staff, product/inventory/content managers); customers have role
 *    "customer" or no role; permissions only count for an active staff user.
 */
final class Auth
{
    public const SUPER_ADMIN = 'admin';
    public const CUSTOMER = 'customer';

    private static ?array $user = null;
    private static bool $loaded = false;
    /** Set when the current request found a disabled account (the auth slice shows "This account has been disabled."). */
    private static bool $wasDisabled = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            self::$user = null;
            $id = Session::get('auth_id');
            if (is_int($id) && $id > 0) {
                $u = self::find($id);
                $fp = Session::get('auth_pw');
                if ($u && (int) $u['is_active'] === 1 && $u['deleted_at'] === null && is_string($fp) && hash_equals(self::fingerprint($u), $fp)) {
                    self::$user = self::present($u);
                } else {
                    if ($u && ((int) $u['is_active'] !== 1 || $u['deleted_at'] !== null)) {
                        self::$wasDisabled = true;
                    }
                    self::logout();
                }
            }
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** "May enter the admin area": the role is a staff role. */
    public static function isStaff(): bool
    {
        $u = self::user();
        return $u !== null && $u['is_staff'];
    }

    public static function isSuperAdmin(): bool
    {
        $u = self::user();
        return $u !== null && $u['is_staff'] && $u['role'] === self::SUPER_ADMIN;
    }

    /** Dotted permission slugs ('products.manage'), granted through the user's role. */
    public static function can(string $permission): bool
    {
        $u = self::user();
        return $u !== null && self::userCan($u, $permission);
    }

    /** Same rule for any presented user array (e.g. listing staff). */
    public static function userCan(array $user, string $permission): bool
    {
        if (empty($user['is_staff']) || (int) ($user['is_active'] ?? 0) !== 1) {
            return false;
        }
        return ($user['role'] ?? null) === self::SUPER_ADMIN || in_array($permission, $user['permissions'] ?? [], true);
    }

    /** True when the session belonged to an account that has since been disabled or deleted. */
    public static function wasDisabled(): bool
    {
        return self::$wasDisabled;
    }

    /**
     * Checks credentials. Returns the user row (raw, with password) when the email exists, the password matches and the
     * account is active and not deleted; null otherwise. Does NOT log in. The email is compared case-insensitively.
     */
    public static function attempt(string $email, string $password): ?array
    {
        $row = Database::get()->one('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1', [mb_strtolower(trim($email))]);
        if (!$row) {
            self::dummyVerify($password);
            return null;
        }
        if (!self::verifyPassword($password, (string) $row['password']) || (int) $row['is_active'] !== 1) {
            return null;
        }
        if (self::needsRehash((string) $row['password'])) {
            Database::get()->exec('UPDATE users SET password = ? WHERE id = ?', [self::hashPassword($password), $row['id']]);
            $row = Database::get()->one('SELECT * FROM users WHERE id = ?', [$row['id']]);
        }
        return $row;
    }

    /** Signs a user in (new session id, so a fixed/stolen pre-login id is useless) and stamps last_login_at. */
    public static function login(array $user): void
    {
        $row = isset($user['password']) ? $user : self::find((int) $user['id']);
        if (!$row) {
            throw new \RuntimeException('Unknown user.');
        }
        // Keep the guest cart token and wishlist so they can be merged into the account after login.
        $keep = [];
        foreach (['cart_token', 'wishlist_ids', 'intended'] as $k) {
            if (Session::get($k) !== null) {
                $keep[$k] = Session::get($k);
            }
        }
        Session::regenerate();
        foreach ($keep as $k => $v) {
            Session::put($k, $v);
        }
        Session::put('auth_id', (int) $row['id']);
        Session::put('auth_pw', self::fingerprint($row));
        Session::forget('admin_last_activity');
        Database::get()->exec('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $row['id']]);
        self::$loaded = false;
        self::$user = null;
        self::$wasDisabled = false;
    }

    /** Signs out: the whole session is cleared and gets a new id and CSRF token (Laravel's logout + invalidate). */
    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
        self::$loaded = true;
    }

    /**
     * After the user changed their own password: keep THIS session signed in with the new fingerprint (other sessions
     * are signed out automatically because their fingerprint no longer matches).
     */
    public static function refreshPasswordFingerprint(): void
    {
        $id = Session::get('auth_id');
        if (is_int($id) && ($row = self::find($id))) {
            Session::put('auth_pw', self::fingerprint($row));
            self::$loaded = false;
        }
    }

    /** Tests/CLI: act as this user (array with at least 'id'), or as a guest with null. */
    public static function actingAs(?array $user): void
    {
        self::$wasDisabled = false;
        if ($user === null) {
            Session::forget('auth_id');
            Session::forget('auth_pw');
            self::$user = null;
            self::$loaded = true;
            return;
        }
        $row = self::find((int) $user['id']);
        if (!$row) {
            throw new \RuntimeException('Unknown user.');
        }
        Session::put('auth_id', (int) $row['id']);
        Session::put('auth_pw', self::fingerprint($row));
        self::$loaded = false;
        self::$user = null;
    }

    /** Forget the cached user so the next Auth::user() re-reads the database (after changing the user's row). */
    public static function forget(): void
    {
        self::$user = null;
        self::$loaded = false;
    }

    /** bcrypt, cost 12 ($2y$): the same format Laravel's Hash::make wrote, so existing passwords keep working. */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** Spends about the same time as a real check, so "unknown email" and "wrong password" cannot be told apart. */
    public static function dummyVerify(string $password): void
    {
        static $hash = null;
        $hash ??= self::hashPassword('dummy-password-for-timing');
        password_verify($password, $hash);
    }

    /** Raw users row (with password) or null. */
    public static function find(int $id): ?array
    {
        return Database::get()->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /**
     * The public shape of a user: the row without password/remember_token, plus role, role_name, is_staff and permissions.
     *
     * @param array<string,mixed> $row a users row
     */
    public static function present(array $row): array
    {
        $db = Database::get();
        $role = $row['role_id'] ? $db->one('SELECT id, slug, name, is_staff FROM roles WHERE id = ?', [$row['role_id']]) : null;
        $perms = $role ? $db->column(
            'SELECT p.slug FROM permissions p JOIN permission_role pr ON pr.permission_id = p.id WHERE pr.role_id = ? ORDER BY p.slug',
            [$role['id']]
        ) : [];
        unset($row['password'], $row['remember_token']);
        $row['role'] = $role['slug'] ?? null;
        $row['role_name'] = $role['name'] ?? null;
        $row['is_staff'] = (bool) ($role['is_staff'] ?? false);
        $row['permissions'] = array_map('strval', $perms);
        return $row;
    }

    private static function fingerprint(array $row): string
    {
        return hash_hmac('sha256', (string) $row['password'], (string) \App\Core\Config::get('key', 'abiha'));
    }
}
