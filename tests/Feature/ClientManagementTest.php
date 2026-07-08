<?php

namespace Tests\Feature;

use App\Models\Client;
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

    public function test_roles_sin_permiso_no_pueden_gestionar_emails_de_albaranes(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $recipient = ClientReceiptEmailRecipient::factory()->create(['client_id' => $client->id]);

        foreach ([Role::ALMACEN, Role::CLIENTE] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug);

            $this->actingAs($user)
                ->post(route('clients.receipt-emails.store', $client), ['email' => 'nuevo@edelvives.com'])
                ->assertForbidden();

            $this->actingAs($user)
                ->delete(route('clients.receipt-emails.destroy', [$client, $recipient]))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('client_receipt_email_recipients', ['id' => $recipient->id]);
        $this->assertDatabaseMissing('client_receipt_email_recipients', ['email' => 'nuevo@edelvives.com']);
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
