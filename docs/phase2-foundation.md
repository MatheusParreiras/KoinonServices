# Koinon: Phase 2 Application Foundation

MVC structure, authentication with e-mail verification, RBAC, and the Notice Board.

Every file below exists in the repository as shown; this document is generated from them. The code was linted with `php -l` on PHP 8.4. It was smoke-tested on PHP's built-in server for routing, 404/405/419/401 responses, security headers, the generic 500 page when the database is down, and tenant-scoped SQL generation. **It has not yet run against a live MySQL database.**

## Assumptions

| # | Decision |
|---|----------|
| A-01 | **The real Phase 1 schema is used, not the column names suggested in the brief.** The brief lists `users.role_id` and `users.condominium_id`. In the Phase 1 schema, identity is global and role and tenant live on the membership table `condominium_users (condominium_id, user_id, role_id, status)`, so one person can belong to several condominiums. The session still stores exactly `user_id`, `role_id` and `condominium_id`, taken from the active membership. For a Super Admin, `role_id` and `condominium_id` are `NULL`, as the brief specifies. |
| A-02 | **Super Admin** is `users.is_super_admin = TRUE` with no membership. To work on tenant data, the Super Admin picks a condominium in `/select-condominium`. The choice is stored separately (`admin_condominium_id`), validated on every request, and written to `audit_logs`. |
| A-03 | **Super Admin and Concierge can publish notices**, as this brief requires. Phase 1 allowed only member authors and did not grant notices to the Concierge. **Migration `0001`** makes `notices.author_user_id` nullable, adds `notices.author_super_admin_id`, and grants the new `notices.create` permission to manager and concierge. Run it after `schema.sql`. |
| A-04 | **Route protection is role-based**, with `requireRole([...])` as the brief asks: the `role:` middleware plus `Controller::requireRole()`. The Phase 1 `permissions` tables are kept in sync, but checking fine-grained permissions is left for later phases. |
| A-05 | **There is no public sign-up page in Phase 2**, because the brief scopes Phase 2 to login, verification and RBAC. Accounts are created with `php bin/create-user.php`. It creates Super Admins (active immediately) and tenant members, who receive the activation e-mail. Invited members (`--invite`) choose their password on the activation page. |
| A-06 | **The verification link opens a confirmation page.** Following Phase 1 FR-AUTH-14, the GET request only shows the page, and the account is activated by a POST with a CSRF token. E-mail security scanners open links automatically and would otherwise use up the single-use token. Tokens are 32 random bytes, stored only as a SHA-256 hash, valid for 24 hours, and single use. Resending is limited to once per 60 seconds and 5 times per 24 hours. |
| A-07 | **Mail:** `App\Mail\Mailer` wraps PHPMailer over SMTP. With `MAIL_ENABLED=false`, which is only honoured when `APP_ENV=local`, messages are written to `storage/logs/mail-dev.log` instead, so the flow can be tested without SMTP. This is the "placeholder" transport. |
| A-08 | **Login gate order:** the "confirm your e-mail" message is shown only after the password has been verified as correct. Every other failure (unknown e-mail, wrong password, locked, blocked) gets one generic message. Five failures lock the account for 15 minutes. |
| A-09 | **The interface is in Portuguese (pt-BR)**, per Phase 1 NFR-UX-03. Code and comments are in English. Moving strings into a translation file is deferred. |
| A-10 | **Sessions:** native PHP sessions stored in `storage/sessions`. They expire after 30 minutes idle or 12 hours in total. They are also validated against `users.status` and `users.session_version` on every request, so blocking a user or changing their password ends the session immediately. |
| A-11 | **A static `Database::connection()` singleton** is used instead of a DI container. Models are the only place that obtains it (`Model::db()`), so moving to constructor injection later changes one method. |

---

## 1. MVC Directory Structure

```
koinon/
├── public/                        ← the ONLY web-server document root
│   ├── index.php                  ← front controller (single entry point)
│   ├── .htaccess                  ← rewrites every non-file URL to index.php
│   └── assets/
│       ├── css/app.css
│       └── js/
│           ├── core/http.js       ← fetch wrapper (CSRF header, JSON, 401 handling)
│           └── notices.js         ← Notice Board page script
├── app/                           ← application code, PSR-4 namespace App\
│   ├── Core/                      ← the in-house mini-framework
│   │   ├── Router.php             ← URL + method → Controller@action, middleware, 404/405
│   │   ├── Request.php / Response.php
│   │   ├── Controller.php         ← base controller
│   │   ├── Model.php              ← base model (global tables)
│   │   ├── TenantModel.php        ← base model (tenant tables, auto-scoped)
│   │   ├── TenantContext.php      ← current condominium of the request
│   │   ├── Database.php           ← PDO connection
│   │   ├── Session.php / Csrf.php / Auth.php
│   │   ├── View.php / helpers.php ← templates and e(), csrf_field(), asset()
│   │   ├── ErrorHandler.php / HttpException.php / Logger.php
│   │   └── Config.php / Env.php
│   ├── Middleware/                ← auth, guest, tenant, role, csrf
│   ├── Controllers/               ← HTTP actions (thin)
│   ├── Models/                    ← one class per table, all SQL lives here
│   ├── Services/                  ← multi-step business logic (login, verification)
│   ├── Mail/Mailer.php            ← the only class that touches PHPMailer
│   └── Views/
│       ├── layouts/               ← app.php (header, nav, footer), auth.php
│       ├── auth/ dashboard/ tenant/ errors/ emails/
├── routes/web.php                 ← the route table
├── config/                        ← app, database, session, mail, security (read from .env)
├── bootstrap.php                  ← shared startup for web and CLI
├── bin/create-user.php            ← CLI: create Super Admin / tenant members
├── database/
│   ├── schema.sql                 ← Phase 1 schema
│   └── migrations/0001_…sql       ← Phase 2 change to notices
├── storage/                       ← writable, NOT web-accessible
│   ├── logs/                      ← app-YYYY-MM-DD.log, mail-dev.log
│   └── sessions/                  ← PHP session files
├── vendor/                        ← Composer (PHPMailer + autoloader), not committed
├── .env / .env.example            ← secrets / template
└── composer.json
```

**Why only `public/` is web-accessible.** The web server's document root points at `public/`, so a URL can only ever map to `index.php` or a static asset. `.env` (database and SMTP passwords), `config/`, `app/` source, `storage/logs` and `storage/sessions` (which contain session data) all sit one level above it. They cannot be downloaded even if the web server is misconfigured to serve `.php` files as plain text, or if someone guesses a file name. Every dynamic request passes through one front controller, so every request gets the same session start, error handling and security headers.

| Folder | Purpose |
|--------|---------|
| `routes/web.php` | **Routing**: every URL, its HTTP method, controller action and middleware, in one readable table. |
| `app/Controllers` | **Controllers**: read the request, call models or services, and return a `Response`. They contain no SQL. |
| `app/Models` | **Models**: all SQL. Global tables extend `Model`. Tenant tables extend `TenantModel`, which scopes every query to the current condominium. |
| `app/Views` | **Views**: PHP templates that escape all output with `e()`. Layouts provide the shared header, navigation and footer. |
| `app/Services` | Logic that spans several tables or needs a transaction, such as login and e-mail verification. |
| `app/Middleware` | Checks that run before a controller: authentication, tenant, role and CSRF. |
| `config/` | **Configuration**: plain PHP arrays filled from `.env`. |
| `public/assets` | **Public assets**: CSS and ES-module JavaScript, served directly by the web server. |

---

## 2. Core Foundation Code

### 2.1 Project files

`composer.json`

```json
{
    "name": "koinon/koinon",
    "description": "Koinon - condominium management SaaS",
    "type": "project",
    "license": "proprietary",
    "require": {
        "php": ">=8.2",
        "ext-mbstring": "*",
        "ext-pdo": "*",
        "ext-pdo_mysql": "*",
        "phpmailer/phpmailer": "^6.9"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        },
        "files": [
            "app/Core/helpers.php"
        ]
    },
    "config": {
        "optimize-autoloader": true,
        "sort-packages": true
    }
}
```

`.env.example`

```ini
# Copy to .env and fill in. Never commit .env.
APP_NAME=Koinon
# local | production. "local" enables the development mail log (see app/Mail/Mailer.php).
APP_ENV=local
APP_DEBUG=false
APP_URL=http://localhost:8000

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=koinon
DB_USERNAME=koinon_app
DB_PASSWORD=

# Must be true in production (HTTPS). false only for http://localhost development.
SESSION_SECURE=false
SESSION_IDLE_MINUTES=30
SESSION_ABSOLUTE_HOURS=12

# false = e-mails are written to storage/logs/mail-dev.log (only allowed when APP_ENV=local).
MAIL_ENABLED=false
MAIL_HOST=smtp.example.com
MAIL_PORT=587
# tls (STARTTLS, port 587) or ssl (SMTPS, port 465)
MAIL_ENCRYPTION=tls
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME=Koinon
```

`.gitignore`

```text
/vendor/
/.env
/storage/logs/*
!/storage/logs/.gitkeep
/storage/sessions/*
!/storage/sessions/.gitkeep
```

`bootstrap.php`

```php
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
```

`config/app.php`

```php
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
```

`config/database.php`

```php
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
```

`config/session.php`

```php
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
```

`config/mail.php`

```php
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
```

`config/security.php`

```php
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
```

`app/Core/Env.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader.
 *
 * Secrets live in a .env file next to (not inside) public/, so they are never
 * downloadable and never committed. Parsing KEY=VALUE lines is a few lines of
 * code, so no third-party package is used for it (Phase 1, NFR-LIB-05).
 */
final class Env
{
    /** @var array<string, string> Values read from the .env file. */
    private static array $values = [];

    /**
     * Reads a .env file. A missing file is not an error: in production the
     * variables may come from the web server or container environment instead.
     */
    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $quoted = strlen($value) >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && $value[-1] === $value[0];
            self::$values[$key] = $quoted ? substr($value, 1, -1) : $value;
        }
    }

    /**
     * Returns a variable. Real environment variables win over the .env file so
     * that deployment tooling can override a value without editing files.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $fromEnvironment = getenv($key);
        if ($fromEnvironment !== false) {
            return $fromEnvironment;
        }

        return self::$values[$key] ?? $default;
    }

    /** Returns a variable interpreted as a boolean ("1", "true", "yes", "on"). */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /** Returns a variable interpreted as an integer. */
    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return ($value !== null && is_numeric($value)) ? (int) $value : $default;
    }
}
```

`app/Core/Config.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Read-only access to the arrays returned by config/*.php.
 *
 * Usage: Config::get('database.host'). The first segment is the file name.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    /** Loads every PHP file in the directory; each must return an array. */
    public static function load(string $directory): void
    {
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            self::$items[basename($file, '.php')] = require $file;
        }
    }

    /** Returns a value by dot-notation key, or $default when it does not exist. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
```

`app/Core/Logger.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Daily file logger (storage/logs/app-YYYY-MM-DD.log).
 *
 * Users only ever see generic error pages; the details go here, tagged with a
 * per-request id so one request's lines can be found together. Never pass
 * passwords, raw tokens or session ids in $context (Phase 1, NFR-OPS-02).
 */
final class Logger
{
    private static ?string $requestId = null;

    /** Logs a failure that needs attention. */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /** Logs something suspicious that was handled (e.g. a CSRF failure). */
    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    /** Logs a normal but noteworthy event. */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /** Random id shared by every log line written during this request. */
    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(8));
    }

    private static function write(string $level, string $message, array $context): void
    {
        $line = sprintf(
            "[%s] [%s] %s %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            self::requestId(),
            $level,
            $message,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
        );

        $file = BASE_PATH . '/storage/logs/app-' . gmdate('Y-m-d') . '.log';
        // @: a logging failure (full disk, permissions) must never break the request itself.
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }
}
```


### 2.2 Database connection (PDO)

`ERRMODE_EXCEPTION`, real prepared statements (`ATTR_EMULATE_PREPARES = false`), utf8mb4 set through the DSN, and a UTC session time zone. Connection errors are logged, and users see a generic 500. The PDO exception is deliberately not chained to the one that is thrown, because its stack trace contains the password.

`app/Core/Database.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Lazily created, shared PDO connection (one per request).
 *
 * A static accessor is used instead of a DI container to keep the framework-free
 * codebase simple; models obtain the connection through Model::db(), so swapping
 * this for constructor injection later only touches the base Model.
 */
final class Database
{
    private static ?PDO $connection = null;

    /** Returns the shared connection, connecting on first use. */
    public static function connection(): PDO
    {
        return self::$connection ??= self::connect();
    }

    /**
     * Runs $callback inside a transaction: commit on success, rollback on any
     * exception (which is then re-thrown).
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function connect(): PDO
    {
        $config = Config::get('database');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );

        try {
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                // Every SQL error becomes an exception instead of a silent false.
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Real server-side prepared statements: the SQL text and the
                // values travel separately, so values can never be parsed as SQL.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            // DATETIME defaults (CURRENT_TIMESTAMP) and NOW() must be UTC (NFR-DATA-02).
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            Logger::error('Database connection failed', [
                'code'    => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
            // The original exception is deliberately NOT chained: the PDO
            // constructor's stack trace contains the DSN, user and password.
            throw new RuntimeException('Database unavailable.');
        }

        return $pdo;
    }
}
```


### 2.3 Front controller and Router

`public/.htaccess`

```apache
# Only public/ is the document root. Everything that is not a real file
# (CSS, JS, images) is routed to the front controller.
Options -Indexes -MultiViews

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
```

`public/index.php`

```php
<?php

/**
 * Front controller: the single PHP entry point exposed by the web server.
 *
 * Every request that is not a static file arrives here (public/.htaccess, or
 * Nginx try_files). Nothing outside public/ is reachable by URL, so config,
 * .env, logs, sessions and source code can never be downloaded.
 */

declare(strict_types=1);

use App\Core\Config;
use App\Core\ErrorHandler;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;

// PHP built-in development server: let it serve existing static files itself.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/bootstrap.php';

$request = Request::fromGlobals();

try {
    Session::start();
    /** @var \App\Core\Router $router */
    $router = require BASE_PATH . '/routes/web.php';
    $response = $router->dispatch($request);
} catch (HttpException $e) {
    $response = ErrorHandler::fromHttpException($e, $request);
} catch (Throwable $e) {
    $response = ErrorHandler::fromThrowable($e, $request);
}

// Security headers on every dynamic response (controllers may override some, e.g. Referrer-Policy).
$response = $response
    ->withDefaultHeader('X-Content-Type-Options', 'nosniff')
    ->withDefaultHeader('X-Frame-Options', 'DENY')
    ->withDefaultHeader('Referrer-Policy', 'same-origin')
    // Scripts and styles only from this origin: no inline <script>, no style="".
    // Even if some user text slipped through unescaped, it could not run.
    ->withDefaultHeader(
        'Content-Security-Policy',
        "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
    )
    // Authenticated pages must not be stored by the browser or shared caches.
    ->withDefaultHeader('Cache-Control', 'no-store');

if (Config::get('session.secure')) {
    $response = $response->withDefaultHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
}

$response->send();
```

