<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Support\Stock\StockLinePayloadResolver;
use App\Support\WmsLineType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMerchandiseRequestRequest extends FormRequest
{
    /**
     * @var array<int, int|string>
     */
    private array $legacyQuantityKeys = [];

    /**
     * @var array<int, array{
     *     item_id:int,
     *     sku:string,
     *     description:string,
     *     stock_pallet_id:int|null,
     *     line_type:string,
     *     stock_peak_index:int|null,
     *     lot:string|null,
     *     location_text:string|null,
     *     units_per_pallet:int,
     *     units_per_peak:int|null,
     *     requested_pallets:int,
     *     requested_peaks:int,
     *     requested_units:int
     * }> | null
     */
    private ?array $resolvedLines = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $resolvedErrors = null;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if ($user->hasRole(Role::CLIENTE)) {
            return $user->client_id !== null;
        }

        return $user->canAccessRole(Role::ALMACEN);
    }

    protected function prepareForValidation(): void
    {
        $submittedLines = $this->input('lines');

        if (! is_array($submittedLines)) {
            $submittedLines = collect($this->input('quantities', []))
                ->map(function ($value, $itemId) {
                    $this->legacyQuantityKeys[] = $itemId;

                    return [
                        'item_id' => $itemId,
                        'line_type' => WmsLineType::PALLET,
                        'quantity' => $value,
                    ];
                })
                ->values()
                ->all();
        }

        $this->merge([
            'camion_propio' => $this->boolean('camion_propio'),
            'client_id' => $this->input('client_id') === '' ? null : $this->input('client_id'),
            'lines' => collect($submittedLines)
                ->map(function ($payload) {
                    if (! is_array($payload)) {
                        return [];
                    }

                    return [
                        'item_id' => $payload['item_id'] ?? null,
                        'line_type' => $payload['line_type'] ?? WmsLineType::PALLET,
                        'stock_pallet_id' => $payload['stock_pallet_id'] ?? null,
                        'stock_peak_index' => $payload['stock_peak_index'] ?? null,
                        'quantity' => ($payload['quantity'] ?? '') === '' ? null : ($payload['quantity'] ?? null),
                    ];
                })
                ->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'client_id' => [
                Rule::requiredIf(fn (): bool => (bool) $this->user()?->canAccessRole(Role::ALMACEN) && ! $this->user()?->hasRole(Role::CLIENTE)),
                'nullable',
                'integer',
                Rule::exists('clients', 'id')->where('active', true),
            ],
            'lines' => ['required', 'array'],
            'camion_propio' => ['boolean'],
            'lines.*.item_id' => ['nullable', 'integer'],
            'lines.*.line_type' => ['required', 'string'],
            'lines.*.stock_pallet_id' => ['nullable', 'integer'],
            'lines.*.stock_peak_index' => ['nullable', 'integer', 'min:1'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->mirrorLegacyValidationErrors($validator);
            $lines = $this->validatedLines();

            if ($lines === []) {
                $message = 'Debes seleccionar al menos una linea valida con pallets o picos.';
                $validator->errors()->add('lines', $message);
                $this->addLegacyQuantitiesError($validator, $message);

                return;
            }

            foreach ($this->resolvedErrors() as $field => $message) {
                $validator->errors()->add($field, $message);
                $this->addLegacyQuantitiesError($validator, $message, $field);
            }
        });
    }

    /**
     * @return array<int, array{
     *     item_id:int,
     *     sku:string,
     *     description:string,
     *     stock_pallet_id:int|null,
     *     line_type:string,
     *     stock_peak_index:int|null,
     *     lot:string|null,
     *     location_text:string|null,
     *     units_per_pallet:int,
     *     units_per_peak:int|null,
     *     requested_pallets:int,
     *     requested_peaks:int,
     *     requested_units:int
     * }>
     */
    public function validatedLines(): array
    {
        if ($this->resolvedLines !== null) {
            return $this->resolvedLines;
        }

        /** @var StockLinePayloadResolver $resolver */
        $resolver = app(StockLinePayloadResolver::class);
        $resolved = $resolver->resolve($this->effectiveClientId(), $this->input('lines', []), true);

        $this->resolvedLines = $resolved['lines'];
        $this->resolvedErrors = $resolved['errors'];

        return $this->resolvedLines;
    }

    public function effectiveClientId(): int
    {
        $user = $this->user();

        if ($user?->hasRole(Role::CLIENTE)) {
            return (int) $user->client_id;
        }

        return (int) $this->input('client_id');
    }

    /**
     * @return array<string, string>
     */
    private function resolvedErrors(): array
    {
        if ($this->resolvedErrors !== null) {
            return $this->resolvedErrors;
        }

        $this->validatedLines();

        return $this->resolvedErrors ?? [];
    }

    private function addLegacyQuantitiesError(Validator $validator, string $message, ?string $field = null): void
    {
        if ($this->legacyQuantityKeys === []) {
            return;
        }

        if ($field === null || $field === 'lines') {
            $validator->errors()->add('quantities', $message);

            return;
        }

        if (preg_match('/^lines\.(\d+)\./', $field, $matches) !== 1) {
            return;
        }

        $legacyKey = $this->legacyQuantityKeys[(int) $matches[1]] ?? null;

        if ($legacyKey === null) {
            $validator->errors()->add('quantities', $message);

            return;
        }

        $validator->errors()->add('quantities.'.$legacyKey, $message);
    }

    private function mirrorLegacyValidationErrors(Validator $validator): void
    {
        if ($this->legacyQuantityKeys === []) {
            return;
        }

        foreach ($validator->errors()->getMessages() as $field => $messages) {
            if (! str_starts_with($field, 'lines.')) {
                continue;
            }

            foreach ($messages as $message) {
                $this->addLegacyQuantitiesError($validator, $message, $field);
            }
        }
    }
}
