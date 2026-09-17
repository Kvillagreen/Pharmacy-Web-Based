<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:4200,http://127.0.0.1:4200,https://pharmacy-chmsu.web.app,https://pharmacy-chmsu.firebaseapp.com'))
    ))),

    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGIN_PATTERNS', '#^https://.*\.web\.app$#,#^https://.*\.firebaseapp\.com$#'))
    ))),

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Type', 'Authorization'],

    'max_age' => 86400,

    'supports_credentials' => false,

];
