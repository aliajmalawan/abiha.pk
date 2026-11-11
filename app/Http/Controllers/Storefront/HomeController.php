<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\HomePageService;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function __invoke(HomePageService $home): View
    {
        return view('storefront.home', ['sections' => $home->sections()]);
    }
}
