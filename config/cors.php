<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    // Canonical production app and local Angular development server. Additional
    // exact origins may be appended per environment; wildcard origins are never allowed.
    'allowed_origins' => array_values(array_unique(array_merge(
        ['https://imperioglobal.org', 'http://localhost:4200'],
        array_filter(array_map('trim', explode(',', (string) env('APP_FRONTEND_ORIGINS', ''))))
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
