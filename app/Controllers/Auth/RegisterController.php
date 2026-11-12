<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Auth\Auth;
use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/** Port of the old Auth\RegisterController + RegisterRequest. */
final class RegisterController extends Controller
{
    public function show(Request $request): Response
    {
        return $this->view('auth/register', [], ['title' => 'Create account', 'noindex' => true]);
    }

    public function store(Request $request): Response
    {
        // One person, one account: "Sana@Example.com" and "sana@example.com" are the same address.
        $input = $request->post;
        if (is_string($input['email'] ?? null)) {
            $input['email'] = mb_strtolower(trim($input['email']));
        }
        $data = $this->validate($input, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'regex:/^[\d+\-\s()]{7,20}$/'],
            'password' => ['required', 'string', 'confirmed', 'max:72', 'min:8', 'password_letters', 'password_numbers'],   // bcrypt reads 72 bytes
        ]);

        // The role is never taken from input: every self-registration is a customer.
        $db = Database::get();
        $roleId = $db->value('SELECT id FROM roles WHERE slug = ?', [Auth::CUSTOMER]);
        $now = now();
        $id = $db->insert(
            'INSERT INTO users (name, email, phone, password, role_id, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
            [$data['name'], $data['email'], $data['phone'], Auth::hashPassword((string) $request->post['password']), $roleId, $now, $now]
        );

        Auth::login(['id' => $id]);
        LoginController::mergeGuestData($id);

        return $this->intended(route('account.dashboard'), 'Welcome to ABIHA®. Your account is ready.');
    }
}
