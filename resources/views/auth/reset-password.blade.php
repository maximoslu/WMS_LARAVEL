@extends('layouts.auth')

@section('title', 'Restablecer contrasena | MAXIMO WMS')

@section('content')
    <div class="auth-header">
        <span class="status-chip">Nuevo acceso</span>
        <h2 class="auth-form-title">Crear nueva contrasena</h2>
        <p class="text-muted">Define una contrasena segura para recuperar el acceso al entorno interno.</p>
    </div>

    <form method="POST" action="{{ route('password.store') }}" class="auth-form">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        <label class="form-field">
            <span>Email de usuario</span>
            <input
                type="email"
                name="email"
                value="{{ old('email', $email) }}"
                autocomplete="email"
                required
                autofocus
                placeholder="nombre@empresa.com"
            >
        </label>

        <label class="form-field">
            <span>Nueva contrasena</span>
            <input
                type="password"
                name="password"
                autocomplete="new-password"
                required
                placeholder="Nueva contrasena"
            >
        </label>

        <label class="form-field">
            <span>Confirmar contrasena</span>
            <input
                type="password"
                name="password_confirmation"
                autocomplete="new-password"
                required
                placeholder="Repite la contrasena"
            >
        </label>

        <div class="auth-actions">
            <button type="submit" class="button-primary">Actualizar contrasena</button>
        </div>
    </form>
@endsection
