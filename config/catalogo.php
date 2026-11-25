<?php

return [
    'jwt' => [
        'secret' => env('JWT_SECRET', ''),
    ],

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME', ''),
        'api_key' => env('CLOUDINARY_API_KEY', ''),
        'api_secret' => env('CLOUDINARY_API_SECRET', ''),
        'backup2_cloud_name' => env('CLOUDINARY_BACKUP2_CLOUD_NAME', ''),
        'backup2_api_key' => env('CLOUDINARY_BACKUP2_API_KEY', ''),
        'backup2_api_secret' => env('CLOUDINARY_BACKUP2_API_SECRET', ''),
    ],

    'groq' => [
        'key_desc' => env('GROQ_API_KEY_DESC', ''),
        'key_suggest' => env('GROQ_API_KEY_SUGGEST', ''),
    ],

    'backup' => [
        'secret_token' => env('BACKUP_SECRET_TOKEN', ''),
        'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    ],

    'inventory' => [
        'sync_token' => env('INVENTORY_SYNC_TOKEN', ''),
    ],

    'notificador' => [
        'enabled' => filter_var(env('PLAXTILINEAS_NOTIFY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'backend_url' => env('PLAXTILINEAS_BACKEND_URL', ''),
        'webhook_secret' => env('PLAXTILINEAS_WEBHOOK_SECRET', ''),
    ],
];
