@extends('layouts.auth')
@section('title', 'DECA para chóferes')
@section('content')
    <h1>DECA para chóferes</h1>
    <p>Introduce el PIN del equipo para preparar tu transporte. Utiliza el móvil con el vehículo parado.</p>
    <form method="POST" action="{{ route('driver.authenticate') }}" class="auth-form">
        @csrf
        <label class="auth-field"><span>PIN de chóferes</span><input class="auth-input" name="password" type="password" autocomplete="current-password" required inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4"></label>
        <button class="auth-button button-primary" type="submit">Entrar a DECA rápido</button>
    </form>
@endsection
