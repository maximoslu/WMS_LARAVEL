<?php

namespace Tests\Feature;

use App\Jobs\ProcessMerchandiseRequestSubmittedNotificationsJob;
use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\MerchandiseRequestLine;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MerchandiseRequestForecastTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_roles_can_view_forecast_and_draft_detail(): void
    {
        $this->seedBaseData();
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $requester = $this->makeUserWithRole(Role::CLIENTE, $client);
        $item = Item::factory()->create(['client_id' => $client->id, 'sku' => 'FORECAST-001']);
        $draft = $this->makeDraft($client, $requester, $item, notes: 'Preparar transporte futuro.');

        foreach ([Role::ADMINISTRACION, Role::ALMACEN, Role::SUPERADMIN] as $roleSlug) {
            $internal = $this->makeUserWithRole($roleSlug);

            $this->actingAs($internal)
                ->get(route('merchandise-requests.forecast.index'))
                ->assertOk()
                ->assertSee('PREVISIÓN DE PEDIDOS')
                ->assertSee($draft->referenceCode())
                ->assertSee('Borrador del cliente')
                ->assertSee('No se muestran viajes orientativos');

            $this->actingAs($internal)
                ->get(route('merchandise-requests.forecast.show', $draft))
                ->assertOk()
                ->assertSee('Información provisional')
                ->assertSee('Preparar transporte futuro.')
                ->assertSee('Las cantidades y referencias pueden cambiar hasta su envío definitivo.')
                ->assertDontSee('Guardar estado')
                ->assertDontSee('Empezar carga')
                ->assertDontSee('Imprimir preparación');
        }
    }

    public function test_cliente_cannot_access_global_forecast_or_foreign_draft(): void
    {
        $this->seedBaseData();
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $otherClient = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $otherRequester = $this->makeUserWithRole(Role::CLIENTE, $otherClient);
        $item = Item::factory()->create(['client_id' => $otherClient->id]);
        $draft = $this->makeDraft($otherClient, $otherRequester, $item);

        $this->actingAs($cliente)
            ->get(route('merchandise-requests.forecast.index'))
            ->assertForbidden();

        $this->actingAs($cliente)
            ->get(route('merchandise-requests.forecast.show', $draft))
            ->assertForbidden();
    }

    public function test_forecast_contains_only_drafts_and_supports_filters_and_sorting(): void
    {
        $this->seedBaseData();
        $friesland = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $edelvives = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $frieslandUser = $this->makeUserWithRole(Role::CLIENTE, $friesland);
        $edelvivesUser = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $frieslandItem = Item::factory()->create(['client_id' => $friesland->id, 'sku' => 'FORECAST-FRIES']);
        $edelvivesItem = Item::factory()->create(['client_id' => $edelvives->id, 'sku' => 'FORECAST-EDEL']);
        $oldDraft = $this->makeDraft($friesland, $frieslandUser, $frieslandItem, notes: 'Con comentario', fillTruck: true);
        $oldDraft->forceFill(['created_at' => Carbon::parse('2026-08-01 08:00'), 'updated_at' => Carbon::parse('2026-08-01 08:00')])->save();
        $newDraft = $this->makeDraft($edelvives, $edelvivesUser, $edelvivesItem);
        $newDraft->forceFill(['created_at' => Carbon::parse('2026-08-04 08:00'), 'updated_at' => Carbon::parse('2026-08-04 08:00')])->save();
        $pending = MerchandiseRequest::factory()->create(['client_id' => $friesland->id, 'requested_by' => $frieslandUser->id, 'status' => MerchandiseRequest::STATUS_PENDING]);
        $sent = MerchandiseRequest::factory()->create(['client_id' => $friesland->id, 'requested_by' => $frieslandUser->id, 'status' => MerchandiseRequest::STATUS_SENT]);
        $internal = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($internal)
            ->get(route('merchandise-requests.forecast.index'))
            ->assertOk()
            ->assertSeeInOrder([$newDraft->referenceCode(), $oldDraft->referenceCode()])
            ->assertSee($oldDraft->client->name)
            ->assertDontSee($pending->referenceCode())
            ->assertDontSee($sent->referenceCode());

        $this->actingAs($internal)
            ->get(route('merchandise-requests.forecast.index', ['client_id' => $friesland->id, 'has_notes' => '1', 'has_fill_truck' => '1']))
            ->assertOk()
            ->assertSee($oldDraft->referenceCode())
            ->assertDontSee($newDraft->referenceCode());

        $this->actingAs($internal)
            ->get(route('merchandise-requests.forecast.index', ['date_from' => '2026-08-04', 'sort' => 'created']))
            ->assertOk()
            ->assertSee($newDraft->referenceCode())
            ->assertDontSee($oldDraft->referenceCode());
    }

    public function test_detail_and_summary_show_provisional_totals_and_fill_truck_line(): void
    {
        $this->seedBaseData();
        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $requester = $this->makeUserWithRole(Role::CLIENTE, $client);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'FORECAST-DETAIL',
            'description' => 'Referencia de previsión',
            'units_per_pallet' => 100,
        ]);
        $draft = $this->makeDraft($client, $requester, $item, notes: 'Necesidad estimada de transporte.');
        MerchandiseRequestLine::factory()->create([
            'merchandise_request_id' => $draft->id,
            'item_id' => $item->id,
            'line_type' => 'peak',
            'requested_pallets' => 0,
            'requested_peaks' => 2,
            'requested_units' => 30,
            'units_per_pallet' => 100,
            'units_per_peak' => 15,
            'fill_truck' => true,
        ]);
        $internal = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($internal)
            ->get(route('merchandise-requests.forecast.show', $draft))
            ->assertOk()
            ->assertSee('Referencia de previsión')
            ->assertSee('Necesidad estimada de transporte.')
            ->assertSee('PARA RELLENAR CAMIÓN')
            ->assertSee('Palés provisionales')
            ->assertSee('2')
            ->assertSee('230')
            ->assertSee('Borrador del cliente');
    }

    public function test_submitted_draft_disappears_from_forecast(): void
    {
        Bus::fake();
        $this->seedBaseData();
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 50]);
        $draft = $this->makeDraft($client, $cliente, $item);
        $internal = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($internal)
            ->get(route('merchandise-requests.forecast.index'))
            ->assertOk()
            ->assertSee($draft->referenceCode());

        $this->actingAs($cliente)
            ->patch(route('merchandise-requests.draft.update', $draft), [
                'submit_action' => 'submit',
                'notes' => 'Pedido definitivo',
                'lines' => [
                    'line_1' => [
                        'item_id' => $item->id,
                        'line_type' => 'pallet',
                        'quantity' => 2,
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(MerchandiseRequest::STATUS_PENDING, $draft->fresh()->status);
        Bus::assertDispatchedAfterResponse(ProcessMerchandiseRequestSubmittedNotificationsJob::class);

        $this->actingAs($internal)
            ->get(route('merchandise-requests.forecast.index'))
            ->assertOk()
            ->assertDontSee($draft->referenceCode());
    }

    public function test_internal_roles_cannot_mutate_or_operationalize_draft(): void
    {
        $this->seedBaseData();
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $requester = $this->makeUserWithRole(Role::CLIENTE, $client);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 20]);
        $draft = $this->makeDraft($client, $requester, $item, notes: 'No modificar');
        $before = $draft->fresh();
        $beforeLineCount = $draft->lines()->count();

        foreach ([Role::ADMINISTRACION, Role::ALMACEN, Role::SUPERADMIN] as $roleSlug) {
            $internal = $this->makeUserWithRole($roleSlug);

            $this->actingAs($internal)
                ->patch(route('merchandise-requests.draft.update', $draft), [
                    'client_id' => $client->id,
                    'submit_action' => 'submit',
                    'notes' => 'Intento de cambio',
                    'lines' => [
                        'line_1' => [
                            'item_id' => $item->id,
                            'line_type' => 'pallet',
                            'quantity' => 1,
                        ],
                    ],
                ])
                ->assertForbidden();

            $this->actingAs($internal)
                ->post(route('merchandise-requests.lines.store', $draft), [
                    'lines' => ['line_1' => ['item_id' => $item->id, 'line_type' => 'pallet', 'quantity' => 1]],
                ])
                ->assertRedirect(route('merchandise-requests.show', $draft))
                ->assertSessionHasErrors('lines');

            $this->actingAs($internal)
                ->patch(route('merchandise-requests.update-status', $draft), ['status' => MerchandiseRequest::STATUS_PENDING])
                ->assertRedirect(route('merchandise-requests.show', $draft))
                ->assertSessionHasErrors('status');

            $this->actingAs($internal)
                ->post(route('dispatches.requests.generate', $draft))
                ->assertRedirect(route('dispatches.requests.show', $draft))
                ->assertSessionHasErrors('dispatch');

            $this->actingAs($internal)
                ->get(route('merchandise-requests.preparation-pdf', $draft))
                ->assertNotFound();

            $this->actingAs($internal)
                ->get(route('dispatches.requests.show', $draft))
                ->assertNotFound();
        }

        $after = $draft->fresh();
        $this->assertSame(MerchandiseRequest::STATUS_DRAFT, $after->status);
        $this->assertSame($before->notes, $after->notes);
        $this->assertSame($beforeLineCount, $draft->lines()->count());
        $this->assertSame(0, GoodsDispatch::query()->count());
    }

    public function test_viewing_forecast_has_no_operational_side_effects_and_avoids_n_plus_one(): void
    {
        $this->seedBaseData();
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $requester = $this->makeUserWithRole(Role::CLIENTE, $client);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 10]);
        $drafts = collect(range(1, 25))->map(fn (int $number): MerchandiseRequest => $this->makeDraft($client, $requester, $item, fillTruck: $number === 1));
        $target = $drafts->last();
        $internal = $this->makeUserWithRole(Role::ALMACEN);
        $auditCount = DB::table('audit_logs')->count();
        $dispatchCount = GoodsDispatch::query()->count();
        $targetUpdatedAt = $target->fresh()->updated_at;
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($internal)
            ->get(route('merchandise-requests.forecast.index'))
            ->assertOk()
            ->assertSee($target->referenceCode());

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(20, $queryCount, 'El listado de previsión no debe generar consultas N+1.');
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSame($dispatchCount, GoodsDispatch::query()->count());
        $this->assertTrue($targetUpdatedAt->equalTo($target->fresh()->updated_at));
    }

    private function seedBaseData(): void
    {
        $this->seed([RoleSeeder::class, ClientSeeder::class]);
    }

    private function makeUserWithRole(string $roleSlug, ?Client $client = null): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
            'client_id' => $client?->id,
        ]);
    }

    private function makeDraft(Client $client, User $requester, Item $item, ?string $notes = null, bool $fillTruck = false): MerchandiseRequest
    {
        $draft = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $requester->id,
            'status' => MerchandiseRequest::STATUS_DRAFT,
            'notes' => $notes,
        ]);

        MerchandiseRequestLine::factory()->create([
            'merchandise_request_id' => $draft->id,
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'requested_pallets' => 2,
            'requested_peaks' => 0,
            'requested_units' => 2 * (int) $item->units_per_pallet,
            'units_per_pallet' => (int) $item->units_per_pallet,
            'fill_truck' => $fillTruck,
        ]);

        return $draft->fresh();
    }
}
