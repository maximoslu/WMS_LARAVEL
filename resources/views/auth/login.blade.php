@extends('layouts.auth')

@section('title', 'Acceso | MAXIMO WMS')

@section('content')
    <div class="auth-header">
        <span class="status-chip">Acceso seguro</span>
        <h2 class="auth-form-title">Acceso a MAXIMO WMS</h2>
        <p class="text-muted">Plataforma operativa logistica.</p>
        <p class="text-muted">Gestion segura de almacen, stock y solicitudes.</p>
    </div>

    <form method="POST" action="{{ route('login.store') }}" class="auth-form">
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

        <label class="form-field">
            <span>Contrasena</span>
            <input
                type="password"
                name="password"
                autocomplete="current-password"
                required
                placeholder="Introduce tu contrasena"
            >
        </label>

        <div class="auth-actions">
            <button type="submit" class="button-primary">Iniciar sesion</button>
            <p class="text-muted">Usa tus credenciales corporativas para acceder al entorno interno.</p>
        </div>
    </form>

    <div class="auth-links">
        <a href="{{ route('access-requests.create') }}">Solicitar acceso</a>
        <a href="{{ route('password.request') }}">Recuperar contrasena</a>
    </div>
@endsection
