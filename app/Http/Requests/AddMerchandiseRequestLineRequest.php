<?php

namespace App\Http\Requests;

use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Support\Stock\StockLinePayloadResolver;
use App\Support\WmsLineType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AddMerchandiseRequestLineRequest extends FormRequest
{
    /**
     * @var array<int, array{
     *     item_id:int,
     *     sku:string,
     *     description:string,
     *     stock_pallet_id:int|null,
     *     line_type:string,
     *     stock_peak_index:int|null,
     *     lot:string|null,
     *     destination_location:string|null,
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
        return $this->user()?->canAccessRole(Role::ALMACEN) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $submittedLines = $this->input('lines');

        if (! is_array($submittedLines)) {
            $submittedLines = [];
        }

        $this->merge([
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
                        'destination_location' => trim((string) ($payload['destination_location'] ?? '')) !== ''
                            ? trim((string) $payload['destination_location'])
                            : null,
                    ];
                })
                ->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array'],
            'lines.*.item_id' => ['nullable', 'integer'],
            'lines.*.line_type' => ['required', 'string'],
            'lines.*.stock_pallet_id' => ['nullable', 'integer'],
            'lines.*.stock_peak_index' => ['nullable', 'integer', 'min:1'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:0'],
            'lines.*.destination_location' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $lines = $this->validatedLines();

            if ($lines === []) {
                $validator->errors()->add('lines', 'Debes seleccionar al menos una linea valida con pallets o picos.');
            }

            foreach ($this->resolvedErrors() as $field => $message) {
                $validator->errors()->add($field, $message);
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
     *     destination_location:string|null,
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
        $resolved = $resolver->resolve($this->merchandiseRequestClientId(), $this->input('lines', []), true);

        $submittedLines = collect($this->input('lines', []))->values();
        $this->resolvedLines = collect($resolved['lines'])
            ->map(function (array $line, int $index) use ($submittedLines): array {
                $line['destination_location'] = $submittedLines->get($index)['destination_location'] ?? null;

                return $line;
            })
            ->all();
        $this->resolvedErrors = $resolved['errors'];

        return $this->resolvedLines;
    }

    private function merchandiseRequestClientId(): int
    {
        $merchandiseRequest = $this->route('merchandiseRequest');

        return $merchandiseRequest instanceof MerchandiseRequest
            ? (int) $merchandiseRequest->client_id
            : 0;
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
}
