<?php

namespace Tests\Feature;

use App\Enums\MerchandiseRequestServiceLevel;
use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\DailyOperations\DailyOperationRecalculationService;
use App\Services\DailyOperations\DailyOperationTotalsService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyOperationFfBreakdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_operations_separates_same_day_dispatches_as_ff_without_changing_the_total(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeWarehouseUser();
        $client = Client::factory()->create();
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 100]);

        $ffDispatch = $this->createSentRequestDispatch(
            $client,
            $item,
            $user,
            MerchandiseRequestServiceLevel::SAME_DAY,
            4,
        );
        $this->createSentRequestDispatch(
            $client,
            $item,
            $user,
            MerchandiseRequestServiceLevel::STANDARD_24H,
            3,
        );
        $this->createSentRequestDispatch($client, $item, $user, null, 2);

        $day = app(DailyOperationRecalculationService::class)->rebuildForDateAndClient(
            '2026-09-18',
            $client->id,
            $user->id,
        );
        $breakdown = app(DailyOperationTotalsService::class)->serviceLevelBreakdown($day);

        $this->assertSame(9, $day->moved_pallets_today);
        $this->assertSame([
            'moved_pallets' => 5,
            'truck_management' => 2,
            'truck_trips' => 2,
        ], $breakdown['normal']);
        $this->assertSame([
            'moved_pallets' => 4,
            'truck_management' => 1,
            'truck_trips' => 1,
        ], $breakdown['ff']);
        $this->assertSame([$ffDispatch->id], $breakdown['ff_dispatch_ids']);

        $this->actingAs($user)
            ->get(route('daily-operations.index', ['date' => '2026-09-18', 'client_id' => $client->id]))
            ->assertOk()
            ->assertSee('PALLETS MOVIDOS DEL DIA · CAUCE NORMAL')
            ->assertSee('SERVICIOS FF · PARA HOY')
            ->assertSee('PALETS MOVIDOS FF')
            ->assertSee('GESTIONES DE CAMION FF')
            ->assertSee('VIAJES FF')
            ->assertSee('FF · Para hoy')
            ->assertSee('Cauce normal');
    }

    private function makeWarehouseUser(): User
    {
        $role = Role::query()->where('slug', Role::ALMACEN)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function createSentRequestDispatch(
        Client $client,
        Item $item,
        User $user,
        ?MerchandiseRequestServiceLevel $serviceLevel,
        int $pallets,
    ): GoodsDispatch {
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $user->id,
            'service_level' => $serviceLevel,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $request->id,
            'type' => GoodsDispatch::TYPE_REQUEST,
            'status' => GoodsDispatch::STATUS_SENT,
            'sent_at' => '2026-09-18 10:00:00',
            'camion_propio' => true,
            'created_by' => $user->id,
        ]);

        GoodsDispatchLine::query()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'sku' => 'SKU-FF-'.$dispatch->id,
            'description' => 'Salida para prueba de servicio',
            'units_per_pallet' => 100,
            'pallets' => $pallets,
            'requested_units' => $pallets * 100,
            'requested_pallets' => $pallets,
            'loaded_pallets' => $pallets,
            'is_extra_line' => false,
        ]);

        return $dispatch;
    }
}
