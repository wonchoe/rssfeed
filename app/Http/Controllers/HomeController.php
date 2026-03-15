<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Models\Subscription;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        return view('home', [
            'sourceCount' => Source::count(),
            'subscriptionCount' => Subscription::count(),
        ]);
    }
}
