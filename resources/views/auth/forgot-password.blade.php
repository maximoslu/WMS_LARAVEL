@extends('layouts.auth')

@section('title', 'Recuperar contrasena | MAXIMO WMS')

@section('content')
    <div class="auth-header">
        <span class="auth-eyebrow">Soporte de acceso</span>
        <h1 class="auth-title">Recuperar contrasena</h1>
        <p class="auth-subtitle">Introduce tu email para continuar.</p>
    </div>

    <form method="POST" action="{{ route('password.email') }}" class="auth-form">
        @csrf

        <label class="auth-field">
            <span>Email de usuario</span>
            <input
                class="auth-input"
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
            <button type="submit" class="auth-button button-primary">Enviar enlace</button>
        </div>
    </form>

    <div class="auth-links">
        <a href="{{ route('login') }}" class="auth-link">Volver al acceso</a>
        <a href="{{ route('access-requests.create') }}" class="auth-link">Solicitar acceso</a>
    </div>
@endsection
