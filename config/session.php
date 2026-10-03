<?php

declare(strict_types=1);

use App\Core\Env;

return [
    // A custom name avoids advertising "PHPSESSID" (i.e. that this is PHP).
    'name'           => 'KOINON_SESSID',
    // Secure cookies are only sent over HTTPS. Must be true in production.
    'secure'         => Env::bool('SESSION_SECURE', true),
    // Logged-in sessions end after this much inactivity...
    'idle_minutes'   => Env::int('SESSION_IDLE_MINUTES', 30),
    // ...and after this total lifetime, even if active (limits a stolen cookie's value).
    'absolute_hours' => Env::int('SESSION_ABSOLUTE_HOURS', 12),
    // Outside the web root, so session files can never be downloaded.
    'save_path'      => BASE_PATH . '/storage/sessions',
];
