@extends('layouts.auth')

@section('title', 'Solicitar acceso | MAXIMO WMS')

@section('content')
    <div class="auth-header">
        <span class="status-chip">Alta pendiente</span>
        <h2 class="auth-form-title">Solicitud de acceso</h2>
        <p class="text-muted">Comparte los datos operativos y prepararemos el alta para su validacion.</p>
    </div>

    <form method="POST" action="{{ route('access-requests.store') }}" class="auth-form">
        @csrf

        <label class="form-field">
            <span>Nombre y apellidos</span>
            <input
                type="text"
                name="name"
                value="{{ old('name') }}"
                autocomplete="name"
                required
                placeholder="Nombre del solicitante"
            >
        </label>

        <label class="form-field">
            <span>Empresa</span>
            <input
                type="text"
                name="company"
                value="{{ old('company') }}"
                placeholder="Cliente o empresa"
            >
        </label>

        <label class="form-field">
            <span>Email de contacto</span>
            <input
                type="email"
                name="email"
                value="{{ old('email') }}"
                autocomplete="email"
                required
                placeholder="nombre@empresa.com"
            >
        </label>

        <label class="form-field">
            <span>Observaciones</span>
            <textarea
                name="notes"
                rows="4"
                placeholder="Operacion, centro, cliente o informacion adicional"
            >{{ old('notes') }}</textarea>
        </label>

        <div class="auth-actions">
            <button type="submit" class="button-primary">Enviar solicitud</button>
            <p class="text-muted">El equipo de MAXIMO revisara la peticion antes de habilitar el acceso.</p>
        </div>
    </form>

    <div class="auth-links">
        <a href="{{ route('login') }}">Volver al acceso</a>
        <a href="{{ route('password.request') }}">Recuperar contrasena</a>
    </div>
@endsection
