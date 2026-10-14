@if ($dispatch->canEditDeliveryAddress())
    <fieldset class="surface-card compact-card" data-dispatch-vehicle>
        <legend>Servicio de transporte</legend>
        @if ($dispatch->client->requiresVehicleType())
        <label class="auth-field"><span>Tipo de vehículo</span>
            <select name="vehicle_type" class="auth-input" data-dispatch-vehicle-type>
                <option value="">Selecciona rígido o tráiler</option>
                @foreach (\App\Support\VehicleType::OPTIONS as $value => $label)
                    <option value="{{ $value }}" @selected(old('vehicle_type', $dispatch->vehicle_type) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        @endif
        @include('components.transport-mode-fields', ['movement' => $dispatch])
        @error('vehicle_type')<small class="form-error">{{ $message }}</small>@enderror
    </fieldset>
@endif
