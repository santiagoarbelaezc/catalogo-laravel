<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [
        'https://plaxtilineas.com',
        'https://www.plaxtilineas.com',
        'http://localhost:4200',
        'http://localhost:4201',
        'http://localhost:3000',
        'https://catalogoplaxtilineas.com',
        'https://www.catalogoplaxtilineas.com',
        'https://catalogoplaxtilineas.sembradoresdevida.org',
        'https://colchonesdistricol.com',
        'https://www.colchonesdistricol.com',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'Accept',
        'Authorization',
        'Origin',
        'X-Requested-With',
        'X-Skip-Error-Toast',
        'Cache-Control',
    ],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => true,
];

