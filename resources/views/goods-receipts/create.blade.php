@extends('layouts.dashboard')

@section('title', 'Nueva entrada | MAXIMO WMS')
@section('topbar_title', 'Nueva entrada')

@section('content')
    @include('goods-receipts._form')
@endsection

