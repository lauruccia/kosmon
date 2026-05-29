<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centro Assistenza — KMoney</title>
    @vite(['resources/css/app.css'])
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; }
        .help-header { background: linear-gradient(135deg, #0f766e 0%, #0891b2 100%); color: #fff; padding: 60px 24px 48px; text-align: center; }
        .help-header h1 { font-size: 2.2rem; font-weight: 800; margin: 0 0 10px; }
        .help-header p { font-size: 1.1rem; opacity: .85; margin: 0; }
        .container { max-width: 800px; margin: 0 auto; padding: 0 20px; }
        .section { padding: 40px 0; }
        .section-title { font-size: 1.3rem; font-weight: 700; margin-bottom: 20px; color: #0f766e; }
        .faq-item { background: #fff; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 10px; overflow: hidden; }
        .faq-q { padding: 16px 20px; cursor: pointer; font-weight: 600; font-size: 15px; display: flex; justify-content: space-between; align-items: center; user-select: none; }
        .faq-q:hover { background: #f0fdf4; }
        .faq-a { padding: 0 20px; max-height: 0; overflow: hidden; transition: max-height .3s ease, padding .3s; font-size: 14.5px; line-height: 1.7; color: #475569; }
        .faq-item.open .faq-a { max-height: 600px; padding: 0 20px 16px; }
        .faq-item.open .faq-q .arrow { transform: rotate(180deg); }
        .arrow { transition: transform .3s; font-size: 12px; color: #64748b; }
        .contact-card { background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 28px 32px; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 13.5px; font-weight: 600; margin-bottom: 6px; }
        .form-control { width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14px; box-sizing: border-box; }
        .form-control:focus { outline: none; border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15,118,110,.12); }
        .btn { background: #0f766e; color: #fff; border: none; padding: 11px 24px; border-radius: 8px; font-size: 14.5px; font-weight: 600; cursor: pointer; }
        .btn:hover { background: #0d6460; }
        .alert { padding: 14px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .alert.success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
        .topnav { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; }
        .topnav-brand { font-weight: 800; font-size: 1.2rem; color: #0f766e; text-decoration: none; }
        .topnav-links { display: flex; gap: 20px; font-size: 14px; }
        .topnav-links a { color: #475569; text-decoration: none; }
        .topnav-links a:hover { color: #0f766e; }
    </style>
</head>
<body>

<nav class="topnav">
    <a href="{{ route('home') }}" class="topnav-brand">KMoney</a>
    <div class="topnav-links">
        <a href="{{ route('home') }}">Home</a>
        <a href="{{ route('login') }}">Accedi</a>
        <a href="{{ route('register') }}">Registrati</a>
    </div>
</nav>

<div class="help-header">
    <h1>Centro Assistenza</h1>
    <p>Trova risposte alle domande più frequenti o contattaci direttamente.</p>
</div>

<div class="container">

    <div class="section">
        <div class="section-title">Domande frequenti</div>

        @php
        $faqs = [
            ['q' => 'Cos\'è KMoney e come funziona il circuito?',
             'a' => 'KMoney è un circuito B2B privato dove le aziende aderenti possono acquistare e vendere beni e servizi utilizzando la valuta interna KY (KiloYield). Ogni azienda registrata ottiene un conto KY e può transare con le altre aziende del circuito.'],
            ['q' => 'Come mi registro e cosa serve per il KYC?',
             'a' => 'La registrazione è gratuita: crea un account, compila il profilo azienda e carica i documenti KYC richiesti (visura camerale, documento del titolare, codice fiscale azienda). Il team KMoney verificherà i documenti entro 1-3 giorni lavorativi.'],
            ['q' => 'Come ricevo e invio pagamenti in KY?',
             'a' => 'Dal tuo portale puoi inviare pagamenti digitando l\'account KY del destinatario o scansionando il suo QR dinamico. Puoi anche creare richieste di pagamento da inviare ai tuoi clienti.'],
            ['q' => 'Cos\'è il fido (massimale negativo) e come richiederlo?',
             'a' => 'Il fido ti permette di avere un saldo KY negativo fino a un limite prestabilito, simile a un castelletto bancario. Puoi richiedere il fido direttamente dal portale — la sezione "Fido" del tuo conto.'],
            ['q' => 'Come funzionano i piani rateali?',
             'a' => 'Puoi dilazionare un pagamento in più rate concordando con la controparte (venditore o acquirente). Le rate vengono processate automaticamente alle scadenze concordate.'],
            ['q' => 'Come posso scaricare l\'estratto conto?',
             'a' => 'Dal menu "Movimenti" puoi scaricare l\'estratto conto in PDF per qualsiasi periodo oppure esportare i movimenti in CSV per l\'integrazione con il tuo software di contabilità.'],
            ['q' => 'Posso integrare KMoney con i miei sistemi?',
             'a' => 'Sì. KMoney offre una API REST v1 con autenticazione Bearer token, webhook per eventi in tempo reale e documentazione interattiva disponibile nel portale alla sezione "Docs API".'],
            ['q' => 'Come viene tutelata la sicurezza del mio account?',
             'a' => 'KMoney supporta autenticazione a due fattori (TOTP), log degli accessi con notifica per IP sconosciuti, e step-up authentication per operazioni sensibili come la creazione di API token.'],
            ['q' => 'Cosa succede se ho un problema con un pagamento?',
             'a' => 'Puoi richiedere un rimborso direttamente dalla pagina movimenti (pulsante "Rimborsa" sul trasferimento). Per dispute complesse, usa il form di contatto qui sotto. Consulta anche la nostra Procedura Reclami nelle pagine legali.'],
            ['q' => 'Dove posso leggere i documenti legali del circuito?',
             'a' => 'Tutti i documenti legali sono disponibili pubblicamente: <a href="' . route('legal.contract') . '">Contratto di Adesione</a>, <a href="' . route('legal.aml-kyc') . '">Politica AML/KYC</a>, <a href="' . route('legal.limits') . '">Limiti Transazionali</a>, <a href="' . route('legal.complaints') . '">Procedura Reclami</a>.'],
        ];
        @endphp

        @foreach($faqs as $faq)
        <div class="faq-item">
            <div class="faq-q" onclick="toggleFaq(this)">
                <span>{{ $faq['q'] }}</span>
                <span class="arrow">▼</span>
            </div>
            <div class="faq-a">{!! $faq['a'] !!}</div>
        </div>
        @endforeach
    </div>

    <div class="section" style="border-top:1px solid #e2e8f0;padding-top:40px;">
        <div class="section-title">Contattaci</div>
        <p style="color:#475569;margin-bottom:24px;">Non hai trovato risposta? Inviaci un messaggio e ti risponderemo entro 1-2 giorni lavorativi.</p>

        @if(session('success'))
            <div class="alert success">{{ session('success') }}</div>
        @endif

        <div class="contact-card">
            <form method="POST" action="{{ route('help.contact') }}">
                @csrf
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div class="form-group">
                        <label class="form-label">Nome *</label>
                        <input type="text" name="name" class="form-control" required value="{{ old('name', auth()->user()?->name) }}">
                        @error('name')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" class="form-control" required value="{{ old('email', auth()->user()?->email) }}">
                        @error('email')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Oggetto *</label>
                    <input type="text" name="subject" class="form-control" required value="{{ old('subject') }}">
                    @error('subject')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Messaggio *</label>
                    <textarea name="body" class="form-control" rows="5" required>{{ old('body') }}</textarea>
                    @error('body')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="btn">Invia messaggio</button>
            </form>
        </div>
    </div>

    <div class="section" style="border-top:1px solid #e2e8f0;padding-top:32px;padding-bottom:60px;">
        <div style="display:flex;gap:32px;flex-wrap:wrap;">
            <div>
                <div style="font-size:12px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">Documenti legali</div>
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <a href="{{ route('legal.contract') }}" style="color:#0f766e;font-size:14px;">Contratto di Adesione</a>
                    <a href="{{ route('legal.aml-kyc') }}" style="color:#0f766e;font-size:14px;">Politica AML/KYC</a>
                    <a href="{{ route('legal.limits') }}" style="color:#0f766e;font-size:14px;">Limiti Transazionali</a>
                    <a href="{{ route('legal.complaints') }}" style="color:#0f766e;font-size:14px;">Procedura Reclami</a>
                </div>
            </div>
            <div>
                <div style="font-size:12px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">Contatti diretti</div>
                @php $branding = \App\Models\SystemSetting::branding(); @endphp
                @if($branding->contact_email)
                    <div style="font-size:14px;color:#475569;">📧 <a href="mailto:{{ $branding->contact_email }}" style="color:#0f766e;">{{ $branding->contact_email }}</a></div>
                @endif
                @if($branding->contact_phone)
                    <div style="font-size:14px;color:#475569;margin-top:4px;">📞 {{ $branding->contact_phone }}</div>
                @endif
            </div>
        </div>
    </div>

</div>

<script>
function toggleFaq(el) {
    const item = el.parentElement;
    item.classList.toggle('open');
}
</script>
</body>
</html>
