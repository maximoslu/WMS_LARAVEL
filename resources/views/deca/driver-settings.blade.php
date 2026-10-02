@extends('layouts.auth')
@section('title', 'Acceso de chóferes | DECA')
@section('content')
    <h1>Contraseña de chóferes</h1>
    <p>Establece una contraseña compartida de al menos 12 caracteres. Solo permite emitir DECA rápidos. Al cambiarla se cierran los accesos anteriores.</p>
    <p>Las emisiones quedan identificadas como acceso compartido de chóferes, bajo el responsable que configure esta contraseña.</p>
    <form method="POST" action="{{ route('deca.driver.save') }}" class="auth-form">
        @csrf
        <label class="auth-field"><span>Nueva contraseña</span><input class="auth-input" type="password" name="password" autocomplete="new-password" minlength="12" maxlength="200" required></label>
        <label class="auth-field"><span>Repetir contraseña</span><input class="auth-input" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="200" required></label>
        <button class="auth-button button-primary" type="submit">Guardar contraseña de chóferes</button>
    </form>
    <p><a href="{{ route('driver.login') }}">Abrir acceso para chóferes</a></p>
    <a href="{{ route('deca.index') }}">Volver a DECA</a>
@endsection
