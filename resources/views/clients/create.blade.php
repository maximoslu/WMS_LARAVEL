@extends('layouts.dashboard')

@section('title', 'Nuevo cliente | MAXIMO WMS')
@section('topbar_title', 'Nuevo cliente')

@section('content')
    @include('clients._form')
@endsection

