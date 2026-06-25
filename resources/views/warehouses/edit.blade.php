@extends('layouts.dashboard')

@section('title', 'Editar almacen | MAXIMO WMS')

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @include('warehouses._form')
@endsection
