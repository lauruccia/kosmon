<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class ReferralController extends Controller
{
    /**
     * GET /portale/invita
     * Pagina con link referral personale e stats inviti.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        // Genera il codice se non esiste ancora
        $user->referralCode();
        $user->refresh();

        $referrals = $user->referrals()
            ->with('company')
            ->latest()
            ->get();

        return view('portal.referral', [
            'pageTitle'    => 'Invita un amico',
            'currentUser'  => $user,
            'referralUrl'  => $user->referralUrl(),
            'referralCode' => $user->referral_code,
            'referrals'    => $referrals,
            'activeNav'    => 'profilo',
        ]);
    }
}
