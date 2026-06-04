@extends('layouts.portal')

@push('head')
    <style>
        /* ── Command bar ─────────────────────────────────────────────────────── */
        .users-command {
            display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: center;
            margin-bottom: 10px; padding: 10px 12px; border: 1px solid var(--line);
            border-left: 4px solid color-mix(in srgb, var(--primary) 78%, #c9a45c 22%);
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, color-mix(in srgb, var(--surface) 94%, #c9a45c 6%), var(--surface) 58%);
            box-shadow: var(--shadow-xs);
        }
        .users-command-title { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .users-command-mark {
            width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center;
            background: linear-gradient(160deg, #0b1f3f, #102f5f);
            color: #f8d98a; border: 1px solid rgba(201,164,92,.5);
            font-size: 11px; font-weight: 900; letter-spacing: .08em;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.16); flex: 0 0 auto;
        }
        .users-command-title h2 { margin: 0; font-size: 18px; letter-spacing: -.01em; }
        .users-command-title p  { margin: 2px 0 0; color: var(--ink-soft); font-size: 12px; }

        /* ── KPIs ────────────────────────────────────────────────────────────── */
        .users-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin-bottom: 10px; }
        .users-kpi {
            min-height: 60px; padding: 8px 11px; border-radius: 8px;
            border: 1px solid var(--line); background: var(--surface); box-shadow: var(--shadow-xs);
        }
        .users-kpi span { display: block; margin-bottom: 3px; color: var(--ink-muted); font-size: 9.5px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        .users-kpi strong { display: block; color: var(--ink); font-size: 18px; line-height: 1; font-variant-numeric: tabular-nums; }

        /* ── Directory card ──────────────────────────────────────────────────── */
        .users-directory-card { padding: 0 !important; overflow: hidden; }
        .users-directory-top {
            padding: 10px 14px 8px; border-bottom: 1px solid var(--line);
            background: linear-gradient(180deg, color-mix(in srgb, var(--surface-soft) 62%, var(--surface) 38%), var(--surface));
        }
        .users-dir-head {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            margin-bottom: 8px;
        }

        /* ── Compact filter bar ──────────────────────────────────────────────── */
        .users-filter-bar { display: flex; align-items: flex-end; gap: 6px; flex-wrap: wrap; row-gap: 5px; }
        .ufb-field { display: flex; flex-direction: column; gap: 2px; }
        .ufb-label {
            font-size: 9.5px; font-weight: 800; text-transform: uppercase;
            letter-spacing: .07em; color: var(--ink-muted); white-space: nowrap;
        }
        .ufb-input, .ufb-select {
            height: 30px; padding: 0 8px; font-size: 12px;
            border: 1px solid var(--line); border-radius: var(--radius-sm);
            background: var(--surface); color: var(--ink);
        }
        .ufb-input--wide { min-width: 160px; }
        .ufb-select      { min-width: 90px; }
        .ufb-select--sm  { min-width: 110px; }
        .ufb-select--xs  { min-width: 54px; max-width: 60px; }
        .ufb-actions     { display: flex; gap: 5px; align-items: flex-end; padding-bottom: 0; }
        .users-directory-meta { display: flex; gap: 5px; flex-wrap: wrap; align-items: center; margin-top: 6px; }

        /* ── Compact CTA ─────────────────────────────────────────────────────── */
        .users-compact-cta { min-height: 30px; padding: 0 10px; font-size: 11.5px; }

        /* ── Table ───────────────────────────────────────────────────────────── */
        .users-table-wrap { overflow-x: auto; }
        .users-table-wrap .admin-table th { padding: 7px 10px; white-space: nowrap; }
        .users-table-wrap .admin-table td { padding: 5px 10px; vertical-align: middle; }
        .user-cell { display: flex; align-items: center; gap: 8px; min-width: 160px; }
        .user-avatar {
            width: 26px; height: 26px; border-radius: 7px; display: grid; place-items: center;
            background: linear-gradient(160deg, color-mix(in srgb, var(--primary-light) 82%, #fff 18%), var(--surface));
            border: 1px solid color-mix(in srgb, var(--primary) 32%, var(--line) 68%);
            font-size: 10px; font-weight: 900; color: var(--primary-strong); flex: 0 0 auto;
        }
        .user-main { min-width: 0; }
        .user-main strong, .user-main .table-muted { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px; }
        .user-balance { font-variant-numeric: tabular-nums; white-space: nowrap; font-size: 12.5px; font-weight: 700; }
        .balance-neg  { color: #c9313e; }
        .balance-pos  { color: #1a7a4a; }
        .balance-zero { color: var(--ink-muted); }
        .user-row-actions { display: flex; justify-content: flex-end; gap: 5px; white-space: nowrap; }
        .sort-link { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 3px; }
        .sort-icon { opacity: .45; font-size: 10px; }
        .sort-icon.active { opacity: 1; color: var(--primary-strong); }

        /* ── Pagination ──────────────────────────────────────────────────────── */
        .users-pagination {
            display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;
            padding: 8px 14px; border-top: 1px solid var(--line); background: var(--surface-soft);
        }
        .users-pagination-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .users-perpage-form { display: flex; align-items: center; gap: 5px; }
        .users-perpage-select { height: 28px; padding: 0 6px; font-size: 12px; border: 1px solid var(--line); border-radius: var(--radius-sm); background: var(--surface); color: var(--ink); }
        .users-page-nav { display: flex; gap: 3px; align-items: center; flex-wrap: wrap; }

        /* ── Provisioning ─────────────────────────────────────────────────────── */
        .users-provisioning {
            border: 1px solid var(--line); border-radius: var(--radius-sm);
            background: var(--surface); box-shadow: var(--shadow-xs); overflow: hidden;
        }
        .users-provisioning summary {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 12px 14px; cursor: pointer; list-style: none;
        }
        .users-provisioning summary::-webkit-details-marker { display: none; }
        .users-provisioning summary h3 { margin: 0; font-size: 16px; }
        .users-provisioning-body { padding: 0 14px 14px; border-top: 1px solid var(--line); }
        .users-create-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 12px 0 10px; }

        /* ── Responsive ──────────────────────────────────────────────────────── */
        @media (max-width: 980px) {
            .users-command { grid-template-columns: 1fr; }
            .users-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .users-create-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .ufb-input--wide { min-width: 130px; }
        }
        @media (max-width: 720px) {
            .users-kpis, .users-create-grid { grid-template-columns: 1fr; }
            .users-pagination { align-items: stretch; flex-direction: column; }
            .users-command .page-actions { width: 100%; }
            .users-filter-bar { flex-direction: column; align-items: stretch; }
            .ufb-select, .ufb-select--sm, .ufb-select--xs, .ufb-input--wide { min-width: 0; width: 100%; }
        }
    </style>
@endpush

@section('content')

    {{-- ── Command bar ─────────────────────────────────────────────────────── --}}
    <section class="users-command">
        <div class="users-command-title">
            <span class="users-command-mark">VIP</span>
            <div>
                <span class="eyebrow">User control room</span>
                <h2>Utenti e profili operativi</h2>
                <p>Directory compatta per controllo accessi, profili e disponibilità KY.</p>
            </div>
        </div>
        <div class="page-actions">
            <a class="cta users-compact-cta" href="#create-user" onclick="document.getElementById('create-user').open = true">Nuovo utente</a>
            <a class="cta secondary users-compact-cta" href="{{ route('admin.accounts.index') }}">Conti</a>
        </div>
    </section>

    {{-- ── KPIs ─────────────────────────────────────────────────────────────── --}}
    <section class="users-kpis">
        <div class="users-kpi"><span>Risultati filtro</span><strong>{{ $filteredUsersCount }}</strong></div>
        <div class="users-kpi"><span>Attivi</span><strong>{{ $activeUsersCount }}</strong></div>
        <div class="users-kpi"><span>Superadmin</span><strong>{{ $superAdminCount }}</strong></div>
        <div class="users-kpi"><span>Aziende / Privati</span><strong>{{ $companyUsersCount }} / {{ $privateUsersCount }}</strong></div>
    </section>

    {{-- ── Directory ───────────────────────────────────────────────────────── --}}
    <section class="card light-card users-directory-card" id="users-directory" style="margin-bottom:10px;">

        @php
            /* Tutti i parametri correnti per preservare lo stato nelle navigate */
            $allParams = array_filter([
                'q'                   => $search ?: null,
                'role_id'             => $selectedRoleId ?? null,
                'status'              => $selectedStatus ?? null,
                'account_holder_type' => $selectedHolderType ?: null,
                'balance_filter'      => $selectedBalanceFilter ?: null,
                'sort'                => $sortField ?: null,
                'dir'                 => ($sortDir !== 'asc') ? $sortDir : null,
                'per_page'            => ($perPage !== 25) ? $perPage : null,
            ], fn ($v) => filled($v));

            /* Closure per header di colonna ordinabili */
            $sortLink = function (string $field, string $label) use ($sortField, $sortDir, $allParams): string {
                $newDir   = ($sortField === $field && $sortDir === 'asc') ? 'desc' : 'asc';
                $isActive = $sortField === $field;
                $icon     = $isActive ? ($sortDir === 'asc' ? '↑' : '↓') : '⇅';
                $url      = route('admin.users.index', array_merge($allParams, ['sort' => $field, 'dir' => $newDir]));
                $iconClass = $isActive ? 'sort-icon active' : 'sort-icon';
                return '<a href="' . e($url) . '" class="sort-link">' . e($label) . ' <span class="' . $iconClass . '">' . $icon . '</span></a>';
            };
        @endphp

        @php
            $balanceTabs = [
                ''               => 'Tutti i saldi',
                'positive'       => '+ Positivo',
                'zero'           => '0 Zero',
                'negative'       => '− Negativo',
                'near_max'       => '▲ Vicino max',
                'near_min'       => '▼ Esiguo',
                'allow_negative' => '⚡ Neg. ammesso',
            ];
        @endphp

        <div class="users-directory-top">
            {{-- Titolo + pill risultati --}}
            <div class="users-dir-head">
                <div><span class="eyebrow">Directory</span><h3 class="section-title" style="margin:0;">Elenco utenti</h3></div>
                <span class="pill">{{ $filteredUsersCount }} risultati</span>
            </div>

            {{-- Barra filtri compatta (una riga) --}}
            <form method="get" action="{{ route('admin.users.index') }}" class="users-filter-bar">

                <div class="ufb-field">
                    <span class="ufb-label">Cerca</span>
                    <input class="ufb-input ufb-input--wide" type="text" name="q"
                           value="{{ $search }}" placeholder="Nome, email, azienda…">
                </div>

                <div class="ufb-field">
                    <span class="ufb-label">Tipo utente</span>
                    <select class="ufb-select" name="account_holder_type">
                        <option value=""        @selected(!$selectedHolderType)>Tutti ({{ $holderTotalCount }})</option>
                        <option value="company" @selected($selectedHolderType === 'company')>Aziende ({{ $companyUsersCount }})</option>
                        <option value="private" @selected($selectedHolderType === 'private')>Privati ({{ $privateUsersCount }})</option>
                    </select>
                </div>

                <div class="ufb-field">
                    <span class="ufb-label">Saldo</span>
                    <select class="ufb-select ufb-select--sm" name="balance_filter">
                        @foreach ($balanceTabs as $bfVal => $bfLabel)
                            <option value="{{ $bfVal }}" @selected($selectedBalanceFilter === $bfVal)>{{ $bfLabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="ufb-field">
                    <span class="ufb-label">Ruolo</span>
                    <select class="ufb-select ufb-select--sm" name="role_id">
                        <option value="">Tutti i ruoli</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected((int)($selectedRoleId ?? 0) === $role->id)>{{ $role->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="ufb-field">
                    <span class="ufb-label">Stato</span>
                    <select class="ufb-select" name="status">
                        <option value="">Tutti</option>
                        <option value="active"   @selected($selectedStatus === 'active')>Attivi</option>
                        <option value="inactive" @selected($selectedStatus === 'inactive')>Disattivi</option>
                    </select>
                </div>

                <div class="ufb-field">
                    <span class="ufb-label">Ordina</span>
                    <select class="ufb-select" name="sort">
                        <option value=""           @selected(!$sortField)>—</option>
                        <option value="name"       @selected($sortField === 'name')>Nome</option>
                        <option value="email"      @selected($sortField === 'email')>Email</option>
                        <option value="balance"    @selected($sortField === 'balance')>Saldo</option>
                        <option value="created_at" @selected($sortField === 'created_at')>Data reg.</option>
                    </select>
                </div>

                <div class="ufb-field">
                    <span class="ufb-label">Dir.</span>
                    <select class="ufb-select ufb-select--xs" name="dir">
                        <option value="asc"  @selected($sortDir === 'asc')>↑</option>
                        <option value="desc" @selected($sortDir === 'desc')>↓</option>
                    </select>
                </div>

                <div class="ufb-actions">
                    <button type="submit" class="cta secondary users-compact-cta">Filtra</button>
                    <a class="cta secondary users-compact-cta" href="{{ route('admin.users.index') }}">Reset</a>
                </div>
            </form>

            {{-- Chip filtri attivi (solo se presente almeno uno) --}}
            @if($search || $selectedRoleId || $selectedStatus || $selectedHolderType || $selectedBalanceFilter || $sortField)
                <div class="users-directory-meta">
                    @if($search)         <span class="chip">{{ $search }}</span> @endif
                    @if($selectedHolderType) <span class="chip">{{ $selectedHolderType === 'company' ? 'Aziende' : 'Privati' }}</span> @endif
                    @if($selectedBalanceFilter) <span class="chip">{{ $balanceTabs[$selectedBalanceFilter] }}</span> @endif
                    @if($selectedRoleId) <span class="chip">{{ $roles->firstWhere('id', $selectedRoleId)?->name }}</span> @endif
                    @if($selectedStatus) <span class="chip">{{ $selectedStatus === 'active' ? 'Attivi' : 'Disattivi' }}</span> @endif
                    @if($sortField)      <span class="chip">{{ $sortField }} {{ $sortDir === 'desc' ? '↓' : '↑' }}</span> @endif
                </div>
            @endif
        </div>

        {{-- ── Table ──────────────────────────────────────────────────────── --}}
        @if ($users->isEmpty())
            <div class="empty-state">Nessun utente trovato con i filtri correnti.</div>
        @else
            <div class="users-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>{!! $sortLink('name', 'Utente') !!}</th>
                            <th>Profilo</th>
                            <th>Conto</th>
                            <th>{!! $sortLink('balance', 'Saldo') !!}</th>
                            <th>Stato</th>
                            <th>{!! $sortLink('created_at', 'Registrato') !!}</th>
                            <th style="min-width:85px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            @php
                                $accounts = $user->ownedAccounts;
                                if ($user->managedAccount) $accounts = $accounts->prepend($user->managedAccount);
                                if ($accounts->isEmpty() && $user->company) $accounts = $user->company->accounts;
                                $accounts       = $accounts->unique('id')->values();
                                $primaryAccount = $accounts->firstWhere('type', 'primary') ?? $accounts->first();
                                $totalBalance   = $accounts->sum('available_balance');
                                $balClass       = $totalBalance < 0 ? 'balance-neg' : ($totalBalance > 0 ? 'balance-pos' : 'balance-zero');
                                $isPrivate      = $user->account_holder_type === 'private' || $accounts->contains(fn ($a) => $a->owner_type === 'private');
                                $profileLabel   = $user->company?->name ?? ($isPrivate ? 'Privato' : 'Sistema');
                                $roleLabel      = $user->roles->pluck('name')->first() ?? $user->role;
                                $initials       = collect(explode(' ', $user->name))->filter()->map(fn ($p) => substr($p, 0, 1))->take(2)->implode('');
                            @endphp
                            <tr>
                                <td>
                                    <div class="user-cell">
                                        <span class="user-avatar">{{ $initials }}</span>
                                        <span class="user-main">
                                            <strong>{{ $user->name }}</strong>
                                            <span class="table-muted">{{ $user->email }}</span>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span style="font-size:12.5px;font-weight:700;">{{ $profileLabel }}</span>
                                    @if($roleLabel)
                                        <span class="table-muted" style="font-size:11px;"> · {{ $roleLabel }}</span>
                                    @endif
                                </td>
                                <td>
                                    <span style="font-size:12.5px;font-weight:600;">{{ $primaryAccount?->display_name ?? '—' }}</span>
                                    @if($accounts->count() > 1)
                                        <span class="table-muted" style="font-size:11px;"> +{{ $accounts->count() - 1 }}</span>
                                    @endif
                                </td>
                                <td>
                                    <strong class="user-balance {{ $balClass }}">{{ ky_format($totalBalance) }} KY</strong>
                                </td>
                                <td>
                                    <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                        <span class="chip {{ $user->is_active ? 'success' : 'pink' }}" style="font-size:10.5px;">{{ $user->is_active ? 'attivo' : 'off' }}</span>
                                        @if($user->is_super_admin)
                                            <span class="chip" style="font-size:10.5px;">SA</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="table-muted" style="font-size:11px;white-space:nowrap;">
                                    {{ $user->created_at->format('d/m/Y') }}
                                </td>
                                <td>
                                    <div class="user-row-actions">
                                        <a class="cta secondary users-compact-cta" href="{{ route('admin.users.show', $user) }}">Dettagli</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- ── Pagination ─────────────────────────────────────────────── --}}
            <div class="users-pagination">
                <div class="table-muted" style="font-size:12px;">
                    {{ $users->firstItem() }}–{{ $users->lastItem() }} di {{ $users->total() }} utenti
                    &nbsp;·&nbsp; pagina {{ $users->currentPage() }} / {{ $users->lastPage() }}
                </div>

                <div class="users-pagination-right">
                    {{-- Per-page selector --}}
                    <form method="get" action="{{ route('admin.users.index') }}" class="users-perpage-form">
                        @foreach ($allParams as $pk => $pv)
                            @if ($pk !== 'per_page' && $pk !== 'page')
                                <input type="hidden" name="{{ $pk }}" value="{{ $pv }}">
                            @endif
                        @endforeach
                        <label class="table-muted" style="font-size:11px;">Per pagina</label>
                        <select name="per_page" class="users-perpage-select" onchange="this.form.submit()">
                            @foreach ($perPageOptions as $opt)
                                <option value="{{ $opt }}" @selected($perPage === $opt)>{{ $opt }}</option>
                            @endforeach
                        </select>
                    </form>

                    {{-- Numeric pages --}}
                    <div class="users-page-nav">
                        {{-- Prev --}}
                        @if ($users->onFirstPage())
                            <span class="cta secondary users-compact-cta" style="pointer-events:none;opacity:.4;">‹</span>
                        @else
                            <a class="cta secondary users-compact-cta" href="{{ $users->previousPageUrl() }}">‹</a>
                        @endif

                        @php
                            $cur   = $users->currentPage();
                            $last  = $users->lastPage();
                            $start = max(1, $cur - 2);
                            $end   = min($last, $cur + 2);
                        @endphp

                        @if ($start > 1)
                            <a class="cta secondary users-compact-cta" href="{{ $users->url(1) }}">1</a>
                            @if ($start > 2)
                                <span class="table-muted" style="padding:0 3px;line-height:30px;">…</span>
                            @endif
                        @endif

                        @for ($p = $start; $p <= $end; $p++)
                            @if ($p === $cur)
                                <span class="cta users-compact-cta" style="pointer-events:none;min-width:30px;text-align:center;">{{ $p }}</span>
                            @else
                                <a class="cta secondary users-compact-cta" style="min-width:30px;text-align:center;" href="{{ $users->url($p) }}">{{ $p }}</a>
                            @endif
                        @endfor

                        @if ($end < $last)
                            @if ($end < $last - 1)
                                <span class="table-muted" style="padding:0 3px;line-height:30px;">…</span>
                            @endif
                            <a class="cta secondary users-compact-cta" href="{{ $users->url($last) }}">{{ $last }}</a>
                        @endif

                        {{-- Next --}}
                        @if ($users->hasMorePages())
                            <a class="cta secondary users-compact-cta" href="{{ $users->nextPageUrl() }}">›</a>
                        @else
                            <span class="cta secondary users-compact-cta" style="pointer-events:none;opacity:.4;">›</span>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </section>

    {{-- ── Crea utente ─────────────────────────────────────────────────────── --}}
    <details class="users-provisioning" id="create-user" @if($errors->any()) open @endif>
        <summary>
            <div><span class="eyebrow">Provisioning</span><h3 class="section-title">Crea utente</h3></div>
            <span class="pill">{{ $roles->count() }} ruoli disponibili</span>
        </summary>
        <div class="users-provisioning-body">
            <form method="post" action="{{ route('admin.users.store') }}">
                @csrf
                <div class="users-create-grid">
                    <div class="field"><label>Nome completo</label><input name="name" type="text" value="{{ old('name') }}" required></div>
                    <div class="field"><label>Email</label><input name="email" type="email" value="{{ old('email') }}" required></div>
                    <div class="field"><label>Password iniziale</label><input name="password" type="password" required></div>
                    <div class="field"><label>Telefono</label><input name="phone" type="text" value="{{ old('phone') }}"></div>
                    <div class="field"><label>Tipologia</label><select name="account_holder_type"><option value="company" @selected(old('account_holder_type', 'company') === 'company')>Azienda</option><option value="private" @selected(old('account_holder_type') === 'private')>Privato</option></select></div>
                    <div class="field"><label>Azienda</label><select name="company_id"><option value="">Nessuna</option>@foreach ($companies as $company)<option value="{{ $company->id }}" @selected((string) old('company_id') === (string) $company->id)>{{ $company->name }}</option>@endforeach</select></div>
                    <div class="field"><label>Etichetta interna</label><input name="role_label" type="text" value="{{ old('role_label') }}" placeholder="backoffice-operator"></div>
                    <div class="field"><label>Tipo accesso</label><select name="is_super_admin"><option value="0" @selected(old('is_super_admin', '0') === '0')>Utente normale</option><option value="1" @selected(old('is_super_admin') === '1')>Superadmin</option></select></div>
                </div>
                <div class="field">
                    <label>Ruoli assegnati</label>
                    <div class="role-grid">
                        @foreach ($roles as $role)
                            <label class="check-tile">
                                <input class="check-mark" type="checkbox" name="roles[]" value="{{ $role->id }}" @checked(in_array($role->id, old('roles', []), true))>
                                <span class="check-tile-copy">
                                    <span class="check-tile-head"><strong>{{ $role->name }}</strong><span class="check-tile-meta">{{ strtoupper($role->scope) }}</span></span>
                                    <span class="subtle">{{ $role->description ?: 'Ruolo operativo.' }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="form-actions"><button type="submit" class="cta">Crea utente</button></div>
            </form>
        </div>
    </details>

@endsection
