<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class BrandingController extends Controller
{
    public function branding(Request $request): \Illuminate\View\View
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);
        $branding = \App\Models\SystemSetting::branding();
        return view('admin.branding', compact('branding'));
    }

    public function brandingUpdate(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $validated = $request->validate([
            'circuit_name'    => ['required', 'string', 'max:80'],
            'circuit_tagline' => ['nullable', 'string', 'max:160'],
            'contact_email'   => ['nullable', 'email', 'max:120'],
            'contact_phone'   => ['nullable', 'string', 'max:40'],
            'website_url'     => ['nullable', 'url', 'max:200'],
            'primary_color'   => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color'    => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'footer_text'     => ['nullable', 'string', 'max:255'],
            'logo'            => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg', 'max:1024'],
        ]);

        $branding = \App\Models\SystemSetting::branding();

        if ($request->hasFile('logo')) {
            // Cancella vecchio logo
            if ($branding->logo_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($branding->logo_path);
            }
            $validated['logo_path'] = $request->file('logo')->store('branding', 'public');
        }

        unset($validated['logo']);
        $branding->update($validated);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.branding.update',
            'auditable_type' => \App\Models\SystemSetting::class,
            'auditable_id'   => $branding->id,
            'context'        => ['circuit_name' => $branding->circuit_name],
        ]);

        return back()->with('admin_success', 'Branding aggiornato con successo.');
    }
}
