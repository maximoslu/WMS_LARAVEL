<?php

return [
    'dispatch' => [
        'enabled' => env('DECA_DISPATCH_ENABLED', true),
        'origin' => 'Calle Buenos Aires 23, polígono industrial Centrovía. 50198 La Muela - Zaragoza, ESPAÑA',
        'carrier_key' => 'monge',
        'shipper_name' => 'MÁXIMO SERVICIOS LOGÍSTICOS S.L.U.',
        'shipper_tax_id' => env('DECA_MAXIMO_TAX_ID'),
        'shipper_address' => 'Calle Buenos Aires 23, polígono industrial Centrovía. 50198 La Muela - Zaragoza, ESPAÑA',
    ],
    // Fiscal identifiers belong in private environment configuration, not source control.
    'carriers' => [
        'monge' => [
            'label' => 'Transportes Monge',
            'name' => 'JORGE MONGE ANTOLÍN',
            'tax_id' => env('DECA_MONGE_TAX_ID'),
            'address' => env('DECA_MONGE_ADDRESS'),
        ],
        'maximo' => [
            'label' => 'Máximo Servicios Logísticos',
            'name' => 'MÁXIMO SERVICIOS LOGÍSTICOS S.L.U.',
            'tax_id' => env('DECA_MAXIMO_TAX_ID'),
            'address' => env('DECA_MAXIMO_ADDRESS'),
        ],
    ],
    'public_base_url' => env('DECA_PUBLIC_BASE_URL', env('APP_URL')),
];
