<?php

/**
 * Application bootstrap shared by the web front controller and the CLI scripts.
 *
 * Loads the Composer autoloader, the .env file and the config/ directory, and
 * sets process-wide defaults (UTC, UTF-8, errors-as-exceptions).
 */

declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;

define('BASE_PATH', __DIR__);

require BASE_PATH . '/vendor/autoload.php';

Env::load(BASE_PATH . '/.env');
Config::load(BASE_PATH . '/config');

// Every DATETIME in the database is UTC (Phase 1, NFR-DATA-02). PHP must agree,
// otherwise gmdate()/date() and MySQL's UTC_TIMESTAMP() would drift apart.
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// Errors are logged, never printed to users: printed errors leak paths, SQL and
// sometimes credentials. APP_DEBUG=true is for a developer's own machine only.
error_reporting(E_ALL);
ini_set('display_errors', Config::get('app.debug') ? '1' : '0');
ini_set('log_errors', '1');

// Turn warnings/notices into exceptions so they cannot be silently ignored.
// Errors silenced with @ are skipped (error_reporting() is masked in that case).
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
