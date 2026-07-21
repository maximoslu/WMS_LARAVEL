<?php

namespace App\Services\Locations;

use App\Models\Location;
use App\Support\Locations\LocationCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LocationCatalogService
{
    /** @return array<string, string> */
    public function typeOptions(): array
    {
        return [
            'calle' => 'Calle',
            'pasillo' => 'Pasillo',
            'estanteria' => 'Estanteria',
            'muelle' => 'Muelle',
            'zona' => 'Zona',
            'libre' => 'Libre',
        ];
    }

    /** @return array{warehouse_id: int, code: string, name: ?string, zone: ?string, aisle: ?string, rack: ?string, level: ?string, position: ?string, active: bool} */
    public function payload(
        int $warehouseId,
        string $type,
        mixed $code,
        ?string $name = null,
        ?string $zone = null,
        ?string $aisle = null,
        ?string $rack = null,
        ?string $level = null,
        ?string $position = null,
        bool $active = true,
    ): array {
        $normalizedType = $this->normalizeType($type);
        $normalizedCode = $this->normalizeCode($normalizedType, $code);

        return [
            'warehouse_id' => $warehouseId,
            'code' => $normalizedCode,
            'name' => $this->normalizeNullableText($name) ?? $this->defaultName($normalizedType, $normalizedCode),
            'zone' => $this->normalizeNullableText($zone),
            'aisle' => $this->normalizeNullableText($aisle),
            'rack' => $this->normalizeNullableText($rack),
            'level' => $this->normalizeNullableText($level),
            'position' => $this->normalizeNullableText($position),
            'active' => $active,
        ];
    }

    /** @return array{created: int, existing: int, errors: int, total: int} */
    public function createRange(int $warehouseId, string $type, int $from, int $to, bool $apply): array
    {
        $created = 0;
        $existing = 0;
        $errors = 0;
        $total = $to - $from + 1;

        DB::transaction(function () use ($warehouseId, $type, $from, $to, $apply, &$created, &$existing, &$errors): void {
            for ($value = $from; $value <= $to; $value++) {
                $payload = $this->payload($warehouseId, $type, (string) $value, active: true);
                $exists = Location::query()
                    ->where('warehouse_id', $warehouseId)
                    ->where('code', $payload['code'])
                    ->exists();

                if ($exists) {
                    $existing++;

                    continue;
                }

                if (! $apply) {
                    $created++;

                    continue;
                }

                try {
                    Location::query()->create($payload);
                    $created++;
                } catch (\Throwable) {
                    $errors++;
                }
            }
        });

        return [
            'created' => $created,
            'existing' => $existing,
            'errors' => $errors,
            'total' => $total,
        ];
    }

    public function normalizeType(?string $type): string
    {
        $normalized = str_replace(' ', '', Str::ascii(mb_strtolower(trim((string) $type))));

        return array_key_exists($normalized, $this->typeOptions()) ? $normalized : 'libre';
    }

    public function normalizeCode(string $type, mixed $code): string
    {
        $type = $this->normalizeType($type);
        $value = trim((string) $code);

        if ($type === 'calle') {
            return LocationCode::normalize($value);
        }

        $normalized = LocationCode::normalize($value);

        if ($type === 'libre') {
            return $normalized;
        }

        $prefix = mb_strtoupper($this->typeOptions()[$type]);

        return str_starts_with($normalized, $prefix.' ')
            ? $normalized
            : LocationCode::normalize($prefix.' '.$normalized);
    }

    public function defaultName(string $type, string $code): string
    {
        $type = $this->normalizeType($type);

        if ($type === 'libre') {
            return $code;
        }

        return $this->typeOptions()[$type].' '.($type === 'calle' ? LocationCode::normalize($code) : $this->removePrefix($type, $code));
    }

    private function removePrefix(string $type, string $code): string
    {
        $prefix = mb_strtoupper($this->typeOptions()[$this->normalizeType($type)]);

        return trim(preg_replace('/^'.preg_quote($prefix, '/').'\s+/u', '', $code) ?? $code);
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
