@extends('layouts.portal')

@section('content')
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;">
    <div style="width:100%;max-width:440px;">

        <div style="text-align:center;margin-bottom:32px;">
            <div style="width:56px;height:56px;background:#fff;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;box-shadow:0 4px 16px rgba(15,34,58,.12);"><span style="width:30px;height:36px;display:block;">@include('partials.brand-k')</span></div>
            <h1 style="font-size:26px;font-weight:700;color:#10263d;margin-bottom:6px;">Nuova password</h1>
            <p style="color:#64748b;font-size:15px;">Scegli una password sicura per il tuo conto KMoney.</p>
        </div>

        <div style="background:#fff;border-radius:16px;padding:32px;box-shadow:0 4px 24px rgba(15,34,58,.08);border:1px solid #e2e8f0;">

            @if ($errors->any())
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px 16px;margin-bottom:20px;">
                    @foreach ($errors->all() as $error)
                        <p style="color:#991b1b;font-size:14px;margin:0 0 4px;">{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div style="margin-bottom:18px;">
                    <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Email</label>
                    <input
                        type="email"
                        name="email"
                        value="{{ old('email', $email ?? '') }}"
                        required
                        placeholder="la-tua@email.it"
                        style="width:100%;padding:12px 14px;border:1.5px solid #d1d9e0;border-radius:10px;font-size:15px;color:#10263d;outline:none;background:#f8fafc;"
                    >
                </div>

                <div style="margin-bottom:18px;">
                    <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Nuova password</label>
                    <input
                        type="password"
                        name="password"
                        required
                        placeholder="Minimo 8 caratteri"
                        style="width:100%;padding:12px 14px;border:1.5px solid #d1d9e0;border-radius:10px;font-size:15px;color:#10263d;outline:none;transition:border-color .2s;"
                        onfocus="this.style.borderColor='#0c4a86'"
                        onblur="this.style.borderColor='#d1d9e0'"
                    >
                </div>

                <div style="margin-bottom:24px;">
                    <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Conferma password</label>
                    <input
                        type="password"
                        name="password_confirmation"
                        required
                        placeholder="Ripeti la nuova password"
                        style="width:100%;padding:12px 14px;border:1.5px solid #d1d9e0;border-radius:10px;font-size:15px;color:#10263d;outline:none;transition:border-color .2s;"
                        onfocus="this.style.borderColor='#0c4a86'"
                        onblur="this.style.borderColor='#d1d9e0'"
                    >
                </div>

                <button type="submit" style="width:100%;padding:13px;background:#0c4a86;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;letter-spacing:.02em;">
                    Reimposta password
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
