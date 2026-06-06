@extends('layouts.auth')

@section('title', 'Recuperar contrasena | MAXIMO WMS')

@section('content')
    <div class="wms-form-header">
        <span class="wms-badge">Soporte de acceso</span>
        <h2>Recuperar contrasena</h2>
        <p>Introduce tu email y dejaremos preparado el envio del enlace de restablecimiento.</p>
    </div>

    <form method="POST" action="{{ route('password.email') }}" class="wms-form-stack">
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

        <button type="submit" class="wms-primary-button">Enviar enlace de recuperacion</button>
    </form>

    <div class="wms-form-links">
        <a href="{{ route('login') }}">Volver al acceso</a>
        <a href="{{ route('access-requests.create') }}">Solicitar acceso</a>
    </div>
@endsection
