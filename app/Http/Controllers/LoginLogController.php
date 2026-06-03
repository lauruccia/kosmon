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

        $currentSessionId = $request->session()->getId();

        $activeSessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(function ($session) use ($currentSessionId) {
                $session->is_current = ($session->id === $currentSessionId);
                return $session;
            });

        return view('portal.login-logs', [
            'pageTitle'      => 'Sessioni e accessi',
            'activeNav'      => 'sessioni',
            'logs'           => $logs,
            'activeSessions' => $activeSessions,
            'activeCount'    => $activeSessions->count(),
        ]);
    }

    /**
     * Termina una singola sessione attiva (non quella corrente).
     */
    public function logoutSession(Request $request, string $sessionId): \Illuminate\Http\RedirectResponse
    {
        $user             = $request->user();
        $currentSessionId = $request->session()->getId();

        if ($sessionId === $currentSessionId) {
            return back()->with('portal_error', 'Non puoi disconnettere la sessione corrente da qui.');
        }

        $deleted = DB::table('sessions')
            ->where('id', $sessionId)
            ->where('user_id', $user->id)
            ->delete();

        $msg = $deleted
            ? 'Dispositivo disconnesso con successo.'
            : 'Sessione non trovata o già terminata.';

        return back()->with('portal_success', $msg);
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
