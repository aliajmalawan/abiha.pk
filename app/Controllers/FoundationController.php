<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;

/** Temporary placeholder home page (foundation smoke route); replaced by the storefront slice's "home" route. */
final class FoundationController extends Controller
{
    public function home(): Response
    {
        return $this->view('foundation/home');
    }
}
