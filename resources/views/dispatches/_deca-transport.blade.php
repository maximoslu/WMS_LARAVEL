@if (config('deca.dispatch.enabled') && $dispatch->canEditDeliveryAddress())
    @php($transport = array_replace(app(\App\Services\Deca\DispatchDecaService::class)->defaults($dispatch), $dispatch->transport_data ?? []))
    <fieldset class="surface-card compact-card" data-deca-transport>
        <legend>DECA de esta salida</legend>
        <p>Origen y cargador: MÁXIMO SERVICIOS LOGÍSTICOS S.L.U. · {{ config('deca.dispatch.origin') }}</p>
        <p>Destino: {{ $dispatch->effectiveDeliveryAddress() ?: 'Completa la dirección de entrega.' }}</p>
        <p>El DECA se emitirá al confirmar el envío, antes de iniciar el transporte. Al confirmar, revisa que estos datos coincidan con el vehículo y la carga real. Puedes guardar la preparación con estos datos pendientes.</p>
        <div class="wms-filter-grid">
            @unless ($dispatch->client->requiresVehicleType())
            <label class="auth-field"><span>Transportista</span>
                <select name="transport[carrier_key]" class="auth-input" data-deca-carrier>
                    <option value="monge" @selected(old('transport.carrier_key', $transport['carrier_key'] ?? 'monge') === 'monge')>Transportes Monge</option>
                    <option value="external" @selected(old('transport.carrier_key', $transport['carrier_key'] ?? '') === 'external')>Transportista externo</option>
                </select>
            </label>
            @endunless
            <label class="auth-field"><span>Peso total de la mercancía (kg)</span><input name="transport[weight_kg]" inputmode="decimal" maxlength="15" class="auth-input" value="{{ old('transport.weight_kg', $transport['weight_kg'] ?? '') }}"></label>
            @unless ($dispatch->client->requiresVehicleType())
            <label class="auth-field"><span>Matrícula del vehículo / tractora</span><input name="transport[tractor_plate]" maxlength="20" class="auth-input" value="{{ old('transport.tractor_plate', $transport['tractor_plate'] ?? '') }}"></label>
            @endunless
            <label class="auth-field"><span>Fecha del transporte</span><input type="date" name="transport[transport_date]" class="auth-input" value="{{ old('transport.transport_date', $transport['transport_date'] ?? now('Europe/Madrid')->toDateString()) }}"></label>
        </div>
        @unless ($dispatch->client->requiresVehicleType())
        <div data-deca-external>
            <label class="auth-field"><span>Razón social del transportista externo</span><input name="transport[carrier_name]" maxlength="180" class="auth-input" value="{{ old('transport.carrier_name', $transport['carrier_name'] ?? '') }}"></label>
            <label class="auth-field"><span>NIF del transportista externo</span><input name="transport[carrier_tax_id]" maxlength="30" class="auth-input" value="{{ old('transport.carrier_tax_id', $transport['carrier_tax_id'] ?? '') }}"></label>
        </div>
        @endunless
        @if ($dispatch->client->requiresVehicleType())
            <p>El remolque se determina a partir del tipo de vehículo seleccionado.</p>
        @else
        <input type="hidden" name="transport[articulated]" value="0">
        <label><input type="checkbox" name="transport[articulated]" value="1" data-deca-articulated @checked(old('transport.articulated', $transport['articulated'] ?? false))> Lleva remolque / semirremolque</label>
        @endif
        @unless ($dispatch->client->requiresVehicleType())
        <label class="auth-field" data-deca-trailer><span>Matrícula del remolque / semirremolque</span><input name="transport[trailer_plate]" maxlength="20" class="auth-input" value="{{ old('transport.trailer_plate', $transport['trailer_plate'] ?? '') }}"></label>
        @endunless
        <input type="hidden" name="transport[special_authorization]" value="0">
        <label><input type="checkbox" name="transport[special_authorization]" value="1" data-deca-special @checked(old('transport.special_authorization', $transport['special_authorization'] ?? false))> Requiere autorización especial de circulación</label>
        <label class="auth-field" data-deca-authorization><span>Número de autorización</span><input name="transport[authorization_number]" maxlength="100" class="auth-input" value="{{ old('transport.authorization_number', $transport['authorization_number'] ?? '') }}"></label>
        <label class="auth-field"><span>Observaciones del transporte (opcional)</span><textarea name="transport[notes]" rows="2" maxlength="2000" class="auth-input">{{ old('transport.notes', $transport['notes'] ?? '') }}</textarea></label>
    </fieldset>
@endif
