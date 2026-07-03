@extends('layouts.auth')

@section('title', 'Solicitar acceso | MAXIMO WMS')

@section('content')
    <div class="auth-header">
        <span class="auth-eyebrow">Alta pendiente</span>
        <h1 class="auth-title">Solicitar acceso</h1>
        <p class="auth-subtitle">Comparte tus datos para continuar.</p>
    </div>

    <form method="POST" action="{{ route('access-requests.store') }}" class="auth-form">
        @csrf

        <label class="auth-field">
            <span>Nombre y apellidos</span>
            <input
                class="auth-input"
                type="text"
                name="name"
                value="{{ old('name') }}"
                autocomplete="name"
                required
                placeholder="Nombre del solicitante"
            >
        </label>

        <label class="auth-field">
            <span>Empresa</span>
            <input
                class="auth-input"
                type="text"
                name="company"
                value="{{ old('company') }}"
                placeholder="Cliente o empresa"
            >
        </label>

        <label class="auth-field">
            <span>Email de contacto</span>
            <input
                class="auth-input"
                type="email"
                name="email"
                value="{{ old('email') }}"
                autocomplete="email"
                required
                placeholder="nombre@empresa.com"
            >
        </label>

        <label class="auth-field">
            <span>Observaciones</span>
            <textarea
                class="auth-input"
                name="notes"
                rows="4"
                placeholder="Operacion, centro, cliente o informacion adicional"
            >{{ old('notes') }}</textarea>
        </label>

        <div class="auth-actions">
            <button type="submit" class="auth-button button-primary">Enviar solicitud</button>
            <p class="auth-footnote">Revision interna previa a la activacion.</p>
        </div>
    </form>

    <div class="auth-links">
        <a href="{{ route('login') }}" class="auth-link">Volver al acceso</a>
        <a href="{{ route('password.request') }}" class="auth-link">Recuperar contrasena</a>
    </div>
@endsection

