<?php

/**
 * Creates accounts from the command line (there is no public sign-up page in Phase 2).
 *
 *   Super Admin (asks for a password; active immediately, Phase 1 FR-AUTH-30):
 *     php bin/create-user.php --super-admin --email=owner@example.com --name="Ana Souza"
 *
 *   Tenant member (receives an activation e-mail):
 *     php bin/create-user.php --email=sindico@example.com --name="João Lima" --condominium=1 --role=manager
 *     add --invite to skip the password prompt: the user chooses it on the activation page.
 *
 * Roles: manager | concierge | resident. When MAIL_ENABLED=false (APP_ENV=local),
 * the activation link is written to storage/logs/mail-dev.log.
 */

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Models\Condominium;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Services\EmailVerificationService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

/** Prints a message to STDERR and exits with an error status. */
function fail(string $message): never
{
    fwrite(STDERR, 'Error: ' . $message . PHP_EOL);
    exit(1);
}

/** Reads a line without echoing it (falls back to visible input if stty is unavailable). */
function promptSecret(string $label): string
{
    echo $label;
    $hidden = DIRECTORY_SEPARATOR === '/' && shell_exec('stty -echo 2>/dev/null') !== null;
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($hidden) {
        shell_exec('stty echo 2>/dev/null');
        echo PHP_EOL;
    }

    return $value;
}

/** Asks for a password twice and returns its hash. */
function promptPasswordHash(): string
{
    $min = (int) Config::get('security.password_min', 10);
    $max = (int) Config::get('security.password_max', 128);
    $password = promptSecret("Password ({$min}-{$max} characters): ");
    if (mb_strlen($password) < $min || mb_strlen($password) > $max) {
        fail("password must have between {$min} and {$max} characters.");
    }
    if (!hash_equals($password, promptSecret('Repeat password: '))) {
        fail('passwords do not match.');
    }

    return password_hash($password, Config::get('security.password_algo'), Config::get('security.password_options'));
}

$options = getopt('', ['email:', 'name:', 'super-admin', 'condominium:', 'role:', 'invite']);
$email = User::normalizeEmail((string) ($options['email'] ?? ''));
$name = trim((string) ($options['name'] ?? ''));
$isSuperAdmin = array_key_exists('super-admin', $options);

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fail('--email is missing or invalid.');
}

$users = new User();
$existing = $users->findByEmail($email);

// ---- Super Admin -------------------------------------------------------------
if ($isSuperAdmin) {
    if ($existing !== null) {
        fail('a user with this e-mail already exists.');
    }
    if ($name === '') {
        fail('--name is required.');
    }
    $id = $users->insert([
        'full_name'         => $name,
        'email'             => $email,
        'password_hash'     => promptPasswordHash(),
        'is_super_admin'    => 1,
        'status'            => 'active',
        'email_verified_at' => gmdate('Y-m-d H:i:s'),
    ]);
    echo "Super Admin #{$id} created. Log in at " . Config::get('app.url') . "/login\n";
    exit(0);
}

// ---- Tenant member -------------------------------------------------------------
$condominiumId = (int) ($options['condominium'] ?? 0);
$roleCode = (string) ($options['role'] ?? '');

if ((new Condominium())->findActive($condominiumId) === null) {
    fail('--condominium must be the id of an active condominium.');
}
if (!in_array($roleCode, ['manager', 'concierge', 'resident'], true)) {
    fail('--role must be manager, concierge or resident.');
}
$roleId = (new Role())->idByCode($roleCode) ?? fail("role \"{$roleCode}\" not found; was the schema seed run?");

$memberships = new Membership();

if ($existing !== null) {
    // Existing account: only add the membership; no new verification needed.
    if ($memberships->exists((int) $existing['id'], $condominiumId)) {
        fail('this user is already a member of that condominium.');
    }
    $memberships->insert([
        'condominium_id' => $condominiumId,
        'user_id'        => (int) $existing['id'],
        'role_id'        => $roleId,
        'status'         => 'active',
        'approved_at'    => gmdate('Y-m-d H:i:s'),
    ]);
    echo "Existing user #{$existing['id']} added to condominium #{$condominiumId} as {$roleCode}.\n";
    exit(0);
}

if ($name === '') {
    fail('--name is required for a new user.');
}
$passwordHash = array_key_exists('invite', $options) ? null : promptPasswordHash();

$user = Database::transaction(function () use ($users, $memberships, $name, $email, $passwordHash, $condominiumId, $roleId): array {
    $userId = $users->insert([
        'full_name'     => $name,
        'email'         => $email,
        'password_hash' => $passwordHash,
        'status'        => 'pending_verification',
    ]);
    $memberships->insert([
        'condominium_id' => $condominiumId,
        'user_id'        => $userId,
        'role_id'        => $roleId,
        'status'         => 'active',
        'approved_at'    => gmdate('Y-m-d H:i:s'),
    ]);

    return (array) $users->find($userId);
});

$sent = (new EmailVerificationService())->sendNew($user, '127.0.0.1');
echo "User #{$user['id']} created as {$roleCode} of condominium #{$condominiumId}.\n";
echo $sent
    ? "Activation e-mail sent" . (Config::get('mail.enabled') ? '' : ' (see storage/logs/mail-dev.log)') . ".\n"
    : "WARNING: the activation e-mail could not be sent; check storage/logs.\n";
