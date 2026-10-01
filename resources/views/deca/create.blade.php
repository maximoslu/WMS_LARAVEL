@extends('layouts.dashboard')
@section('title', 'Crear DECA | MAXIMO WMS')
@section('topbar_title', 'Crear DECA')
@section('content')
<x-breadcrumbs :items="[['label' => 'DECA', 'href' => route('deca.index')], ['label' => 'Crear DECA manual']]" />
<div class="deca-home deca-form-page">
    <header><h1>Crear DECA manual</h1><p>Completa los datos y genera el PDF con QR antes de iniciar el transporte.</p></header>
    @if($errors->any())
        <div class="deca-errors" role="alert"><strong>Revisa estos datos:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <form method="POST" action="{{ route('deca.store') }}" class="deca-form" data-deca-form>
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
            <p class="deca-help">El cargador contractual es quien contrata directamente con el transportista.</p>
            @foreach(['shipper_name' => ['Nombre o razón social del cargador', 180], 'shipper_tax_id' => ['NIF del cargador', 30], 'shipper_address' => ['Domicilio del cargador (dirección, CP y localidad)', 300]] as $field => [$label, $max])
                <label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" value="{{ old($field) }}" required maxlength="{{ $max }}">
            @endforeach
        </fieldset>
        <fieldset class="surface-card deca-fields">
            <legend>2. Trayecto</legend>
            <label for="transport_date">Fecha del transporte</label>
            <input id="transport_date" type="date" name="transport_date" value="{{ old('transport_date', now('Europe/Madrid')->toDateString()) }}" min="{{ now('Europe/Madrid')->toDateString() }}" required>
            @foreach(['origin' => 'Lugar de carga / origen', 'destination' => 'Lugar de descarga / destino'] as $field => $label)
                <label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" value="{{ old($field) }}" required maxlength="300" placeholder="Empresa, dirección y localidad">
            @endforeach
        </fieldset>
        <fieldset class="surface-card deca-fields">
            <legend>3. Mercancía</legend>
            <label for="goods">Descripción de la mercancía</label><textarea id="goods" name="goods" rows="3" required maxlength="1200" placeholder="Ej.: 12 palets de libros">{{ old('goods') }}</textarea>
            <label for="weight_kg">Peso total (kg)</label><input id="weight_kg" name="weight_kg" inputmode="decimal" value="{{ old('weight_kg') }}" required maxlength="12" placeholder="Ej.: 12500" aria-describedby="weight-help">
            <small id="weight-help">Sin separadores de miles. Admite hasta tres decimales.</small>
        </fieldset>
        <fieldset class="surface-card deca-fields">
            <legend>4. Vehículo</legend>
            <label for="tractor_plate">Matrícula del vehículo / cabeza tractora</label><input id="tractor_plate" name="tractor_plate" value="{{ old('tractor_plate') }}" required maxlength="20" autocapitalize="characters" spellcheck="false" placeholder="Ej.: 1234 ABC">
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
            <button class="button-primary deca-submit" type="submit">Generar PDF con QR</button><a href="{{ route('deca.index') }}" class="deca-back">Volver a DECA</a>
        </div>
    </form>
</div>
@endsection
