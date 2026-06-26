<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $friesland = Client::query()->where('code', 'FRIESLAND')->first();
        $edelvives = Client::query()->where('code', 'EDELVIVES')->first();

        foreach ([
            [
                'client_id' => $friesland?->id,
                'name' => 'PROVEEDOR LACTEOS NORTE',
                'tax_id' => 'B12345678',
                'email' => 'compras@lacteosnorte.test',
                'phone' => '944000001',
                'contact_name' => 'Laura Perez',
                'notes' => 'Proveedor demo para recepciones de Friesland.',
            ],
            [
                'client_id' => $edelvives?->id,
                'name' => 'PROVEEDOR EDUCA PAPEL',
                'tax_id' => 'B87654321',
                'email' => 'pedidos@educapapel.test',
                'phone' => '976000002',
                'contact_name' => 'Miguel Sanz',
                'notes' => 'Proveedor demo para recepciones de Edelvives.',
            ],
            [
                'client_id' => null,
                'name' => 'TRANSPORTE GENERAL MAXIMO',
                'tax_id' => 'A11223344',
                'email' => 'trafico@transportegeneral.test',
                'phone' => '915000003',
                'contact_name' => 'Sara Ruiz',
                'notes' => 'Proveedor global para pruebas operativas.',
            ],
        ] as $supplier) {
            Supplier::query()->updateOrCreate(
                ['name' => $supplier['name']],
                [
                    ...$supplier,
                    'active' => true,
                ]
            );
        }
    }
}
