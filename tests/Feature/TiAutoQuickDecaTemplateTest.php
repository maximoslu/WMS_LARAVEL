<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TiAutoQuickDecaTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_ti_auto_tauste_to_maximo_template_has_the_fixed_service_data(): void
    {
        $this->seed(RoleSeeder::class);
        $operator = User::factory()->create([
            'role_id' => Role::query()->where('slug', Role::ALMACEN)->value('id'),
        ]);
        $preset = config('deca_quick.templates.ti-auto-tauste-maximo');

        $this->assertSame('TI Auto Tauste → MAXIMO', $preset['title']);
        $this->assertSame('TI GROUP AUTOMOTIVE SYSTEMS SA', $preset['shipper_name']);
        $this->assertSame('A60012218', $preset['shipper_tax_id']);
        $this->assertSame('POLÍGONO LAS ROZAS, CALLE B, TAUSTE', $preset['shipper_address']);
        $this->assertSame('TI GROUP AUTOMOTIVE SYSTEMS SA, POLÍGONO LAS ROZAS, CALLE B, TAUSTE', $preset['origin']);
        $this->assertSame('MÁXIMO SERVICIOS LOGÍSTICOS S.L.U., CALLE BUENOS AIRES 23, POLÍGONO INDUSTRIAL CENTROVÍA, 50198 LA MUELA, ZARAGOZA, ESPAÑA', $preset['destination']);
        $this->assertSame('Mercancía paletizada — 18 palés', $preset['goods']);
        $this->assertSame('5200', $preset['weight_kg']);

        $this->actingAs($operator)
            ->get(route('deca.quick'))
            ->assertOk()
            ->assertSeeText('TI Auto Tauste → MAXIMO')
            ->assertSeeText('Mercancía paletizada — 18 palés')
            ->assertSeeText('5.200 kg aprox.');

        $this->get(route('deca.quick.create', 'ti-auto-tauste-maximo'))
            ->assertOk()
            ->assertSeeText('TI GROUP AUTOMOTIVE SYSTEMS SA')
            ->assertSeeText('A60012218')
            ->assertSeeText('POLÍGONO LAS ROZAS, CALLE B, TAUSTE')
            ->assertSeeText('50198 LA MUELA')
            ->assertSeeText('Mercancía paletizada — 18 palés')
            ->assertSeeText('5.200 kg');
    }
}
