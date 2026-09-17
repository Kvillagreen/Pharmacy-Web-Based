<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:4200,http://127.0.0.1:4200,http://localhost:4201,http://127.0.0.1:4201,https://pharmacy-chmsu.web.app,https://pharmacy-chmsu.firebaseapp.com'))
    ))),

    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGIN_PATTERNS', '#^http://192\.168\.\d{1,3}\.\d{1,3}:4201$#,#^http://10\.\d{1,3}\.\d{1,3}\.\d{1,3}:4201$#,#^http://172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3}:4201$#,#^https://.*\.web\.app$#,#^https://.*\.firebaseapp\.com$#'))
    ))),

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Type', 'Authorization'],

    'max_age' => 86400,

    'supports_credentials' => false,

];
