<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Services\PasswordBroker;

/** Port of the old Auth\PasswordResetController (forgot password + reset with a single-use, 60-minute token). */
final class PasswordResetController extends Controller
{
    public function request(Request $request): Response
    {
        return $this->view('auth/forgot-password', [], ['title' => 'Forgot password', 'noindex' => true]);
    }

    public function email(Request $request): Response
    {
        $data = $this->validate($request, ['email' => 'required|email']);
        PasswordBroker::sendResetLink((string) $data['email']);

        // Identical answer whether or not the account exists (no account enumeration).
        return $this->back(route('password.request'), 'If an account exists for that email, a reset link is on its way.');
    }

    public function form(Request $request): Response
    {
        $email = $request->query['email'] ?? null;
        return $this->view('auth/reset-password', [
            'token' => (string) ($request->params['token'] ?? ''),
            'email' => is_string($email) ? $email : null,
        ], ['title' => 'Reset password', 'noindex' => true]);
    }

    public function update(Request $request): Response
    {
        $data = $this->validate($request, [
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'string', 'confirmed', 'max:72', 'min:8', 'password_letters', 'password_numbers'],
        ]);

        $status = PasswordBroker::reset((string) $data['email'], (string) $data['token'], (string) $request->post['password']);
        if ($status === PasswordBroker::RESET) {
            return $this->redirect(route('login'), 'Your password has been reset. You can sign in now.');
        }
        // One generic message for an unknown address and a wrong/expired/used token (no account enumeration).
        throw ValidationException::withMessages(['email' => 'This password reset token is invalid.']);
    }
}
