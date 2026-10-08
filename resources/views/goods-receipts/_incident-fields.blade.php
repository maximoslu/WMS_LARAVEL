@php
    $incidentFieldId = 'goods-receipt-incident-'.($receipt->id ?: 'new');
    $incidentNotesId = $incidentFieldId.'-notes';
    $hasIncident = old('has_incident', $receipt->has_incident);
@endphp

<div class="goods-receipt-incident-field" data-goods-receipt-incident>
    <input type="hidden" name="has_incident" value="0">

    <label class="goods-receipt-incident-toggle" for="{{ $incidentFieldId }}">
        <input
            id="{{ $incidentFieldId }}"
            type="checkbox"
            name="has_incident"
            value="1"
            @checked($hasIncident)
            data-goods-receipt-incident-toggle
            aria-controls="{{ $incidentNotesId }}"
            aria-expanded="{{ $hasIncident ? 'true' : 'false' }}"
        >
        <span class="goods-receipt-incident-toggle-icon" aria-hidden="true">!</span>
        <span>
            <strong>Incidencia</strong>
            <small>Marcar si esta entrada requiere comunicar una incidencia al cliente.</small>
        </span>
    </label>

    <div id="{{ $incidentNotesId }}" class="goods-receipt-incident-notes" data-goods-receipt-incident-notes @unless($hasIncident) hidden @endunless>
        <label class="auth-field">
            <span>Observación de incidencia</span>
            <textarea name="incident_notes" rows="3" class="auth-input" maxlength="2000" data-goods-receipt-incident-notes-input>{{ old('incident_notes', $receipt->incident_notes) }}</textarea>
            <small class="helper-text">Este texto se mostrará al cliente junto al albarán y en el aviso de disponibilidad.</small>
            @error('incident_notes')
                <small class="form-error">{{ $message }}</small>
            @enderror
        </label>
    </div>
</div>
