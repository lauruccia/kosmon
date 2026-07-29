@extends('layouts.portal')

@section('content')
@if(session('status'))
    <div style="margin-bottom:14px;padding:12px 16px;border-radius:10px;background:rgba(22,163,74,.09);border:1px solid rgba(22,163,74,.3);color:#166534;font-size:13px;font-weight:600;">
        {{ session('status') }}
    </div>
@endif
@if($errors->any())
    <div style="margin-bottom:14px;padding:12px 16px;border-radius:10px;background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.3);color:#b91c1c;font-size:13px;font-weight:600;">
        {{ $errors->first() }}
    </div>
@endif

<div class="card card-pad" style="margin-bottom:14px;max-width:640px;">
    <span style="display:inline-block;margin-bottom:6px;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--primary);">Lavora con noi</span>
    <h2 style="margin:0 0 10px;font-size:18px;">Diventa agente KNM</h2>
    <p style="margin:0 0 14px;color:var(--ink-muted);font-size:13px;line-height:1.6;">
        Sei già cliente KMoney: da qui puoi candidarti per diventare anche agente KNM e iniziare
        a guadagnare all'interno del circuito. Ecco cosa significa in pratica:
    </p>
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
        <div style="display:flex;gap:10px;align-items:flex-start;font-size:13px;color:var(--ink-soft);line-height:1.55;">
            <span aria-hidden="true">🤝</span>
            <span>Inviti nuovi clienti e altri agenti, costruendo la tua struttura all'interno del circuito KMoney.</span>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-start;font-size:13px;color:var(--ink-soft);line-height:1.55;">
            <span aria-hidden="true">📈</span>
            <span>Maturi punti e sali di qualifica man mano che la tua struttura cresce e vende.</span>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-start;font-size:13px;color:var(--ink-soft);line-height:1.55;">
            <span aria-hidden="true">💶</span>
            <span>Guadagni commissioni e bonus sulle vendite e le ricariche del tuo circuito.</span>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-start;font-size:13px;color:var(--ink-soft);line-height:1.55;">
            <span aria-hidden="true">📝</span>
            <span>La richiesta viene esaminata dal nostro team: se approvata, firmi digitalmente (con OTP via email) il contratto di nomina ad agente prima di iniziare.</span>
        </div>
    </div>

    @if($user->hasPendingMlmAgentRequest())
        <div style="padding:14px 16px;border-radius:10px;background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.3);color:#b45309;font-size:13px;font-weight:600;">
            La tua richiesta è stata inviata il {{ $user->mlm_agent_requested_at?->format('d/m/Y H:i') }} ed è in attesa di revisione.
            Ti avviseremo via email non appena verrà esaminata.
        </div>
    @elseif($user->hasRejectedMlmAgentRequest())
        <div style="padding:14px 16px;border-radius:10px;background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.3);color:#b91c1c;font-size:13px;margin-bottom:16px;">
            <strong style="display:block;margin-bottom:4px;">La tua richiesta precedente non è stata approvata.</strong>
            @if($user->mlm_agent_rejection_reason)
                <span>Motivo: {{ $user->mlm_agent_rejection_reason }}</span>
            @endif
        </div>

        <form method="POST" action="{{ route('portal.mlm.agent-request.store') }}">
            @csrf
            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">Vuoi ripresentare la richiesta? Aggiungi un messaggio (opzionale)</label>
            <textarea name="note" rows="3" maxlength="1000" style="width:100%;border-radius:10px;border:1px solid var(--line);padding:10px 12px;font-size:13px;">{{ old('note') }}</textarea>
            <button type="submit" class="btn btn-primary" style="margin-top:12px;">Ripresenta la richiesta</button>
        </form>
    @else
        <form method="POST" action="{{ route('portal.mlm.agent-request.store') }}">
            @csrf
            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">Messaggio per il team (opzionale)</label>
            <textarea name="note" rows="3" maxlength="1000" placeholder="Raccontaci perché vuoi diventare agente KNM..." style="width:100%;border-radius:10px;border:1px solid var(--line);padding:10px 12px;font-size:13px;">{{ old('note') }}</textarea>
            <button type="submit" class="btn btn-primary" style="margin-top:12px;">Richiedi di diventare agente</button>
        </form>
    @endif
</div>
@endsection
