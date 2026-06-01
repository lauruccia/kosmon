@extends('layouts.portal')

@section('content')
<div class="page-intro page-intro--row">
    <div class="page-intro-body">
        <span class="eyebrow">Configurazione</span>
        <h2>Visibilità menu utenti</h2>
        <p>Nascondi o mostra ogni voce del menu per tutti gli utenti, per tipo di account, per azienda o per singolo utente. La regola più specifica ha sempre la precedenza.</p>
    </div>
</div>

@if(session('portal_success'))
    <div class="notice success" style="margin-bottom:12px;">{{ session('portal_success') }}</div>
@endif

{{-- ── LEGENDA PRIORITÀ ─────────────────────────────────────────── --}}
<div class="light-card" style="margin-bottom:14px;display:flex;gap:14px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:12px;font-weight:700;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.08em;">Priorità →</span>
    @foreach([
        ['color'=>'#6366f1','label'=>'Utente specifico','desc'=>'Sovrascrive tutto'],
        ['color'=>'#0284c7','label'=>'Azienda specifica','desc'=>'Sovrascrive tipo e globale'],
        ['color'=>'#0891b2','label'=>'Tipo account','desc'=>'Privati o aziende'],
        ['color'=>'#64748b','label'=>'Globale','desc'=>'Tutti gli utenti'],
        ['color'=>'#16a34a','label'=>'Default','desc'=>'Nessuna regola → visibile'],
    ] as $leg)
    <div style="display:flex;align-items:center;gap:6px;">
        <span style="width:10px;height:10px;border-radius:50%;background:{{$leg['color']}};flex-shrink:0;"></span>
        <span style="font-size:12px;font-weight:700;color:var(--ink);">{{ $leg['label'] }}</span>
        <span style="font-size:11px;color:var(--ink-soft);">— {{ $leg['desc'] }}</span>
    </div>
    @endforeach
</div>

