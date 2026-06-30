@extends('layouts.dashboard')

@section('title', 'Crear booking | MAXIMO WMS')
@section('topbar_title', 'Crear booking')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <a href="{{ route('bookings.index') }}">Bookings</a>
        <span>/</span>
        <span>Crear</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">{{ $isClient ? 'Solicitar booking' : 'Crear booking' }}</h2>
            <span class="ops-page-meta">La solicitud quedará pendiente de validación operativa.</span>
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
        @if ($isClient)
            <p class="helper-text">Indica únicamente el tipo, la fecha prevista, quién viene o sale y cualquier observación útil. El equipo interno completará el resto de datos operativos.</p>
        @endif
        <p class="helper-text">TODO: preparar sincronización futura con Google Workspace Calendar cuando exista la integración corporativa.</p>
        @include('bookings._form')
    </section>
@endsection
