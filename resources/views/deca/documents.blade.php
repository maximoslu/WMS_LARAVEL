@extends('layouts.dashboard')
@section('title', 'Documentos DECA | MAXIMO WMS')
@section('topbar_title', 'Documentos DECA')
@section('content')
<x-breadcrumbs :items="[['label' => 'DECA', 'href' => route('deca.index')], ['label' => 'Documentos']]" />
<div class="deca-home deca-form-page">
    <header><h1>Documentos DECA</h1><p>Documentos emitidos por el equipo para MAXIMO y Transportes Monge.</p></header>
    <a href="{{ route('deca.create') }}" class="button-primary">Crear DECA manual</a>
    @forelse($documents as $document)
        <article class="surface-card deca-fields">
            <strong class="deca-number">{{ $document->number }}</strong><span>{{ $document->snapshot['carrier']['label'] }} · {{ $document->transport_date->format('d/m/Y') }}</span>
            <p>{{ $document->snapshot['origin'] }} → {{ $document->snapshot['destination'] }}</p><span>{{ $document->snapshot['tractor_plate'] }} · {{ $document->snapshot['shipper_name'] }}</span>
            <a href="{{ route('deca.show', $document) }}" class="button-secondary">Ver documento y QR</a>
        </article>
    @empty
        <section class="surface-card deca-fields"><h2>Todavía no hay documentos</h2><p>Cuando emitas el primer DECA, aparecerá aquí.</p></section>
    @endforelse
    {{ $documents->links() }}
</div>
@endsection
