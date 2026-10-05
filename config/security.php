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

    // ---- Phase 5 ---------------------------------------------------------------
    // Token lifetimes. Invitations last longer than resets because people often
    // open them days later; a reset link is only useful right now.
    'invitation_ttl_hours'        => 72,
    'invitation_resend_cooldown'  => 60, // seconds between two resends of one invitation
    'invitation_max_per_day'      => 5,  // e-mails per invitation per 24 h
    'password_reset_ttl_minutes'  => 60,
    'email_change_ttl_hours'      => 24,

    // Avatar uploads (stored outside the web root, in storage/uploads/avatars).
    'avatar_max_bytes' => 1_048_576,

    // Database-backed rate limits: action => subject kind => [max attempts, window seconds].
    // Login also keeps the per-account lockout above (5 wrong passwords = 15 min).
    'rate_limits' => [
        'login'             => ['ip' => [20, 900], 'account' => [10, 900]],
        'password_forgot'   => ['ip' => [5, 900], 'account' => [3, 3600]],
        'password_reset'    => ['ip' => [10, 900]],
        'invitation_resend' => ['ip' => [30, 3600], 'account' => [10, 3600]],
        'invitation_accept' => ['ip' => [10, 900]],
        'email_change'      => ['account' => [5, 3600]],
    ],
];
