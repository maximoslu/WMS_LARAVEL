@extends('layouts.dashboard')

@section('title', 'Editar booking | MAXIMO WMS')
@section('topbar_title', 'Editar booking')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <a href="{{ route('bookings.index') }}">Bookings</a>
        <span>/</span>
        <a href="{{ route('bookings.show', $booking) }}">{{ $booking->referenceCode() }}</a>
        <span>/</span>
        <span>Editar</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Editar booking {{ $booking->referenceCode() }}</h2>
            <span class="ops-page-meta">{{ $booking->scheduledWindowLabel() }}</span>
        </div>
    </section>

    @if ($errors->any())
        <div class="alert alert-error">
            @foreach ($errors->all() as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    @endif

    <section class="surface-card compact-card daily-ops-card">
        @include('bookings._form')
    </section>
@endsection
