@extends('layouts.auth')

@section('title', 'Solicitar acceso | MAXIMO WMS')

@section('content')
    <div class="wms-form-header">
        <span class="wms-badge">Alta pendiente</span>
        <h2>Solicitar acceso</h2>
        <p>Deja tus datos y prepararemos el alta para su validacion por parte del equipo de MAXIMO.</p>
    </div>

    <form method="POST" action="{{ route('access-requests.store') }}" class="wms-form-stack">
        @csrf

        <label class="wms-field">
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

        <label class="wms-field">
            <span>Empresa</span>
            <input
                type="text"
                name="company"
                value="{{ old('company') }}"
                placeholder="Cliente o empresa"
            >
        </label>

        <label class="wms-field">
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

        <label class="wms-field">
            <span>Observaciones</span>
            <textarea
                name="notes"
                rows="4"
                placeholder="Operacion, centro, cliente o informacion adicional"
            >{{ old('notes') }}</textarea>
        </label>

        <button type="submit" class="wms-primary-button">Enviar solicitud</button>
    </form>

    <div class="wms-form-links">
        <a href="{{ route('login') }}">Volver al acceso</a>
        <a href="{{ route('password.request') }}">Recuperar contrasena</a>
    </div>
@endsection
