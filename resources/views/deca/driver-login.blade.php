@extends('layouts.auth')
@section('title', 'DECA para chóferes')
@section('content')
    <h1>DECA para chóferes</h1>
    <p>Introduce la contraseña del equipo para preparar tu transporte. Utiliza el móvil con el vehículo parado.</p>
    <form method="POST" action="{{ route('driver.authenticate') }}" class="auth-form">
        @csrf
        <label class="auth-field"><span>Contraseña de chóferes</span><input class="auth-input" name="password" type="password" autocomplete="current-password" required maxlength="200"></label>
        <button class="auth-button button-primary" type="submit">Entrar a DECA rápido</button>
    </form>
@endsection
