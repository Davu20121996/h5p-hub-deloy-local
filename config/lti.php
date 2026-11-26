<?php

return [
    'storage_directory' => storage_path('app/private'),
    'key_directory' => storage_path('app/private/lti-keys'),
    'login_lifetime' => 300,
    'launch_lifetime' => 7200,
    'allow_http' => env('LTI_ALLOW_HTTP', false),
    'managed_client_url' => env('LTI_MANAGED_CLIENT_URL'),
    'provisioning_secret' => env('HUB_PROVISIONING_SECRET'),
    'demo_enabled' => env('LTI_DEMO_ENABLED', false),
    'demo_jwks_base' => env('LTI_DEMO_JWKS_BASE_URL'),
    'max_package_bytes' => 50 * 1024 * 1024,
    'max_extracted_bytes' => 200 * 1024 * 1024,
];
