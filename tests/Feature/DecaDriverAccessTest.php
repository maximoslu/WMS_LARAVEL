<?php

namespace Tests\Feature;

use App\Models\DecaDocument;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DecaDriverAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $owner = User::factory()->create(['role_id' => Role::where('slug', Role::ADMINISTRACION)->firstOrFail()->id]);
        DB::table('deca_driver_access')->insert(['id' => 1, 'owner_id' => $owner->id, 'version' => (string) Str::uuid(), 'password_hash' => Hash::make('0123')]);
        Storage::fake('local');
        config(['deca.public_base_url' => 'https://wms.example.test', 'deca.carriers.monge.tax_id' => '00000000T']);
    }

    public function test_driver_access_is_separate_from_wms_and_issues_only_quick_documents(): void
    {
        $this->get(route('driver.quick'))->assertRedirect(route('driver.login'));
        $this->post(route('driver.authenticate'), ['password' => '9999'])->assertSessionHasErrors('password');
        $this->post(route('driver.authenticate'), ['password' => '0123'])->assertRedirect(route('driver.quick'));
        $this->assertGuest();
        foreach (['dashboard', 'deca.index', 'deca.documents', 'deca.create', 'deca.driver.settings'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        $this->get(route('driver.quick'))->assertOk()->assertDontSee(route('dashboard'), false)->assertDontSee('Crear DECA manual');
        $this->get(route('driver.create', 'edelvives-supply-chain'))->assertOk()->assertSee('1933MYN');
        $data = ['submission_key' => (string) Str::uuid(), 'carrier_key' => 'monge', 'transport_date' => now('Europe/Madrid')->toDateString(), 'tractor_plate' => '3100KGC', 'articulated' => 0, 'special_authorization' => 0, 'confirmed' => 1];
        $this->post(route('driver.store', 'edelvives-supply-chain'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $document = DecaDocument::sole();
        $this->assertSame('shared_driver_portal', $document->snapshot['access_channel']);
        $this->get(route('driver.show', $document))->assertOk()->assertSee('data:image/svg+xml;base64,', false)->assertDontSee('Ver documentos');
        $this->post(route('driver.store', 'edelvives-supply-chain'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('deca_documents', 1);
        $this->get($document->public_url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post(route('driver.logout'))->assertRedirect(route('driver.login'));
        $this->get(route('driver.show', $document))->assertRedirect(route('driver.login'));
        $this->post(route('driver.authenticate'), ['password' => '0123']);
        $this->get(route('driver.show', $document))->assertNotFound();
    }

    public function test_password_rotation_expiry_and_throttling(): void
    {
        $this->post(route('driver.authenticate'), ['password' => '0123']);
        $this->withSession(['deca_driver.expires' => time() - 1])->get(route('driver.quick'))->assertRedirect(route('driver.login'));
        $this->post(route('driver.authenticate'), ['password' => '0123']);
        DB::table('deca_driver_access')->where('id', 1)->update(['version' => (string) Str::uuid()]);
        $this->get(route('driver.quick'))->assertRedirect(route('driver.login'));
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('driver.authenticate'), ['password' => '9999'])->assertSessionHasErrors('password');
        }
        $this->post(route('driver.authenticate'), ['password' => '9999'])->assertStatus(429);
    }

    public function test_only_administration_can_set_the_shared_password(): void
    {
        $owner = User::findOrFail(DB::table('deca_driver_access')->value('owner_id'));
        $password = '0456';
        $this->actingAs($owner)->post(route('deca.driver.save'), ['password' => $password, 'password_confirmation' => $password])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check($password, DB::table('deca_driver_access')->value('password_hash')));
        $warehouse = User::factory()->create(['role_id' => Role::where('slug', Role::ALMACEN)->firstOrFail()->id]);
        $this->actingAs($warehouse)->post(route('deca.driver.save'), ['password' => $password, 'password_confirmation' => $password])->assertForbidden();
    }

    public function test_pin_requires_exactly_four_digits_and_numeric_mobile_keyboard(): void
    {
        $this->get(route('driver.login'))->assertOk()->assertSee('inputmode="numeric"', false)->assertSee('maxlength="4"', false);
        $owner = User::findOrFail(DB::table('deca_driver_access')->value('owner_id'));
        $this->actingAs($owner);
        foreach (['123', '12345', 'abcd', '12a4'] as $pin) {
            $this->post(route('deca.driver.save'), ['password' => $pin, 'password_confirmation' => $pin])->assertSessionHasErrors('password');
        }
        $this->assertTrue(Hash::check('0123', DB::table('deca_driver_access')->value('password_hash')));
    }
}
