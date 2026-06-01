{{--
  Parametri passati:
    $rule      MenuVisibility|null  — regola attuale (null = nessuna)
    $key       string               — menu_item_key
    $scope     string               — scope_type
    $scopeId   int|null             — scope_id (per company/user)
    $accType   string|null          — account_type (per account_type scope)
--}}
@php
    $hasRule = $rule !== null;
    $isOn    = $hasRule && $rule->visible;
    $isOff   = $hasRule && !$rule->visible;
@endphp

<div style="display:flex;flex-direction:column;align-items:center;gap:4px;">

    {{-- Badge stato --}}
    @if(!$hasRule)
        <span style="font-size:10px;color:var(--ink-muted);font-weight:600;">— default —</span>
    @else
        <span class="pill {{ $isOn ? 'success' : 'warn' }}" style="font-size:9px;">
            {{ $isOn ? '✓ VISIBILE' : '✗ NASCOSTA' }}
        </span>
    @endif

    {{-- Toggle buttons --}}
    <div style="display:flex;gap:4px;">
        {{-- Mostra (ON) --}}
        <form method="POST" action="{{ route('admin.menu-visibility.store') }}">
            @csrf
            <input type="hidden" name="menu_item_key" value="{{ $key }}">
            <input type="hidden" name="scope_type"    value="{{ $scope }}">
            <input type="hidden" name="visible"       value="1">
            @if($scopeId)
            <input type="hidden" name="scope_id"      value="{{ $scopeId }}">
            @endif
            @if($accType)
            <input type="hidden" name="account_type"  value="{{ $accType }}">
            @endif
            <button type="submit"
                class="btn btn-sm"
                style="padding:2px 8px;font-size:11px;background:{{ $isOn ? '#065f46' : '#f1f5f9' }};color:{{ $isOn ? '#d1fae5' : 'var(--ink-soft)' }};border-color:{{ $isOn ? '#065f46' : '#cbd5e1' }};"
                title="Rendi visibile">
                ON
            </button>
        </form>

        {{-- Nascondi (OFF) --}}
        <form method="POST" action="{{ route('admin.menu-visibility.store') }}">
            @csrf
            <input type="hidden" name="menu_item_key" value="{{ $key }}">
            <input type="hidden" name="scope_type"    value="{{ $scope }}">
            <input type="hidden" name="visible"       value="0">
            @if($scopeId)
            <input type="hidden" name="scope_id"      value="{{ $scopeId }}">
            @endif
            @if($accType)
            <input type="hidden" name="account_type"  value="{{ $accType }}">
            @endif
            <button type="submit"
                class="btn btn-sm"
                style="padding:2px 8px;font-size:11px;background:{{ $isOff ? '#9f1239' : '#f1f5f9' }};color:{{ $isOff ? '#ffe4e6' : 'var(--ink-soft)' }};border-color:{{ $isOff ? '#9f1239' : '#cbd5e1' }};"
                title="Nascondi">
                OFF
            </button>
        </form>

        {{-- Rimuovi regola (torna a default) --}}
        @if($hasRule)
        <form method="POST" action="{{ route('admin.menu-visibility.destroy') }}">
            @csrf @method('DELETE')
            <input type="hidden" name="menu_item_key" value="{{ $key }}">
            <input type="hidden" name="scope_type"    value="{{ $scope }}">
            @if($scopeId)
            <input type="hidden" name="scope_id"      value="{{ $scopeId }}">
            @endif
            @if($accType)
            <input type="hidden" name="account_type"  value="{{ $accType }}">
            @endif
            <button type="submit"
                class="btn btn-sm btn-danger"
                style="padding:2px 6px;font-size:11px;"
                title="Rimuovi regola (torna a default)">
                ×
            </button>
        </form>
        @endif
    </div>
</div>
