@extends('layouts.auth')

@section('title', 'Restablecer contrasena | MAXIMO WMS')

@section('content')
    <div class="wms-form-header">
        <span class="wms-badge">Nuevo acceso</span>
        <h2>Crear nueva contrasena</h2>
        <p>Define una contrasena segura para recuperar el acceso a MAXIMO WMS.</p>
    </div>

    <form method="POST" action="{{ route('password.store') }}" class="wms-form-stack">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        <label class="wms-field">
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

        <label class="wms-field">
            <span>Nueva contrasena</span>
            <input
                type="password"
                name="password"
                autocomplete="new-password"
                required
                placeholder="Nueva contrasena"
            >
        </label>

        <label class="wms-field">
            <span>Confirmar contrasena</span>
            <input
                type="password"
                name="password_confirmation"
                autocomplete="new-password"
                required
                placeholder="Repite la contrasena"
            >
        </label>

        <button type="submit" class="wms-primary-button">Actualizar contrasena</button>
    </form>
@endsection
