@extends('layouts.auth')

@section('title', 'Acceso | MAXIMO WMS')

@section('content')
    <div class="auth-header">
        <span class="auth-eyebrow">Acceso operativo</span>
        <h1 class="auth-title">MAXIMO WMS</h1>
        <p class="auth-subtitle">Identificate para continuar.</p>
    </div>

    <form method="POST" action="{{ route('login.store') }}" class="auth-form">
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

        <label class="auth-field">
            <span>Contrasena</span>
            <input
                class="auth-input"
                type="password"
                name="password"
                autocomplete="current-password"
                required
                placeholder="Introduce tu contrasena"
            >
        </label>

        <div class="auth-actions">
            <button type="submit" class="auth-button button-primary">Iniciar sesion</button>
            <p class="auth-footnote">Entorno interno protegido.</p>
        </div>
    </form>

    <div class="auth-links">
        <a href="{{ route('access-requests.create') }}" class="auth-link">Solicitar acceso</a>
        <a href="{{ route('password.request') }}" class="auth-link">Recuperar contrasena</a>
    </div>
@endsection
