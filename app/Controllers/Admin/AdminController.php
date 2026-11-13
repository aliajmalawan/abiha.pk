<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Response;
use App\Core\Session;

/** Shared helpers of the admin controllers. */
abstract class AdminController extends Controller
{
    /** Laravel's back()->withErrors([...]): flash keyed errors and return to the previous page (else $fallback). */
    protected function backWithErrors(array $errors, string $fallback, bool $withInput = false, array $input = []): Response
    {
        Session::flash('errors', $errors);
        if ($withInput) {
            unset($input['_token'], $input['_method'], $input['card_api_secret'], $input['card_webhook_secret']);   // secrets are never put back into a form
            Session::flash('old', $input);
        }
        return $this->back($fallback);
    }
}
