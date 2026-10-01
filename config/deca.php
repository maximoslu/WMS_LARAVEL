<?php

return [
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
