<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Auth\Auth;
use App\Controllers\Controller;
use App\Core\Response;
use App\Core\View;

/** Shared by the account controllers: renders a page inside account/layout (the old @extends('account.layout')). */
abstract class AccountBase extends Controller
{
    protected function accountView(string $template, array $data, string $title, string $heading = 'My account'): Response
    {
        $this->shareChrome();
        $inner = View::partial($template, $data);
        return $this->view('account/layout', ['account' => $inner, 'heading' => $heading], ['title' => $title, 'noindex' => true]);
    }

    /** @return array the signed-in user (the auth middleware guarantees one) */
    protected function me(): array
    {
        return Auth::user() ?? [];
    }
}