`app/Core/Request.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable view of the current HTTP request.
 *
 * Wrapping the superglobals in one object keeps controllers testable and gives
 * one place to normalise the path and to decode JSON bodies sent by fetch().
 */
final class Request
{
    /** JSON bodies larger than this are ignored (cheap protection against huge payloads). */
    private const MAX_JSON_BYTES = 1_048_576;

    /**
     * @param array<string, mixed>  $query  Query-string parameters ($_GET).
     * @param array<string, mixed>  $body   Form fields ($_POST) or decoded JSON body.
     * @param array<string, mixed>  $server Server variables ($_SERVER).
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $server
    ) {
    }

    /** Builds the request from PHP's superglobals. */
    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // "/login/" and "/login" are the same route; "/" stays "/".
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = '/' . trim(is_string($path) ? $path : '/', '/');

        $body = $_POST;
        if (str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $raw = file_get_contents('php://input', false, null, 0, self::MAX_JSON_BYTES);
            $decoded = json_decode($raw === false ? '' : $raw, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        return new self($method, $path, $_GET, $body, $_SERVER);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Returns a query-string value. */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** Returns a body value (form field or JSON property). */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Returns a body value as a string, or '' when it is missing or not a
     * string. Prevents "Array to string" errors when a client sends
     * title[]=x or {"title": {...}} to probe the application.
     */
    public function string(string $key): string
    {
        $value = $this->body[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /** Returns a body value interpreted as a checkbox/boolean. */
    public function boolean(string $key): bool
    {
        $value = $this->body[$key] ?? false;

        return $value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'true';
    }

    /** Returns a request header, e.g. header('X-CSRF-Token'). */
    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** True for fetch()/API calls, which must receive JSON errors instead of HTML pages. */
    public function wantsJson(): bool
    {
        return str_starts_with($this->path, '/api/')
            || str_contains((string) $this->header('Accept'), 'application/json');
    }

    /** True for methods that change state and therefore require a CSRF token. */
    public function isStateChanging(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * Client IP. REMOTE_ADDR only: X-Forwarded-For is client-controlled and must
     * not be trusted unless a known reverse proxy is configured.
     */
    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}
```

`app/Core/Response.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * An HTTP response built by a controller and sent once by the front controller.
 *
 * Controllers return Response objects instead of echoing, so middleware and the
 * front controller can still add headers (security headers, cache control).
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private array $headers = []
    ) {
    }

    /** An HTML page. */
    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * A JSON document. JSON_HEX_* escapes <, >, & and quotes so the output stays
     * inert even if a browser were tricked into treating it as HTML.
     */
    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return new self($body, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /**
     * A redirect to a path inside this application. External URLs are refused,
     * so no code path can be turned into an open redirect.
     */
    public static function redirect(string $path, int $status = 302): self
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Only same-site redirects are allowed.');
        }

        return new self('', $status, ['Location' => $path]);
    }

    /** Returns a copy with the header set (replacing any existing value). */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    /** Returns a copy with the header set only if the controller did not set it. */
    public function withDefaultHeader(string $name, string $value): self
    {
        return isset($this->headers[$name]) ? $this : $this->withHeader($name, $value);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** Writes status, headers and body to the client. */
    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
```

`app/Core/HttpException.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * An expected HTTP error (404, 403, 419...) thrown anywhere during a request and
 * turned into an HTML page or JSON body by ErrorHandler. The message is shown
 * to the user, so it must never contain internal details.
 */
final class HttpException extends RuntimeException
{
    private const DEFAULT_MESSAGES = [
        400 => 'Requisição inválida.',
        401 => 'Entre para continuar.',
        403 => 'Você não tem permissão para acessar este recurso.',
        404 => 'Página não encontrada.',
        405 => 'Método não permitido.',
        419 => 'Sua sessão expirou. Recarregue a página e tente novamente.',
        422 => 'Os dados enviados são inválidos.',
        500 => 'Ocorreu um erro inesperado. Tente novamente em instantes.',
    ];

    /**
     * @param array<string, string> $headers Extra response headers (e.g. Allow for 405).
     */
    public function __construct(
        private readonly int $status,
        ?string $message = null,
        private readonly array $headers = []
    ) {
        parent::__construct($message ?? (self::DEFAULT_MESSAGES[$status] ?? 'Erro.'));
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
```

`app/Core/ErrorHandler.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Converts exceptions into user-safe responses.
 *
 * Expected errors (HttpException) show their message. Anything else is logged
 * with full details and answered with a generic 500, because exception
 * messages can contain SQL, file paths or configuration values.
 */
final class ErrorHandler
{
    /** Response for an expected HTTP error. */
    public static function fromHttpException(HttpException $e, Request $request): Response
    {
        $response = self::render($e->status(), $e->getMessage(), $request);
        foreach ($e->headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /** Logs an unexpected error and returns a generic 500. */
    public static function fromThrowable(Throwable $e, Request $request): Response
    {
        Logger::error('Unhandled ' . $e::class, [
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'method'  => $request->method(),
            'path'    => $request->path(),
        ]);

        return self::render(500, (new HttpException(500))->getMessage(), $request);
    }

    private static function render(int $status, string $message, Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => $message], $status);
        }

        try {
            // Error pages use the auth layout: it needs no database or session
            // data, so it still renders when the error came from either of them.
            $html = View::render('errors/error', [
                'title'     => 'Erro ' . $status,
                'status'    => $status,
                'message'   => $message,
                'requestId' => Logger::requestId(),
                'flashes'   => [],
            ], 'layouts/auth');
        } catch (Throwable) {
            $html = '<!doctype html><meta charset="utf-8"><title>Erro</title><p>' . e($message) . '</p>';
        }

        return Response::html($html, $status);
    }
}
```

`app/Core/Router.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\Middleware;
use LogicException;

/**
 * Maps "METHOD /path" to [Controller::class, 'action'] with a middleware list.
 *
 *   $router->get('/dashboard', [DashboardController::class, 'index'], ['auth', 'tenant']);
 *   $router->post('/api/notices', [NoticeController::class, 'store'],
 *       ['auth', 'tenant', 'csrf', 'role:super_admin,manager,concierge']);
 *
 * Path parameters: "/notices/{id:\d+}" passes $id to the action as a named
 * argument. A parameter without a pattern matches one path segment ([^/]+).
 * Patterns cannot contain "}" (use \d+ rather than \d{1,5}).
 *
 * Middleware specs are "alias" or "alias:arg1,arg2"; aliases are registered
 * with alias() and run in the order listed, before the controller.
 */
final class Router
{
    /** @var array<string, class-string<Middleware>> */
    private array $aliases = [];

    /** @var list<array{method: string, regex: string, handler: array{0: class-string, 1: string}, middleware: list<string>}> */
    private array $routes = [];

    /**
     * Registers a middleware alias usable in route definitions.
     *
     * @param class-string<Middleware> $class
     */
    public function alias(string $name, string $class): void
    {
        $this->aliases[$name] = $class;
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /**
     * Finds the matching route and runs it through its middleware.
     *
     * @throws HttpException 404 when no path matches, 405 when the path exists for another method.
     */
    public function dispatch(Request $request): Response
    {
        // HEAD is answered by the GET route (the body is discarded by the web server).
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path(), $matches) !== 1) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }

            // Keep only the named groups ("id" => "42"), not the numeric ones.
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            return $this->run($route, $request, $params);
        }

        if ($allowed !== []) {
            throw new HttpException(405, null, ['Allow' => implode(', ', array_unique($allowed))]);
        }

        throw new HttpException(404);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    private function add(string $method, string $path, array $handler, array $middleware): void
    {
        [$class, $action] = $handler;
        if (!method_exists($class, $action)) {
            // Fail at boot rather than at the first visit to a mistyped route.
            throw new LogicException(sprintf('Route %s %s: %s::%s() does not exist.', $method, $path, $class, $action));
        }

        $this->routes[] = [
            'method'     => $method,
            'regex'      => $this->compile($path),
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    /** Converts "/notices/{id:\d+}" into "#^/notices/(?P<id>\d+)$#". */
    private function compile(string $path): string
    {
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#',
            static fn (array $m): string => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $path
        );

        return '#^' . $regex . '$#';
    }

    /**
     * Builds the middleware "onion" around the controller call and runs it.
     *
     * @param array{method: string, regex: string, handler: array{0: class-string, 1: string}, middleware: list<string>} $route
     * @param array<string, string> $params
     */
    private function run(array $route, Request $request, array $params): Response
    {
        [$class, $action] = $route['handler'];

        $pipeline = static function (Request $request) use ($class, $action, $params): Response {
            $controller = new $class($request);

            return $controller->$action(...$params);
        };

        // Wrap from the last middleware to the first so the first listed runs first.
        foreach (array_reverse($route['middleware']) as $spec) {
            [$name, $arguments] = array_pad(explode(':', $spec, 2), 2, null);
            $middlewareClass = $this->aliases[$name]
                ?? throw new LogicException(sprintf('Unknown middleware "%s".', $name));
            $middleware = new $middlewareClass($arguments === null ? [] : explode(',', $arguments));

            $next = $pipeline;
            $pipeline = static fn (Request $request): Response => $middleware->handle($request, $next);
        }

        return $pipeline($request);
    }
}
```

`routes/web.php`

```php
<?php

/**
 * Route table. Returns the configured Router to public/index.php.
 *
 * Middleware run left to right:
 *   guest   - only for logged-out visitors
 *   auth    - only for logged-in users
 *   tenant  - resolves the condominium (must come after auth)
 *   role:.. - requireRole([...]) (must come after tenant, which refreshes the role)
 *   csrf    - required on every POST
 */

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\NoticeController;
use App\Controllers\TenantController;
use App\Controllers\VerificationController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\RoleMiddleware;
use App\Middleware\TenantMiddleware;

$router = new Router();

$router->alias('guest', GuestMiddleware::class);
$router->alias('auth', AuthMiddleware::class);
$router->alias('tenant', TenantMiddleware::class);
$router->alias('role', RoleMiddleware::class);
$router->alias('csrf', CsrfMiddleware::class);

// --- Authentication ---------------------------------------------------------
$router->get('/', [DashboardController::class, 'home']);
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);
$router->post('/logout', [AuthController::class, 'logout'], ['auth', 'csrf']);

// --- E-mail verification (works logged out: the user cannot log in yet) ------
$router->get('/verify-email', [VerificationController::class, 'show']);
$router->post('/verify-email', [VerificationController::class, 'verify'], ['csrf']);
$router->get('/verify-email/resend', [VerificationController::class, 'showResend'], ['guest']);
$router->post('/verify-email/resend', [VerificationController::class, 'resend'], ['guest', 'csrf']);

// --- Tenant selection ---------------------------------------------------------
$router->get('/select-condominium', [TenantController::class, 'select'], ['auth']);
$router->post('/select-condominium', [TenantController::class, 'choose'], ['auth', 'csrf']);

// --- Dashboard / Notice Board -------------------------------------------------
$router->get('/dashboard', [DashboardController::class, 'index'], ['auth', 'tenant']);
$router->get('/api/notices', [NoticeController::class, 'index'], ['auth', 'tenant']);
$router->post('/api/notices', [NoticeController::class, 'store'], [
    'auth',
    'tenant',
    'role:' . implode(',', NoticeController::CREATOR_ROLES),
    'csrf',
]);

return $router;
```


### 2.4 Base Controller, base Models and Views

`Model` serves global tables. `TenantModel` (§3.4) serves every table that has a `condominium_id`. Column names in the generic `insert()` and `update()` come only from each model's `$fillable` whitelist, and all values are bound parameters.

`app/Core/Controller.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base class for every controller.
 *
 * Controllers stay thin: read input from $this->request, call a model or
 * service, return a Response. Multi-table logic lives in app/Services.
 */
abstract class Controller
{
    public function __construct(protected readonly Request $request)
    {
    }

    /**
     * Renders a view inside a layout, adding the data every layout needs.
     *
     * @param array<string, mixed> $data
     */
    protected function view(string $template, array $data = [], int $status = 200, string $layout = 'layouts/app'): Response
    {
        $user = Auth::user();
        $data += [
            'title'           => 'Koinon',
            'activeNav'       => '',
            'scripts'         => [],
            'currentUserName' => $user['full_name'] ?? null,
            'currentRole'     => Auth::roleLabel(),
            'isSuperAdmin'    => Auth::isSuperAdmin(),
            'tenantName'      => TenantContext::name(),
            'flashes'         => Session::pullFlashes(['success', 'warning', 'error']),
        ];

        return Response::html(View::render($template, $data, $layout), $status);
    }

    /** JSON response for fetch() endpoints. */
    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /** Redirect to a path inside the application. */
    protected function redirect(string $path): Response
    {
        return Response::redirect($path);
    }

    /**
     * Aborts with 403 unless the user has one of the roles. Use it inside
     * actions as defence in depth, or when the rule depends on the record.
     *
     * @param list<string> $roles
     * @throws HttpException
     */
    protected function requireRole(array $roles): void
    {
        if (!Auth::hasRole($roles)) {
            throw new HttpException(403);
        }
    }

    /**
     * The authenticated user (routes using this are behind the "auth" middleware).
     *
     * @return array<string, mixed>
     * @throws HttpException
     */
    protected function user(): array
    {
        return Auth::user() ?? throw new HttpException(401);
    }
}
```

`app/Core/Model.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use PDO;
use PDOStatement;

/**
 * Base class for models of GLOBAL tables (users, condominiums, roles, tokens...).
 *
 * Models of tenant-scoped tables must extend TenantModel instead. That rule is
 * what keeps tenant data isolated, so it is enforced by code review.
 *
 * SQL safety: values are always bound parameters. Column and table names
 * cannot be bound, so the generic insert()/update() helpers only accept columns
 * listed in $fillable (a whitelist written by the developer, never by the user).
 */
abstract class Model
{
    /** Table name, set by each subclass. */
    protected string $table;

    /** @var list<string> Columns writable through insert()/update(). */
    protected array $fillable = [];

    /** Returns one row by primary key, or null. */
    public function find(int $id): ?array
    {
        return $this->selectWhere(['id' => $id]);
    }

    /**
     * Inserts a row with the whitelisted columns from $data.
     *
     * @param array<string, mixed> $data
     * @return int The new row's id.
     */
    public function insert(array $data): int
    {
        return $this->insertRow($this->onlyFillable($data));
    }

    /**
     * Updates whitelisted columns of a row by primary key.
     *
     * @param array<string, mixed> $data
     * @return int Number of rows changed.
     */
    public function update(int $id, array $data): int
    {
        return $this->updateWhere($this->onlyFillable($data), ['id' => $id]);
    }

    protected function db(): PDO
    {
        return Database::connection();
    }

    /**
     * Prepares and executes a statement, binding each value with the PDO type
     * that matches its PHP type. Explicit types matter with real prepared
     * statements: e.g. LIMIT rejects a value bound as a string.
     *
     * @param array<string, mixed> $params
     */
    protected function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->db()->prepare($sql);
        foreach ($params as $name => $value) {
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $statement->bindValue(':' . $name, $value, $type);
        }
        $statement->execute();

        return $statement;
    }

    /** @param array<string, mixed> $params */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<string, mixed> $params
     * @return int Affected rows.
     */
    protected function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * SELECT * ... WHERE col = :col AND ... LIMIT 1. $where keys come from code only.
     *
     * @param array<string, mixed> $where
     */
    protected function selectWhere(array $where): ?array
    {
        $sql = sprintf('SELECT * FROM `%s` WHERE %s LIMIT 1', $this->table, $this->conditions($where, 'w_'));

        return $this->fetchOne($sql, $this->prefixKeys($where, 'w_'));
    }

    /** @param array<string, mixed> $data Column => value; keys must already be whitelisted. */
    protected function insertRow(array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->table,
            implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        );
        $this->execute($sql, $data);

        return (int) $this->db()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $set   Columns to change (whitelisted).
     * @param array<string, mixed> $where Conditions (keys from code only).
     */
    protected function updateWhere(array $set, array $where): int
    {
        $assignments = implode(', ', array_map(
            static fn (string $c): string => sprintf('`%s` = :s_%s', $c, $c),
            array_keys($set)
        ));
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $this->table, $assignments, $this->conditions($where, 'w_'));

        return $this->execute($sql, $this->prefixKeys($set, 's_') + $this->prefixKeys($where, 'w_'));
    }

    /**
     * Keeps only whitelisted columns; rejects an empty result so a typo cannot
     * silently produce "UPDATE t SET  WHERE ...".
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function onlyFillable(array $data): array
    {
        $filtered = array_intersect_key($data, array_flip($this->fillable));
        if ($filtered === []) {
            throw new InvalidArgumentException(sprintf('No writable columns given for table "%s".', $this->table));
        }

        return $filtered;
    }

    /** @param array<string, mixed> $where */
    private function conditions(array $where, string $prefix): string
    {
        return implode(' AND ', array_map(
            static fn (string $c): string => sprintf('`%s` = :%s%s', $c, $prefix, $c),
            array_keys($where)
        ));
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function prefixKeys(array $values, string $prefix): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[$prefix . $key] = $value;
        }

        return $result;
    }
}
```

