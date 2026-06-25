@extends('layouts.dashboard')

@section('title', 'Dashboard | MAXIMO WMS')
@section('topbar_title', 'Dashboard')

@section('content')
    <section class="surface-card ops-page-header ops-page-header--dense compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Dashboard</h2>
            <span class="ops-page-meta">{{ $visibleModuleCount }} modulos visibles</span>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons">
            <span class="ops-status badge-compact">{{ $currentRoleName }}</span>
        </div>
    </section>

    <section class="ops-section-grid ops-section-grid--dashboard" aria-label="Secciones operativas">
        @foreach ($navigationSections as $section)
            <article class="surface-card ops-index-card compact-card">
                <div class="ops-section-heading ops-index-heading">
                    <strong>{{ $section['title'] }}</strong>
                    <span class="ops-status badge-compact">{{ count($section['children']) }}</span>
                </div>

                <div class="ops-index-list">
                    @foreach ($section['children'] as $child)
                        <a href="{{ route($child['route']) }}" class="ops-index-link{{ request()->routeIs(...($child['active_patterns'] ?? [$child['route']])) ? ' is-active' : '' }}">
                            <strong>{{ $child['title'] }}</strong>
                            <span class="ops-status badge-compact {{ $child['status'] === 'ready' ? 'ops-status--ready' : 'ops-status--placeholder' }}">
                                {{ $child['status_label'] }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </article>
        @endforeach
    </section>
@endsection
