<?php

return [

    // Base URL of the Next.js frontend; used for CORS and links in emails.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    'employees' => [
        'per_page_default' => 12,
        'per_page_max' => 100,
    ],

    // Development seeding only. Never commit real values.
    'seed_admin' => [
        'name' => env('SEED_ADMIN_NAME', 'Administrator'),
        'email' => env('SEED_ADMIN_EMAIL'),
        'password' => env('SEED_ADMIN_PASSWORD'),
    ],

];
