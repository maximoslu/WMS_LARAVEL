@if ($dispatch->client->requiresVehicleType() && $dispatch->canEditDeliveryAddress())
    <fieldset class="surface-card compact-card" data-dispatch-vehicle>
        <legend>Vehículo · TI Automotive</legend>
        <label class="auth-field"><span>Tipo de vehículo</span>
            <select name="vehicle_type" class="auth-input" data-dispatch-vehicle-type>
                <option value="">Selecciona rígido o tráiler</option>
                @foreach (\App\Support\VehicleType::OPTIONS as $value => $label)
                    <option value="{{ $value }}" @selected(old('vehicle_type', $dispatch->vehicle_type) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="auth-field"><span>Camión propio</span>
            <select name="camion_propio" class="auth-input">
                <option value="1" @selected((bool) old('camion_propio', $dispatch->camion_propio))>Sí</option>
                <option value="0" @selected(! (bool) old('camion_propio', $dispatch->camion_propio))>No</option>
            </select>
        </label>
        <p>Una gestión por vehículo. Se contabiliza un viaje adicional si el transporte es propio, independientemente de los palés.</p>
        @error('vehicle_type')<small class="form-error">{{ $message }}</small>@enderror
    </fieldset>
@endif
