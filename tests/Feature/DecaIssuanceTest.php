<?php

namespace Tests\Feature;

use App\Models\DecaDocument;
use App\Models\Role;
use App\Models\User;
use App\Services\Deca\DecaPdfService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DecaIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        config(['deca.public_base_url' => 'https://wms.example.test', 'deca.carriers.monge.tax_id' => '00000000T', 'deca.carriers.maximo.tax_id' => 'B00000000']);
        $this->operator = User::factory()->create(['role_id' => Role::where('slug', Role::ALMACEN)->firstOrFail()->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'submission_key' => (string) Str::uuid(), 'carrier_key' => 'monge',
            'shipper_name' => 'Cargador de prueba', 'shipper_tax_id' => 'B00000000',
            'shipper_address' => 'Calle Ejemplo 1, Zaragoza', 'origin' => 'Almacén Zaragoza',
            'destination' => 'Almacén Madrid', 'transport_date' => now('Europe/Madrid')->toDateString(),
            'goods' => '12 palets de libros', 'weight_kg' => '1500,250', 'tractor_plate' => '1234 abc',
            'articulated' => '0', 'special_authorization' => '0', 'confirmed' => '1',
        ], $overrides);
    }

    public function test_form_and_history_are_available_to_operators(): void
    {
        $this->actingAs($this->operator)->get(route('deca.create'))->assertOk()->assertSee('JORGE MONGE ANTOLÍN')->assertSee('Generar PDF con QR');
        $this->get(route('deca.documents'))->assertOk()->assertSee('Todavía no hay documentos');
    }

    public function test_quick_templates_issue_their_fixed_data_and_require_vehicle_selection(): void
    {
        $this->actingAs($this->operator);
        foreach (config('deca_quick.templates') as $key => $preset) {
            $this->get(route('deca.quick.create', $key))->assertOk()
                ->assertSee($preset['title'])->assertSee('1933MYN')->assertSee('3100KGC');
            $url = route('deca.quick.store', $key);
            foreach (['', '9999XXX'] as $plate) {
                $this->post($url, $this->payload(['tractor_plate' => $plate]))
                    ->assertSessionHasErrors('tractor_plate');
            }
            $payload = $this->payload(['tractor_plate' => '1933MYN', 'goods' => 'Manipulated', 'weight_kg' => 1]);
            $this->post($url, $payload)->assertSessionHasNoErrors()->assertRedirect();
            $document = DecaDocument::latest('id')->firstOrFail();
            foreach (['shipper_name', 'shipper_tax_id', 'shipper_address', 'origin', 'destination', 'goods', 'weight_kg'] as $field) {
                $this->assertSame($preset[$field], $document->snapshot[$field]);
            }
            $this->assertSame('1933MYN', $document->snapshot['tractor_plate']);
            $this->get($document->public_url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->post($url, $payload)->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('deca_documents', 2);
    }

    public function test_quick_routes_reject_unknown_templates_and_unauthorized_users(): void
    {
        $this->get(route('deca.quick.create', 'edelvives-supply-chain'))->assertRedirect(route('login'));
        $this->actingAs($this->operator)->get(route('deca.quick.create', 'unknown'))->assertNotFound();
        $this->post(route('deca.quick.store', 'unknown'), $this->payload())->assertNotFound();
        $client = User::factory()->create(['role_id' => Role::where('slug', Role::CLIENTE)->firstOrFail()->id]);
        $this->actingAs($client)->get(route('deca.quick.create', 'edelvives-supply-chain'))->assertForbidden();
        $this->post(route('deca.quick.store', 'edelvives-supply-chain'), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('deca_documents', 0);
    }

    public function test_issuance_preserves_pdf_and_guest_qr_download_is_identical(): void
    {
        $this->actingAs($this->operator)->post(route('deca.store'), $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $document = DecaDocument::sole();
        $this->assertSame('1234 ABC', $document->snapshot['tractor_plate']);
        $this->assertSame('1500.250', $document->snapshot['weight_kg']);
        $this->assertSame('00000000T', $document->snapshot['carrier']['tax_id']);
        $bytes = Storage::disk('local')->get($document->pdf_path);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertLessThanOrEqual(5_000_000, strlen($bytes));
        $this->assertStringContainsString('/CreationDate (D:', $bytes);
        $this->assertStringContainsString('/ModDate (D:', $bytes);
        $this->assertSame(hash('sha256', $bytes), $document->pdf_sha256);
        $this->assertTrue($document->retain_until->greaterThan(now()->addDays(364)));
        $this->get(route('deca.show', $document))->assertOk()->assertSee('Descargar PDF con QR');
        config(['deca.carriers.monge.name' => 'Nombre cambiado']);
        $this->get(route('deca.download', $document))->assertContent($bytes);
        auth()->forgetGuards();
        $this->get($document->public_url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertContent($bytes);
        $this->get('/documentos/deca/'.str_repeat('a', 64).'.pdf')->assertNotFound();
    }

    public function test_double_submission_issues_only_one_document(): void
    {
        $payload = $this->payload();
        $this->actingAs($this->operator)->post(route('deca.store'), $payload)->assertRedirect();
        $this->post(route('deca.store'), $payload)->assertRedirect();
        $this->assertDatabaseCount('deca_documents', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('deca'));
    }

    public function test_required_and_conditional_data_are_enforced(): void
    {
        $this->actingAs($this->operator)->post(route('deca.store'), $this->payload([
            'carrier_key' => 'other', 'articulated' => '1', 'special_authorization' => '1',
            'weight_kg' => '0', 'shipper_tax_id' => '', 'confirmed' => '0', 'transport_date' => '2020-01-01',
        ]))->assertSessionHasErrors(['carrier_key', 'trailer_plate', 'authorization_number', 'weight_kg', 'shipper_tax_id', 'confirmed', 'transport_date']);
        $this->assertDatabaseCount('deca_documents', 0);
    }

    public function test_second_carrier_and_conditional_fields_are_saved(): void
    {
        $this->actingAs($this->operator)->post(route('deca.store'), $this->payload([
            'carrier_key' => 'maximo', 'articulated' => '1', 'trailer_plate' => 'r1234 abc',
            'special_authorization' => '1', 'authorization_number' => 'AUTH-TEST',
            'carrier_name' => 'Manipulated', 'carrier_tax_id' => 'Manipulated',
        ]))->assertSessionHasNoErrors();
        $document = DecaDocument::sole();
        $this->assertSame('MÁXIMO SERVICIOS LOGÍSTICOS S.L.U.', $document->snapshot['carrier']['name']);
        $this->assertSame('R1234 ABC', $document->snapshot['trailer_plate']);
        $this->assertSame('AUTH-TEST', $document->snapshot['authorization_number']);
    }

    public function test_missing_private_config_and_insecure_public_url_prevent_emission(): void
    {
        config(['deca.carriers.monge.tax_id' => null]);
        $this->actingAs($this->operator)->post(route('deca.store'), $this->payload())->assertSessionHasErrors('carrier_key');
        config(['deca.carriers.monge.tax_id' => '00000000T', 'deca.public_base_url' => 'http://localhost']);
        $this->post(route('deca.store'), $this->payload())->assertSessionHasErrors('carrier_key');
        $this->assertDatabaseCount('deca_documents', 0);
    }

    public function test_clients_cannot_create_list_view_or_download_internal_documents(): void
    {
        $this->actingAs($this->operator)->post(route('deca.store'), $this->payload());
        $document = DecaDocument::sole();
        $client = User::factory()->create(['role_id' => Role::where('slug', Role::CLIENTE)->firstOrFail()->id]);
        $this->actingAs($client)->post(route('deca.store'), $this->payload())->assertForbidden();
        foreach (['deca.create', 'deca.documents', 'deca.show', 'deca.download'] as $route) {
            $this->get(route($route, $document))->assertForbidden();
        }
    }

    public function test_pdf_failure_does_not_create_document(): void
    {
        $this->mock(DecaPdfService::class)->shouldReceive('render')->once()->andThrow(new \RuntimeException('PDF failure'));
        $this->actingAs($this->operator)->post(route('deca.store'), $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('deca_documents', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('deca'));
    }

    public function test_corrupt_pdf_is_not_served_or_silently_regenerated(): void
    {
        $this->actingAs($this->operator)->post(route('deca.store'), $this->payload());
        $document = DecaDocument::sole();
        Storage::disk('local')->put($document->pdf_path, 'corrupted');
        $this->get($document->public_url)->assertStatus(503);
    }
}
