<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Seo;

/** 403 / 404 / 419 / 429 pages inside the store layout (used by Core\ErrorHandler). */
final class ErrorPageController extends Controller
{
    private const TITLES = [
        403 => 'You do not have access',
        404 => 'Page not found',
        419 => 'Your session expired',
        429 => 'Too many attempts',
    ];

    public function render(int $status): string
    {
        Seo::reset();
        return $this->view('errors/' . $status, ['status' => $status], ['title' => self::TITLES[$status] ?? 'Error', 'noindex' => true], $status)->body;
    }
}