`app/Core/View.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

use LogicException;
use Throwable;

/**
 * Renders PHP templates from app/Views, optionally inside a layout.
 *
 * Templates must print every dynamic value through e(). The only exception is
 * $content inside a layout: it is the HTML produced by an already-escaped
 * template, so escaping it again would print tags as text.
 */
final class View
{
    /**
     * @param string               $template e.g. "dashboard/index" (app/Views/dashboard/index.php)
     * @param array<string, mixed> $data     Variables available inside the template.
     * @param string|null          $layout   Layout template, or null for a bare fragment (e-mails).
     */
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }

        return self::renderFile($layout, ['content' => $content] + $data);
    }

    /** @param array<string, mixed> $data */
    private static function renderFile(string $template, array $data): string
    {
        // Template names come from code, but the whitelist also rules out "../" tricks.
        if (preg_match('#^[a-z0-9_\-/]+$#i', $template) !== 1 || str_contains($template, '..')) {
            throw new LogicException(sprintf('Invalid view name "%s".', $template));
        }

        $file = BASE_PATH . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new LogicException(sprintf('View "%s" not found.', $template));
        }

        // A static closure gives the template its own scope: it sees only $data,
        // never $this or the renderer's local variables.
        $render = static function (string $__file, array $__data): void {
            extract($__data, EXTR_SKIP);
            require $__file;
        };

        ob_start();
        try {
            $render($file, $data);
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
```

`app/Core/helpers.php`

```php
<?php

/**
 * Global view helpers, autoloaded through composer.json "files".
 */

declare(strict_types=1);

use App\Core\Csrf;

if (!function_exists('e')) {
    /**
     * Escapes a value for HTML text and attribute contexts.
     *
     * ENT_QUOTES escapes both quote types (safe inside attributes);
     * ENT_SUBSTITUTE replaces invalid UTF-8 instead of returning an empty string.
     */
    function e(string|int|float|bool|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    /** The current session's CSRF token (for the <meta> tag read by JavaScript). */
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    /** Hidden input carrying the CSRF token; required in every POST form. */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
    }
}

if (!function_exists('asset')) {
    /**
     * URL of a file in public/assets with a cache-busting version (file mtime),
     * so browsers can cache assets for long periods yet pick up new deployments.
     */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = BASE_PATH . '/public/assets/' . $path;
        $version = is_file($file) ? (string) filemtime($file) : '0';

        return '/assets/' . $path . '?v=' . $version;
    }
}
```


---

## 3. Authentication and Authorization

### 3.1 How the pieces fit

```
POST /login ─► csrf ─► AuthController::login ─► AuthService::attempt
                                                   ├─ unknown e-mail ─► dummy password_verify ─► INVALID
                                                   ├─ locked ─────────────────────────────────► INVALID
                                                   ├─ wrong password ─► failed_login_count++ ─► INVALID
                                                   ├─ blocked / deleted ──────────────────────► INVALID
                                                   ├─ email_verified_at IS NULL ─────────────► UNVERIFIED (offer resend)
                                                   └─ OK ─► rehash if needed ─► SUCCESS
             SUCCESS ─► Super Admin ─────────────► Auth::login(user, null)       ─► /select-condominium
                     ─► 1 active membership ─────► Auth::login(user, membership) ─► /dashboard
                     ─► several memberships ─────► Auth::login(user, null)       ─► /select-condominium
                     ─► none ────────────────────► "awaiting approval" (no session)

Every protected request:  auth ─► tenant ─► role:… ─► csrf (POST) ─► controller
```

### 3.2 Login, sessions, CSRF

`app/Core/Session.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Hardened wrapper around PHP's native sessions.
 *
 * Security decisions:
 *  - Cookie: HttpOnly (JavaScript cannot read it, which limits XSS session theft),
 *    Secure (HTTPS only), SameSite=Lax (not sent on cross-site POSTs, which is a
 *    second layer of CSRF defence), lifetime 0 (deleted when the browser closes).
 *  - use_strict_mode: PHP refuses session ids it did not create, which blocks
 *    session fixation through a planted cookie.
 *  - Idle and absolute timeouts are enforced here, server-side, because the
 *    cookie lifetime alone is controlled by the client.
 *  - Files are stored in storage/sessions, outside the web root.
 */
final class Session
{
    private const FLASH_KEY = '_flash';

    /** Starts the session with the hardened settings and enforces timeouts. */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $config = Config::get('session');

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) ($config['idle_minutes'] * 60));

        session_save_path($config['save_path']);
        session_name($config['name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (bool) $config['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        self::enforceTimeouts($config);
    }

    /**
     * Issues a new session id and deletes the old session file. Called on
     * login, logout and tenant switch so an id observed before a privilege
     * change is useless afterwards.
     */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /** Removes every value from the current session (keeps the session itself). */
    public static function clear(): void
    {
        $_SESSION = [];
    }

    /**
     * Fully ends the session: empties it, expires the cookie in the browser and
     * deletes the server-side file. A fresh, empty session is started so the
     * caller can still flash a message (e.g. "you have logged out").
     */
    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
        session_destroy();

        // Strict mode rejects the old id still present in the request cookie,
        // so this creates a brand-new id.
        self::start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string ...$keys): void
    {
        foreach ($keys as $key) {
            unset($_SESSION[$key]);
        }
    }

    /** Stores a value that survives exactly one redirect (messages, old input). */
    public static function flash(string $key, string $value): void
    {
        $_SESSION[self::FLASH_KEY][$key] = $value;
    }

    /** Returns and removes a flashed value. */
    public static function pullFlash(string $key): ?string
    {
        $value = $_SESSION[self::FLASH_KEY][$key] ?? null;
        unset($_SESSION[self::FLASH_KEY][$key]);

        return is_string($value) ? $value : null;
    }

    /**
     * Returns and removes several flashed values at once, keyed by name.
     *
     * @param list<string> $keys
     * @return array<string, string>
     */
    public static function pullFlashes(array $keys): array
    {
        $messages = [];
        foreach ($keys as $key) {
            $value = self::pullFlash($key);
            if ($value !== null) {
                $messages[$key] = $value;
            }
        }

        return $messages;
    }

    /**
     * Ends a logged-in session after SESSION_IDLE_MINUTES of inactivity or
     * SESSION_ABSOLUTE_HOURS since login, whichever comes first.
     *
     * @param array<string, mixed> $config
     */
    private static function enforceTimeouts(array $config): void
    {
        $now = time();
        $lastActivity = $_SESSION['_last_activity'] ?? null;
        $createdAt = $_SESSION['_created_at'] ?? null;

        $idleExpired = is_int($lastActivity) && $now - $lastActivity > $config['idle_minutes'] * 60;
        $absoluteExpired = is_int($createdAt) && $now - $createdAt > $config['absolute_hours'] * 3600;

        if (isset($_SESSION['user_id']) && ($idleExpired || $absoluteExpired)) {
            self::clear();
            self::regenerate();
            self::flash('warning', 'Sua sessão expirou por inatividade. Entre novamente.');
        }

        $_SESSION['_created_at'] ??= $now;
        $_SESSION['_last_activity'] = $now;
    }
}
```

`app/Core/Csrf.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Synchronizer-token CSRF protection.
 *
 * One random token per session. Forms send it in a hidden "_csrf" field
 * (csrf_field()); fetch() sends it in the X-CSRF-Token header, read from the
 * <meta name="csrf-token"> tag. A malicious site can make the browser submit a
 * form here, but it cannot read the token, so the forged request is rejected.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    /** Returns the session's token, creating it on first use. */
    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /** Replaces the token (on login), so a token seen before login is worthless. */
    public static function rotate(): void
    {
        Session::forget(self::SESSION_KEY);
        self::token();
    }

    /** Constant-time comparison: timing does not reveal how many characters matched. */
    public static function validate(?string $token): bool
    {
        $expected = Session::get(self::SESSION_KEY);

        return is_string($token) && $token !== ''
            && is_string($expected) && $expected !== ''
            && hash_equals($expected, $token);
    }
}
```

`app/Core/Auth.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/**
 * The authenticated user of the current request.
 *
 * Session layout after login:
 *   user_id          int       users.id
 *   session_version  int       copy of users.session_version at login
 *   condominium_id   int|null  active membership's tenant; NULL for a Super Admin
 *   role_id          int|null  condominium_users.role_id; NULL for a Super Admin
 *   role_code        str|null  roles.code ("manager", "concierge", "resident")
 *   admin_condominium_id int   (Super Admin only) tenant chosen in the selector
 *
 * The user row is re-read from the database on every request. A blocked user,
 * or a password change (which increments users.session_version), therefore
 * ends every existing session immediately, not when the cookie expires.
 */
final class Auth
{
    /** Pseudo-role used in role checks for users.is_super_admin = TRUE. */
    public const SUPER_ADMIN = 'super_admin';

    private const ROLE_LABELS = [
        self::SUPER_ADMIN => 'Super Admin',
        'manager'         => 'Síndico / Administração',
        'concierge'       => 'Portaria / Segurança',
        'resident'        => 'Morador',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $user = null;
    private static bool $resolved = false;

    /**
     * Starts an authenticated session.
     *
     * @param array<string, mixed>      $user       Row from `users`.
     * @param array<string, mixed>|null $membership Active membership to enter, or null
     *                                              (Super Admin, or user who must choose).
     */
    public static function login(array $user, ?array $membership): void
    {
        // New session id: an id planted or observed before login is now worthless
        // (session fixation). Old data (e.g. pre-login values) is dropped too.
        Session::regenerate();
        Session::clear();
        Csrf::rotate();

        Session::set('user_id', (int) $user['id']);
        Session::set('session_version', (int) $user['session_version']);
        Session::set('_created_at', time()); // absolute timeout counts from login

        self::enterMembership($membership);

        self::$user = $user;
        self::$resolved = true;
    }

    /**
     * Stores the tenant and role of a membership in the session (or clears them).
     *
     * @param array<string, mixed>|null $membership Row from Membership::findActive().
     */
    public static function enterMembership(?array $membership): void
    {
        Session::set('condominium_id', $membership === null ? null : (int) $membership['condominium_id']);
        Session::set('role_id', $membership === null ? null : (int) $membership['role_id']);
        Session::set('role_code', $membership === null ? null : (string) $membership['role_code']);
    }

    /** Ends the session completely (server file, cookie and in-memory state). */
    public static function logout(): void
    {
        self::$user = null;
        self::$resolved = true;
        Session::destroy();
    }

    /**
     * The logged-in user's row, or null. A session whose user was blocked or
     * deleted, or whose session_version is stale, is destroyed here.
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $userId = Session::get('user_id');
        if (!is_int($userId)) {
            return null;
        }

        $user = (new User())->find($userId);
        $valid = $user !== null
            && $user['status'] === 'active'
            && (int) $user['session_version'] === Session::get('session_version');

        if (!$valid) {
            Logger::info('Session invalidated', ['user_id' => $userId]);
            self::logout();

            return null;
        }

        return self::$user = $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    /** Read from the database row, never from the session or the request. */
    public static function isSuperAdmin(): bool
    {
        return (bool) (self::user()['is_super_admin'] ?? false);
    }

    /** "super_admin", the membership's role code, or null when no tenant was entered. */
    public static function roleCode(): ?string
    {
        if (!self::check()) {
            return null;
        }
        if (self::isSuperAdmin()) {
            return self::SUPER_ADMIN;
        }
        $role = Session::get('role_code');

        return is_string($role) ? $role : null;
    }

    /**
     * True when the current user has one of the given roles.
     *
     * @param list<string> $roles e.g. [Auth::SUPER_ADMIN, 'manager']
     */
    public static function hasRole(array $roles): bool
    {
        $role = self::roleCode();

        return $role !== null && in_array($role, $roles, true);
    }

    /** Human-readable name of the current role, for the header. */
    public static function roleLabel(): ?string
    {
        $role = self::roleCode();

        return $role === null ? null : (self::ROLE_LABELS[$role] ?? $role);
    }
}
```

`app/Models/User.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Global identity table `users` (not tenant-scoped: one person, one row,
 * possibly members of several condominiums through condominium_users).
 */
final class User extends Model
{
    protected string $table = 'users';

    protected array $fillable = [
        'full_name',
        'email',
        'password_hash',
        'phone',
        'is_super_admin',
        'status',
        'email_verified_at',
    ];

    /** E-mails are stored trimmed and lower-cased; callers pass raw input. */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            ['email' => self::normalizeEmail($email)]
        );
    }

    /** Locks the user row for the rest of the current transaction. */
    public function findForUpdate(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM users WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    /** True while users.locked_until (UTC) is in the future. */
    public function isLocked(array $user): bool
    {
        if ($user['locked_until'] === null) {
            return false;
        }
        $until = new DateTimeImmutable((string) $user['locked_until'], new DateTimeZone('UTC'));

        return $until > new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Counts a failed password and locks the account when the limit is reached.
     *
     * One atomic UPDATE, so parallel guesses cannot race past the limit.
     * locked_until is assigned BEFORE failed_login_count because MySQL evaluates
     * SET clauses left to right: it must still see the old counter value.
     */
    public function recordFailedLogin(int $id, int $maxAttempts, int $lockMinutes): void
    {
        $this->execute(
            'UPDATE users
                SET locked_until = IF(failed_login_count + 1 >= :max_a,
                                      UTC_TIMESTAMP() + INTERVAL :lock_minutes MINUTE,
                                      locked_until),
                    failed_login_count = IF(failed_login_count + 1 >= :max_b, 0, failed_login_count + 1)
              WHERE id = :id',
            ['max_a' => $maxAttempts, 'max_b' => $maxAttempts, 'lock_minutes' => $lockMinutes, 'id' => $id]
        );
    }

    /** Resets the failure counter and, when given, stores an upgraded password hash. */
    public function recordSuccessfulLogin(int $id, ?string $rehashed): void
    {
        $this->execute(
            'UPDATE users
                SET failed_login_count = 0,
                    locked_until = NULL,
                    last_login_at = UTC_TIMESTAMP(),
                    password_hash = COALESCE(:rehashed, password_hash)
              WHERE id = :id',
            ['rehashed' => $rehashed, 'id' => $id]
        );
    }

    /**
     * Activates the account after e-mail verification. $passwordHash is set for
     * invited users, who choose their password on the activation page.
     */
    public function markEmailVerified(int $id, ?string $passwordHash): void
    {
        $this->execute(
            "UPDATE users
                SET email_verified_at = UTC_TIMESTAMP(),
                    status = 'active',
                    password_hash = COALESCE(:password_hash, password_hash)
              WHERE id = :id",
            ['password_hash' => $passwordHash, 'id' => $id]
        );
    }
}
```

