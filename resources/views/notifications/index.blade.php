@extends('layouts.dashboard')

@section('title', 'Notificaciones | MAXIMO WMS')
@section('topbar_title', 'Notificaciones')

@section('content')
    @php
        $breadcrumbs = [


        ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
        ['label' => 'Notificaciones'],
        ];
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Notificaciones</h2>
            <span class="ops-page-meta">{{ $notifications->total() }} registros</span>
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if ($notifications->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin avisos</span>
            <h3>No hay notificaciones recientes.</h3>
            <p>Cuando el sistema registre avisos operativos apareceran aqui.</p>
        </article>
    @else
        <section class="surface-card compact-card notification-summary-card">
            <strong>Mostrando {{ $notifications->firstItem() }}-{{ $notifications->lastItem() }} de {{ $notifications->total() }} notificaciones</strong>
        </section>

        <section class="notification-list">
            @foreach ($notifications as $notification)
                @include('notifications._card', ['notification' => $notification])
            @endforeach
        </section>

        @if ($notifications->hasPages())
            <div class="pagination-card surface-card compact-card">
                {{ $notifications->links() }}
            </div>
        @endif
    @endif
@endsection





