@extends('layouts.auth')

@section('title', 'Restablecer contrasena | MAXIMO WMS')

@section('content')
    <div class="auth-header">
        <span class="auth-eyebrow">Nuevo acceso</span>
        <h1 class="auth-title">Crear nueva contrasena</h1>
        <p class="auth-subtitle">Actualiza tus credenciales para continuar.</p>
    </div>

    <form method="POST" action="{{ route('password.store') }}" class="auth-form">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        <label class="auth-field">
            <span>Email de usuario</span>
            <input
                class="auth-input"
                type="email"
                name="email"
                value="{{ old('email', $email) }}"
                autocomplete="email"
                required
                autofocus
                placeholder="nombre@empresa.com"
            >
        </label>

        <label class="auth-field">
            <span>Nueva contrasena</span>
            <input
                class="auth-input"
                type="password"
                name="password"
                autocomplete="new-password"
                required
                placeholder="Nueva contrasena"
            >
        </label>

        <label class="auth-field">
            <span>Confirmar contrasena</span>
            <input
                class="auth-input"
                type="password"
                name="password_confirmation"
                autocomplete="new-password"
                required
                placeholder="Repite la contrasena"
            >
        </label>

        <div class="auth-actions">
            <button type="submit" class="auth-button button-primary">Actualizar contrasena</button>
        </div>
    </form>
@endsection

