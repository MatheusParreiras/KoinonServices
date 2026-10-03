<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name'  => Env::get('APP_NAME', 'Koinon'),
    // "local" or "production". Some development conveniences are refused outside "local".
    'env'   => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    // Absolute base URL, used to build links inside e-mails (no trailing slash).
    'url'   => rtrim((string) Env::get('APP_URL', 'http://localhost:8000'), '/'),
];
