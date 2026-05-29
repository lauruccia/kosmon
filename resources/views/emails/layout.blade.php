{{-- Email layout branded — legge colori/nome da SystemSetting::branding() --}}
@php
    $brand = \App\Models\SystemSetting::branding();
    $primaryColor = $brand->primary_color ?? '#3d5566';
    $accentColor  = $brand->accent_color  ?? '#4d7a52';
    $circuitName  = $brand->circuit_name  ?? 'KMoney';
    $circuitTagline = $brand->circuit_tagline ?? 'La moneta complementare del Gruppo Kosmos';
    $logoUrl      = $brand->logoUrl();
    $footerText   = $brand->footer_text
        ?: ('© ' . date('Y') . ' ' . $circuitName . ' — tutti i diritti riservati');
    $contactEmail = $brand->contact_email ?? null;

    // Variante più scura del colore primario per header gradient
    // Semplificazione: abbassa la luminosità del 15%
    $darkPrimary = $primaryColor; // usa stesso colore per email clients compatibility
@endphp<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $emailTitle ?? $circuitName }}</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{background-color:#f4f7fb;font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;color:#1a2233;-webkit-font-smoothing:antialiased}
        .wrapper{max-width:600px;margin:0 auto;padding:32px 16px}
        .email-card{background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(15,34,58,.08)}
        .email-header{background:{{ $primaryColor }};padding:28px 32px}
        .brand-mark{width:44px;height:44px;background:rgba(255,255,255,.18);border-radius:12px;display:inline-flex;align-items:center;justify-content:center;font-size:15px;font-weight:800;color:#ffffff;letter-spacing:.02em;vertical-align:middle}
        .brand-name{font-size:22px;font-weight:700;color:#ffffff;letter-spacing:.02em;vertical-align:middle}
        .brand-tag{font-size:12px;color:rgba(255,255,255,.65);margin-top:2px}
        .hero-strip{background:#f0f4f8;border-bottom:3px solid {{ $primaryColor }};padding:20px 32px}
        .hero-strip .email-type{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:{{ $primaryColor }};margin-bottom:4px}
        .hero-strip h1{font-size:22px;font-weight:700;color:#10263d;line-height:1.3}
        .email-body{padding:32px 32px 24px}
        p{font-size:15px;line-height:1.7;color:#334155;margin-bottom:16px}
        .greeting{font-size:16px;font-weight:600;color:#10263d;margin-bottom:12px}
        .amount-block{background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid {{ $primaryColor }};border-radius:10px;padding:18px 20px;margin:20px 0}
        .amount-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:6px}
        .amount-value{font-size:32px;font-weight:300;color:{{ $primaryColor }};letter-spacing:.06em}
        .amount-value span{font-size:16px;font-weight:600;margin-left:4px}
        .info-table{width:100%;border-collapse:collapse;margin:16px 0;font-size:14px}
        .info-table tr td{padding:8px 0;border-bottom:1px solid #f1f5f9}
        .info-table tr:last-child td{border-bottom:none}
        .info-table td:first-child{color:#64748b;font-weight:500;width:40%}
        .info-table td:last-child{color:#1a2233;font-weight:600;text-align:right}
        .cta-wrap{text-align:center;margin:28px 0 20px}
        .cta-btn{display:inline-block;background:{{ $primaryColor }};color:#ffffff !important;text-decoration:none;font-size:15px;font-weight:700;padding:14px 32px;border-radius:10px;letter-spacing:.02em}
        .cta-btn.secondary{background:transparent;color:{{ $primaryColor }} !important;border:2px solid {{ $primaryColor }};margin-left:12px}
        .alert{border-radius:10px;padding:14px 18px;margin:16px 0;font-size:14px}
        .alert.info{background:#eff6ff;border-left:4px solid #3b82f6;color:#1e3a5f}
        .alert.success{background:#f0fdf4;border-left:4px solid #22c55e;color:#14532d}
        .alert.warning{background:#fffbeb;border-left:4px solid #f59e0b;color:#78350f}
        .alert.danger{background:#fef2f2;border-left:4px solid #ef4444;color:#7f1d1d}
        .divider{border:none;border-top:1px solid #f1f5f9;margin:20px 0}
        .email-footer{background:#f8fafc;border-top:1px solid #e2e8f0;padding:20px 32px;text-align:center}
        .email-footer p{font-size:12px;color:#94a3b8;margin-bottom:4px;line-height:1.6}
        .email-footer a{color:#64748b}
        @media only screen and (max-width:600px){
            .wrapper{padding:12px 8px}
            .email-header,.hero-strip,.email-body,.email-footer{padding-left:20px;padding-right:20px}
            .amount-value{font-size:26px}
            .cta-btn.secondary{margin-left:0;margin-top:8px;display:block}
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="email-card">

        {{-- Header --}}
        <div class="email-header">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td>
                        <table cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="padding-right:12px;vertical-align:middle;">
                                    @if($logoUrl)
                                        <img src="{{ $logoUrl }}" alt="{{ $circuitName }}"
                                             style="height:44px;max-width:120px;object-fit:contain;display:block;">
                                    @else
                                        <div class="brand-mark">{{ strtoupper(mb_substr($circuitName,0,2)) }}</div>
                                    @endif
                                </td>
                                <td style="vertical-align:middle;">
                                    <div class="brand-name">{{ $circuitName }}</div>
                                    <div class="brand-tag">{{ $circuitTagline }}</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>

        {{-- Hero strip --}}
        @if(isset($emailType) || isset($emailTitle))
        <div class="hero-strip">
            @if(isset($emailType))
                <div class="email-type">{{ $emailType }}</div>
            @endif
            @if(isset($emailTitle))
                <h1>{{ $emailTitle }}</h1>
            @endif
        </div>
        @endif

        {{-- Body --}}
        <div class="email-body">
            @yield('content')
        </div>

        {{-- Footer --}}
        <div class="email-footer">
            <p>Questa email è stata inviata automaticamente da <strong>{{ $circuitName }}</strong>.</p>
            <p>Non rispondere a questa email.
                @if($contactEmail)
                    Per assistenza scrivi a <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
                @else
                    Per assistenza accedi al tuo portale.
                @endif
            </p>
            <p style="margin-top:8px;">{{ $footerText }}</p>
            <p style="margin-top:6px;">
                <a href="{{ config('app.url') }}">{{ config('app.url') }}</a>
            </p>
        </div>

    </div>
</div>
</body>
</html>
