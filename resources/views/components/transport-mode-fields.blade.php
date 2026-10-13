@php
    $mode = old('transport_mode', $movement->transport_mode);
    $data = $movement->transport_data ?? [];
@endphp
<div data-transport-mode-fields>
    <label class="auth-field"><span>Servicio de transporte</span>
        <select name="transport_mode" class="auth-input" data-transport-mode>
            <option value="">Selecciona quién realiza el servicio</option>
            @foreach (\App\Support\TransportMode::OPTIONS as $value => $label)
                <option value="{{ $value }}" @selected($mode === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @error('transport_mode')<small class="form-error">{{ $message }}</small>@enderror
    </label>
    <p data-transport-monge>Vehículos Monge: 1933MYN y 3100KGC, ambos rígidos. Para un tráiler a cargo de MAXIMO selecciona Subcontratado.</p>
    <p>Monge y subcontratado por MAXIMO suman un servicio de transporte. El transporte del cliente no suma viaje de MAXIMO.</p>
    @if ($movement->exists && $movement->transport_mode === null)
        <p>Registro anterior: {{ \App\Support\TransportMode::label(null, (bool) $movement->camion_propio) }}. Selecciona la modalidad real antes de confirmar.</p>
    @endif
    <label class="auth-field"><span>Matrícula del vehículo / tractora</span>
        <input name="transport[tractor_plate]" list="monge-vehicles" maxlength="20" class="auth-input" value="{{ old('transport.tractor_plate', $data['tractor_plate'] ?? '') }}" data-transport-plate>
        <datalist id="monge-vehicles">@foreach (config('transport.monge_vehicles') as $plate => $type)<option value="{{ $plate }}">Camión rígido · Monge</option>@endforeach</datalist>
        @error('transport.tractor_plate')<small class="form-error">{{ $message }}</small>@enderror
    </label>
    <div data-transport-external>
        <label class="auth-field"><span>Razón social del transportista</span><input name="transport[carrier_name]" maxlength="180" class="auth-input" value="{{ old('transport.carrier_name', $data['carrier_name'] ?? '') }}"></label>
        <label class="auth-field"><span>NIF del transportista</span><input name="transport[carrier_tax_id]" maxlength="30" class="auth-input" value="{{ old('transport.carrier_tax_id', $data['carrier_tax_id'] ?? '') }}"></label>
    </div>
    <label class="auth-field" data-transport-trailer><span>Matrícula del semirremolque</span><input name="transport[trailer_plate]" maxlength="20" class="auth-input" value="{{ old('transport.trailer_plate', $data['trailer_plate'] ?? '') }}"></label>
</div>
