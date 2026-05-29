<?php

namespace App\Http\Controllers;

use App\Models\SupportMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\Mail;

class HelpController extends Controller
{
    public function index(): View
    {
        return view('help.index', ['pageTitle' => 'Centro Assistenza']);
    }

    public function contact(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'    => ['required', 'string', 'max:100'],
            'email'   => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:200'],
            'body'    => ['required', 'string', 'max:3000'],
        ]);

        $validated['user_id'] = $request->user()?->id;

        $msg = SupportMessage::create($validated);

        // Notifica admin via email
        try {
            $branding = \App\Models\SystemSetting::branding();
            $adminEmail = $branding->contact_email ?? config('mail.from.address');
            Mail::raw(
                "Nuova richiesta assistenza #{$msg->id}\nDa: {$msg->name} <{$msg->email}>\nOggetto: {$msg->subject}\n\n{$msg->body}",
                function ($m) use ($adminEmail, $msg) {
                    $m->to($adminEmail)
                      ->subject("[KMoney Support] {$msg->subject}");
                }
            );
        } catch (\Throwable) { /* silenzioso */ }

        return redirect()->route('help.index')
            ->with('success', 'Messaggio inviato! Ti risponderemo entro 1-2 giorni lavorativi.');
    }
}
