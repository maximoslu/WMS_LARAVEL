<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\Supplier;
use App\Support\Stock\StockVariantCatalog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AjaxSearchController extends Controller
{
    public function stockVariants(Request $request, StockVariantCatalog $variantCatalog): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'active_only' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        $user = $request->user();
        $clientId = $this->resolveClientId($user, $validated['client_id'] ?? null);
        $limit = (int) ($validated['limit'] ?? 10);
        $activeOnly = $user->hasRole(Role::CLIENTE)
            ? true
            : filter_var($validated['active_only'] ?? false, FILTER_VALIDATE_BOOL);

        return response()->json([
            'data' => $variantCatalog->search($query, $clientId, $limit, $activeOnly),
        ]);
    }

    public function items(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'status' => ['nullable', Rule::in(Item::statuses())],
            'active_only' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        $user = $request->user();
        $clientId = $this->resolveClientId($user, $validated['client_id'] ?? null);
        $limit = (int) ($validated['limit'] ?? 10);
        $activeOnly = $user->hasRole(Role::CLIENTE)
            ? true
            : filter_var($validated['active_only'] ?? false, FILTER_VALIDATE_BOOL);

        $items = Item::query()
            ->with('client')
            ->when($clientId !== null, fn (Builder $builder) => $builder->where('client_id', $clientId))
            ->when($activeOnly, fn (Builder $builder) => $builder->where('active', true))
            ->when(
                isset($validated['status']) && $validated['status'] !== null,
                fn (Builder $builder) => $builder->where('status', $validated['status'])
            )
            ->where(function (Builder $builder) use ($query): void {
                $builder
                    ->where('sku', 'like', '%'.$query.'%')
                    ->orWhere('description', 'like', '%'.$query.'%');
            })
            ->orderBy('sku')
            ->limit($limit)
            ->get()
            ->map(fn (Item $item): array => [
                'id' => $item->id,
                'label' => $item->sku.' - '.$item->description,
                'value' => $item->sku,
                'meta' => trim(($item->client?->name ? $item->client->name.' · ' : '').number_format($item->units_per_pallet, 0, ',', '.').' uds/pallet'),
                'sku' => $item->sku,
                'description' => $item->description,
                'client_id' => $item->client_id,
                'units_per_pallet' => $item->units_per_pallet,
                'default_location_id' => $item->default_location_id,
                'status' => $item->status,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $items]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAccessRole(Role::ALMACEN), 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        $clientId = isset($validated['client_id']) ? (int) $validated['client_id'] : null;

        $suppliers = Supplier::query()
            ->where('active', true)
            ->when($clientId !== null, function (Builder $builder) use ($clientId): void {
                $builder->where(function (Builder $builder) use ($clientId): void {
                    $builder
                        ->whereNull('client_id')
                        ->orWhere('client_id', $clientId);
                });
            })
            ->where('name', 'like', '%'.$query.'%')
            ->orderBy('name')
            ->limit((int) ($validated['limit'] ?? 10))
            ->get()
            ->map(fn (Supplier $supplier): array => [
                'id' => $supplier->id,
                'label' => $supplier->name,
                'value' => $supplier->name,
                'meta' => $supplier->client_id === null ? 'Proveedor global' : null,
                'name' => $supplier->name,
                'client_id' => $supplier->client_id,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $suppliers]);
    }

    public function clients(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAccessRole(Role::ALMACEN), 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        $clients = Client::query()
            ->where('active', true)
            ->where(function (Builder $builder) use ($query): void {
                $builder
                    ->where('name', 'like', '%'.$query.'%')
                    ->orWhere('code', 'like', '%'.$query.'%');
            })
            ->orderBy('name')
            ->limit((int) ($validated['limit'] ?? 10))
            ->get()
            ->map(fn (Client $client): array => [
                'id' => $client->id,
                'label' => $client->name,
                'value' => $client->name,
                'meta' => $client->code,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $clients]);
    }

    public function locations(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAccessRole(Role::ALMACEN), 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        $locations = Location::query()
            ->with('warehouse')
            ->where('active', true)
            ->where('code', 'like', '%'.$query.'%')
            ->orderBy('code')
            ->limit((int) ($validated['limit'] ?? 10))
            ->get()
            ->map(fn (Location $location): array => [
                'id' => $location->id,
                'label' => $location->code,
                'value' => $location->code,
                'meta' => $location->warehouse?->code ?: 'Sin almacen',
            ])
            ->values()
            ->all();

        return response()->json(['data' => $locations]);
    }

    public function lots(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAccessRole(Role::ALMACEN), 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'stock_status' => ['nullable', Rule::in(StockPallet::statuses())],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        $lots = StockPallet::query()
            ->with('item')
            ->where('active', true)
            ->whereNotNull('lot')
            ->where('lot', '<>', '')
            ->when(isset($validated['client_id']) && $validated['client_id'] !== null, fn (Builder $builder) => $builder->where('client_id', $validated['client_id']))
            ->when(isset($validated['item_id']) && $validated['item_id'] !== null, fn (Builder $builder) => $builder->where('item_id', $validated['item_id']))
            ->when(
                isset($validated['stock_status']) && $validated['stock_status'] !== null,
                fn (Builder $builder) => $builder->where('status', $validated['stock_status'])
            )
            ->where('lot', 'like', '%'.$query.'%')
            ->orderByDesc('received_at')
            ->orderBy('lot')
            ->limit(40)
            ->get()
            ->unique(fn (StockPallet $batch) => implode('|', [$batch->lot, $batch->item_id, $batch->client_id]))
            ->take((int) ($validated['limit'] ?? 10))
            ->map(fn (StockPallet $batch): array => [
                'id' => $batch->id,
                'label' => (string) $batch->lot,
                'value' => (string) $batch->lot,
                'meta' => trim(($batch->item?->sku ? $batch->item->sku.' · ' : '').(optional($batch->received_at)->format('d/m/Y') ?: 'Sin fecha')),
                'item_id' => $batch->item_id,
                'client_id' => $batch->client_id,
                'status' => $batch->status,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $lots]);
    }

    private function resolveClientId(User $user, mixed $requestedClientId): ?int
    {
        if ($user->hasRole(Role::CLIENTE)) {
            return $user->client_id !== null ? (int) $user->client_id : null;
        }

        return isset($requestedClientId) && (int) $requestedClientId > 0
            ? (int) $requestedClientId
            : null;
    }
}
