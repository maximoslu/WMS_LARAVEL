@extends('layouts.dashboard')

@section('title', 'Editar solicitud de mercancia | MAXIMO WMS')
@section('topbar_title', 'Editar solicitud')

@section('content')
    @include('merchandise-requests._form')
@endsection
