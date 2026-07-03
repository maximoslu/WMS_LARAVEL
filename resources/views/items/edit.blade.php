@extends('layouts.dashboard')

@section('title', 'Editar articulo | MAXIMO WMS')

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @include('items._form')
@endsection

