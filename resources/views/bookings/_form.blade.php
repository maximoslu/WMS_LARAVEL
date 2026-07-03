@php
    $isEditing = $booking !== null;
    $formAction = $isEditing ? route('bookings.update', $booking) : route('bookings.store');
@endphp

<form method="POST" action="{{ $formAction }}" class="item-form daily-ops-entry-form booking-form{{ $isClient ? ' booking-form--client' : ' booking-form--internal' }}">
    @csrf
    @if ($isEditing)
        @method('PUT')
    @endif

    <div class="form-grid daily-ops-entry-grid">
        @unless ($isClient)
            <label class="auth-field">
                <span>Cliente</span>
                <select name="client_id" class="auth-input" required>
                    <option value="">Seleccionar cliente</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((string) old('client_id', $booking?->client_id) === (string) $client->id)>
                            {{ $client->name }}
                        </option>
                    @endforeach
                </select>
            </label>
        @endunless

        <label class="auth-field">
            <span>Tipo</span>
            <select name="type" class="auth-input" required>
                @foreach ($typeOptions as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', $booking?->type) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label class="auth-field">
            <span>Fecha solicitada</span>
            <input type="date" name="scheduled_date" value="{{ old('scheduled_date', $booking?->scheduled_date?->format('Y-m-d')) }}" class="auth-input" required>
        </label>

        <label class="auth-field{{ $isClient ? ' item-form-field--full' : '' }}">
            <span>{{ $isClient ? 'Transportista o referencia de llegada' : 'Transportista' }}</span>
            <input type="text" name="carrier_name" value="{{ old('carrier_name', $booking?->carrier_name) }}" class="auth-input" @required($isClient)>
        </label>

        @if (! $isClient)
            <label class="auth-field">
                <span>Hora desde</span>
                <input type="time" name="scheduled_time_from" value="{{ old('scheduled_time_from', $booking?->scheduled_time_from ? substr($booking->scheduled_time_from, 0, 5) : null) }}" class="auth-input">
            </label>

            <label class="auth-field">
                <span>Hora hasta</span>
                <input type="time" name="scheduled_time_to" value="{{ old('scheduled_time_to', $booking?->scheduled_time_to ? substr($booking->scheduled_time_to, 0, 5) : null) }}" class="auth-input">
            </label>

            <label class="auth-field">
                <span>NÂº pallets previstos</span>
                <input type="number" min="0" name="pallets_expected" value="{{ old('pallets_expected', $booking?->pallets_expected) }}" class="auth-input">
            </label>

            <label class="auth-field">
                <span>Matrícula vehículo</span>
                <input type="text" name="vehicle_plate" value="{{ old('vehicle_plate', $booking?->vehicle_plate) }}" class="auth-input">
            </label>

            <label class="auth-field">
                <span>Conductor</span>
                <input type="text" name="driver_name" value="{{ old('driver_name', $booking?->driver_name) }}" class="auth-input">
            </label>

            <label class="auth-field">
                <span>Persona de contacto</span>
                <input type="text" name="contact_name" value="{{ old('contact_name', $booking?->contact_name) }}" class="auth-input">
            </label>

            <label class="auth-field">
                <span>Teléfono</span>
                <input type="text" name="contact_phone" value="{{ old('contact_phone', $booking?->contact_phone) }}" class="auth-input">
            </label>

            <label class="auth-field">
                <span>Origen / destino</span>
                <input type="text" name="origin_destination" value="{{ old('origin_destination', $booking?->origin_destination) }}" class="auth-input">
            </label>

            <label class="auth-field">
                <span>Referencia documental</span>
                <input type="text" name="document_reference" value="{{ old('document_reference', $booking?->document_reference) }}" class="auth-input">
            </label>

            <label class="auth-field">
                <span>Muelle</span>
                <input type="text" name="loading_dock" value="{{ old('loading_dock', $booking?->loading_dock) }}" class="auth-input">
            </label>

            <label class="auth-field">
                <span>Asignado a</span>
                <select name="assigned_to" class="auth-input">
                    <option value="">Sin asignar</option>
                    @foreach ($internalUsers as $internalUser)
                        <option value="{{ $internalUser->id }}" @selected((string) old('assigned_to', $booking?->assigned_to) === (string) $internalUser->id)>
                            {{ $internalUser->name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="auth-field">
                <span>Almacén</span>
                <select name="warehouse_id" class="auth-input">
                    <option value="">Sin almacén</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected((string) old('warehouse_id', $booking?->warehouse_id) === (string) $warehouse->id)>
                            {{ $warehouse->name }}
                        </option>
                    @endforeach
                </select>
            </label>
        @endif

        <label class="auth-field item-form-field--full">
            <span>Observaciones</span>
            <textarea name="notes" rows="4" class="auth-input">{{ old('notes', $booking?->notes) }}</textarea>
        </label>

        @unless ($isClient)
            <label class="auth-field item-form-field--full">
                <span>Notas internas</span>
                <textarea name="internal_notes" rows="4" class="auth-input">{{ old('internal_notes', $booking?->internal_notes) }}</textarea>
            </label>
        @endunless
    </div>

    <div class="item-form-actions action-buttons daily-ops-entry-actions">
        <button type="submit" class="button-primary compact-button btn-compact">
            {{ $isEditing ? ($isClient ? 'Guardar solicitud' : 'Guardar booking') : ($isClient ? 'Enviar solicitud' : 'Enviar solicitud de booking') }}
        </button>
    </div>
</form>

