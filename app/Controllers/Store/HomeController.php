<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Controllers\Controller;
use App\Core\Response;
use App\Services\HomePage;
use App\Support\SchemaBuilder;

/** The home page (port of the old Storefront\HomeController). */
final class HomeController extends Controller
{
    public function index(): Response
    {
        return $this->view('store/home', ['sections' => HomePage::sections()], [
            'description' => setting('seo.description') ?: 'Discover ' . cfg('abiha.name') . '®: considered pieces, quietly luxurious.',
            // Organization + WebSite describe the brand once, on the home page. Facts come from the admin settings only.
            'jsonld' => [SchemaBuilder::organization(), SchemaBuilder::website()],
        ]);
    }
}
