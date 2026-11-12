<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Mail\Mailer;

/**
 * Password reset tokens (table password_reset_tokens), the port of Laravel's password broker with the old settings
 * (config/auth.php: expire 60 minutes, throttle 60 seconds):
 *  - one row per email; the token itself is never stored, only its bcrypt hash (Hash::make in Laravel);
 *  - a new link for the same address is refused for 60 seconds (THROTTLED, nothing is sent);
 *  - a token is valid for 60 minutes and is deleted after a successful reset (single use);
 *  - the reset email links to /reset-password/{token}?email=... (route password.reset).
 *
 *   PasswordBroker::sendResetLink('a@b.c')                 // 'sent' | 'user' (no such account) | 'throttled'
 *   PasswordBroker::reset('a@b.c', $token, 'NewPass123')   // 'reset' | 'user' | 'token'
 * Controllers answer the same thing for every outcome (no account enumeration).
 */
final class PasswordBroker
{
    public const EXPIRE_MINUTES = 60;
    public const THROTTLE_SECONDS = 60;

    public const SENT = 'sent';
    public const RESET = 'reset';
    public const INVALID_USER = 'user';
    public const INVALID_TOKEN = 'token';
    public const THROTTLED = 'throttled';

    public static function sendResetLink(string $email): string
    {
        $db = Database::get();
        $user = self::user($email);
        if (!$user) {
            return self::INVALID_USER;
        }
        $record = $db->one('SELECT * FROM password_reset_tokens WHERE email = ?', [$user['email']]);
        if ($record && $record['created_at'] && strtotime((string) $record['created_at']) + self::THROTTLE_SECONDS > time()) {
            return self::THROTTLED;
        }
        // hash_hmac('sha256', Str::random(40), APP_KEY), like Laravel's DatabaseTokenRepository::createNewToken().
        $token = hash_hmac('sha256', bin2hex(random_bytes(20)), (string) Config::get('key', 'abiha'));
        $db->exec('DELETE FROM password_reset_tokens WHERE email = ?', [$user['email']]);
        $db->exec('INSERT INTO password_reset_tokens (email, token, created_at) VALUES (?, ?, ?)', [$user['email'], Auth::hashPassword($token), now()]);

        Mailer::sendNow((string) $user['email'], 'reset-password', [
            'url' => route('password.reset', ['token' => $token, 'email' => $user['email']]),
            'expire' => self::EXPIRE_MINUTES,
        ]);
        return self::SENT;
    }

    /** Checks email + token, sets the new password (and a new remember_token), and uses the token up. */
    public static function reset(string $email, string $token, string $password): string
    {
        $db = Database::get();
        $user = self::user($email);
        if (!$user) {
            return self::INVALID_USER;
        }
        $record = $db->one('SELECT * FROM password_reset_tokens WHERE email = ?', [$user['email']]);
        $expired = !$record || !$record['created_at'] || strtotime((string) $record['created_at']) + self::EXPIRE_MINUTES * 60 < time();
        if ($expired || !Auth::verifyPassword($token, (string) $record['token'])) {
            return self::INVALID_TOKEN;
        }
        $db->exec('UPDATE users SET password = ?, remember_token = ?, updated_at = ? WHERE id = ?', [Auth::hashPassword($password), substr(bin2hex(random_bytes(30)), 0, 60), now(), $user['id']]);
        $db->exec('DELETE FROM password_reset_tokens WHERE email = ?', [$user['email']]);
        return self::RESET;
    }

    /** Active-or-not, but never a deleted (archived) account, like the Eloquent user provider with SoftDeletes. */
    private static function user(string $email): ?array
    {
        return Database::get()->one('SELECT id, email FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1', [mb_strtolower(trim($email))]);
    }
}