{{-- ── TABELLA PRINCIPALE ───────────────────────────────────────── --}}
<div class="light-card" style="padding:0;overflow:hidden;">
    <table class="admin-table" style="width:100%;">
        <thead>
            <tr>
                <th style="width:200px;">Voce menu</th>
                <th>Globale<br><small style="font-weight:400;text-transform:none;letter-spacing:0;">tutti</small></th>
                <th>Solo privati</th>
                <th>Solo aziende</th>
                <th style="width:220px;">Azienda specifica</th>
                <th style="width:220px;">Utente specifico</th>
                <th style="width:60px;"></th>
            </tr>
        </thead>
        <tbody>
        @foreach($menuItems as $key => $label)
            @php
                $rGlobal   = $rules->get("{$key}|global||");
                $rPrivate  = $rules->get("{$key}|account_type|private|");
                $rCompany  = $rules->get("{$key}|account_type|company|");
                // Regole azienda e utente specifici
                $companyRules = $rules->filter(fn($r) => $r->menu_item_key === $key && $r->scope_type === 'company');
                $userRules    = $rules->filter(fn($r) => $r->menu_item_key === $key && $r->scope_type === 'user');
            @endphp
            <tr>
                {{-- Nome voce --}}
                <td>
                    <strong style="font-size:13px;">{{ $label }}</strong>
                    <br><small style="color:var(--ink-muted);font-size:11px;">{{ $key }}</small>
                </td>

                {{-- GLOBALE --}}
                <td>
                    @include('admin.menu-visibility._rule-cell', [
                        'rule'     => $rGlobal,
                        'key'      => $key,
                        'scope'    => 'global',
                        'scopeId'  => null,
                        'accType'  => null,
                        'users'    => $users,
                        'companies'=> $companies,
                    ])
                </td>

                {{-- SOLO PRIVATI --}}
                <td>
                    @include('admin.menu-visibility._rule-cell', [
                        'rule'     => $rPrivate,
                        'key'      => $key,
                        'scope'    => 'account_type',
                        'scopeId'  => null,
                        'accType'  => 'private',
                        'users'    => $users,
                        'companies'=> $companies,
                    ])
                </td>

                {{-- SOLO AZIENDE --}}
                <td>
                    @include('admin.menu-visibility._rule-cell', [
                        'rule'     => $rCompany,
                        'key'      => $key,
                        'scope'    => 'account_type',
                        'scopeId'  => null,
                        'accType'  => 'company',
                        'users'    => $users,
                        'companies'=> $companies,
                    ])
                </td>

                {{-- AZIENDE SPECIFICHE --}}
                <td>
                    @foreach($companyRules as $cr)
                        <div style="display:flex;align-items:center;gap:6px;margin-bottom:5px;">
                            <span style="font-size:11px;font-weight:600;color:var(--ink);">
                                {{ $companies->firstWhere('id', $cr->scope_id)?->name ?? 'ID '.$cr->scope_id }}
                            </span>
                            <span class="pill {{ $cr->visible ? 'success' : 'warn' }}" style="font-size:9px;padding:0 6px;">
                                {{ $cr->visible ? 'ON' : 'OFF' }}
                            </span>
                            <form method="POST" action="{{ route('admin.menu-visibility.destroy') }}" style="display:inline;">
                                @csrf @method('DELETE')
                                <input type="hidden" name="menu_item_key" value="{{ $key }}">
                                <input type="hidden" name="scope_type" value="company">
                                <input type="hidden" name="scope_id" value="{{ $cr->scope_id }}">
                                <button type="submit" class="btn btn-sm btn-danger" style="padding:1px 6px;font-size:10px;" title="Rimuovi">×</button>
                            </form>
                        </div>
                    @endforeach
                    {{-- Aggiungi regola azienda --}}
                    <form method="POST" action="{{ route('admin.menu-visibility.store') }}" style="display:flex;gap:4px;margin-top:4px;">
                        @csrf
                        <input type="hidden" name="menu_item_key" value="{{ $key }}">
                        <input type="hidden" name="scope_type" value="company">
                        <select name="scope_id" class="form-control" style="font-size:11px;padding:3px 6px;min-height:28px;flex:1;">
                            <option value="">— azienda —</option>
                            @foreach($companies as $co)
                                <option value="{{ $co->id }}">{{ $co->name }}</option>
                            @endforeach
                        </select>
                        <select name="visible" class="form-control" style="font-size:11px;padding:3px 6px;min-height:28px;width:60px;">
                            <option value="1">ON</option>
                            <option value="0">OFF</option>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm">+</button>
                    </form>
                </td>

                {{-- UTENTI SPECIFICI --}}
                <td>
                    @foreach($userRules as $ur)
                        <div style="display:flex;align-items:center;gap:6px;margin-bottom:5px;">
                            <span style="font-size:11px;font-weight:600;color:var(--ink);">
                                {{ $users->firstWhere('id', $ur->scope_id)?->name ?? 'ID '.$ur->scope_id }}
                            </span>
                            <span class="pill {{ $ur->visible ? 'success' : 'warn' }}" style="font-size:9px;padding:0 6px;">
                                {{ $ur->visible ? 'ON' : 'OFF' }}
                            </span>
                            <form method="POST" action="{{ route('admin.menu-visibility.destroy') }}" style="display:inline;">
                                @csrf @method('DELETE')
                                <input type="hidden" name="menu_item_key" value="{{ $key }}">
                                <input type="hidden" name="scope_type" value="user">
                                <input type="hidden" name="scope_id" value="{{ $ur->scope_id }}">
                                <button type="submit" class="btn btn-sm btn-danger" style="padding:1px 6px;font-size:10px;" title="Rimuovi">×</button>
                            </form>
                        </div>
                    @endforeach
                    {{-- Aggiungi regola utente --}}
                    <form method="POST" action="{{ route('admin.menu-visibility.store') }}" style="display:flex;gap:4px;margin-top:4px;">
                        @csrf
                        <input type="hidden" name="menu_item_key" value="{{ $key }}">
                        <input type="hidden" name="scope_type" value="user">
                        <select name="scope_id" class="form-control" style="font-size:11px;padding:3px 6px;min-height:28px;flex:1;">
                            <option value="">— utente —</option>
                            @foreach($users as $u)
                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </select>
                        <select name="visible" class="form-control" style="font-size:11px;padding:3px 6px;min-height:28px;width:60px;">
                            <option value="1">ON</option>
                            <option value="0">OFF</option>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm">+</button>
                    </form>
                </td>

                {{-- RESET --}}
                <td style="text-align:center;">
                    <form method="POST" action="{{ route('admin.menu-visibility.reset', $key) }}" onsubmit="return confirm('Rimuovere tutte le regole per \'{{$label}}\'?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-danger" title="Reset tutte le regole">↺</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
