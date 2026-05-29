<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Transfer;
use App\Models\Listing;

class HomeController extends Controller
{
    public function index()
    {
        if (auth()->check()) {
            return redirect()->route('portal.dashboard');
        }

        $stats = [
            'companies' => Company::where('kyc_status', 'approved')->count(),
            'transfers' => Transfer::count(),
            'listings'  => Listing::count(),
        ];

        return view('home', compact('stats'));
    }
}