`app/Models/AuditLog.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Request;

/**
 * Append-only security trail `audit_logs` (mixed scope: condominium_id is NULL
 * for platform-level events such as logins).
 */
final class AuditLog extends Model
{
    protected string $table = 'audit_logs';

    /**
     * @param string               $action        e.g. "auth.login_failed", "notice.published"
     * @param array<string, mixed> $details       Extra context. Never passwords or tokens.
     */
    public function record(
        string $action,
        Request $request,
        ?int $actorUserId = null,
        ?int $condominiumId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = []
    ): void {
        $this->execute(
            'INSERT INTO audit_logs
                (condominium_id, actor_user_id, action_code, entity_type, entity_id, details, ip_address, user_agent)
             VALUES
                (:condominium_id, :actor_user_id, :action_code, :entity_type, :entity_id, :details, INET6_ATON(:ip), :user_agent)',
            [
                'condominium_id' => $condominiumId,
                'actor_user_id'  => $actorUserId,
                'action_code'    => $action,
                'entity_type'    => $entityType,
                'entity_id'      => $entityId,
                'details'        => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'ip'             => $request->ip(),
                'user_agent'     => $request->userAgent(),
            ]
        );
    }
}
```

`app/Services/LoginResult.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Outcome of AuthService::attempt().
 *
 * INVALID covers unknown e-mail, wrong password, locked, blocked and deleted
 * accounts alike, so the login form shows one generic message for all of them
 * and nobody can probe which e-mails are registered.
 */
final class LoginResult
{
    public const SUCCESS = 'success';
    public const INVALID = 'invalid';
    public const UNVERIFIED = 'unverified';

    /** @param array<string, mixed>|null $user */
    private function __construct(
        public readonly string $status,
        public readonly ?array $user = null
    ) {
    }

    /** @param array<string, mixed> $user */
    public static function success(array $user): self
    {
        return new self(self::SUCCESS, $user);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID);
    }

    /** @param array<string, mixed> $user */
    public static function unverified(array $user): self
    {
        return new self(self::UNVERIFIED, $user);
    }
}
```

`app/Services/AuthService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\User;

/**
 * Credential checking for the login form.
 */
final class AuthService
{
    /**
     * bcrypt hash of a throwaway string. When the e-mail is unknown we still run
     * password_verify() against it, so "no such user" takes as long as "wrong
     * password" and response time does not reveal which e-mails exist.
     */
    private const DUMMY_HASH = '$2y$12$m0Zd22rlmFIfn6jg/q/Alug9GjO9s0K6bVR7MWeXK6AXppBOmNyZ.';

    public function __construct(
        private readonly User $users = new User(),
        private readonly AuditLog $audit = new AuditLog()
    ) {
    }

    /**
     * Checks an e-mail/password pair.
     *
     * Order matters: the "unverified e-mail" answer is only given AFTER the
     * password was proven correct. Otherwise anyone could type an e-mail and
     * learn that an unverified account exists for it.
     */
    public function attempt(string $email, string $password, Request $request): LoginResult
    {
        $email = User::normalizeEmail($email);
        $maxLength = (int) Config::get('security.password_max', 128);

        if ($email === '' || $password === '' || strlen($password) > $maxLength * 4) {
            return LoginResult::invalid();
        }

        $user = $this->users->findByEmail($email);

        // Unknown e-mail, or invited user who has not set a password yet.
        if ($user === null || $user['password_hash'] === null) {
            password_verify($password, self::DUMMY_HASH);
            $this->audit->record('auth.login_failed', $request, details: ['email' => $email, 'reason' => 'unknown']);

            return LoginResult::invalid();
        }

        $userId = (int) $user['id'];

        // While locked, even the right password is refused (and not checked against
        // the real hash), which stops brute force from continuing during the lock.
        if ($this->users->isLocked($user)) {
            password_verify($password, self::DUMMY_HASH);
            $this->audit->record('auth.login_locked', $request, $userId);

            return LoginResult::invalid();
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->users->recordFailedLogin(
                $userId,
                (int) Config::get('security.login_max_attempts', 5),
                (int) Config::get('security.login_lock_minutes', 15)
            );
            $this->audit->record('auth.login_failed', $request, $userId, details: ['reason' => 'password']);

            return LoginResult::invalid();
        }

        if ($user['status'] === 'blocked' || $user['status'] === 'deleted') {
            $this->audit->record('auth.login_failed', $request, $userId, details: ['reason' => $user['status']]);

            return LoginResult::invalid();
        }

        // E-mail verification gate.
        if ($user['email_verified_at'] === null || $user['status'] !== 'active') {
            $this->audit->record('auth.login_unverified', $request, $userId);

            return LoginResult::unverified($user);
        }

        // Transparently upgrade the hash when PHP's default algorithm/cost changed.
        $algorithm = Config::get('security.password_algo', PASSWORD_DEFAULT);
        $options = Config::get('security.password_options', []);
        $rehashed = password_needs_rehash((string) $user['password_hash'], $algorithm, $options)
            ? password_hash($password, $algorithm, $options)
            : null;

        $this->users->recordSuccessfulLogin($userId, $rehashed);
        $this->audit->record('auth.login_succeeded', $request, $userId);

        return LoginResult::success($user);
    }
}
```

`app/Controllers/AuthController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Models\Membership;
use App\Services\AuthService;
use App\Services\LoginResult;

/**
 * Login and logout.
 */
final class AuthController extends Controller
{
    /** One message for every failure cause, so the form reveals nothing about accounts. */
    private const GENERIC_FAILURE = 'E-mail ou senha inválidos.';

    /** GET /login */
    public function showLogin(): Response
    {
        return $this->view('auth/login', [
            'title'           => 'Entrar',
            'email'           => Session::pullFlash('old_email') ?? '',
            'unverifiedEmail' => Session::pullFlash('unverified_email'),
        ], layout: 'layouts/auth');
    }

    /** POST /login (CSRF-protected by the route). */
    public function login(): Response
    {
        $email = $this->request->string('email');
        $result = (new AuthService())->attempt($email, $this->request->string('password'), $this->request);

        if ($result->status === LoginResult::INVALID) {
            Session::flash('error', self::GENERIC_FAILURE);
            Session::flash('old_email', $email);

            return $this->redirect('/login');
        }

        if ($result->status === LoginResult::UNVERIFIED) {
            // Only reached with the correct password, so this tells the owner,
            // not a stranger, that the account is waiting for confirmation.
            Session::flash('warning', 'Você precisa confirmar seu e-mail antes de entrar. Verifique sua caixa de entrada.');
            Session::flash('unverified_email', (string) $result->user['email']);
            Session::flash('old_email', $email);

            return $this->redirect('/login');
        }

        $user = $result->user;

        // Super Admin: global, no membership. Chooses a condominium to manage.
        if ((bool) $user['is_super_admin']) {
            Auth::login($user, null);

            return $this->redirect('/select-condominium');
        }

        $memberships = (new Membership())->activeForUser((int) $user['id']);
        if ($memberships === []) {
            Session::flash('error', 'Sua conta ainda não tem acesso ativo a nenhum condomínio. Aguarde a aprovação da administração.');

            return $this->redirect('/login');
        }

        // Exactly one condominium: enter it. Several: let the user choose.
        $single = count($memberships) === 1 ? $memberships[0] : null;
        Auth::login($user, $single);

        return $this->redirect($single !== null ? '/dashboard' : '/select-condominium');
    }

    /** POST /logout. A POST with CSRF token, so another site cannot log users out. */
    public function logout(): Response
    {
        Auth::logout();
        Session::flash('success', 'Você saiu com segurança.');

        return $this->redirect('/login');
    }
}
```


### 3.3 E-mail verification and mailer

1. `EmailVerificationService::sendNew()` generates `bin2hex(random_bytes(32))`, stores only `hash('sha256', …)` with `expires_at = UTC_TIMESTAMP() + 24 h`, revokes older tokens, and sends the link after the transaction commits.
2. `GET /verify-email?token=…` (`inspect()`) is read-only and shows a "Confirm" button, or password fields for invited users.
3. `POST /verify-email` (`verify()`) locks the token row with `FOR UPDATE`, re-checks it, sets `consumed_at`, sets `users.email_verified_at` and `status = 'active'`, and revokes every other token of that user, all in one transaction.
4. `POST /verify-email/resend` is throttled and always gives the same answer.

`app/Models/EmailVerificationToken.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Activation tokens `email_verification_tokens` (global: they belong to a user,
 * not to a tenant).
 *
 * Only SHA-256 hashes are stored. A database leak therefore does not reveal any
 * usable activation link. Times are computed by MySQL (UTC_TIMESTAMP()) so that
 * expiry never depends on the PHP server's clock.
 */
final class EmailVerificationToken extends Model
{
    protected string $table = 'email_verification_tokens';

    /** Stores a new token hash valid for $ttlHours. */
    public function create(int $userId, string $tokenHash, string $ip, int $ttlHours): void
    {
        $this->execute(
            'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, request_ip)
             VALUES (:user_id, :token_hash, UTC_TIMESTAMP() + INTERVAL :ttl HOUR, INET6_ATON(:ip))',
            ['user_id' => $userId, 'token_hash' => $tokenHash, 'ttl' => $ttlHours, 'ip' => $ip]
        );
    }

    /**
     * Finds a token by hash. `is_expired` is computed by MySQL. With $forUpdate
     * the row stays locked until the transaction ends, so two simultaneous
     * clicks cannot both consume the same token.
     */
    public function findByHash(string $tokenHash, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT id, user_id, consumed_at, revoked_at,
                       (expires_at <= UTC_TIMESTAMP()) AS is_expired
                  FROM email_verification_tokens
                 WHERE token_hash = :token_hash'
            . ($forUpdate ? ' FOR UPDATE' : '');

        return $this->fetchOne($sql, ['token_hash' => $tokenHash]);
    }

    /** Marks a token as used (single use). */
    public function markConsumed(int $id): void
    {
        $this->execute(
            'UPDATE email_verification_tokens SET consumed_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $id]
        );
    }

    /** Revokes every still-usable token of a user (on resend and after activation). */
    public function revokeOutstanding(int $userId): void
    {
        $this->execute(
            'UPDATE email_verification_tokens
                SET revoked_at = UTC_TIMESTAMP()
              WHERE user_id = :user_id AND consumed_at IS NULL AND revoked_at IS NULL',
            ['user_id' => $userId]
        );
    }

    /** Seconds since the user's most recent token, or null if there is none. */
    public function secondsSinceLast(int $userId): ?int
    {
        $row = $this->fetchOne(
            'SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), UTC_TIMESTAMP()) AS seconds
               FROM email_verification_tokens WHERE user_id = :user_id',
            ['user_id' => $userId]
        );

        return ($row === null || $row['seconds'] === null) ? null : (int) $row['seconds'];
    }

    /** Number of tokens created for the user in the last $hours hours. */
    public function countSince(int $userId, int $hours): int
    {
        $row = $this->fetchOne(
            'SELECT COUNT(*) AS total FROM email_verification_tokens
              WHERE user_id = :user_id AND created_at > UTC_TIMESTAMP() - INTERVAL :hours HOUR',
            ['user_id' => $userId, 'hours' => $hours]
        );

        return (int) ($row['total'] ?? 0);
    }
}
```

`app/Services/VerificationResult.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Outcome of checking or consuming an e-mail verification token.
 */
final class VerificationResult
{
    /** Token usable: show the confirm button / account activated. */
    public const VALID = 'valid';
    /** Token older than 24 h: offer a new link. */
    public const EXPIRED = 'expired';
    /** Token already consumed or account already verified: send to login. */
    public const ALREADY_USED = 'already_used';
    /** Unknown, malformed or revoked token. */
    public const INVALID = 'invalid';
    /** Invited user must choose a password to activate (no password given). */
    public const PASSWORD_REQUIRED = 'password_required';

    /** @param array<string, mixed>|null $user */
    public function __construct(
        public readonly string $state,
        public readonly ?array $user = null
    ) {
    }

    public function isValid(): bool
    {
        return $this->state === self::VALID;
    }

    /** Invited users have no password yet and choose it on the activation page. */
    public function needsPassword(): bool
    {
        return $this->user !== null && $this->user['password_hash'] === null;
    }
}
```

`app/Services/EmailVerificationService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Mail\Mailer;
use App\Models\AuditLog;
use App\Models\EmailVerificationToken;
use App\Models\User;

/**
 * Account activation by e-mail (Phase 1, FR-AUTH-12 to FR-AUTH-17).
 *
 *  1. sendNew(): 32 random bytes -> 64 hex chars. Only hash('sha256', raw) is
 *     stored; the raw token exists only in the e-mailed link.
 *  2. inspect(): used by GET /verify-email to show the right page. Read-only:
 *     link scanners in mail clients may open the link, so GET never consumes.
 *  3. verify(): POST /verify-email. In one transaction, locks the token row,
 *     re-checks it, consumes it, activates the user and revokes other tokens.
 *  4. resend(): throttled (60 s cooldown, 5 per 24 h) and silent, so it reveals
 *     nothing about which e-mails exist.
 */
final class EmailVerificationService
{
    public function __construct(
        private readonly EmailVerificationToken $tokens = new EmailVerificationToken(),
        private readonly User $users = new User(),
        private readonly Mailer $mailer = new Mailer(),
        private readonly AuditLog $audit = new AuditLog()
    ) {
    }

    /**
     * Revokes the user's outstanding tokens, creates a new one and e-mails it.
     *
     * @param array<string, mixed> $user
     * @return bool False when the e-mail could not be sent (the user can resend).
     */
    public function sendNew(array $user, string $ip): bool
    {
        $userId = (int) $user['id'];
        $rawToken = bin2hex(random_bytes(32));

        Database::transaction(function () use ($userId, $rawToken, $ip): void {
            $this->tokens->revokeOutstanding($userId);
            $this->tokens->create(
                $userId,
                hash('sha256', $rawToken),
                $ip,
                (int) Config::get('security.verification_ttl_hours', 24)
            );
        });

        // Sent after the commit: a slow SMTP server must never hold database locks.
        return $this->mailer->sendVerification((string) $user['email'], (string) $user['full_name'], $rawToken);
    }

    /**
     * Sends a new link if the account is still unverified and not throttled.
     * Always silent: the caller shows the same message whatever happened.
     */
    public function resend(string $email, Request $request): void
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || $user['status'] !== 'pending_verification') {
            return;
        }

        $userId = (int) $user['id'];
        $sinceLast = $this->tokens->secondsSinceLast($userId);
        $cooldown = (int) Config::get('security.verification_resend_cooldown', 60);
        $maxPerDay = (int) Config::get('security.verification_max_per_day', 5);

        if (($sinceLast !== null && $sinceLast < $cooldown) || $this->tokens->countSince($userId, 24) >= $maxPerDay) {
            $this->audit->record('auth.verification_resend_throttled', $request, $userId);

            return;
        }

        $this->sendNew($user, $request->ip());
        $this->audit->record('auth.verification_resent', $request, $userId);
    }

    /** Read-only check of a raw token (for the GET landing page). */
    public function inspect(string $rawToken): VerificationResult
    {
        if (!self::isWellFormed($rawToken)) {
            return new VerificationResult(VerificationResult::INVALID);
        }

        $token = $this->tokens->findByHash(hash('sha256', $rawToken));
        $user = $token === null ? null : $this->users->find((int) $token['user_id']);

        return new VerificationResult($this->evaluate($token, $user), $user);
    }

    /**
     * Consumes the token and activates the account.
     *
     * @param string|null $newPasswordHash Required for invited users (no password yet);
     *                                     must already be validated and hashed.
     */
    public function verify(string $rawToken, ?string $newPasswordHash, Request $request): VerificationResult
    {
        if (!self::isWellFormed($rawToken)) {
            return new VerificationResult(VerificationResult::INVALID);
        }

        return Database::transaction(function () use ($rawToken, $newPasswordHash, $request): VerificationResult {
            // Lock the token, then the user: concurrent submits are serialised,
            // and the second one sees consumed_at set and gets ALREADY_USED.
            $token = $this->tokens->findByHash(hash('sha256', $rawToken), true);
            $user = $token === null ? null : $this->users->findForUpdate((int) $token['user_id']);

            $state = $this->evaluate($token, $user);
            if ($state !== VerificationResult::VALID) {
                return new VerificationResult($state, $user);
            }
            if ($user['password_hash'] === null && $newPasswordHash === null) {
                return new VerificationResult(VerificationResult::PASSWORD_REQUIRED, $user);
            }

            $userId = (int) $user['id'];
            $this->tokens->markConsumed((int) $token['id']);
            // Only invited users (no password yet) may set one here; for everyone
            // else a submitted value is ignored, so this page cannot reset passwords.
            $this->users->markEmailVerified($userId, $user['password_hash'] === null ? $newPasswordHash : null);
            $this->tokens->revokeOutstanding($userId);
            $this->audit->record('auth.email_verified', $request, $userId);

            return new VerificationResult(VerificationResult::VALID, $user);
        });
    }

    /** Tokens are exactly 64 lowercase hex chars; anything else is rejected without a query. */
    private static function isWellFormed(string $rawToken): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $rawToken) === 1;
    }

    /**
     * @param array<string, mixed>|null $token
     * @param array<string, mixed>|null $user
     */
    private function evaluate(?array $token, ?array $user): string
    {
        if ($token === null || $user === null) {
            return VerificationResult::INVALID;
        }
        // Checked before "revoked": after activation the other tokens are revoked,
        // and clicking an older link should say "already activated", not "invalid".
        if ($token['consumed_at'] !== null || $user['email_verified_at'] !== null) {
            return VerificationResult::ALREADY_USED;
        }
        if ($token['revoked_at'] !== null || $user['status'] !== 'pending_verification') {
            return VerificationResult::INVALID;
        }
        if ((int) $token['is_expired'] === 1) {
            return VerificationResult::EXPIRED;
        }

        return VerificationResult::VALID;
    }
}
```

