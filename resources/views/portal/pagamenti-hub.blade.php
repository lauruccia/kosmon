@extends('layouts.portal')

@section('content')

<style>
/* ── Hub Header ─────────────────────────────────────── */
.hub-header {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 60%, #0f52c4 100%);
    border-radius: var(--radius, 12px);
    padding: 28px 32px;
    margin-bottom: 24px;
    color: #fff;
}
.hub-header h1 { font-size: 26px; font-weight: 900; letter-spacing: -.02em; margin: 0 0 6px; }
.hub-header p { font-size: 14px; opacity: .7; margin: 0; }

/* ── Saldo rapido nell'header ─────────────────────── */
.hub-balance {
    display: inline-flex; align-items: baseline; gap: 6px;
    background: rgba(255,255,255,.12); border-radius: 10px;
    padding: 8px 16px; margin-top: 14px;
}
.hub-balance__amount { font-size: 22px; font-weight: 900; }
.hub-balance__currency { font-size: 12px; opacity: .7; font-weight: 600; }
.hub-balance__label { font-size: 11px; opacity: .55; margin-left: 8px; }

/* ── Sezione ─────────────────────────────────────── */
.hub-section-title {
    font-size: 11px; font-weight: 800; text-transform: uppercase;
    letter-spacing: .1em; color: var(--ink-muted, #6b7280);
    margin: 0 0 12px;
    display: flex; align-items: center; gap: 8px;
}
.hub-section-title::after {
    content: ''; flex: 1; height: 1px; background: var(--line, #e5e7eb);
}

/* ── Card griglia ────────────────────────────────── */
.hub-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 24px;
}
@media (max-width: 520px) { .hub-grid { grid-template-columns: 1fr 1fr; } }

.hub-card {
    background: var(--surface, #fff);
    border: 1.5px solid var(--line, #e5e7eb);
    border-radius: 14px;
    padding: 20px 18px 16px;
    text-decoration: none;
    color: inherit;
    display: flex; flex-direction: column; gap: 10px;
    transition: border-color .15s, box-shadow .15s, transform .1s;
    cursor: pointer;
}
.hub-card:hover {
    border-color: var(--primary, #0f52c4);
    box-shadow: 0 4px 20px rgba(15,82,196,.12);
    transform: translateY(-1px);
}
.hub-card--primary {
    background: linear-gradient(135deg, #0f52c4, #1e40af);
    border-color: transparent;
    color: #fff;
}
.hub-card--primary:hover { box-shadow: 0 6px 24px rgba(15,82,196,.35); border-color: transparent; }
.hub-card--green { border-color: #bbf7d0; }
.hub-card--green:hover { border-color: #16a34a; box-shadow: 0 4px 20px rgba(22,163,74,.12); }
.hub-card--amber { border-color: #fde68a; }
.hub-card--amber:hover { border-color: #d97706; box-shadow: 0 4px 20px rgba(217,119,6,.12); }

.hub-card__icon {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.hub-card--primary .hub-card__icon { background: rgba(255,255,255,.2); }
.hub-card__icon--blue  { background: #dbeafe; }
.hub-card__icon--green { background: #dcfce7; }
.hub-card__icon--amber { background: #fef3c7; }
.hub-card__icon--violet { background: #ede9fe; }
.hub-card__icon--slate { background: #f1f5f9; }
.hub-card__icon--pink  { background: #fce7f3; }

.hub-card__title {
    font-size: 14px; font-weight: 800; letter-spacing: -.01em;
    line-height: 1.2;
}
.hub-card--primary .hub-card__title { color: #fff; }

.hub-card__desc {
    font-size: 12px; color: var(--ink-muted, #6b7280); line-height: 1.5;
    margin-top: -4px;
}
.hub-card--primary .hub-card__desc { color: rgba(255,255,255,.7); }

.hub-card__badge {
    display: inline-flex; align-items: center;
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .06em; padding: 3px 8px; border-radius: 99px;
    margin-top: auto; align-self: flex-start;
}
.hub-card__badge--blue   { background: #dbeafe; color: #1d4ed8; }
.hub-card__badge--green  { background: #dcfce7; color: #15803d; }
.hub-card__badge--amber  { background: #fef3c7; color: #92400e; }
.hub-card__badge--white  { background: rgba(255,255,255,.2); color: #fff; }
.hub-card__badge--violet { background: #ede9fe; color: #6d28d9; }
.hub-card__badge--slate  { background: #f1f5f9; color: #475569; }

/* ── Cronologia destinatari ──────────────────────── */
.recent-list {
    display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 24px;
}
.recent-chip {
    display: inline-flex; align-items: center; gap: 7px;
    background: var(--surface, #fff);
    border: 1.5px solid var(--line, #e5e7eb);
    border-radius: 99px; padding: 6px 14px 6px 8px;
    font-size: 13px; font-weight: 600;
    text-decoration: none; color: var(--ink, #111827);
    transition: border-color .15s, background .15s;
}
.recent-chip:hover { border-color: var(--primary, #0f52c4); background: #f0f6ff; color: var(--primary); }
.recent-chip__avatar {
    width: 24px; height: 24px; border-radius: 50%;
    background: linear-gradient(135deg, #0f52c4, #6366f1);
    color: #fff; font-size: 10px; font-weight: 800;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
</style>

{{-- Header --}}
<div class="hub-header">
    <h1>💸 Invia &amp; Ricevi KY</h1>
    <p>Scegli il metodo più comodo per te o per il tuo cliente.</p>
    <div class="hub-balance">
        <span class="hub-balance__amount">{{ ky_format($currentBalance) }}</span>
        <span class="hub-balance__currency">KY</span>
        <span class="hub-balance__label">saldo disponibile</span>
    </div>
</div>

{{-- Destinatari recenti --}}
@if($recentRecipients->isNotEmpty())
<p class="hub-section-title">Inviato di recente</p>
<div class="recent-list">
    @foreach($recentRecipients as $rec)
    <a class="recent-chip"
       href="{{ route('portal.pay.form') }}?to={{ $rec->id }}"
       title="{{ $rec->display_name }}">
        <span class="recent-chip__avatar">{{ mb_strtoupper(mb_substr($rec->display_name, 0, 1)) }}</span>
        {{ Str::limit($rec->display_name, 22) }}
    </a>
    @endforeach
</div>
@endif

{{-- INVIA --}}
<p class="hub-section-title">Invia KMoney</p>
<div class="hub-grid">

    <a href="{{ route('portal.pay.form') }}" class="hub-card hub-card--primary">
        <div class="hub-card__icon">➡️</div>
        <div class="hub-card__title">Pagamento diretto</div>
        <div class="hub-card__desc">Scegli l'azienda dalla rubrica e inserisci l'importo. Conferma in 2 step.</div>
        <span class="hub-card__badge hub-card__badge--white">⚡ Immediato</span>
    </a>

    <a href="{{ route('portal.incasso-qr.form') }}" class="hub-card hub-card--green">
        <div class="hub-card__icon hub-card__icon--green">📷</div>
        <div class="hub-card__title">Scansiona QR</div>
        <div class="hub-card__desc">Scansiona il QR del merchant e paga in un tap. Valido 10 minuti.</div>
        <span class="hub-card__badge hub-card__badge--green">QR dinamico</span>
    </a>

    <a href="{{ route('portal.incasso-nfc.form') }}" class="hub-card hub-card--amber">
        <div class="hub-card__icon hub-card__icon--amber">📡</div>
        <div class="hub-card__title">Tap NFC</div>
        <div class="hub-card__desc">Avvicina la carta NFC al POS del commerciante. Sicuro con HMAC.</div>
        <span class="hub-card__badge hub-card__badge--amber">Contactless</span>
    </a>

    <a href="{{ route('portal.incasso-sonic.form') }}" class="hub-card">
        <div class="hub-card__icon hub-card__icon--violet">🔊</div>
        <div class="hub-card__title">Sonic Pay</div>
        <div class="hub-card__desc">Trasferimento via ultrasuoni con il microfono del telefono.</div>
        <span class="hub-card__badge hub-card__badge--violet">Audio token</span>
    </a>

    <a href="{{ route('portal.incasso-codice.form') }}" class="hub-card">
        <div class="hub-card__icon hub-card__icon--slate">🔑</div>
        <div class="hub-card__title">Codice di pagamento</div>
        <div class="hub-card__desc">Inserisci il codice univoco fornito dal merchant.</div>
        <span class="hub-card__badge hub-card__badge--slate">Codice OTP</span>
    </a>

    <a href="{{ route('portal.text-requests.create') }}" class="hub-card">
        <div class="hub-card__icon hub-card__icon--pink">📋</div>
        <div class="hub-card__title">Richiesta formale</div>
        <div class="hub-card__desc">Invia una richiesta di pagamento formale con nota e importo fisso.</div>
        <span class="hub-card__badge hub-card__badge--slate">Con notifica</span>
    </a>

</div>

{{-- RICEVI --}}
<p class="hub-section-title">Ricevi KMoney</p>
<div class="hub-grid">

    <a href="{{ route('portal.receive.form') }}" class="hub-card hub-card--primary">
        <div class="hub-card__icon">📥</div>
        <div class="hub-card__title">Richiedi pagamento</div>
        <div class="hub-card__desc">Seleziona un'azienda e richiedi un importo. Ricevi conferma in tempo reale.</div>
        <span class="hub-card__badge hub-card__badge--white">⚡ Request</span>
    </a>

    <a href="{{ route('portal.incasso-qr.form') }}" class="hub-card hub-card--green">
        <div class="hub-card__icon hub-card__icon--green">🔲</div>
        <div class="hub-card__title">Genera QR</div>
        <div class="hub-card__desc">Crea un QR dinamico con importo preimpostato. Il cliente scansiona e paga.</div>
        <span class="hub-card__badge hub-card__badge--green">10 minuti</span>
    </a>

    <a href="{{ route('portal.payment-links.create') }}" class="hub-card hub-card--amber">
        <div class="hub-card__icon hub-card__icon--amber">🔗</div>
        <div class="hub-card__title">Link permanente</div>
        <div class="hub-card__desc">Condividi un link via WhatsApp/email. Valido da 1 a 90 giorni.</div>
        <span class="hub-card__badge hub-card__badge--amber">Condivisibile</span>
    </a>

    <a href="{{ route('portal.incasso-nfc.form') }}" class="hub-card">
        <div class="hub-card__icon hub-card__icon--slate">💳</div>
        <div class="hub-card__title">POS NFC</div>
        <div class="hub-card__desc">Attiva una sessione NFC e ricevi il pagamento tap dal cliente.</div>
        <span class="hub-card__badge hub-card__badge--slate">NFC merchant</span>
    </a>

    <a href="{{ route('portal.nfc-cards.index') }}" class="hub-card">
        <div class="hub-card__icon hub-card__icon--violet">📶</div>
        <div class="hub-card__title">Le mie card NFC</div>
        <div class="hub-card__desc">Gestisci le tue carte NFC fisiche: attiva, blocca, vedi limiti.</div>
        <span class="hub-card__badge hub-card__badge--violet">Card fisiche</span>
    </a>

    <a href="{{ route('portal.payment-links.index') }}" class="hub-card">
        <div class="hub-card__icon hub-card__icon--pink">📊</div>
        <div class="hub-card__title">I miei link</div>
        <div class="hub-card__desc">Storico dei link di pagamento attivi e scaduti.</div>
        <span class="hub-card__badge hub-card__badge--slate">Storico link</span>
    </a>

</div>

{{-- ALTRI STRUMENTI --}}
<p class="hub-section-title">Strumenti avanzati</p>
<div class="hub-grid">

    <a href="{{ route('portal.payment-plans.index') }}" class="hub-card">
        <div class="hub-card__icon hub-card__icon--blue">📅</div>
        <div class="hub-card__title">Piani rateali</div>
        <div class="hub-card__desc">Dilaziona un pagamento in rate mensili concordate.</div>
        <span class="hub-card__badge hub-card__badge--blue">Rate</span>
    </a>

    <a href="{{ route('portal.scheduled-payments.index') }}" class="hub-card">
        <div class="hub-card__icon hub-card__icon--green">⏰</div>
        <div class="hub-card__title">Pagamenti programmati</div>
        <div class="hub-card__desc">Imposta un pagamento automatico a una data futura o ricorrente.</div>
        <span class="hub-card__badge hub-card__badge--green">Auto</span>
    </a>

    <a href="{{ route('portal.netting.index') }}" class="hub-card">
        <div class="hub-card__icon hub-card__icon--violet">🔄</div>
        <div class="hub-card__title">Compensazione</div>
        <div class="hub-card__desc">Compensa crediti e debiti incrociati con altri partecipanti.</div>
        <span class="hub-card__badge hub-card__badge--violet">Netting</span>
    </a>

    <a href="{{ route('portal.requests') }}" class="hub-card">
        <div class="hub-card__icon hub-card__icon--amber">🔔</div>
        <div class="hub-card__title">Richieste in attesa</div>
        <div class="hub-card__desc">Conferma o rifiuta le richieste di pagamento ricevute.
            @if($pendingCount > 0)
            <strong style="color:#d97706;"> ({{ $pendingCount }})</strong>
            @endif
        </div>
        <span class="hub-card__badge hub-card__badge--amber">Da gestire</span>
    </a>

</div>

@endsection
