<?php

declare(strict_types=1);

use App\Core\Env;

// The application user should only have SELECT/INSERT/UPDATE/DELETE on koinon.*
// (Phase 1, NFR-SEC-12). Schema changes are run by a separate migration user.
return [
    'host'     => Env::get('DB_HOST', '127.0.0.1'),
    'port'     => Env::int('DB_PORT', 3306),
    'database' => Env::get('DB_DATABASE', 'koinon'),
    'username' => Env::get('DB_USERNAME', 'koinon_app'),
    'password' => Env::get('DB_PASSWORD', ''),
];
