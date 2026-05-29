@extends('layouts.portal')

@section('content')
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;">
    <div style="width:100%;max-width:440px;">

        <div style="text-align:center;margin-bottom:32px;">
            <div style="width:56px;height:56px;background:#0c4a86;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-size:20px;font-weight:800;color:#fff;letter-spacing:.02em;margin-bottom:16px;">KY</div>
            <h1 style="font-size:26px;font-weight:700;color:#10263d;margin-bottom:6px;">Recupera accesso</h1>
            <p style="color:#64748b;font-size:15px;">Inserisci la tua email e ti invieremo un link per reimpostare la password.</p>
        </div>

        @if (session('status'))
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:16px 20px;margin-bottom:20px;display:flex;gap:10px;align-items:flex-start;">
                <span style="color:#16a34a;font-size:18px;line-height:1;">✓</span>
                <p style="color:#14532d;font-size:14px;line-height:1.5;margin:0;">{{ session('status') }}</p>
            </div>
        @endif

        <div style="background:#fff;border-radius:16px;padding:32px;box-shadow:0 4px 24px rgba(15,34,58,.08);border:1px solid #e2e8f0;">

            @if ($errors->any())
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px 16px;margin-bottom:20px;">
                    <p style="color:#991b1b;font-size:14px;margin:0;">{{ $errors->first() }}</p>
                </div>
            @endif

            <form method="POST" action="{{ route('password.email') }}">
                @csrf
                <div style="margin-bottom:20px;">
                    <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Email</label>
                    <input
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                        placeholder="la-tua@email.it"
                        style="width:100%;padding:12px 14px;border:1.5px solid #d1d9e0;border-radius:10px;font-size:15px;color:#10263d;outline:none;transition:border-color .2s;"
                        onfocus="this.style.borderColor='#0c4a86'"
                        onblur="this.style.borderColor='#d1d9e0'"
                    >
                </div>

                <button type="submit" style="width:100%;padding:13px;background:#0c4a86;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;letter-spacing:.02em;">
                    Invia link di recupero
                </button>
            </form>
        </div>

        <p style="text-align:center;margin-top:20px;font-size:14px;color:#64748b;">
            Ricordi la password?
            <a href="{{ route('login') }}" style="color:#0c4a86;font-weight:600;text-decoration:none;">Accedi</a>
        </p>
    </div>
</div>
@endsection