`app/Mail/Mailer.php`

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Config;
use App\Core\Logger;
use App\Core\View;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * The only class that talks to PHPMailer (Phase 1, NFR-LIB-02).
 *
 * PHPMailer is used because PHP's mail() cannot do SMTP authentication, TLS,
 * multipart HTML/text bodies or proper header encoding, and gets no useful error
 * back. Everything else depends on this adapter, so the library stays replaceable.
 */
final class Mailer
{
    /**
     * Sends one message. Returns false (after logging) instead of throwing, so a
     * mail outage never turns a successful action into an error page.
     */
    public function send(string $toEmail, string $toName, string $subject, string $html, string $text): bool
    {
        $config = Config::get('mail');

        if (!$config['enabled']) {
            return $this->writeDevelopmentLog($toEmail, $subject, $text);
        }

        if (!class_exists(PHPMailer::class)) {
            Logger::error('PHPMailer is not installed; run "composer install".');

            return false;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = (string) $config['host'];
            $mail->Port = (int) $config['port'];
            $mail->SMTPAuth = true;
            $mail->Username = (string) $config['username'];
            $mail->Password = (string) $config['password'];
            $mail->SMTPSecure = $config['encryption'] === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout = 10; // seconds; never keep the user waiting on a dead SMTP server
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            $mail->setFrom((string) $config['from_address'], (string) $config['from_name']);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $text;

            $mail->send();

            return true;
        } catch (PHPMailerException $e) {
            Logger::error('E-mail delivery failed', ['to' => $toEmail, 'error' => $mail->ErrorInfo]);

            return false;
        }
    }

    /** Sends the account activation link. */
    public function sendVerification(string $toEmail, string $toName, string $rawToken): bool
    {
        $link = Config::get('app.url') . '/verify-email?token=' . $rawToken;
        $hours = (int) Config::get('security.verification_ttl_hours', 24);

        $html = View::render('emails/verify', ['name' => $toName, 'link' => $link, 'hours' => $hours], null);
        $text = "Olá, {$toName}!\n\n"
            . "Confirme seu e-mail para ativar sua conta no Koinon:\n{$link}\n\n"
            . "O link vale por {$hours} horas e só pode ser usado uma vez.\n"
            . "Se você não criou esta conta, ignore este e-mail.\n";

        return $this->send($toEmail, $toName, 'Confirme seu e-mail - Koinon', $html, $text);
    }

    /**
     * Placeholder transport for development (MAIL_ENABLED=false): writes the
     * message to storage/logs/mail-dev.log so the activation flow can be tested
     * without an SMTP server.
     *
     * Refused unless APP_ENV=local, because that log contains raw activation
     * tokens, which must never be persisted in production (NFR-SEC-08).
     */
    private function writeDevelopmentLog(string $toEmail, string $subject, string $text): bool
    {
        if (Config::get('app.env') !== 'local') {
            Logger::error('MAIL_ENABLED=false outside APP_ENV=local: e-mail not sent.', ['to' => $toEmail]);

            return false;
        }

        $entry = sprintf("==== %s\nTo: %s\nSubject: %s\n\n%s\n", gmdate('c'), $toEmail, $subject, $text);
        file_put_contents(BASE_PATH . '/storage/logs/mail-dev.log', $entry, FILE_APPEND | LOCK_EX);

        return true;
    }
}
```

`app/Controllers/VerificationController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Services\EmailVerificationService;
use App\Services\VerificationResult;

/**
 * E-mail verification: link landing page, activation and resend.
 */
final class VerificationController extends Controller
{
    /**
     * GET /verify-email?token=...
     *
     * Only displays a confirmation button; nothing is consumed on GET because
     * e-mail security scanners open links automatically and would otherwise
     * burn the single-use token before the user clicks it.
     */
    public function show(): Response
    {
        $token = (string) $this->request->query('token', '');
        $result = (new EmailVerificationService())->inspect($token);

        return $this->verificationPage($token, $result, []);
    }

    /** POST /verify-email: consumes the token and activates the account. */
    public function verify(): Response
    {
        $token = $this->request->string('token');
        $service = new EmailVerificationService();
        $inspection = $service->inspect($token);

        $passwordHash = null;
        if ($inspection->isValid() && $inspection->needsPassword()) {
            $errors = $this->validateNewPassword();
            if ($errors !== []) {
                return $this->verificationPage($token, $inspection, $errors, 422);
            }
            $passwordHash = password_hash(
                $this->request->string('password'),
                Config::get('security.password_algo', PASSWORD_DEFAULT),
                Config::get('security.password_options', [])
            );
        }

        $result = $service->verify($token, $passwordHash, $this->request);
        if (!$result->isValid()) {
            return $this->verificationPage($token, $result, []);
        }

        Session::flash('success', 'E-mail confirmado! Sua conta está ativa, você já pode entrar.');

        return $this->redirect('/login');
    }

    /** GET /verify-email/resend */
    public function showResend(): Response
    {
        return $this->view('auth/resend', [
            'title' => 'Reenviar link de ativação',
            'email' => (string) $this->request->query('email', ''),
        ], layout: 'layouts/auth');
    }

    /**
     * POST /verify-email/resend. Always answers with the same message, whether
     * or not the e-mail exists, is pending, or was throttled.
     */
    public function resend(): Response
    {
        (new EmailVerificationService())->resend($this->request->string('email'), $this->request);
        Session::flash('success', 'Se houver uma conta aguardando confirmação para este e-mail, enviamos um novo link de ativação.');

        return $this->redirect('/login');
    }

    /** @return array<string, string> Field => message. */
    private function validateNewPassword(): array
    {
        $password = $this->request->string('password');
        $min = (int) Config::get('security.password_min', 10);
        $max = (int) Config::get('security.password_max', 128);

        if (mb_strlen($password) < $min || mb_strlen($password) > $max) {
            return ['password' => "A senha deve ter entre {$min} e {$max} caracteres."];
        }
        if (!hash_equals($password, $this->request->string('password_confirmation'))) {
            return ['password_confirmation' => 'As senhas não conferem.'];
        }

        return [];
    }

    /** @param array<string, string> $errors */
    private function verificationPage(string $token, VerificationResult $result, array $errors, int $status = 200): Response
    {
        return $this->view('auth/verify', [
            'title'         => 'Confirmar e-mail',
            'state'         => $result->state,
            'token'         => $token,
            'needsPassword' => $result->needsPassword(),
            'passwordMin'   => (int) Config::get('security.password_min', 10),
            'errors'        => $errors,
        ], $status, 'layouts/auth')
            // The token is in this page's URL: never leak it to other sites via Referer.
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
```


### 3.4 Tenant isolation

**How a tenant user is confined to their condominium:**

1. **The tenant comes from the server, never from the client.** `TenantMiddleware` reads `condominium_id` from the session and, **on every request**, checks it is still an active membership in an active condominium. It then sets `TenantContext`. No URL segment, form field or JSON property is ever used as the tenant.
2. **Models scope automatically.** Every tenant model extends `TenantModel`:
   - `find($id)` runs `WHERE id = :id AND condominium_id = :tenant`. A tampered id from another condominium returns `null`, which becomes **404**: the user learns nothing, not even that the record exists.
   - `insert()` **discards** any `condominium_id` in the input and writes the context's tenant.
   - `update()` cannot touch other tenants' rows and cannot move a row to another tenant.
   - Hand-written queries (see `Notice::current()`) include `condominium_id = :tenant` and take their parameters through `scoped()`.
3. **It fails closed.** If a route forgets the `tenant` middleware, `TenantContext::id()` throws. The request returns an error instead of running an unscoped query.
4. **The database is the backstop.** The Phase 1 composite foreign keys `(condominium_id, x_id) → parent(condominium_id, id)` reject any cross-tenant reference, even one created by a buggy query.
5. **Changing tenant is a single, checked endpoint.** `POST /select-condominium` accepts the id only after confirming an active membership, regenerates the session id, and refreshes the role.

**Super Admin (`condominium_id` is NULL).** The Super Admin has no membership, so `condominium_id` and `role_id` stay `NULL` in the session. `TenantMiddleware` recognises `users.is_super_admin`, read from the database and not from the session, and uses `admin_condominium_id`, the condominium the Super Admin explicitly selected. It checks that condominium is active on every request and writes an audit entry when the Super Admin enters it. All tenant queries still go through `TenantModel`, so the Super Admin also works on exactly one condominium at a time. Nothing is ever queried "across all tenants" by accident.

`Membership` deliberately extends the global `Model`: it is the query that *decides* the tenant, so it runs before a tenant exists. It is always filtered by `user_id`.

`app/Core/TenantContext.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

use LogicException;

/**
 * The condominium (tenant) the current request operates on.
 *
 * Set exactly once per request by TenantMiddleware, from server-side session
 * state that was validated against the database: never from a URL, form field,
 * JSON body or cookie. TenantModel reads it to scope every query.
 *
 * It fails closed: asking for the id before it is set throws, so a route that
 * forgot the "tenant" middleware breaks loudly instead of running unscoped queries.
 */
final class TenantContext
{
    private static ?int $condominiumId = null;
    private static ?string $condominiumName = null;

    public static function set(int $condominiumId, string $condominiumName): void
    {
        self::$condominiumId = $condominiumId;
        self::$condominiumName = $condominiumName;
    }

    /** @throws LogicException when no tenant has been resolved for this request. */
    public static function id(): int
    {
        if (self::$condominiumId === null) {
            throw new LogicException('Tenant context is not set: the route is missing the "tenant" middleware.');
        }

        return self::$condominiumId;
    }

    public static function has(): bool
    {
        return self::$condominiumId !== null;
    }

    /** Display name of the current condominium, or null outside tenant routes. */
    public static function name(): ?string
    {
        return self::$condominiumName;
    }
}
```

`app/Core/TenantModel.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base class for every model whose table has a `condominium_id` column.
 *
 * TENANT ISOLATION: every query this class builds is scoped to
 * TenantContext::id(), the tenant the TenantMiddleware resolved from the
 * server-side session. No method accepts a condominium id from the caller, so a
 * controller cannot pass a tampered value through, even by mistake.
 *
 *  - find(42) runs "WHERE id = 42 AND condominium_id = <current tenant>". A
 *    record from another condominium is simply not found, so the user gets the
 *    same 404 as for an id that does not exist, and learns nothing.
 *  - insert() discards any condominium_id in $data and sets the current tenant.
 *  - update()/delete() only touch rows of the current tenant.
 *  - Hand-written queries in subclasses must contain
 *    "condominium_id = :tenant" and pass their parameters through scoped().
 *
 * The composite foreign keys of the Phase 1 schema are the database-level
 * backstop: even a buggy hand-written query cannot link rows across tenants.
 */
abstract class TenantModel extends Model
{
    /** Returns a row of the current tenant by id, or null (also for other tenants' ids). */
    public function find(int $id): ?array
    {
        return $this->selectWhere(['id' => $id, 'condominium_id' => $this->tenantId()]);
    }

    /**
     * Inserts a row owned by the current tenant.
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        unset($data['condominium_id']); // never trust a tenant id supplied by the caller

        return $this->insertRow($this->onlyFillable($data) + ['condominium_id' => $this->tenantId()]);
    }

    /**
     * Updates a row of the current tenant; rows of other tenants are untouched (returns 0).
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): int
    {
        unset($data['condominium_id']); // a row can never be moved to another tenant

        return $this->updateWhere($this->onlyFillable($data), ['id' => $id, 'condominium_id' => $this->tenantId()]);
    }

    /** The tenant every query of this model is restricted to. */
    protected function tenantId(): int
    {
        return TenantContext::id();
    }

    /**
     * Adds the current tenant as the ":tenant" parameter of a hand-written query.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function scoped(array $params = []): array
    {
        return ['tenant' => $this->tenantId()] + $params;
    }
}
```

`app/Models/Condominium.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * The tenant registry `condominiums` (global table, managed by the Super Admin).
 */
final class Condominium extends Model
{
    protected string $table = 'condominiums';

    /** Returns the condominium only if its status is 'active'. */
    public function findActive(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT id, name FROM condominiums WHERE id = :id AND status = 'active'",
            ['id' => $id]
        );
    }

    /**
     * Every active condominium, for the Super Admin's selector.
     *
     * @return list<array{id: int, name: string, city: string}>
     */
    public function allActive(): array
    {
        return $this->fetchAll(
            "SELECT id, name, city FROM condominiums WHERE status = 'active' ORDER BY name"
        );
    }
}
```

`app/Models/Membership.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Memberships `condominium_users`: which user belongs to which condominium, with
 * which role.
 *
 * Although the table has a condominium_id column, this model extends the global
 * Model on purpose. It is the bridge used to DECIDE the tenant (at login and in
 * TenantMiddleware), so it runs before a TenantContext exists. Every query is
 * filtered by user_id instead: a user can only ever see their own memberships.
 */
final class Membership extends Model
{
    protected string $table = 'condominium_users';

    protected array $fillable = [
        'condominium_id',
        'user_id',
        'role_id',
        'status',
        'approved_by_user_id',
        'approved_at',
    ];

    private const ACTIVE_SELECT = <<<'SQL'
        SELECT cu.condominium_id,
               cu.role_id,
               r.code AS role_code,
               r.name AS role_name,
               c.name AS condominium_name,
               c.city AS condominium_city
          FROM condominium_users cu
          JOIN roles r        ON r.id = cu.role_id
          JOIN condominiums c ON c.id = cu.condominium_id
         WHERE cu.user_id = :user_id
           AND cu.status = 'active'
           AND c.status = 'active'
        SQL;

    /**
     * Active memberships of a user in active condominiums.
     *
     * @return list<array<string, mixed>>
     */
    public function activeForUser(int $userId): array
    {
        return $this->fetchAll(self::ACTIVE_SELECT . ' ORDER BY c.name', ['user_id' => $userId]);
    }

    /** One active membership of the user in the given condominium, or null. */
    public function findActive(int $userId, int $condominiumId): ?array
    {
        return $this->fetchOne(
            self::ACTIVE_SELECT . ' AND cu.condominium_id = :condominium_id LIMIT 1',
            ['user_id' => $userId, 'condominium_id' => $condominiumId]
        );
    }

    /** True when the user has any membership (any status) in the condominium. */
    public function exists(int $userId, int $condominiumId): bool
    {
        return $this->fetchOne(
            'SELECT 1 FROM condominium_users WHERE user_id = :user_id AND condominium_id = :condominium_id',
            ['user_id' => $userId, 'condominium_id' => $condominiumId]
        ) !== null;
    }
}
```

`app/Models/Role.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Global role catalogue `roles` (manager, concierge, resident).
 * Super Admin is not a row here: it is users.is_super_admin.
 */
final class Role extends Model
{
    protected string $table = 'roles';

    public function idByCode(string $code): ?int
    {
        $row = $this->fetchOne('SELECT id FROM roles WHERE code = :code', ['code' => $code]);

        return $row === null ? null : (int) $row['id'];
    }
}
```

`app/Controllers/TenantController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Models\AuditLog;
use App\Models\Condominium;
use App\Models\Membership;

/**
 * Choosing the condominium to work in: the ONLY way the tenant of a session changes.
 */
final class TenantController extends Controller
{
    /** GET /select-condominium */
    public function select(): Response
    {
        $userId = (int) Auth::id();
        $options = Auth::isSuperAdmin()
            ? array_map(
                static fn (array $c): array => ['id' => (int) $c['id'], 'name' => $c['name'], 'detail' => $c['city']],
                (new Condominium())->allActive()
            )
            : array_map(
                static fn (array $m): array => [
                    'id'     => (int) $m['condominium_id'],
                    'name'   => $m['condominium_name'],
                    'detail' => $m['role_name'],
                ],
                (new Membership())->activeForUser($userId)
            );

        return $this->view('tenant/select', [
            'title'   => 'Escolher condomínio',
            'options' => $options,
        ], layout: 'layouts/auth');
    }

    /**
     * POST /select-condominium
     *
     * The submitted id is only a REQUEST. It is accepted after checking an
     * active membership (tenant users) or an active condominium (Super Admin).
     * Otherwise the answer is 404, the same as for an id that does not exist.
     */
    public function choose(): Response
    {
        $condominiumId = (int) $this->request->string('condominium_id');

        if (Auth::isSuperAdmin()) {
            $condominium = (new Condominium())->findActive($condominiumId) ?? throw new HttpException(404);
            Session::regenerate();
            Session::set('admin_condominium_id', (int) $condominium['id']);
            // Super Admin access to tenant data is always traceable (Phase 1, NFR-SEC-13).
            (new AuditLog())->record('support.tenant_entered', $this->request, Auth::id(), (int) $condominium['id']);

            return $this->redirect('/dashboard');
        }

        $membership = (new Membership())->findActive((int) Auth::id(), $condominiumId)
            ?? throw new HttpException(404);
        Session::regenerate();
        Auth::enterMembership($membership);

        return $this->redirect('/dashboard');
    }
}
```


### 3.5 Middleware (including `requireRole`)

The `role:` middleware is the route-level `requireRole([...])`. `Controller::requireRole()` (§2.4) is the same check inside an action, used as defence in depth.

`app/Middleware/Middleware.php`

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * A step that runs before the controller and may stop the request.
 *
 * $params carries the comma-separated arguments of the route spec, e.g.
 * "role:manager,concierge" creates the middleware with ['manager', 'concierge'].
 */
abstract class Middleware
{
    /** @param list<string> $params */
    public function __construct(protected readonly array $params = [])
    {
    }

    /**
     * Either returns a Response itself (redirect, error) or calls $next to continue.
     *
     * @param callable(Request): Response $next
     */
    abstract public function handle(Request $request, callable $next): Response;
}
```

`app/Middleware/AuthMiddleware.php`

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Route spec "auth": only logged-in users get through.
 *
 * Pages redirect to the login form; API calls get a JSON 401 instead (a redirect
 * would hand fetch() the login page HTML).
 */
final class AuthMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Auth::check()) {
            if ($request->wantsJson()) {
                throw new HttpException(401);
            }
            Session::flash('warning', 'Entre para continuar.');

