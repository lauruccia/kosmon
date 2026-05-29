@extends('layouts.portal')

@section('content')

@php
    $isActive    = $pr->status === 'pending';
    $isPaid      = $pr->status === 'paid';
    $isCancelled = $pr->status === 'cancelled';
    $isExpired   = $pr->status === 'expired';

    $whatsappText = urlencode('Ciao! Ti mando il link per il pagamento di ' . number_format($pr->amount, 2, ',', '.') . ' KY' . ($pr->description ? ' — ' . $pr->description : '') . ': ' . $payUrl);
    $mailSubject  = urlencode('Richiesta pagamento ' . number_format($pr->amount, 2, ',', '.') . ' KY');
    $mailBody     = urlencode('Ciao,' . "\n\n" . 'Ecco il link per il pagamento di ' . number_format($pr->amount, 2, ',', '.') . ' KY' . ($pr->description ? ' (' . $pr->description . ')' : '') . ':' . "\n\n" . $payUrl . "\n\nGrazie.");
@endphp

<div class="portal-grid" style="--grid-cols:2;align-items:start;">

    {{-- QR + importo --}}
    <div class="stack">
        <section class="card card-pad" style="text-align:center;">

            {{-- Badge stato --}}
            @if($isPaid)
                <div style="background:#d1fae5;border-radius:12px;padding:12px 20px;margin-bottom:20px;display:flex;align-items:center;justify-content:center;gap:10px;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <strong style="color:#065f46;font-size:14px;">Pagato da {{ $pr->fromAccount?->company?->name ?? $pr->fromAccount?->display_name ?? 'Cliente' }} il {{ $pr->paid_at?->format('d/m/Y H:i') }}</strong>
                </div>
            @elseif($isCancelled)
                <div style="background:#fee2e2;border-radius:12px;padding:12px 20px;margin-bottom:20px;">
                    <strong style="color:#991b1b;font-size:14px;">Link annullato</strong>
                </div>
            @elseif($isExpired)
                <div style="background:#fef3c7;border-radius:12px;padding:12px 20px;margin-bottom:20px;">
                    <strong style="color:#92400e;font-size:14px;">Link scaduto il {{ $pr->expires_at->format('d/m/Y') }}</strong>
                </div>
            @else
                <div style="background:#eff6ff;border-radius:12px;padding:10px 20px;margin-bottom:20px;font-size:13px;color:var(--primary);font-weight:600;">
                    Scade il {{ $pr->expires_at->format('d/m/Y') }} alle {{ $pr->expires_at->format('H:i') }}
                </div>
            @endif

            {{-- Importo --}}
            <div style="font-size:48px;font-weight:800;letter-spacing:-2px;line-height:1;color:var(--ink);margin-bottom:4px;">
                {{ number_format($pr->amount, 2, ',', '.') }}
                <span style="font-size:22px;font-weight:600;color:var(--ink-muted);">KY</span>
            </div>
            @if($pr->description)
                <div style="font-size:14px;color:var(--ink-muted);margin-top:8px;margin-bottom:20px;">{{ $pr->description }}</div>
            @else
                <div style="margin-bottom:20px;"></div>
            @endif

            {{-- QR code --}}
            @if($isActive)
                <div style="display:inline-block;padding:16px;background:#fff;border-radius:16px;border:2px solid var(--line);margin-bottom:20px;">
                    {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(180)->errorCorrection('M')->generate($payUrl) !!}
                </div>
            @endif

            {{-- URL copiabile --}}
            <div style="display:flex;gap:8px;align-items:center;background:var(--surface-soft);border:1px solid var(--line);border-radius:10px;padding:10px 14px;margin-bottom:20px;text-align:left;">
                <input
                    id="pay-url-input"
                    type="text"
                    value="{{ $payUrl }}"
                    readonly
                    style="flex:1;border:none;background:transparent;font-size:12px;color:var(--ink-muted);font-family:monospace;outline:none;min-width:0;"
                >
                <button
                    onclick="copyPayUrl()"
                    id="copy-btn"
                    style="flex-shrink:0;padding:6px 12px;background:var(--primary);color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;"
                >
                    Copia link
                </button>
            </div>

            {{-- Pulsanti condivisione --}}
            @if($isActive)
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                {{-- WhatsApp --}}
                <a
                    href="https://wa.me/?text={{ $whatsappText }}"
                    target="_blank"
                    rel="noopener"
                    style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;background:#25d366;color:#fff;border-radius:10px;font-weight:700;font-size:14px;text-decoration:none;"
                >
                    <svg width="20" height="20" viewBox="0 0 32 32" fill="currentColor">
                        <path d="M16 2C8.28 2 2 8.28 2 16c0 2.46.66 4.76 1.8 6.76L2 30l7.44-1.76A13.9 13.9 0 0016 30c7.72 0 14-6.28 14-14S23.72 2 16 2zm0 25.4a11.34 11.34 0 01-5.8-1.58l-.42-.25-4.42 1.06 1.1-4.28-.27-.44A11.34 11.34 0 014.6 16c0-6.28 5.12-11.4 11.4-11.4S27.4 9.72 27.4 16 22.28 27.4 16 27.4zm6.26-8.5c-.34-.17-2.02-1-2.34-1.1-.32-.12-.56-.17-.8.17-.24.34-.92 1.1-1.12 1.33-.2.22-.42.25-.76.08-.34-.17-1.44-.53-2.74-1.69-1.01-.9-1.69-2.02-1.89-2.36-.2-.34-.02-.52.15-.69.15-.15.34-.4.5-.6.18-.2.24-.34.36-.56.12-.22.06-.42-.02-.58-.08-.17-.8-1.93-1.1-2.64-.28-.68-.57-.6-.8-.6-.2-.01-.44-.01-.68-.01s-.6.08-.92.42c-.32.34-1.22 1.19-1.22 2.9s1.25 3.36 1.42 3.6c.18.22 2.46 3.76 5.96 5.27 3.5 1.5 3.5 1 4.13.94.63-.06 2.02-.82 2.3-1.62.28-.8.28-1.48.2-1.62-.08-.14-.32-.22-.66-.38z"/>
                    </svg>
                    WhatsApp
                </a>

                {{-- Email --}}
                <a
                    href="mailto:?subject={{ $mailSubject }}&body={{ $mailBody }}"
                    style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;background:var(--surface);color:var(--ink);border:2px solid var(--line);border-radius:10px;font-weight:700;font-size:14px;text-decoration:none;"
                >
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                        <polyline points="22,6 12,13 2,6"/>
                    </svg>
                    Email
                </a>
            </div>
            @endif

            {{-- Annulla link --}}
            @if($isActive)
                <form method="POST" action="{{ route('portal.payment-links.cancel', $pr->token) }}"
                      style="margin-top:16px;"
                      onsubmit="return confirm('Sei sicuro di voler annullare questo link?')">
                    @csrf
                    <button type="submit" style="background:none;border:none;font-size:13px;color:var(--danger);cursor:pointer;font-weight:600;">
                        Annulla link
                    </button>
                </form>
            @else
                <a href="{{ route('portal.payment-links.create') }}"
                   class="cta"
                   style="display:inline-flex;margin-top:16px;justify-content:center;">
                    Crea nuovo link
                </a>
            @endif

        </section>
    </div>

    {{-- Dettagli + istruzioni --}}
    <div class="stack">
        <section class="card light-card card-pad">
            <div class="k-tag">Dettagli link</div>
            <div style="margin-top:16px;display:grid;gap:14px;">
                <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line);padding-bottom:10px;">
                    <span style="font-size:13px;color:var(--ink-muted);">Importo</span>
                    <strong style="font-size:16px;">{{ number_format($pr->amount, 2, ',', '.') }} KY</strong>
                </div>
                @if($pr->description)
                <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line);padding-bottom:10px;">
                    <span style="font-size:13px;color:var(--ink-muted);">Causale</span>
                    <span style="font-size:13px;font-weight:600;">{{ $pr->description }}</span>
                </div>
                @endif
                <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line);padding-bottom:10px;">
                    <span style="font-size:13px;color:var(--ink-muted);">Creato</span>
                    <span style="font-size:13px;font-weight:600;">{{ $pr->created_at->format('d/m/Y H:i') }}</span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line);padding-bottom:10px;">
                    <span style="font-size:13px;color:var(--ink-muted);">Scade</span>
                    <span style="font-size:13px;font-weight:600;color:{{ $isExpired ? 'var(--danger)' : 'var(--ink)' }};">
                        {{ $pr->expires_at->format('d/m/Y H:i') }}
                    </span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:13px;color:var(--ink-muted);">Conto destinatario</span>
                    <span style="font-size:13px;font-weight:600;">{{ $account->company?->name ?? $account->display_name }}</span>
                </div>
            </div>
        </section>

        <section class="card light-card card-pad">
            <div class="k-tag">Istruzioni</div>
            <div style="margin-top:14px;display:grid;gap:12px;">
                <div style="display:flex;gap:10px;align-items:flex-start;">
                    <div style="width:24px;height:24px;border-radius:50%;background:var(--primary);color:#fff;font-weight:800;font-size:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">1</div>
                    <div style="font-size:13px;color:var(--ink-muted);">Copia il link o usa i pulsanti qui accanto per inviarlo al tuo cliente via <strong>WhatsApp</strong> o <strong>email</strong>.</div>
                </div>
                <div style="display:flex;gap:10px;align-items:flex-start;">
                    <div style="width:24px;height:24px;border-radius:50%;background:var(--primary);color:#fff;font-weight:800;font-size:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">2</div>
                    <div style="font-size:13px;color:var(--ink-muted);">Il cliente clicca il link, accede al portale KMoney con il suo account, e conferma il pagamento.</div>
                </div>
                <div style="display:flex;gap:10px;align-items:flex-start;">
                    <div style="width:24px;height:24px;border-radius:50%;background:#059669;color:#fff;font-weight:800;font-size:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">3</div>
                    <div style="font-size:13px;color:var(--ink-muted);">Ricevi una notifica e il saldo si aggiorna immediatamente.</div>
                </div>
            </div>
        </section>

        <a href="{{ route('portal.payment-links.index') }}"
           style="display:flex;align-items:center;gap:6px;font-size:13px;color:var(--ink-muted);text-decoration:none;font-weight:600;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
            Tutti i link
        </a>
    </div>

</div>
@endsection

@push('scripts')
<script>
function copyPayUrl() {
    const input = document.getElementById('pay-url-input');
    const btn   = document.getElementById('copy-btn');
    navigator.clipboard.writeText(input.value).then(() => {
        btn.textContent = 'Copiato!';
        btn.style.background = '#059669';
        setTimeout(() => {
            btn.textContent = 'Copia link';
            btn.style.background = '';
        }, 2000);
    }).catch(() => {
        input.select();
        document.execCommand('copy');
        btn.textContent = 'Copiato!';
        setTimeout(() => { btn.textContent = 'Copia link'; }, 2000);
    });
}
</script>
@endpush
