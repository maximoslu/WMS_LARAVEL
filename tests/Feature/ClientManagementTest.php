<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientDispatchEmailRecipient;
use App\Models\ClientReceiptEmailRecipient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_address_can_be_saved_for_client(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($administracion)
            ->put(route('clients.update', $client), [
                'name' => $client->name,
                'code' => $client->code,
                'delivery_address' => 'Calle Mayor 1',
                'delivery_postal_code' => '28001',
                'delivery_city' => 'Madrid',
                'delivery_province' => 'Madrid',
                'delivery_country' => 'Espana',
                'active' => 1,
            ])
            ->assertRedirect(route('clients.index'));

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'delivery_address' => 'Calle Mayor 1',
            'delivery_postal_code' => '28001',
            'delivery_city' => 'Madrid',
            'delivery_province' => 'Madrid',
            'delivery_country' => 'Espana',
        ]);
    }

    public function test_storage_occupancy_visibility_defaults_true_for_new_clients(): void
    {
        $client = Client::factory()->create([
            'name' => 'Cliente Ocupacion',
            'code' => 'OCUPACION',
        ]);

        $this->assertTrue($client->fresh()->show_storage_occupancy_to_client);
    }

    public function test_friesland_seed_keeps_storage_occupancy_visibility_disabled(): void
    {
        $this->seedBaseData();

        $friesland = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $edelvives = Client::query()->where('code', 'EDELVIVES')->firstOrFail();

        $this->assertFalse($friesland->show_storage_occupancy_to_client);
        $this->assertTrue($edelvives->show_storage_occupancy_to_client);
    }

    public function test_client_form_allows_enabling_storage_occupancy_visibility(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($administracion)
            ->put(route('clients.update', $client), [
                'name' => $client->name,
                'code' => $client->code,
                'delivery_address' => $client->delivery_address,
                'delivery_postal_code' => $client->delivery_postal_code,
                'delivery_city' => $client->delivery_city,
                'delivery_province' => $client->delivery_province,
                'delivery_country' => $client->delivery_country,
                'active' => 1,
                'show_storage_occupancy_to_client' => 1,
            ])
            ->assertRedirect(route('clients.index'));

        $this->assertTrue($client->fresh()->show_storage_occupancy_to_client);
    }

    public function test_client_form_saves_disabled_storage_occupancy_when_checkbox_is_not_sent(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($administracion)
            ->put(route('clients.update', $client), [
                'name' => $client->name,
                'code' => $client->code,
                'delivery_address' => $client->delivery_address,
                'delivery_postal_code' => $client->delivery_postal_code,
                'delivery_city' => $client->delivery_city,
                'delivery_province' => $client->delivery_province,
                'delivery_country' => $client->delivery_country,
                'active' => 1,
            ])
            ->assertRedirect(route('clients.index'));

        $this->assertFalse($client->fresh()->show_storage_occupancy_to_client);
    }

    public function test_client_form_renders_storage_occupancy_checkbox(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($administracion)
            ->get(route('clients.edit', $client))
            ->assertOk()
            ->assertSeeText('Mostrar ocupacion de almacen al cliente')
            ->assertSeeText('Permite que los usuarios de este cliente vean el total de huecos utilizados en el almacen.')
            ->assertSee('name="show_storage_occupancy_to_client"', false);
    }

    public function test_se_puede_anadir_email_adicional_valido_a_cliente(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($administracion)
            ->post(route('clients.receipt-emails.store', $client), [
                'email' => 'ADMIN@EDELVIVES.COM',
                'name' => 'Administracion Edelvives',
            ])
            ->assertRedirect(route('clients.edit', $client));

        $this->assertDatabaseHas('client_receipt_email_recipients', [
            'client_id' => $client->id,
            'email' => 'admin@edelvives.com',
            'name' => 'Administracion Edelvives',
        ]);
    }

    public function test_no_se_puede_anadir_email_invalido(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($administracion)
            ->post(route('clients.receipt-emails.store', $client), [
                'email' => 'no-es-un-email',
            ])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('client_receipt_email_recipients', 0);
    }

    public function test_no_se_duplica_email_adicional(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);

        ClientReceiptEmailRecipient::factory()->create([
            'client_id' => $client->id,
            'email' => 'repetido@edelvives.com',
        ]);

        $this->actingAs($administracion)
            ->post(route('clients.receipt-emails.store', $client), [
                'email' => 'repetido@edelvives.com',
            ])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('client_receipt_email_recipients', 1);
    }

    public function test_se_puede_eliminar_email_adicional(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);

        $recipient = ClientReceiptEmailRecipient::factory()->create([
            'client_id' => $client->id,
        ]);

        $this->actingAs($administracion)
            ->delete(route('clients.receipt-emails.destroy', [$client, $recipient]))
            ->assertRedirect(route('clients.edit', $client));

        $this->assertDatabaseMissing('client_receipt_email_recipients', ['id' => $recipient->id]);
    }

    public function test_se_puede_gestionar_emails_de_albaranes_de_salida(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($administracion)
            ->get(route('clients.edit', $client))
            ->assertOk()
            ->assertSee('Emails para albaranes de salida')
            ->assertSee('carretillero@cliente.com');

        $this->actingAs($administracion)
            ->post(route('clients.dispatch-emails.store', $client), [
                'email' => 'CARRETILLERO@EDELVIVES.COM',
                'name' => 'Carretillero Edelvives',
            ])
            ->assertRedirect(route('clients.edit', $client));

        $this->assertDatabaseHas('client_dispatch_email_recipients', [
            'client_id' => $client->id,
            'email' => 'carretillero@edelvives.com',
            'name' => 'Carretillero Edelvives',
        ]);

        $recipient = ClientDispatchEmailRecipient::query()->where('client_id', $client->id)->firstOrFail();

        $this->actingAs($administracion)
            ->delete(route('clients.dispatch-emails.destroy', [$client, $recipient]))
            ->assertRedirect(route('clients.edit', $client));

        $this->assertDatabaseMissing('client_dispatch_email_recipients', ['id' => $recipient->id]);
    }

    public function test_roles_sin_permiso_no_pueden_gestionar_emails_de_albaranes(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $recipient = ClientReceiptEmailRecipient::factory()->create(['client_id' => $client->id]);
        $dispatchRecipient = ClientDispatchEmailRecipient::factory()->create(['client_id' => $client->id]);

        foreach ([Role::ALMACEN, Role::CLIENTE] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug);

            $this->actingAs($user)
                ->post(route('clients.receipt-emails.store', $client), ['email' => 'nuevo@edelvives.com'])
                ->assertForbidden();

            $this->actingAs($user)
                ->delete(route('clients.receipt-emails.destroy', [$client, $recipient]))
                ->assertForbidden();

            $this->actingAs($user)
                ->post(route('clients.dispatch-emails.store', $client), ['email' => 'salidas@edelvives.com'])
                ->assertForbidden();

            $this->actingAs($user)
                ->delete(route('clients.dispatch-emails.destroy', [$client, $dispatchRecipient]))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('client_receipt_email_recipients', ['id' => $recipient->id]);
        $this->assertDatabaseHas('client_dispatch_email_recipients', ['id' => $dispatchRecipient->id]);
        $this->assertDatabaseMissing('client_receipt_email_recipients', ['email' => 'nuevo@edelvives.com']);
        $this->assertDatabaseMissing('client_dispatch_email_recipients', ['email' => 'salidas@edelvives.com']);
    }

    private function seedBaseData(): void
    {
        $this->seed([
            RoleSeeder::class,
            ClientSeeder::class,
        ]);
    }

    private function makeUserWithRole(string $roleSlug): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
        ]);
    }
}
