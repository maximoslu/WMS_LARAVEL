<?php

namespace App\Services\Deca;

use App\Models\DecaDocument;
use App\Models\GoodsDispatch;
use App\Models\User;
use App\Support\TransportMode;
use App\Support\VehicleType;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DispatchDecaService
{
    public function defaults(?GoodsDispatch $dispatch = null): array
    {
        return [
            'carrier_key' => $dispatch !== null && ! $dispatch->camion_propio ? 'external' : config('deca.dispatch.carrier_key'),
            'articulated' => '0',
            'special_authorization' => '0',
        ];
    }

    public function validate(array $input, bool $complete = false): array
    {
        $input = array_intersect_key($input, array_flip([
            'carrier_key',
            'weight_kg', 'tractor_plate', 'trailer_plate', 'articulated',
            'special_authorization', 'authorization_number', 'transport_date',
            'carrier_name', 'carrier_tax_id', 'notes',
        ]));
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = trim($value);
            }
        }
        foreach (['tractor_plate', 'trailer_plate', 'carrier_tax_id'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = mb_strtoupper($input[$key]);
            }
        }
        if (isset($input['weight_kg']) && is_string($input['weight_kg'])) {
            $input['weight_kg'] = str_replace(',', '.', $input['weight_kg']);
        }
        $required = $complete ? 'required' : 'nullable';

        return Validator::make(['transport' => $input], [
            'transport.carrier_key' => [$required, Rule::in(['monge', 'external'])],
            'transport.weight_kg' => [$required, 'numeric', 'gt:0', 'max:9999999', 'decimal:0,3'],
            'transport.tractor_plate' => [$required, 'string', 'max:20'],
            'transport.articulated' => [$required, 'boolean'],
            'transport.trailer_plate' => [$complete ? 'required_if:transport.articulated,1' : 'nullable', 'nullable', 'string', 'max:20'],
            'transport.special_authorization' => [$required, 'boolean'],
            'transport.authorization_number' => [$complete ? 'required_if:transport.special_authorization,1' : 'nullable', 'nullable', 'string', 'max:100'],
            'transport.transport_date' => [$required, 'date_format:Y-m-d', ...($complete ? ['after_or_equal:'.now('Europe/Madrid')->toDateString()] : [])],
            'transport.carrier_name' => [$complete ? 'required_if:transport.carrier_key,external' : 'nullable', 'nullable', 'string', 'max:180'],
            'transport.carrier_tax_id' => [$complete ? 'required_if:transport.carrier_key,external' : 'nullable', 'nullable', 'string', 'max:30'],
            'transport.notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'required' => 'Completa :attribute para emitir el DECA.',
            'required_if' => 'Completa :attribute para este transporte.',
        ], [
            'transport.weight_kg' => 'el peso en kg', 'transport.tractor_plate' => 'la matrícula',
            'transport.carrier_key' => 'el transportista',
            'transport.transport_date' => 'la fecha del transporte', 'transport.trailer_plate' => 'la matrícula del remolque',
            'transport.carrier_name' => 'la razón social del transportista', 'transport.carrier_tax_id' => 'el NIF del transportista',
        ])->validate()['transport'] ?? [];
    }

    public function saveDraft(GoodsDispatch $dispatch, array $input): void
    {
        if (! $dispatch->canEditDeliveryAddress() || $dispatch->deca_document_id !== null) {
            throw ValidationException::withMessages(['transport' => 'El transporte de una salida enviada no se puede modificar.']);
        }
        $data = array_replace($this->defaults($dispatch), $dispatch->transport_data ?? [], $input);
        if ($dispatch->client->requiresVehicleType() && $dispatch->vehicle_type !== null) {
            $data['articulated'] = $dispatch->vehicle_type === 'trailer' ? '1' : '0';
        }
        if ($dispatch->transport_mode !== null) {
            $data = TransportMode::data($dispatch->transport_mode, $data, $dispatch->vehicle_type);
        }
        $data = $this->validate($data);
        $updates = ['transport_data' => $data];
        if (! $dispatch->client->requiresVehicleType()) {
            $updates['camion_propio'] = ($data['carrier_key'] ?? 'monge') === 'monge';
        }
        $dispatch->update($updates);
    }

    public function discardRolledBackDocument(?DecaDocument $document): void
    {
        // After-commit notification errors must never remove a committed PDF.
        if ($document !== null && ! DecaDocument::whereKey($document->id)->exists()) {
            Storage::disk('local')->delete($document->pdf_path);
        }
    }

    // Called only inside the workflow transaction, while the dispatch row is locked.
    public function issue(GoodsDispatch $dispatch, User $user): DecaDocument
    {
        if ($dispatch->deca_document_id !== null) {
            return $dispatch->decaDocument()->firstOrFail();
        }
        $data = array_replace($this->defaults($dispatch), $dispatch->transport_data ?? []);
        if ($dispatch->client->requiresVehicleType()) {
            VehicleType::requireForClient($dispatch->client, $dispatch->vehicle_type);
            $data['articulated'] = $dispatch->vehicle_type === 'trailer' ? '1' : '0';
        }
        if ($dispatch->transport_mode !== null) {
            $data = TransportMode::data($dispatch->transport_mode, $data, $dispatch->vehicle_type, true);
        }
        $data = $this->validate($data, true);
        foreach (['shipper_name', 'shipper_tax_id', 'shipper_address'] as $field) {
            $data[$field] = config('deca.dispatch.'.$field);
            if (blank($data[$field])) {
                throw ValidationException::withMessages(['transport' => 'Administración debe completar los datos fiscales de MAXIMO antes de emitir el DECA.']);
            }
        }
        $dispatch->loadMissing(['client', 'lines.allocations', 'lines.sourceRequestLine']);
        $origin = trim((string) config('deca.dispatch.origin'));
        $destination = $dispatch->effectiveDeliveryAddress();
        if ($origin === '' || trim($destination) === '') {
            throw ValidationException::withMessages(['transport' => 'Completa la dirección del almacén y la dirección de entrega antes de emitir el DECA.']);
        }
        $carrier = null;
        if ($data['carrier_key'] === 'external') {
            $carrier = ['name' => $data['carrier_name'], 'tax_id' => $data['carrier_tax_id'], 'label' => 'Transportista externo'];
        }
        if (! (bool) $data['articulated']) {
            unset($data['trailer_plate']);
        }
        if (! (bool) $data['special_authorization']) {
            unset($data['authorization_number']);
        }
        $goods = $dispatch->lines->filter(fn ($line) => $line->hasDeliveredQuantity())
            ->map(fn ($line) => $line->sku.' — '.$line->description.' — '.$line->loadedUnitsTotal().' unidades')
            ->implode("\n");
        $document = app(DecaIssuanceService::class)->issue($user, [
            ...$data, 'submission_key' => (string) Str::uuid(),
            'origin' => $origin, 'destination' => $destination, 'goods' => $goods,
            'dispatch_number' => $dispatch->dispatchNumber(), 'client_id' => $dispatch->client_id,
            'access_channel' => 'goods_dispatch',
        ], $carrier);
        try {
            $dispatch->update(['deca_document_id' => $document->id]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($document->pdf_path);
            throw $exception;
        }

        return $document;
    }
}
