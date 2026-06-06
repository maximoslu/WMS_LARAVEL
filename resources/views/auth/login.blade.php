@extends('layouts.auth')

@section('title', 'Acceso | MAXIMO WMS')

@section('content')
    <div class="wms-form-header">
        <span class="wms-badge">Acceso operativo</span>
        <h2>Iniciar sesion</h2>
        <p>Accede al entorno de trabajo del WMS con tu email corporativo y tu contraseña.</p>
    </div>

    <form method="POST" action="{{ route('login.store') }}" class="wms-form-stack">
        @csrf

        <label class="wms-field">
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

        <label class="wms-field">
            <span>Contrasena</span>
            <input
                type="password"
                name="password"
                autocomplete="current-password"
                required
                placeholder="Introduce tu contrasena"
            >
        </label>

        <button type="submit" class="wms-primary-button">Iniciar sesion</button>
    </form>

    <div class="wms-form-links">
        <a href="{{ route('access-requests.create') }}">Solicitar acceso</a>
        <a href="{{ route('password.request') }}">Has olvidado tu contrasena?</a>
    </div>
@endsection
