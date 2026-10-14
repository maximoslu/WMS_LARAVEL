<?php

namespace App\Support;

use App\Models\GoodsDispatch;
use App\Models\GoodsReceipt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransportMode
{
    public const OPTIONS = [
        'monge' => 'MAXIMO · Transportes Monge',
        'subcontracted' => 'MAXIMO · Subcontratado',
        'client' => 'Transporte del cliente',
    ];

    public static function label(?string $mode, bool $legacyOwn = false): string
    {
        return self::OPTIONS[$mode] ?? ($legacyOwn ? 'MAXIMO · Modalidad sin clasificar' : 'Transporte sin clasificar');
    }

    public static function own(string $mode): bool
    {
        return in_array($mode, ['monge', 'subcontracted'], true);
    }

    public static function data(string $mode, array $data, ?string $type, bool $complete = false): array
    {
        Validator::make(['transport_mode' => $mode], ['transport_mode' => ['required', Rule::in(array_keys(self::OPTIONS))]])->validate();
        $data['carrier_key'] = $mode === 'monge' ? 'monge' : 'external';
        foreach (['tractor_plate', 'trailer_plate', 'carrier_tax_id'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = mb_strtoupper(trim($data[$field]));
            }
        }
        $required = $complete ? 'required' : 'nullable';
        $rules = [
            'transport.tractor_plate' => [$required, 'string', 'max:20'],
            'transport.carrier_name' => [$complete && $mode !== 'monge' ? 'required' : 'nullable', 'string', 'max:180'],
            'transport.carrier_tax_id' => [$complete && $mode !== 'monge' ? 'required' : 'nullable', 'string', 'max:30'],
            'transport.trailer_plate' => [$complete && $type === 'trailer' ? 'required' : 'nullable', 'string', 'max:20'],
        ];
        if ($mode === 'monge') {
            if ((bool) ($data['articulated'] ?? false)) {
                throw ValidationException::withMessages(['transport.articulated' => 'Los vehículos actuales de Monge no llevan semirremolque.']);
            }
            $rules['transport.tractor_plate'][] = Rule::in(array_keys(config('transport.monge_vehicles', [])));
            if ($type !== null && $type !== 'rigid') {
                throw ValidationException::withMessages(['vehicle_type' => 'Los vehículos actuales de Monge son rígidos. Para un tráiler de MAXIMO selecciona Subcontratado.']);
            }
            unset($data['carrier_name'], $data['carrier_tax_id']);
        }
        Validator::make(['transport' => $data], $rules, ['required' => 'Completa :attribute para confirmar el transporte.', 'in' => 'Selecciona un vehículo habitual de Monge.'], [
            'transport.tractor_plate' => 'la matrícula', 'transport.trailer_plate' => 'la matrícula del semirremolque',
            'transport.carrier_name' => 'el transportista', 'transport.carrier_tax_id' => 'el NIF del transportista',
        ])->validate();
        if ($type === 'rigid') {
            unset($data['trailer_plate']);
        }

        return $data;
    }

    public static function requireComplete(GoodsReceipt|GoodsDispatch $movement): void
    {
        // Older non-TI movements retain their original own-transport flag until classified.
        if ($movement->transport_mode === null && ! $movement->client->requiresVehicleType()) {
            return;
        }
        if (! isset(self::OPTIONS[$movement->transport_mode ?? ''])) {
            throw ValidationException::withMessages(['transport_mode' => 'Indica si el transporte es Monge, subcontratado por MAXIMO o del cliente.']);
        }
        self::data($movement->transport_mode, $movement->transport_data ?? [], $movement->vehicle_type, true);
    }
}
