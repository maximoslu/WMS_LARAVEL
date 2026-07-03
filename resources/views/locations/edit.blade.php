@extends('layouts.dashboard')

@section('title', 'Editar ubicacion | MAXIMO WMS')

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @include('locations._form')
@endsection

