@extends('layouts.portal')

@section('content')
<div class="page-actions">
            <a class="cta" href="#create-role">Nuovo ruolo</a>
            <a class="cta secondary" href="{{ route('admin.users.index') }}">Utenti</a>
        </div>
    </section>

    <section class="card light-card" id="create-role" style="margin-bottom:22px;">
        <div class="section-head"><div><span class="eyebrow">RBAC</span><h3 class="section-title">Crea ruolo</h3></div><span class="pill">{{ $permissions->count() }} permessi</span></div>
        <form method="post" action="{{ route('admin.roles.store') }}">
            @csrf
            <div class="field-grid">
                <div class="field-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;">
                    <div class="field"><label>Nome ruolo</label><input name="name" type="text" value="{{ old('name') }}" required></div>
                    <div class="field"><label>Scope</label><input name="scope" type="text" value="{{ old('scope', 'system') }}" required></div>
                </div>
                <div class="field"><label>Descrizione</label><input name="description" type="text" value="{{ old('description') }}" placeholder="Responsabilita e limiti operativi"></div>
                <div class="field"><label>Permessi</label><div class="permission-grid">@foreach ($permissions as $permission)<label class="check-tile"><input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked(in_array($permission->id, old('permissions', []), true))><span><strong>{{ $permission->name }}</strong><br><span class="subtle">{{ $permission->slug }}</span></span></label>@endforeach</div></div>
            </div>
            <div class="form-actions"><button type="submit" class="cta">Crea ruolo</button></div>
        </form>
    </section>

    <section class="card light-card">
        <div class="section-head"><div><span class="eyebrow">Matrix</span><h3 class="section-title">Ruoli esistenti</h3></div><span class="pill">{{ $roles->count() }}</span></div>
        <div class="timeline-list">
            @foreach ($roles as $role)
                <article class="timeline-item">
                    <div class="entity-head">
                        <div>
                            <strong>{{ $role->name }}</strong>
                            <div class="table-muted">{{ $role->slug }} · scope {{ strtoupper($role->scope) }}</div>
                        </div>
                        <span class="chip">{{ $role->users()->count() }} utenti</span>
                    </div>
                    <form method="post" action="{{ route('admin.roles.update', $role) }}" class="field-grid">
                        @csrf
                        <div class="field"><label>Descrizione</label><input name="description" type="text" value="{{ $role->description }}"></div>
                        <div class="field"><label>Permessi</label><div class="permission-grid">@foreach ($permissions as $permission)<label class="check-tile"><input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked($role->permissions->contains('id', $permission->id))><span><strong>{{ $permission->name }}</strong><br><span class="subtle">{{ $permission->slug }}</span></span></label>@endforeach</div></div>
                        <div class="form-actions"><button type="submit" class="cta secondary">Aggiorna ruolo</button></div>
                    </form>
                </article>
            @endforeach
        </div>
    </section>
@endsection