            return Response::redirect('/login');
        }

        return $next($request);
    }
}
```

`app/Middleware/GuestMiddleware.php`

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Route spec "guest": pages for logged-out visitors (login, resend link).
 * A logged-in user is sent to the dashboard instead.
 */
final class GuestMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (Auth::check()) {
            return Response::redirect('/dashboard');
        }

        return $next($request);
    }
}
```

`app/Middleware/CsrfMiddleware.php`

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;

/**
 * Route spec "csrf": state-changing requests must carry the session's CSRF token,
 * either in the "_csrf" form field or in the X-CSRF-Token header (fetch()).
 *
 * Add it to every POST route, including the login form: without it an attacker
 * could log a victim into the attacker's account ("login CSRF").
 */
final class CsrfMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if ($request->isStateChanging()) {
            $token = $request->string('_csrf');
            if ($token === '') {
                $token = (string) $request->header('X-CSRF-Token');
            }

            if (!Csrf::validate($token)) {
                Logger::warning('CSRF token mismatch', ['path' => $request->path(), 'ip' => $request->ip()]);
                throw new HttpException(419);
            }
        }

        return $next($request);
    }
}
```

`app/Middleware/TenantMiddleware.php`

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TenantContext;
use App\Models\Condominium;
use App\Models\Membership;

/**
 * Route spec "tenant" (always after "auth"): resolves which condominium this
 * request operates on and stores it in TenantContext.
 *
 * The tenant comes ONLY from the server-side session, and is re-validated
 * against the database on every request:
 *  - Tenant users: the session's condominium_id must still be an ACTIVE
 *    membership in an ACTIVE condominium. The role is re-read at the same time,
 *    so a demotion or deactivation by a manager takes effect on the next click.
 *  - Super Admin: has no membership (condominium_id is NULL). They pick a
 *    condominium in the selector; that choice is kept separately in
 *    admin_condominium_id and checked to still be an active condominium.
 *
 * No URL, form field or JSON property can change the tenant. The only way is
 * the POST /select-condominium endpoint, which checks the membership first.
 */
final class TenantMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (Auth::isSuperAdmin()) {
            $condominiumId = Session::get('admin_condominium_id');
            $condominium = is_int($condominiumId) ? (new Condominium())->findActive($condominiumId) : null;
            if ($condominium === null) {
                return $this->chooseTenant($request);
            }
            TenantContext::set((int) $condominium['id'], (string) $condominium['name']);

            return $next($request);
        }

        $condominiumId = Session::get('condominium_id');
        $userId = (int) Auth::id();
        $membership = is_int($condominiumId) ? (new Membership())->findActive($userId, $condominiumId) : null;
        if ($membership === null) {
            Auth::enterMembership(null);

            return $this->chooseTenant($request);
        }

        Auth::enterMembership($membership); // refresh role from the database
        TenantContext::set((int) $membership['condominium_id'], (string) $membership['condominium_name']);

        return $next($request);
    }

    private function chooseTenant(Request $request): Response
    {
        if ($request->wantsJson()) {
            throw new HttpException(403, 'Selecione um condomínio para continuar.');
        }

        return Response::redirect('/select-condominium');
    }
}
```

`app/Middleware/RoleMiddleware.php`

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;

/**
 * Route spec "role:a,b,c", the requireRole([...]) check at route level.
 *
 *   ['auth', 'tenant', 'role:super_admin,manager']
 *
 * Place it after "tenant": the tenant middleware refreshes the role from the
 * database, so this check never relies on a stale role in the session.
 * Hiding a button in the UI is not access control; this middleware is.
 */
final class RoleMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Auth::hasRole($this->params)) {
            Logger::warning('Role check failed', [
                'user_id'  => Auth::id(),
                'role'     => Auth::roleCode(),
                'required' => $this->params,
                'path'     => $request->path(),
            ]);
            throw new HttpException(403);
        }

        return $next($request);
    }
}
```


### 3.6 Views for authentication

`app/Views/layouts/auth.php`

```php
<?php
/**
 * Minimal centred layout for logged-out pages, the condominium selector and
 * error pages. It needs no database access, so error pages can always render.
 *
 * @var string                $content Rendered page HTML (already escaped).
 * @var string                $title
 * @var array<string, string> $flashes
 */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Koinon') ?> · Koinon</title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="auth">
<main class="auth__card">
    <div class="auth__brand">Koinon</div>

    <?php foreach (($flashes ?? []) as $type => $message): ?>
        <div class="alert alert--<?= e($type) ?>" role="status"><?= e($message) ?></div>
    <?php endforeach; ?>

    <?= $content /* already-escaped page HTML */ ?>
</main>
</body>
</html>
```

`app/Views/auth/login.php`

```php
<?php
/**
 * @var string      $email           Previously typed e-mail (re-filled after an error).
 * @var string|null $unverifiedEmail Set when the password was right but the e-mail is unconfirmed.
 */
?>
<h1 class="auth__title">Entrar</h1>

<?php if ($unverifiedEmail !== null): ?>
    <div class="callout">
        <p>Não recebeu o e-mail de ativação? Podemos enviar um novo link.</p>
        <form method="post" action="/verify-email/resend">
            <?= csrf_field() ?>
            <input type="hidden" name="email" value="<?= e($unverifiedEmail) ?>">
            <button type="submit" class="btn">Reenviar link de ativação</button>
        </form>
    </div>
<?php endif; ?>

<form method="post" action="/login" class="form" novalidate>
    <?= csrf_field() ?>

    <label class="form__field">
        <span class="form__label">E-mail</span>
        <input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
    </label>

    <label class="form__field">
        <span class="form__label">Senha</span>
        <input type="password" name="password" autocomplete="current-password" required>
    </label>

    <button type="submit" class="btn btn--primary btn--block">Entrar</button>
</form>

<p class="auth__links">
    <a href="/verify-email/resend">Não recebeu o e-mail de ativação?</a>
</p>
```

`app/Views/auth/verify.php`

```php
<?php
/**
 * Landing page of the activation link, in one of these states (VerificationResult):
 * valid | password_required | expired | already_used | invalid.
 *
 * @var string                $state
 * @var string                $token
 * @var bool                  $needsPassword
 * @var int                   $passwordMin
 * @var array<string, string> $errors
 */

use App\Services\VerificationResult;
?>
<h1 class="auth__title">Confirmar e-mail</h1>

<?php if ($state === VerificationResult::VALID || $state === VerificationResult::PASSWORD_REQUIRED): ?>
    <p>
        <?= $needsPassword
            ? 'Para ativar sua conta, escolha uma senha e confirme.'
            : 'Clique no botão abaixo para confirmar seu e-mail e ativar sua conta.' ?>
    </p>

    <form method="post" action="/verify-email" class="form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <?php if ($needsPassword): ?>
            <label class="form__field">
                <span class="form__label">Nova senha (mínimo <?= e($passwordMin) ?> caracteres)</span>
                <input type="password" name="password" autocomplete="new-password" required minlength="<?= e($passwordMin) ?>">
                <?php if (isset($errors['password'])): ?>
                    <span class="form__error"><?= e($errors['password']) ?></span>
                <?php endif; ?>
            </label>
            <label class="form__field">
                <span class="form__label">Repita a senha</span>
                <input type="password" name="password_confirmation" autocomplete="new-password" required>
                <?php if (isset($errors['password_confirmation'])): ?>
                    <span class="form__error"><?= e($errors['password_confirmation']) ?></span>
                <?php endif; ?>
            </label>
        <?php endif; ?>

        <button type="submit" class="btn btn--primary btn--block">Confirmar meu e-mail</button>
    </form>

<?php elseif ($state === VerificationResult::EXPIRED): ?>
    <div class="alert alert--warning">Este link expirou. Peça um novo link de ativação.</div>
    <a class="btn btn--primary btn--block" href="/verify-email/resend">Enviar novo link</a>

<?php elseif ($state === VerificationResult::ALREADY_USED): ?>
    <div class="alert alert--success">Esta conta já foi ativada. Você já pode entrar.</div>
    <a class="btn btn--primary btn--block" href="/login">Ir para o login</a>

<?php else: ?>
    <div class="alert alert--error">Link de ativação inválido. Confira se copiou o endereço completo ou peça um novo link.</div>
    <a class="btn btn--block" href="/verify-email/resend">Enviar novo link</a>
<?php endif; ?>
```

`app/Views/auth/resend.php`

```php
<?php
/**
 * @var string $email
 */
?>
<h1 class="auth__title">Reenviar link de ativação</h1>
<p>Informe o e-mail da sua conta. Se ela ainda não foi ativada, enviaremos um novo link.</p>

<form method="post" action="/verify-email/resend" class="form" novalidate>
    <?= csrf_field() ?>
    <label class="form__field">
        <span class="form__label">E-mail</span>
        <input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" required autofocus>
    </label>
    <button type="submit" class="btn btn--primary btn--block">Enviar link</button>
</form>

<p class="auth__links"><a href="/login">Voltar ao login</a></p>
```

`app/Views/tenant/select.php`

```php
<?php
/**
 * @var list<array{id: int, name: string, detail: string}> $options
 * @var bool $isSuperAdmin
 */
?>
<h1 class="auth__title">
    <?= $isSuperAdmin ? 'Escolha o condomínio para administrar' : 'Escolha o condomínio' ?>
</h1>

<?php if ($options === []): ?>
    <div class="alert alert--warning">Nenhum condomínio ativo disponível.</div>
<?php else: ?>
    <ul class="choice-list">
        <?php foreach ($options as $option): ?>
            <li>
                <form method="post" action="/select-condominium">
                    <?= csrf_field() ?>
                    <input type="hidden" name="condominium_id" value="<?= e($option['id']) ?>">
                    <button type="submit" class="choice">
                        <span class="choice__name"><?= e($option['name']) ?></span>
                        <span class="choice__detail"><?= e($option['detail']) ?></span>
                    </button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="post" action="/logout" class="auth__links">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn--ghost">Sair</button>
</form>
```

`app/Views/errors/error.php`

```php
<?php
/**
 * Generic error page. Shows only user-safe text plus a request id that support
 * can match against storage/logs.
 *
 * @var int    $status
 * @var string $message
 * @var string $requestId
 */
?>
<h1 class="auth__title">Erro <?= e($status) ?></h1>
<p><?= e($message) ?></p>
<p class="muted">Código de referência: <code><?= e($requestId) ?></code></p>
<p class="auth__links"><a href="/">Voltar ao início</a></p>
```

`app/Views/emails/verify.php`

```php
<?php
/**
 * HTML body of the activation e-mail. E-mail clients ignore external CSS, so
 * styles are inline here (the web CSP does not apply to e-mails).
 *
 * @var string $name
 * @var string $link
 * @var int    $hours
 */
?>
<!doctype html>
<html lang="pt-BR">
<body style="margin:0;padding:24px;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center">
        <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #d9dee5;border-radius:6px;">
            <tr><td style="padding:24px 32px;border-bottom:3px solid #1d3c6e;font-size:20px;font-weight:bold;color:#1d3c6e;">Koinon</td></tr>
            <tr><td style="padding:32px;font-size:15px;line-height:1.6;">
                <p>Olá, <?= e($name) ?>!</p>
                <p>Confirme seu e-mail para ativar sua conta no Koinon.</p>
                <p style="margin:32px 0;">
                    <a href="<?= e($link) ?>" style="background:#1d3c6e;color:#ffffff;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:bold;">Confirmar e-mail</a>
                </p>
                <p style="font-size:13px;color:#5f6b7a;">
                    O link vale por <?= e($hours) ?> horas e só pode ser usado uma vez.<br>
                    Se o botão não funcionar, copie este endereço no navegador:<br>
                    <span style="word-break:break-all;"><?= e($link) ?></span>
                </p>
                <p style="font-size:13px;color:#5f6b7a;">Se você não criou esta conta, ignore este e-mail.</p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
