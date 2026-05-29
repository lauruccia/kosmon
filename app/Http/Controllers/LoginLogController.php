<?php

namespace App\Http\Controllers;

use App\Models\LoginLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LoginLogController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $logs = LoginLog::where('user_id', $user->id)
            ->orderByDesc('logged_in_at')
            ->paginate(25);

        return view('portal.login-logs', [
            'pageTitle'    => 'Sessioni e accessi',
            'activeNav'    => 'sessioni',
            'logs'         => $logs,
            'activeCount'  => DB::table('sessions')->where('user_id', $user->id)->count(),
        ]);
    }

    /**
     * Termina tutte le sessioni attive tranne quella corrente.
     */
    public function logoutAll(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user             = $request->user();
        $currentSessionId = $request->session()->getId();

        $terminated = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        $msg = $terminated > 0
            ? "Disconnesso da {$terminated} altro/i dispositivo/i."
            : 'Nessun altro dispositivo attivo da disconnettere.';

        return back()->with('portal_success', $msg);
    }
}
