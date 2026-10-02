@extends(($driverPortal ?? false) ? 'layouts.deca-driver' : 'layouts.dashboard')
@section('title', 'DECA emitido | MAXIMO WMS')
@section('topbar_title', 'DECA emitido')
@section('content')
@unless($driverPortal ?? false)
<x-breadcrumbs :items="[['label' => 'DECA', 'href' => route('deca.index')], ['label' => 'Documento emitido']]" />
@endunless
<div class="deca-home deca-form-page">
    @if(session('status'))<p class="deca-notice" role="status">{{ session('status') }}</p>@endif
    <section class="surface-card deca-fields">
        <h1>DECA emitido</h1><p class="deca-number">{{ $document->number }}</p>
        <strong>{{ $document->snapshot['carrier']['name'] }}</strong>
        <p>{{ $document->transport_date->format('d/m/Y') }} · {{ $document->snapshot['tractor_plate'] }}</p>
        <dl class="deca-summary">
            @foreach(['shipper_name' => 'Cargador contractual', 'origin' => 'Origen', 'destination' => 'Destino', 'goods' => 'Mercancía'] as $field => $label)<dt>{{ $label }}</dt><dd>{{ $document->snapshot[$field] }}</dd>@endforeach
            <dt>Peso</dt><dd>{{ number_format((float) $document->snapshot['weight_kg'], 3, ',', '.') }} kg</dd>
        </dl>
        <a class="button-primary" href="{{ ($driverPortal ?? false) ? $document->public_url : route('deca.download', $document) }}">Descargar PDF con QR</a>
        <img class="deca-qr" src="{{ $qr }}" alt="QR para descargar directamente este DECA">
        <p class="deca-help">Este QR está incluido en el PDF. Quien lo tenga puede descargar el documento sin iniciar sesión.</p>
        <button type="button" class="button-secondary" data-deca-share="{{ $document->public_url }}" hidden>Compartir enlace al PDF</button><p role="status" data-deca-share-status></p>
        <p class="deca-help">Emitido el {{ $document->issued_at->timezone('Europe/Madrid')->format('d/m/Y H:i:s') }} (hora de Madrid). Se conserva el PDF original.</p>
        @if($driverPortal ?? false)
            <a href="{{ route('driver.quick') }}" class="button-secondary">Crear otro DECA rápido</a>
        @else
        <a href="{{ route('deca.create') }}" class="button-secondary">Crear otro DECA</a><a href="{{ route('deca.documents') }}" class="deca-back">Ver documentos</a>
        @endif
    </section>
</div>
@endsection
