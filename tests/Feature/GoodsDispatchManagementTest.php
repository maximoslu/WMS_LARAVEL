<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoodsDispatchManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_cliente_cannot_access_goods_dispatch_module(): void
    {
        $this->seedBaseData();
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->get(route('dispatches.index'))
            ->assertForbidden();
    }

    public function test_internal_roles_can_access_goods_dispatch_module(): void
    {
        $this->seedBaseData();

        foreach ([Role::ALMACEN, Role::ADMINISTRACION, Role::SUPERADMIN] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug);

            $this->actingAs($user)
                ->get(route('dispatches.index'))
                ->assertOk()
                ->assertSee('Salida de mercancia');
        }
    }

    public function test_manual_dispatch_requires_client_and_lines(): void
    {
        $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($almacen)
            ->from(route('dispatches.create'))
            ->post(route('dispatches.store'), [
                'client_id' => null,
                'quantities' => [],
            ])
            ->assertRedirect(route('dispatches.create'))
            ->assertSessionHasErrors(['client_id', 'quantities']);
    }

    public function test_valid_manual_dispatch_is_saved_with_lines(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA000X',
            'units_per_pallet' => 700,
        ]);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($almacen)
            ->post(route('dispatches.store'), [
                'client_id' => $client->id,
                'notes' => 'Salida urgente',
                'quantities' => [
                    $item->id => 4,
                ],
            ])
            ->assertRedirect();

        $dispatch = GoodsDispatch::query()->firstOrFail();

        $this->assertSame(GoodsDispatch::STATUS_DRAFT, $dispatch->status);
        $this->assertSame(GoodsDispatch::TYPE_MANUAL, $dispatch->type);
        $this->assertDatabaseHas('goods_dispatch_lines', [
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'sku' => 'CAJA000X',
            'pallets' => 4,
            'requested_units' => 2800,
        ]);
    }

    public function test_dispatch_from_request_copies_lines_and_prevents_duplicates(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 500,
        ]);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
        ]);
        $merchandiseRequest->lines()->create([
            'item_id' => $item->id,
            'lot' => $item->lot,
            'units_per_pallet' => 500,
            'requested_pallets' => 3,
            'requested_units' => 1500,
        ]);

        $this->actingAs($almacen)
            ->post(route('dispatches.requests.generate', $merchandiseRequest))
            ->assertRedirect();

        $dispatch = GoodsDispatch::query()->firstOrFail();

        $this->assertSame($merchandiseRequest->id, $dispatch->merchandise_request_id);
        $this->assertDatabaseHas('goods_dispatch_lines', [
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'pallets' => 3,
        ]);
        $this->assertDatabaseHas('merchandise_requests', [
            'id' => $merchandiseRequest->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);

        $this->actingAs($almacen)
            ->post(route('dispatches.requests.generate', $merchandiseRequest))
            ->assertRedirect(route('dispatches.show', $dispatch));

        $this->assertSame(1, GoodsDispatch::query()->count());
    }

    public function test_changing_dispatch_status_to_sent_sets_sent_at(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
            'sent_at' => null,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), [
                'status' => GoodsDispatch::STATUS_SENT,
            ])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $this->assertNotNull($dispatch->fresh()->sent_at);
    }

    public function test_delivery_note_pdf_responds_correctly_when_dispatch_is_sent(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $client->update([
            'delivery_address' => 'Calle Mayor 1',
            'delivery_postal_code' => '28001',
            'delivery_city' => 'Madrid',
            'delivery_province' => 'Madrid',
            'delivery_country' => 'Espana',
        ]);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->actingAs($almacen)
            ->get(route('dispatches.delivery-note', $dispatch))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_preparation_pdf_responds_correctly(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
        ]);

        $this->actingAs($almacen)
            ->get(route('merchandise-requests.preparation-pdf', $merchandiseRequest))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    private function seedBaseData(): void
    {
        $this->seed([
            RoleSeeder::class,
            ClientSeeder::class,
        ]);
    }

    private function makeUserWithRole(string $roleSlug, ?Client $client = null): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
            'client_id' => $client?->id,
        ]);
    }
}
