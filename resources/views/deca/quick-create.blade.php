@extends(($driverPortal ?? false) ? 'layouts.deca-driver' : 'layouts.dashboard')
@section('title', 'DECA rápido | MAXIMO WMS')
@section('topbar_title', 'DECA rápido')
@section('content')
@unless($driverPortal ?? false)
<x-breadcrumbs :items="[['label' => 'DECA', 'href' => route('deca.index')], ['label' => 'DECA rápido', 'href' => route(($driverPortal ?? false) ? 'driver.quick' : 'deca.quick')], ['label' => $preset['title']]]" />
@endunless
<div class="deca-home deca-form-page">
    <header><h1>{{ $preset['title'] }}</h1><p>Revisa el servicio, selecciona el vehículo y genera el PDF con QR.</p></header>
    @if($errors->any())
        <div class="deca-errors" role="alert"><strong>Revisa estos datos:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <form method="POST" action="{{ route(($driverPortal ?? false) ? 'driver.store' : 'deca.quick.store', $templateKey) }}" class="deca-form" data-deca-form>
        @csrf
        <input type="hidden" name="submission_key" value="{{ old('submission_key', $submissionKey) }}">
        <fieldset class="surface-card deca-fields">
            <legend>1. Empresas</legend>
            <label for="carrier_key">Empresa transportista</label>
            <select id="carrier_key" name="carrier_key" required data-deca-carrier>
                <option value="">Selecciona una empresa</option>
                @foreach($carriers as $key => $carrier)<option value="{{ $key }}" @selected(old('carrier_key') === $key)>{{ $carrier['label'] }}</option>@endforeach
            </select>
            @foreach($carriers as $key => $carrier)
                <div class="deca-carrier-details" data-deca-carrier-details="{{ $key }}" @if(old('carrier_key') !== $key) hidden @endif>
                    <strong>{{ $carrier['name'] }}</strong><span>NIF: {{ $carrier['tax_id'] ?: 'Pendiente de configuración' }}</span><span>{{ $carrier['address'] }}</span>
                </div>
            @endforeach
        </fieldset>
        <section class="surface-card deca-fields">
            <h2>Datos del servicio</h2>
            <dl class="deca-summary">
                @foreach(['shipper_name' => 'Cargador', 'shipper_tax_id' => 'NIF', 'shipper_address' => 'Domicilio fiscal', 'origin' => 'Recogida', 'destination' => 'Entrega', 'goods' => 'Mercancía'] as $field => $label)
                    <dt>{{ $label }}</dt><dd>{{ $preset[$field] }}</dd>
                @endforeach
                <dt>Peso aproximado</dt><dd>{{ number_format($preset['weight_kg'], 0, ',', '.') }} kg</dd>
            </dl>
            <label for="transport_date">Fecha del transporte</label>
            <input id="transport_date" type="date" name="transport_date" value="{{ old('transport_date', now('Europe/Madrid')->toDateString()) }}" min="{{ now('Europe/Madrid')->toDateString() }}" required>
        </section>
        <fieldset class="surface-card deca-fields">
            <legend>Vehículo</legend>
            <label for="tractor_plate">¿Qué vehículo llevas?</label>
            <select id="tractor_plate" name="tractor_plate" required>
                <option value="">Selecciona la matrícula</option>
                @foreach($plates as $plate)<option value="{{ $plate }}" @selected(old('tractor_plate') === $plate)>{{ $plate }}</option>@endforeach
            </select>
            <input type="hidden" name="articulated" value="0">
            <label class="deca-check"><input type="checkbox" name="articulated" value="1" @checked(old('articulated')) data-deca-toggle="trailer"> Lleva remolque o semirremolque</label>
            <div data-deca-conditional="trailer"><label for="trailer_plate">Matrícula del remolque / semirremolque</label><input id="trailer_plate" name="trailer_plate" value="{{ old('trailer_plate') }}" maxlength="20" autocapitalize="characters" spellcheck="false"></div>
            <input type="hidden" name="special_authorization" value="0">
            <label class="deca-check"><input type="checkbox" name="special_authorization" value="1" @checked(old('special_authorization')) data-deca-toggle="authorization"> Requiere autorización especial de circulación</label>
            <div data-deca-conditional="authorization"><label for="authorization_number">Número de autorización especial</label><input id="authorization_number" name="authorization_number" value="{{ old('authorization_number') }}" maxlength="100"></div>
            <label for="notes">Observaciones o reservas (opcional)</label><textarea id="notes" name="notes" rows="3" maxlength="2000">{{ old('notes') }}</textarea>
        </fieldset>
        <div class="surface-card deca-fields">
            <label class="deca-check"><input type="checkbox" name="confirmed" value="1" required @checked(old('confirmed'))> He revisado los datos y el servicio de transporte todavía no ha comenzado.</label>
            <p class="deca-help">Al emitir se guarda el documento definitivo. Entrega el PDF al conductor antes de la salida.</p>
            <button class="button-primary deca-submit" type="submit">Generar PDF con QR</button><a href="{{ route(($driverPortal ?? false) ? 'driver.quick' : 'deca.quick') }}" class="deca-back">Volver a DECA rápido</a>
        </div>
    </form>
</div>
@endsection
