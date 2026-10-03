<?php

declare(strict_types=1);

return [
    // PASSWORD_DEFAULT is bcrypt today and follows PHP's recommendation in future
    // versions; password_needs_rehash() upgrades stored hashes on login.
    'password_algo'    => PASSWORD_DEFAULT,
    'password_options' => ['cost' => 12],
    'password_min'     => 10,
    'password_max'     => 128,

    // Account lockout (Phase 1, FR-AUTH-03).
    'login_max_attempts' => 5,
    'login_lock_minutes' => 15,

    // E-mail verification tokens (Phase 1, A-05).
    'verification_ttl_hours'        => 24,
    'verification_resend_cooldown'  => 60, // seconds between two resends
    'verification_max_per_day'      => 5,
];