```


### 3.7 Account creation CLI

`bin/create-user.php`

```php
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
```


---

## 4. Dashboard / Notice Board

**Role-based UI and server enforcement.** `NoticeController::CREATOR_ROLES` (`super_admin`, `manager`, `concierge`) is the single list behind three checks:

- `DashboardController` uses it to decide whether to render the "Novo aviso" button and dialog. This only affects the UI.
- `routes/web.php` builds the `role:` middleware of `POST /api/notices` from it. A resident who calls the endpoint directly gets **403**.
- `NoticeController::store()` checks it again with `requireRole()`.

**Safe rendering.** `notices.js` clones a `<template>` and fills every field with `textContent`. Line breaks are kept by CSS (`white-space: pre-line`), not by turning text into HTML. The CSP (`script-src 'self'`) blocks inline scripts as a second line of defence.

`database/migrations/0001_phase2_notice_authors.sql`

```sql
-- =====================================================================================
-- Migration 0001 - Phase 2: notice authorship for Super Admin, publishing for Concierge
--
-- Phase 2 requires the Super Admin, the Property Manager and the Concierge to
-- publish notices. Phase 1 only allowed authors who are members of the
-- condominium (composite FK to condominium_users), and a Super Admin has no
-- membership. Changes:
--   1. notices.author_user_id becomes NULLable (still a composite, tenant-safe FK).
--   2. New notices.author_super_admin_id -> users(id) for notices written by a
--      Super Admin.
--   3. New permission notices.create, granted to manager and concierge.
--
-- "Exactly one author column is set" is enforced in NoticeController, not with a
-- CHECK: MySQL restricts CHECK constraints on foreign-key columns (error 3823),
-- and the schema keeps the Phase 1 rule of no CHECK on FK columns.
--
-- Run once, after database/schema.sql, with a user that has ALTER privileges.
-- =====================================================================================

USE koinon;

-- The FK must be dropped before its column can change nullability, then re-created.
ALTER TABLE notices DROP FOREIGN KEY fk_notices_author;

ALTER TABLE notices
  MODIFY COLUMN author_user_id INT UNSIGNED NULL
    COMMENT 'Member who wrote the notice; NULL when written by a Super Admin',
  ADD COLUMN author_super_admin_id INT UNSIGNED NULL
    COMMENT 'Super Admin who wrote the notice; NULL for member authors'
    AFTER author_user_id,
  ADD KEY ix_notices_author_super_admin (author_super_admin_id);

ALTER TABLE notices
  ADD CONSTRAINT fk_notices_author
    FOREIGN KEY (condominium_id, author_user_id)
    REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_notices_author_super_admin
    FOREIGN KEY (author_super_admin_id) REFERENCES users (id) ON DELETE RESTRICT;

INSERT INTO permissions (code, module, description) VALUES
  ('notices.create', 'notices', 'Publish notices on the notice board');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.code IN ('manager', 'concierge')
  AND p.code = 'notices.create';
```

`app/Models/Notice.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Notice board `notices` (tenant-scoped).
 *
 * Every query here contains "n.condominium_id = :tenant" and gets its parameters
 * from scoped(), so it can only ever return the current condominium's notices.
 */
final class Notice extends TenantModel
{
    public const PRIORITIES = ['normal', 'important', 'urgent'];
    public const TITLE_MAX = 150;
    public const BODY_MAX = 10000;

    protected string $table = 'notices';

    // condominium_id is intentionally absent: TenantModel::insert() sets it.
    protected array $fillable = [
        'author_user_id',
        'author_super_admin_id',
        'title',
        'body',
        'priority',
        'is_pinned',
        'status',
        'publish_at',
        'expires_at',
    ];

    /** Shared column list: the author name comes from either author column. */
    private const DISPLAY_SELECT = <<<'SQL'
        SELECT n.id, n.title, n.body, n.priority, n.is_pinned, n.publish_at, n.expires_at,
               u.full_name AS author_name
          FROM notices n
          LEFT JOIN users u ON u.id = COALESCE(n.author_user_id, n.author_super_admin_id)
         WHERE n.condominium_id = :tenant
        SQL;

    /**
     * Notices currently visible on the homepage: published, already started, not
     * expired; pinned first, then newest. Served by index
     * ix_notices_homepage (condominium_id, status, is_pinned, publish_at).
     *
     * @return list<array<string, mixed>>
     */
    public function current(int $limit = 50): array
    {
        $sql = self::DISPLAY_SELECT . <<<'SQL'
               AND n.status = 'published'
               AND n.publish_at <= UTC_TIMESTAMP()
               AND (n.expires_at IS NULL OR n.expires_at > UTC_TIMESTAMP())
             ORDER BY n.is_pinned DESC, n.publish_at DESC
             LIMIT :limit
            SQL;

        return $this->fetchAll($sql, $this->scoped(['limit' => $limit]));
    }

    /** One notice of the current tenant in display format, or null. */
    public function findForDisplay(int $id): ?array
    {
        return $this->fetchOne(self::DISPLAY_SELECT . ' AND n.id = :id', $this->scoped(['id' => $id]));
    }
}
```

`app/Controllers/DashboardController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;

/**
 * Logged-in homepage: the Notice Board.
 */
final class DashboardController extends Controller
{
    /** GET / */
    public function home(): Response
    {
        return $this->redirect(Auth::check() ? '/dashboard' : '/login');
    }

    /**
     * GET /dashboard
     *
     * The page itself is a shell; notices are loaded by notices.js from
     * GET /api/notices. canCreateNotice only decides whether the button is shown:
     * the POST endpoint enforces the same rule on the server.
     */
    public function index(): Response
    {
        return $this->view('dashboard/index', [
            'title'           => 'Mural de avisos',
            'activeNav'       => 'notices',
            'canCreateNotice' => Auth::hasRole(NoticeController::CREATOR_ROLES),
            'scripts'         => ['js/notices.js'],
        ]);
    }
}
```

`app/Controllers/NoticeController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Core\TenantContext;
use App\Models\AuditLog;
use App\Models\Notice;
use DateTimeImmutable;
use DateTimeZone;

/**
 * JSON API of the Notice Board. Both routes run behind auth + tenant, so every
 * query is limited to the current condominium by the Notice model.
 */
final class NoticeController extends Controller
{
    /**
     * Who may publish notices. The single source of truth for the route
     * middleware (routes/web.php), the defence-in-depth check in store() and
     * the "Novo aviso" button (DashboardController).
     */
    public const CREATOR_ROLES = [Auth::SUPER_ADMIN, 'manager', 'concierge'];

    /** GET /api/notices: current notices of the current tenant. */
    public function index(): Response
    {
        $notices = array_map([$this, 'present'], (new Notice())->current());

        return $this->json(['data' => $notices]);
    }

    /**
     * POST /api/notices: publishes a notice immediately.
     *
     * Protected three times: "role:" middleware on the route, requireRole()
     * here, and the composite foreign key that only accepts a member of this
     * condominium as author.
     */
    public function store(): Response
    {
        $this->requireRole(self::CREATOR_ROLES);

        $title = trim($this->request->string('title'));
        $body = trim($this->request->string('body'));
        $priority = $this->request->string('priority') ?: 'normal';
        $isPinned = $this->request->boolean('is_pinned');

        $errors = [];
        if (mb_strlen($title) < 3 || mb_strlen($title) > Notice::TITLE_MAX) {
            $errors['title'] = 'O título deve ter entre 3 e ' . Notice::TITLE_MAX . ' caracteres.';
        }
        if ($body === '' || mb_strlen($body) > Notice::BODY_MAX) {
            $errors['body'] = 'A mensagem é obrigatória e pode ter até ' . Notice::BODY_MAX . ' caracteres.';
        }
        if (!in_array($priority, Notice::PRIORITIES, true)) {
            $errors['priority'] = 'Prioridade inválida.';
        }
        if ($errors !== []) {
            return $this->json(['errors' => $errors], 422);
        }

        // Exactly one author column is set. A Super Admin has no membership in the
        // condominium, so they are recorded in author_super_admin_id (migration 0001).
        $userId = (int) Auth::id();
        $isSuperAdmin = Auth::isSuperAdmin();

        $notices = new Notice();
        $id = $notices->insert([
            'author_user_id'        => $isSuperAdmin ? null : $userId,
            'author_super_admin_id' => $isSuperAdmin ? $userId : null,
            'title'                 => $title,
            'body'                  => $body,
            'priority'              => $priority,
            'is_pinned'             => $isPinned ? 1 : 0,
            'status'                => 'published',
            'publish_at'            => gmdate('Y-m-d H:i:s'),
        ]);

        (new AuditLog())->record('notice.published', $this->request, $userId, TenantContext::id(), 'notice', $id);

        return $this->json(['data' => $this->present((array) $notices->findForDisplay($id))], 201);
    }

    /**
     * Shapes a row for the client. Only the fields the UI needs are exposed;
     * dates become ISO-8601 UTC so the browser can format them in local time.
     *
     * @param array<string, mixed> $notice
     * @return array<string, mixed>
     */
    private function present(array $notice): array
    {
        return [
            'id'           => (int) $notice['id'],
            'title'        => (string) $notice['title'],
            'body'         => (string) $notice['body'],
            'priority'     => (string) $notice['priority'],
            'is_pinned'    => (bool) $notice['is_pinned'],
            'published_at' => self::isoUtc($notice['publish_at']),
            'author_name'  => $notice['author_name'] !== null ? (string) $notice['author_name'] : null,
        ];
    }

    private static function isoUtc(?string $datetime): ?string
    {
        if ($datetime === null) {
            return null;
        }

        return (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
```

`app/Views/layouts/app.php`

```php
<?php
/**
 * Main layout of the management area (corporate, desktop-first).
 *
 * @var string                $content         Rendered page HTML (already escaped by the page template).
 * @var string                $title
 * @var string                $activeNav
 * @var list<string>          $scripts         Paths under public/assets loaded as ES modules.
 * @var string|null           $currentUserName
 * @var string|null           $currentRole
 * @var bool                  $isSuperAdmin
 * @var string|null           $tenantName
 * @var array<string, string> $flashes
 */

$navigation = [
    ['key' => 'notices', 'label' => 'Mural de avisos', 'href' => '/dashboard'],
    ['key' => 'concierge', 'label' => 'Portaria', 'href' => null],
    ['key' => 'reservations', 'label' => 'Reservas', 'href' => null],
    ['key' => 'occurrences', 'label' => 'Ocorrências', 'href' => null],
    ['key' => 'financial', 'label' => 'Financeiro', 'href' => null],
];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> · Koinon</title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="app">
<header class="topbar">
    <a class="topbar__brand" href="/dashboard">Koinon</a>

    <div class="topbar__tenant">
        <?php if ($tenantName !== null): ?>
            <span class="topbar__tenant-name"><?= e($tenantName) ?></span>
        <?php endif; ?>
        <?php if ($isSuperAdmin): ?>
            <span class="tag tag--admin">Modo Super Admin</span>
        <?php endif; ?>
        <a class="topbar__switch" href="/select-condominium">Trocar condomínio</a>
    </div>

    <div class="topbar__user">
        <div class="topbar__identity">
            <span class="topbar__name"><?= e($currentUserName) ?></span>
            <span class="topbar__role"><?= e($currentRole) ?></span>
        </div>
        <form method="post" action="/logout">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--ghost">Sair</button>
        </form>
    </div>
</header>

<div class="shell">
    <nav class="sidebar" aria-label="Módulos">
        <ul class="sidebar__list">
            <?php foreach ($navigation as $item): ?>
                <li>
                    <?php if ($item['href'] !== null): ?>
                        <a class="sidebar__link<?= $activeNav === $item['key'] ? ' is-active' : '' ?>"
                           href="<?= e($item['href']) ?>"
                           <?= $activeNav === $item['key'] ? 'aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
                    <?php else: ?>
                        <span class="sidebar__link is-disabled" title="Disponível em breve"><?= e($item['label']) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <main class="main">
        <?php foreach ($flashes as $type => $message): ?>
            <div class="alert alert--<?= e($type) ?>" role="status"><?= e($message) ?></div>
        <?php endforeach; ?>

        <?= $content /* already-escaped page HTML */ ?>
    </main>
</div>

<footer class="footer">
    <span>© <?= e(date('Y')) ?> Koinon · Gestão condominial</span>
</footer>

<?php foreach ($scripts as $script): ?>
    <script type="module" src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
```

`app/Views/dashboard/index.php`

```php
<?php
/**
 * Notice Board. The list is filled by public/assets/js/notices.js from
 * GET /api/notices. The <template> is cloned and filled with textContent, so
 * notice text is never parsed as HTML.
 *
 * @var string|null $tenantName
 * @var bool        $canCreateNotice UI only; POST /api/notices enforces the rule.
 */
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Mural de avisos</h1>
        <p class="page-header__subtitle">Comunicados oficiais de <?= e($tenantName) ?></p>
    </div>
    <?php if ($canCreateNotice): ?>
        <button type="button" class="btn btn--primary" id="open-notice-form">Novo aviso</button>
    <?php endif; ?>
</section>

<section id="notice-board" class="panel" aria-live="polite" aria-busy="true">
    <div class="state" data-state="loading">
        <span class="spinner" aria-hidden="true"></span>
        Carregando avisos…
    </div>
    <div class="state" data-state="empty" hidden>
        Nenhum aviso publicado no momento.
    </div>
    <div class="state state--error" data-state="error" hidden>
        <p>Não foi possível carregar os avisos.</p>
        <button type="button" class="btn" id="retry-notices">Tentar novamente</button>
    </div>
    <ul class="notice-list" id="notice-list" hidden></ul>
</section>

<template id="notice-template">
    <li class="notice">
        <div class="notice__header">
            <span class="badge" data-field="priority"></span>
            <span class="tag" data-field="pinned" hidden>Fixado</span>
            <h2 class="notice__title" data-field="title"></h2>
        </div>
        <p class="notice__body" data-field="body"></p>
        <div class="notice__meta">
            <span data-field="author"></span>
            <span aria-hidden="true">·</span>
            <time data-field="date"></time>
        </div>
    </li>
</template>

<?php if ($canCreateNotice): ?>
    <dialog id="notice-dialog" class="dialog" aria-labelledby="notice-dialog-title">
        <form id="notice-form" class="form" novalidate>
            <h2 id="notice-dialog-title" class="dialog__title">Novo aviso</h2>
            <div class="alert alert--error" id="notice-form-error" hidden></div>

            <label class="form__field">
                <span class="form__label">Título</span>
                <input type="text" name="title" maxlength="150" required>
                <span class="form__error" data-error-for="title"></span>
            </label>

            <label class="form__field">
                <span class="form__label">Mensagem</span>
                <textarea name="body" rows="8" maxlength="10000" required></textarea>
                <span class="form__error" data-error-for="body"></span>
            </label>

            <div class="form__row">
                <label class="form__field">
                    <span class="form__label">Prioridade</span>
                    <select name="priority">
                        <option value="normal">Normal</option>
                        <option value="important">Importante</option>
                        <option value="urgent">Urgente</option>
                    </select>
                    <span class="form__error" data-error-for="priority"></span>
                </label>
                <label class="form__check">
                    <input type="checkbox" name="is_pinned" value="1">
                    Fixar no topo do mural
                </label>
            </div>

            <div class="dialog__actions">
                <button type="button" class="btn" data-close>Cancelar</button>
                <button type="submit" class="btn btn--primary">Publicar aviso</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>
```

`public/assets/js/core/http.js`

```javascript
/**
 * Small fetch() wrapper shared by every page script.
 *
 * - Sends cookies (same-origin) and asks for JSON.
 * - Adds the CSRF token (from <meta name="csrf-token">) to state-changing requests.
 * - Redirects to the login page when the session has expired (401).
 * - Throws HttpError for any non-2xx answer, carrying the decoded JSON body.
 */

export class HttpError extends Error {
    /**
     * @param {number} status
     * @param {any} payload Decoded JSON body, or null.
     */
    constructor(status, payload) {
        super(`HTTP ${status}`);
        this.status = status;
        this.payload = payload;
    }
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function request(method, url, body) {
    const headers = { Accept: 'application/json' };
    const init = { method, headers, credentials: 'same-origin' };

    if (method !== 'GET') {
        headers['X-CSRF-Token'] = csrfToken();
    }
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
    }

    const response = await fetch(url, init);

    let payload = null;
    try {
        payload = await response.json();
    } catch {
        payload = null; // empty or non-JSON body
    }

    if (response.status === 401) {
        window.location.assign('/login');
    }
    if (!response.ok) {
        throw new HttpError(response.status, payload);
    }

    return payload;
}

export const getJson = (url) => request('GET', url);
export const postJson = (url, body) => request('POST', url, body);
```

`public/assets/js/notices.js`

```javascript
/**
 * Notice Board: loads notices from GET /api/notices and, for authorised roles,
 * publishes new ones through POST /api/notices.
 *
 * SECURITY: notice text is user content. It is only ever written with
 * textContent (never innerHTML), so markup in a notice is shown as plain text
 * and cannot run as script.
 */
import { getJson, postJson, HttpError } from './core/http.js';

const PRIORITY_LABELS = {
    normal: 'Normal',
    important: 'Importante',
    urgent: 'Urgente',
};

const dateFormatter = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'long', timeStyle: 'short' });

const board = document.getElementById('notice-board');
const list = document.getElementById('notice-list');
const template = document.getElementById('notice-template');

/**
 * Shows exactly one of: loading | empty | error | list.
 * @param {'loading'|'empty'|'error'|'list'} name
 */
function showState(name) {
    board.querySelectorAll('[data-state]').forEach((element) => {
        element.hidden = element.dataset.state !== name;
    });
    list.hidden = name !== 'list';
    board.setAttribute('aria-busy', String(name === 'loading'));
}

/**
 * Builds one <li> from the template.
 * @param {{id:number,title:string,body:string,priority:string,is_pinned:boolean,published_at:string,author_name:string|null}} notice
 */
function renderNotice(notice) {
    const item = template.content.firstElementChild.cloneNode(true);
    const field = (name) => item.querySelector(`[data-field="${name}"]`);

    field('title').textContent = notice.title;
    field('body').textContent = notice.body;
    field('author').textContent = notice.author_name ?? 'Administração';

    const badge = field('priority');
    // Only known values become CSS classes; anything else falls back to "normal".
    const priority = Object.hasOwn(PRIORITY_LABELS, notice.priority) ? notice.priority : 'normal';
    badge.textContent = PRIORITY_LABELS[priority];
    badge.classList.add(`badge--${priority}`);

    if (notice.is_pinned) {
        field('pinned').hidden = false;
        item.classList.add('notice--pinned');
    }

    const time = field('date');
    if (notice.published_at) {
        time.dateTime = notice.published_at;
        time.textContent = dateFormatter.format(new Date(notice.published_at));
    }

    return item;
}

async function loadNotices() {
    showState('loading');
    try {
        const { data } = await getJson('/api/notices');
        list.replaceChildren(...data.map(renderNotice));
        showState(data.length > 0 ? 'list' : 'empty');
    } catch (error) {
        console.error('Failed to load notices', error);
        showState('error');
    }
}

/** Wires the "Novo aviso" dialog. Absent from the page for roles without permission. */
function setupNoticeForm() {
    const openButton = document.getElementById('open-notice-form');
    const dialog = document.getElementById('notice-dialog');
    if (!openButton || !dialog) {
        return;
    }

    const form = document.getElementById('notice-form');
    const generalError = document.getElementById('notice-form-error');
    const submitButton = form.querySelector('button[type="submit"]');

    const clearErrors = () => {
        generalError.hidden = true;
        generalError.textContent = '';
        form.querySelectorAll('[data-error-for]').forEach((element) => {
            element.textContent = '';
        });
    };

    openButton.addEventListener('click', () => {
        form.reset();
        clearErrors();
        dialog.showModal();
    });

    form.querySelector('[data-close]').addEventListener('click', () => dialog.close());

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors();
        submitButton.disabled = true;

        const formData = new FormData(form);
        try {
            await postJson('/api/notices', {
                title: String(formData.get('title') ?? ''),
                body: String(formData.get('body') ?? ''),
                priority: String(formData.get('priority') ?? 'normal'),
                is_pinned: formData.get('is_pinned') === '1',
            });
            dialog.close();
            await loadNotices();
        } catch (error) {
            if (error instanceof HttpError && error.status === 422 && error.payload?.errors) {
                for (const [name, message] of Object.entries(error.payload.errors)) {
                    const target = form.querySelector(`[data-error-for="${CSS.escape(name)}"]`);
                    if (target) {
                        target.textContent = message;
                    }
                }
            } else {
                const message = error instanceof HttpError ? error.payload?.error : null;
                generalError.textContent = message ?? 'Não foi possível publicar o aviso. Tente novamente.';
                generalError.hidden = false;
            }
        } finally {
            submitButton.disabled = false;
        }
    });
}

