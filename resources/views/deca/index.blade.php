@extends('layouts.dashboard')

@section('title', 'DECA | MAXIMO WMS')
@section('topbar_title', 'DECA')

@section('content')
    <x-breadcrumbs :items="[
        ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
        ['label' => 'DECA'],
    ]" />

    <div class="deca-home">
        <section class="surface-card deca-intro" aria-labelledby="deca-title">
            <span class="module-tag">MAXIMO · Transportes Monge</span>
            <h1 id="deca-title">Cartas de porte digitales</h1>
            <p>Tu espacio para preparar la documentación de transporte desde el móvil.</p>
            <p class="deca-notice">Rellena el transporte antes de salir, revisa los datos y emite tu PDF con QR.</p>
        </section>

        <div class="deca-actions">
            <section class="surface-card deca-action" aria-labelledby="deca-create-title">
                <span class="module-tag">01 · Preparar</span>
                <h2 id="deca-create-title">Crear DECA</h2>
                <p>Completa los datos del transporte en pocos pasos: empresas, trayecto, mercancía y vehículo.</p>
                <a href="{{ route('deca.create') }}" class="button-primary">Crear DECA manual</a>
            </section>
            <section class="surface-card deca-action" aria-labelledby="deca-documents-title">
                <span class="module-tag">02 · Consultar</span>
                <h2 id="deca-documents-title">Mis documentos</h2>
                <p>Aquí podrás consultar los documentos emitidos, descargar su PDF y acceder al QR.</p>
                <a href="{{ route('deca.documents') }}" class="button-secondary">Consultar documentos</a>
            </section>
        </div>

        <section class="surface-card deca-intro" aria-labelledby="deca-workflow-title">
            <h2 id="deca-workflow-title">Así prepararás tu DECA</h2>
            <ol class="deca-steps">
                <li><strong>Rellena los datos</strong><span>Identifica al cargador y al transportista e indica el trayecto y la carga.</span></li>
                <li><strong>Revisa el documento</strong><span>Comprueba la información antes de emitirlo.</span></li>
                <li><strong>Descarga el PDF y su QR</strong><span>Ten el documento preparado para el conductor antes de iniciar el transporte.</span></li>
            </ol>
        </section>
    </div>
@endsection
