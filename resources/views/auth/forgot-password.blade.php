@extends('layouts.auth')

@section('title', 'Recuperar contrasena | MAXIMO WMS')

@section('content')
    <div class="auth-header">
        <span class="status-chip">Soporte de acceso</span>
        <h2 class="auth-form-title">Recuperar contrasena</h2>
        <p class="text-muted">Introduce tu email corporativo y prepararemos el enlace de restablecimiento.</p>
    </div>

    <form method="POST" action="{{ route('password.email') }}" class="auth-form">
        @csrf

        <label class="form-field">
            <span>Email de usuario</span>
            <input
                type="email"
                name="email"
                value="{{ old('email') }}"
                autocomplete="email"
                required
                autofocus
                placeholder="nombre@empresa.com"
            >
        </label>

        <div class="auth-actions">
            <button type="submit" class="button-primary">Enviar enlace de recuperacion</button>
        </div>
    </form>

    <div class="auth-links">
        <a href="{{ route('login') }}">Volver al acceso</a>
        <a href="{{ route('access-requests.create') }}">Solicitar acceso</a>
    </div>
@endsection