document.getElementById('retry-notices').addEventListener('click', loadNotices);
setupNoticeForm();
loadNotices();
```

`public/assets/css/app.css`

```css
/*
 * Koinon - management area styles (corporate, desktop-first).
 * No inline styles anywhere: the Content-Security-Policy only allows this file.
 */

:root {
    --color-bg: #f4f6f9;
    --color-surface: #ffffff;
    --color-border: #d9dee5;
    --color-text: #1f2933;
    --color-muted: #5f6b7a;
    --color-primary: #1d3c6e;
    --color-primary-hover: #152d54;
    --color-primary-soft: #e8eef7;
    --color-danger: #b42318;
    --color-danger-soft: #fef3f2;
    --color-warning: #b54708;
    --color-warning-soft: #fffaeb;
    --color-success: #067647;
    --color-success-soft: #ecfdf3;

    --radius: 6px;
    --shadow: 0 1px 2px rgba(16, 24, 40, 0.06);
    --font: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    --topbar-height: 60px;
    --sidebar-width: 232px;
}

*,
*::before,
*::after {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
}

body {
    font-family: var(--font);
    font-size: 15px;
    line-height: 1.5;
    color: var(--color-text);
    background: var(--color-bg);
}

a {
    color: var(--color-primary);
}

h1,
h2 {
    margin: 0;
    line-height: 1.25;
}

[hidden] {
    display: none !important;
}

.muted {
    color: var(--color-muted);
}

/* ---------- App shell (desktop-first: 1024px minimum) ---------- */

body.app {
    min-width: 1024px;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

.topbar {
    height: var(--topbar-height);
    display: flex;
    align-items: center;
    gap: 24px;
    padding: 0 24px;
    background: var(--color-primary);
    color: #ffffff;
}

.topbar__brand {
    font-size: 20px;
    font-weight: 700;
    letter-spacing: 0.02em;
    color: #ffffff;
    text-decoration: none;
    width: calc(var(--sidebar-width) - 24px);
}

.topbar__tenant {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
}

.topbar__tenant-name {
    font-weight: 600;
}

.topbar__switch {
    color: #c9d6ea;
    font-size: 13px;
}

.topbar__user {
    display: flex;
    align-items: center;
    gap: 16px;
}

.topbar__identity {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    line-height: 1.2;
}

.topbar__name {
    font-weight: 600;
}

.topbar__role {
    font-size: 12px;
    color: #c9d6ea;
}

.topbar .btn--ghost {
    color: #ffffff;
    border-color: rgba(255, 255, 255, 0.4);
}

.shell {
    display: flex;
    flex: 1;
}

.sidebar {
    width: var(--sidebar-width);
    flex-shrink: 0;
    background: var(--color-surface);
    border-right: 1px solid var(--color-border);
    padding: 16px 0;
}

.sidebar__list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.sidebar__link {
    display: block;
    padding: 10px 24px;
    color: var(--color-text);
    text-decoration: none;
    border-left: 3px solid transparent;
}

.sidebar__link:hover {
    background: var(--color-bg);
}

.sidebar__link.is-active {
    border-left-color: var(--color-primary);
    background: var(--color-primary-soft);
    color: var(--color-primary);
    font-weight: 600;
}

.sidebar__link.is-disabled {
    color: #9aa4b2;
    cursor: not-allowed;
}

.main {
    flex: 1;
    padding: 32px 40px;
    max-width: 1200px;
}

.footer {
    padding: 16px 24px;
    font-size: 13px;
    color: var(--color-muted);
    border-top: 1px solid var(--color-border);
    background: var(--color-surface);
}

/* ---------- Page header & panel ---------- */

.page-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 24px;
    margin-bottom: 24px;
}

.page-header__title {
    font-size: 24px;
}

.page-header__subtitle {
    margin: 4px 0 0;
    color: var(--color-muted);
}

.panel {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
}

/* ---------- Loading / empty / error states ---------- */

.state {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    padding: 48px 24px;
    color: var(--color-muted);
    text-align: center;
}

.state p {
    margin: 0;
}

.state--error {
    color: var(--color-danger);
}

.spinner {
    width: 24px;
    height: 24px;
    border: 3px solid var(--color-border);
    border-top-color: var(--color-primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

/* ---------- Notices ---------- */

.notice-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.notice {
    padding: 20px 24px;
    border-bottom: 1px solid var(--color-border);
}

.notice:last-child {
    border-bottom: 0;
}

.notice--pinned {
    background: #fbfcfe;
    border-left: 3px solid var(--color-primary);
}

.notice__header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
}

.notice__title {
    font-size: 17px;
}

.notice__body {
    margin: 0 0 12px;
    /* Keeps the author's line breaks without converting text to HTML. */
    white-space: pre-line;
    overflow-wrap: anywhere;
}

.notice__meta {
    display: flex;
    gap: 6px;
    font-size: 13px;
    color: var(--color-muted);
}

.badge,
.tag {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
}

.badge--normal {
    background: var(--color-bg);
    color: var(--color-muted);
}

.badge--important {
    background: var(--color-warning-soft);
    color: var(--color-warning);
}

.badge--urgent {
    background: var(--color-danger-soft);
    color: var(--color-danger);
}

.tag {
    background: var(--color-primary-soft);
    color: var(--color-primary);
}

.tag--admin {
    background: #f2c94c;
    color: #3d2f00;
}

/* ---------- Buttons ---------- */

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 9px 16px;
    font: inherit;
    font-weight: 600;
    font-size: 14px;
    color: var(--color-text);
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    cursor: pointer;
    text-decoration: none;
}

.btn:hover {
    background: var(--color-bg);
}

.btn:focus-visible,
.sidebar__link:focus-visible,
.choice:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: 2px;
}

.btn:disabled {
    opacity: 0.6;
    cursor: progress;
}

.btn--primary {
    color: #ffffff;
    background: var(--color-primary);
    border-color: var(--color-primary);
}

.btn--primary:hover {
    background: var(--color-primary-hover);
}

.btn--ghost {
    background: transparent;
}

.btn--ghost:hover {
    background: rgba(255, 255, 255, 0.1);
}

.btn--block {
    width: 100%;
}

/* ---------- Forms ---------- */

.form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.form__field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex: 1;
}

.form__label {
    font-size: 13px;
    font-weight: 600;
}

.form__row {
    display: flex;
    align-items: flex-end;
    gap: 24px;
}

.form__check {
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 10px;
}

.form__error {
    font-size: 13px;
    color: var(--color-danger);
}

.form__error:empty {
    display: none;
}

input[type="text"],
input[type="email"],
input[type="password"],
select,
textarea {
    width: 100%;
    padding: 9px 12px;
    font: inherit;
    color: var(--color-text);
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
}

textarea {
    resize: vertical;
}

/* ---------- Alerts ---------- */

.alert {
    padding: 12px 16px;
    margin-bottom: 16px;
    border: 1px solid transparent;
    border-radius: var(--radius);
    font-size: 14px;
}

.alert--success {
    background: var(--color-success-soft);
    border-color: #abefc6;
    color: var(--color-success);
}

.alert--warning {
    background: var(--color-warning-soft);
    border-color: #fedf89;
    color: var(--color-warning);
}

.alert--error {
    background: var(--color-danger-soft);
    border-color: #fecdca;
    color: var(--color-danger);
}

.callout {
    padding: 16px;
    margin-bottom: 20px;
    background: var(--color-primary-soft);
    border-radius: var(--radius);
}

.callout p {
    margin: 0 0 12px;
}

/* ---------- Dialog ---------- */

.dialog {
    width: 640px;
    max-width: calc(100vw - 48px);
    padding: 28px;
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    box-shadow: 0 12px 32px rgba(16, 24, 40, 0.18);
}

.dialog::backdrop {
    background: rgba(16, 24, 40, 0.45);
}

.dialog__title {
    font-size: 20px;
}

.dialog__actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

/* ---------- Logged-out pages ---------- */

body.auth {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}

.auth__card {
    width: 420px;
    max-width: 100%;
    padding: 36px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-top: 4px solid var(--color-primary);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
}

.auth__brand {
    font-size: 22px;
    font-weight: 700;
    color: var(--color-primary);
    margin-bottom: 20px;
}

.auth__title {
    font-size: 20px;
    margin-bottom: 16px;
}

.auth__links {
    margin: 20px 0 0;
    font-size: 14px;
    text-align: center;
}

.auth .btn--ghost {
    border: 0;
    color: var(--color-muted);
}

.choice-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.choice {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    padding: 14px 16px;
    font: inherit;
    text-align: left;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    cursor: pointer;
}

.choice:hover {
    border-color: var(--color-primary);
    background: var(--color-primary-soft);
}

.choice__name {
    font-weight: 600;
}

.choice__detail {
    font-size: 13px;
    color: var(--color-muted);
}
```


---

## Setup

1. **Dependencies:** run `composer install`. It installs PHPMailer and generates the PSR-4 autoloader. PHP 8.2+ is required, with `pdo_mysql`, `mbstring` and `openssl`.
2. **Database**, run as a MySQL user with DDL rights:
   ```
   mysql -u root -p < database/schema.sql
   mysql -u root -p < database/migrations/0001_phase2_notice_authors.sql
   ```
   Then create the restricted application user:
   ```sql
   CREATE USER 'koinon_app'@'localhost' IDENTIFIED BY 'change-me';
   GRANT SELECT, INSERT, UPDATE, DELETE ON koinon.* TO 'koinon_app'@'localhost';
   ```
3. **Configuration:** run `cp .env.example .env`, then fill in `DB_*`, `APP_URL`, `MAIL_*`, and `SESSION_SECURE=true` for production. In production also set `APP_ENV=production`, `APP_DEBUG=false` and `MAIL_ENABLED=true`.
4. **Writable folders:** `storage/logs` and `storage/sessions` must be writable by the web server user.
5. **Document root:** point the virtual host at `koinon/public`. Apache needs `mod_rewrite` and `AllowOverride All`. For Nginx, use `root …/public;` and `location / { try_files $uri /index.php?$query_string; }`. For local development, run `php -S localhost:8000 -t public public/index.php`.
6. **First accounts** (there is no condominium management UI yet, so insert one condominium first):
   ```sql
   INSERT INTO koinon.condominiums (name, slug, signup_code, address_line, city, state_province, postal_code)
   VALUES ('Residencial Aurora', 'residencial-aurora', 'AURORA01', 'Rua A, 100', 'São Paulo', 'SP', '01000-000');
   ```
   ```
   php bin/create-user.php --super-admin --email=owner@example.com --name="Owner"
   php bin/create-user.php --email=sindico@example.com --name="Síndico" --condominium=1 --role=manager
   php bin/create-user.php --email=morador@example.com --name="Morador" --condominium=1 --role=resident --invite
   ```
   With `MAIL_ENABLED=false`, the activation links appear in `storage/logs/mail-dev.log`.
