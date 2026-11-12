<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Services\AccountOrders;

/** Port of the old Account\AccountController (overview, profile, password). */
final class AccountController extends AccountBase
{
    public function dashboard(Request $request): Response
    {
        $db = Database::get();
        $me = $this->me();
        $recent = array_map(static function (array $o) use ($db): array {
            $o['item_quantity'] = (int) $db->value('SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = ?', [$o['id']]);
            return $o;
        }, $db->all('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 3', [$me['id']]));

        return $this->accountView('account/dashboard', [
            'recentOrders' => $recent,
            'orderCount' => (int) $db->value('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$me['id']]),
            'wishlistCount' => (int) $db->value('SELECT COUNT(*) FROM wishlist_items wi JOIN wishlists w ON w.id = wi.wishlist_id WHERE w.user_id = ?', [$me['id']]),
            'defaultAddress' => $db->one('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, created_at DESC, id DESC LIMIT 1', [$me['id']]),
        ], 'My account', 'Hello, ' . explode(' ', (string) $me['name'])[0]);
    }

    public function profile(Request $request): Response
    {
        return $this->accountView('account/profile', [], 'Profile', 'Profile & password');
    }

    public function updateProfile(Request $request): Response
    {
        $me = $this->me();
        $email = $request->post['email'] ?? null;
        $changing = mb_strtolower(is_string($email) ? trim($email) : '') !== mb_strtolower((string) $me['email']);
        $v = Validator::make($request->post, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email,' . (int) $me['id']],
            'phone' => ['nullable', 'regex:/^[\d+\-\s()]{7,20}$/'],
            // Changing the sign-in email is an account-takeover path, so it needs the current password.
            'email_password' => $changing ? ['required', 'string'] : ['nullable', 'string'],
        ], ['email_password.required' => 'Enter your current password to change your email address.']);
        $errors = $v->errors();
        $pw = $request->post['email_password'] ?? null;
        if (!isset($errors['email_password']) && is_string($pw) && $pw !== '' && !self::currentPasswordOk($pw)) {
            $errors['email_password'] = 'The password is incorrect.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $data = $v->validated();
        Database::get()->exec('UPDATE users SET name = ?, email = ?, phone = ?, updated_at = ? WHERE id = ?', [$data['name'], $data['email'], $data['phone'], now(), $me['id']]);
        Auth::forget();

        return $this->back(route('account.profile'), 'Profile updated.');
    }

    public function updatePassword(Request $request): Response
    {
        $v = Validator::make($request->post, [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'max:72', 'min:8', 'password_letters', 'password_numbers', 'different:current_password'],
        ]);
        $errors = $v->errors();
        $current = $request->post['current_password'] ?? null;
        if (!isset($errors['current_password']) && !self::currentPasswordOk((string) $current)) {
            $errors = ['current_password' => 'The password is incorrect.'] + $errors;
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        Database::get()->exec('UPDATE users SET password = ?, updated_at = ? WHERE id = ?', [Auth::hashPassword((string) $request->post['password']), now(), Auth::id()]);
        // Every other session dies (their password fingerprint no longer matches); this one stays signed in.
        Auth::refreshPasswordFingerprint();
        Session::regenerate();

        return $this->back(route('account.profile'), 'Password changed.');
    }

    private static function currentPasswordOk(string $password): bool
    {
        $row = Auth::find((int) Auth::id());
        return $row !== null && Auth::verifyPassword($password, (string) $row['password']);
    }
}
