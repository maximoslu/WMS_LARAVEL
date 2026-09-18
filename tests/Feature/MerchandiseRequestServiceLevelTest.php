<?php

namespace Tests\Feature;

use App\Enums\MerchandiseRequestServiceLevel;
use App\Models\Client;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use App\Notifications\CustomerMerchandiseRequestSubmittedNotification;
use App\Notifications\InternalMerchandiseRequestSubmittedNotification;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

class MerchandiseRequestServiceLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_form_shows_the_two_visible_service_level_choices(): void
    {
        $this->seedBaseData();
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        Item::factory()->create(['client_id' => $client->id]);
        $customer = $this->customerFor($client);

        $this->actingAs($customer)
            ->get(route('merchandise-requests.create'))
            ->assertOk()
            ->assertSee('¿Cuándo necesitas este pedido?')
            ->assertSee('Para hoy')
            ->assertSee('Cauce normal')
            ->assertSee('Entrega dentro de las 24 horas siguientes.')
            ->assertSee('name="service_level"', false)
            ->assertSee('value="same_day"', false)
            ->assertSee('value="standard_24h"', false);
    }

    public function test_customer_can_submit_a_same_day_order_and_its_service_is_visible_in_the_detail(): void
    {
        $this->seedBaseData();
        [$client, $customer, $item] = $this->customerWithAvailableItem();

        $this->actingAs($customer)
            ->post(route('merchandise-requests.store'), $this->submittedPayload($item, MerchandiseRequestServiceLevel::SAME_DAY))
            ->assertRedirect();

        $request = MerchandiseRequest::query()->sole();

        $this->assertSame(MerchandiseRequestServiceLevel::SAME_DAY, $request->service_level);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $request->id,
            'event' => 'merchandise_request_created',
        ]);

        $this->actingAs($customer)
            ->get(route('merchandise-requests.show', $request))
            ->assertOk()
            ->assertSee('Servicio')
            ->assertSee('Para hoy');
    }

    public function test_service_level_is_required_when_sending_but_optional_for_a_draft_and_preserved_on_edit(): void
    {
        $this->seedBaseData();
        [, $customer, $item] = $this->customerWithAvailableItem();

        $this->actingAs($customer)
            ->from(route('merchandise-requests.create'))
            ->post(route('merchandise-requests.store'), $this->submittedPayload($item))
            ->assertRedirect(route('merchandise-requests.create'))
            ->assertSessionHasErrors('service_level');

        $this->assertDatabaseCount('merchandise_requests', 0);

        $this->actingAs($customer)
            ->post(route('merchandise-requests.store'), [
                'submit_action' => 'draft',
                'notes' => 'Pendiente de decidir el plazo.',
            ])
            ->assertRedirect();

        $draft = MerchandiseRequest::query()->sole();
        $this->assertTrue($draft->isDraft());
        $this->assertNull($draft->service_level);

        $this->actingAs($customer)
            ->patch(route('merchandise-requests.draft.update', $draft), $this->submittedPayload($item, MerchandiseRequestServiceLevel::STANDARD_24H, 'draft'))
            ->assertRedirect(route('merchandise-requests.show', $draft));

        $this->assertSame(MerchandiseRequestServiceLevel::STANDARD_24H, $draft->fresh()->service_level);

        $this->actingAs($customer)
            ->get(route('merchandise-requests.draft.edit', $draft))
            ->assertOk()
            ->assertSee('value="standard_24h" checked', false);

        $this->actingAs($customer)
            ->patch(route('merchandise-requests.draft.update', $draft), $this->submittedPayload($item, MerchandiseRequestServiceLevel::STANDARD_24H))
            ->assertRedirect(route('merchandise-requests.show', $draft));

        $this->assertSame(MerchandiseRequest::STATUS_PENDING, $draft->fresh()->status);
        $this->assertSame(MerchandiseRequestServiceLevel::STANDARD_24H, $draft->fresh()->service_level);
    }

    public function test_customer_and_internal_submission_emails_identify_same_day_and_normal_service_in_subject_and_body(): void
    {
        $this->seedBaseData();
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $customer = $this->customerFor($client);
        $warehouseUser = $this->userWithRole(Role::ALMACEN);

        foreach ([MerchandiseRequestServiceLevel::SAME_DAY, MerchandiseRequestServiceLevel::STANDARD_24H] as $serviceLevel) {
            $request = $this->requestWithLine($client, $customer, $serviceLevel);
            $customerMail = (new CustomerMerchandiseRequestSubmittedNotification($request))->toMail($customer);
            $internalMail = (new InternalMerchandiseRequestSubmittedNotification($request))->toMail($warehouseUser);

            $this->assertInstanceOf(MailMessage::class, $customerMail);
            $this->assertInstanceOf(MailMessage::class, $internalMail);
            $this->assertStringContainsString($serviceLevel->emailSubjectLabel(), (string) $customerMail->subject);
            $this->assertStringContainsString($serviceLevel->emailSubjectLabel(), (string) $internalMail->subject);
            $this->assertContains('Servicio solicitado: '.$serviceLevel->label(), $customerMail->introLines);
            $this->assertContains($serviceLevel->description(), $customerMail->introLines);
            $this->assertContains('Servicio solicitado: '.$serviceLevel->label(), $internalMail->introLines);
            $this->assertContains($serviceLevel->description(), $internalMail->introLines);
        }
    }

    public function test_legacy_orders_without_a_service_level_remain_readable(): void
    {
        $this->seedBaseData();
        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $customer = $this->customerFor($client);
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $customer->id,
            'service_level' => null,
        ]);

        $this->assertSame('Plazo de servicio sin indicar', $request->serviceLevelLabel());

        $this->actingAs($customer)
            ->get(route('merchandise-requests.show', $request))
            ->assertOk()
            ->assertSee('Plazo de servicio sin indicar');
    }

    /**
     * @return array{0: Client, 1: User, 2: Item}
     */
    private function customerWithAvailableItem(): array
    {
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $customer = $this->customerFor($client);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 100,
        ]);
        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 100,
            'quantity_units' => 500,
            'full_pallets' => 5,
            'warehouse_pallets' => 5,
            'peak_1' => 0,
        ]);

        return [$client, $customer, $item];
    }

    /**
     * @return array<string, mixed>
     */
    private function submittedPayload(Item $item, ?MerchandiseRequestServiceLevel $serviceLevel = null, string $submitAction = 'submit'): array
    {
        return array_filter([
            'submit_action' => $submitAction,
            'service_level' => $serviceLevel?->value,
            'lines' => [
                'line_1' => [
                    'item_id' => $item->id,
                    'line_type' => 'pallet',
                    'quantity' => 1,
                ],
            ],
        ], fn (mixed $value): bool => $value !== null);
    }

    private function requestWithLine(Client $client, User $customer, MerchandiseRequestServiceLevel $serviceLevel): MerchandiseRequest
    {
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 100,
        ]);
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $customer->id,
            'service_level' => $serviceLevel,
        ]);
        $request->lines()->create([
            'item_id' => $item->id,
            'units_per_pallet' => 100,
            'requested_pallets' => 1,
            'requested_units' => 100,
        ]);

        return $request->fresh(['client', 'requestedBy', 'lines.item']);
    }

    private function customerFor(Client $client): User
    {
        return $this->userWithRole(Role::CLIENTE, $client);
    }

    private function userWithRole(string $roleSlug, ?Client $client = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'client_id' => $client?->id,
        ]);
    }

    private function seedBaseData(): void
    {
        $this->seed([RoleSeeder::class, ClientSeeder::class]);
    }
}
