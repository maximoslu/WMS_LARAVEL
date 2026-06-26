@extends('layouts.dashboard')

@section('title', 'Nueva solicitud de mercancia | MAXIMO WMS')
@section('topbar_title', 'Nueva solicitud')

@section('content')
    @include('merchandise-requests._form')
@endsection
