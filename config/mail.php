<?php

declare(strict_types=1);

use App\Core\Env;

return [
    // false = development mode: messages are written to storage/logs/mail-dev.log
    // instead of being sent (only honoured when APP_ENV=local).
    'enabled'      => Env::bool('MAIL_ENABLED', false),
    'host'         => Env::get('MAIL_HOST', ''),
    'port'         => Env::int('MAIL_PORT', 587),
    'encryption'   => Env::get('MAIL_ENCRYPTION', 'tls'),
    'username'     => Env::get('MAIL_USERNAME', ''),
    'password'     => Env::get('MAIL_PASSWORD', ''),
    'from_address' => Env::get('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
    'from_name'    => Env::get('MAIL_FROM_NAME', 'Koinon'),
];
