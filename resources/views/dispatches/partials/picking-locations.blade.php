@php($pickingLocationSummaries = $line->pickingLocationSummaries())

<div class="wms-picking-location-list">
    <strong>Ubicación de recogida</strong>
    @forelse ($pickingLocationSummaries as $pickingSummary)
        <span>
            Recoger en: {{ $pickingSummary['location'] }}
            @if ($pickingSummary['quantity'])
                · {{ $pickingSummary['quantity'] }}
            @endif
        </span>
    @empty
        <span>Pendiente de asignar ubicación</span>
    @endforelse
</div>
