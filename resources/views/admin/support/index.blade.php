@extends('layouts.portal')

@section('content')
<div style="max-width:900px;margin:0 auto;padding:0 16px 48px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
        <div>
            <div class="eyebrow">Admin</div>
            <h1 class="page-title">Messaggi assistenza</h1>
        </div>
        @if($openCount > 0)
            <span class="chip" style="background:#fef3c7;color:#92400e;font-size:13px;">{{ $openCount }} aperti</span>
        @endif
    </div>

    @if(session('success'))
        <div class="alert success" style="margin-bottom:20px;">{{ session('success') }}</div>
    @endif

    @if($messages->isEmpty())
        <div class="card card-pad empty-state">Nessun messaggio di assistenza.</div>
    @else
        <section class="card" style="padding:0;overflow:hidden;">
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Da</th>
                        <th>Oggetto</th>
                        <th>Data</th>
                        <th>Stato</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($messages as $msg)
                    <tr>
                        <td style="color:var(--ink-muted);font-size:12px;">#{{ $msg->id }}</td>
                        <td>
                            <strong style="font-size:13.5px;">{{ $msg->name }}</strong>
                            <div class="subtle" style="font-size:11.5px;">{{ $msg->email }}</div>
                        </td>
                        <td>
                            <div style="font-size:13.5px;">{{ $msg->subject }}</div>
                            <div class="subtle" style="font-size:12px;max-width:320px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $msg->body }}</div>
                        </td>
                        <td style="font-size:12.5px;color:var(--ink-soft);">{{ $msg->created_at->format('d/m/Y H:i') }}</td>
                        <td>
                            <span class="chip {{ $msg->isOpen() ? 'warning' : 'success' }}">{{ $msg->isOpen() ? 'Aperto' : 'Risolto' }}</span>
                        </td>
                        <td>
                            <button onclick="toggleBody({{ $msg->id }})" class="cta secondary" style="font-size:11.5px;min-height:26px;padding:0 10px;">Leggi</button>
                            @if($msg->isOpen())
                            <form method="POST" action="{{ route('admin.support.resolve', $msg) }}" style="display:inline;">
                                @csrf
                                <button class="cta" style="font-size:11.5px;min-height:26px;padding:0 10px;background:#16a34a;">Risolto</button>
                            </form>
                            @endif
                            <a href="mailto:{{ $msg->email }}?subject=Re: {{ urlencode($msg->subject) }}" class="cta secondary" style="font-size:11.5px;min-height:26px;padding:0 10px;">Rispondi</a>
                        </td>
                    </tr>
                    <tr id="body-{{ $msg->id }}" style="display:none;">
                        <td colspan="6" style="padding:12px 16px;background:var(--surface-2);font-size:13.5px;line-height:1.7;white-space:pre-wrap;">{{ $msg->body }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        <div style="margin-top:16px;">{{ $messages->links() }}</div>
    @endif
</div>

<script>
function toggleBody(id) {
    var row = document.getElementById('body-' + id);
    row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}
</script>
@endsection
