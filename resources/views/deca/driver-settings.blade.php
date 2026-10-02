@extends('layouts.auth')
@section('title', 'Acceso de chóferes | DECA')
@section('content')
    <h1>PIN de chóferes</h1>
    <p>Establece un PIN compartido de cuatro dígitos. Solo permite emitir DECA rápidos. Al cambiarlo se cierran los accesos anteriores.</p>
    <p>Las emisiones quedan identificadas como acceso compartido de chóferes, bajo el responsable que configure este PIN.</p>
    <form method="POST" action="{{ route('deca.driver.save') }}" class="auth-form">
        @csrf
        <label class="auth-field"><span>Nuevo PIN</span><input class="auth-input" type="password" name="password" autocomplete="new-password" inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" required></label>
        <label class="auth-field"><span>Repetir PIN</span><input class="auth-input" type="password" name="password_confirmation" autocomplete="new-password" inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" required></label>
        <button class="auth-button button-primary" type="submit">Guardar PIN de chóferes</button>
    </form>
    <p><a href="{{ route('driver.login') }}">Abrir acceso para chóferes</a></p>
    <a href="{{ route('deca.index') }}">Volver a DECA</a>
@endsection
