# Koinon: Phase 3 Management Modules

Concierge, Reservations, Occurrences and Financial, built on the Phase 2 foundation.

This document is generated from the files in the repository. Verification so far:

- All PHP files pass `php -l` (PHP 8.4), and all JavaScript passes `node --check`.
- Every new view was rendered with sample data, and injected `<script>`, `<b>` and `<i>` came out escaped.
- The money parser and the date validators were unit-checked.
- Every new route was smoke-tested for redirect, 401, 404 and 405 behaviour.
- **No query has run against a live MySQL database yet.** Run migrations 0001 and 0002 and try each flow before relying on it.

## Assumptions

| # | Decision |
|---|----------|
| A-01 | **Names come from the real Phase 1 schema and Phase 2 code** in the repository: `visitors`/`visits`, `packages`, `common_areas`/`reservations`, `occurrences`/`occurrence_updates`, `invoices`/`invoice_items`, `units`/`unit_residents`. A resident's units are the rows in `unit_residents` that have no `move_out_date` in the past. |
| A-02 | **Tenant id source.** The brief asks that `condominium_id` always come from `$_SESSION['condominium_id']`. In this codebase, `TenantMiddleware` reads that session value, re-validates it against the database on every request, and publishes it as `TenantContext::id()`. `TenantModel` injects it into every query. Models never read `$_SESSION` or request input for the tenant. |
| A-03 | **Super Admin in these modules is read-only.** The Super Admin has `condominium_id = NULL` because they have no membership. To view a condominium they select one explicitly, and `TenantContext` is then set from `admin_condominium_id`, which is validated and audited. They can open the concierge desk, the reservation list, every ticket and the financial overview. They cannot register visits, book, reply or create charges. The schema records every actor in these modules (`created_by_user_id`, `author_user_id` and so on) as a member of that condominium (Phase 1 composite FKs), and Phase 1 FR-TEN-04 defines Super Admin support access as read-only. |
| A-04 | **Reservations use free time ranges** (migration `0002`), as the brief requires. Phase 1's fixed slots are now optional. A booking starts and ends on the same local day, lasts at least 30 minutes, and respects each area's minimum and maximum advance, capacity and per-unit limit. Times are the condominium's local wall-clock time. |
| A-05 | **Double booking is prevented by an area-row lock.** MySQL cannot declare "no overlapping ranges" as a constraint. Each booking therefore locks the `common_areas` row (`SELECT … FOR UPDATE`), runs the overlap query (`existing.start < new.end AND existing.end > new.start`, which also locks the overlapping rows), and inserts, all in one transaction. Locking only the existing reservations would not be enough: when the period is empty there is nothing to lock, and two inserts could both succeed. Shared areas such as the gym allow up to `bookings_per_slot` overlapping bookings. |
| A-06 | **Default areas.** BBQ ("Churrasqueira"), Party Room ("Salão de festas") and Gym ("Academia") are created automatically the first time a condominium opens Reservations, because there is no area-management screen yet. Default income categories are created the first time a manager opens Financial. |
| A-07 | **Occurrence types and statuses.** "Complaint" maps to the ENUM value `complaint` and "Damage Report" to `maintenance`. Statuses are limited to `open`, `in_progress` and `resolved`. Managers handle tickets. Concierge staff and residents see only the tickets they opened. Managers can add internal notes, which residents never receive: those notes are filtered out in SQL, not just hidden in HTML. |
| A-08 | **Charges.** A charge is an `open` invoice with one item, numbered per condominium through `tenant_counters`. Money is parsed from text into an exact decimal string (`"1234.50"`) and bound to `DECIMAL(12,2)`; floats are never used. The schema's unique key allows only one monthly fee per unit per month. Recording payments is outside this brief and left for a later phase. |
| A-09 | **Visitor RG (LGPD).** RGs are normalised (digits plus an optional final X). They are shown masked (`•••••678X`) and never included in JSON. Only the manager, concierge and Super Admin can open the desk screen, so residents never see visitor data. The RG field is not re-filled after a validation error, so it is never stored in the session. A retention or purge policy for old visitor records should be defined before production. |
| A-10 | **IDOR responses are 404.** A record of another condominium, or another resident's record, gets the same 404 as an id that does not exist. A 403 would confirm that the record exists. |
| A-11 | **Pickup code.** Package pickup requires the 6-digit code e-mailed to the unit's residents (Phase 1 FR-CON-11). The desk asks the resident for it; the code is never displayed on the desk screen. |

---

## Security model applied to every module

| Rule | Where it is enforced |
|------|---------------------|
| **Tenant isolation** | Every model extends `TenantModel`, and every query contains `condominium_id = :tenant` with the value from `scoped()`. `insert()` overwrites any `condominium_id` sent by a client. Joins repeat `x.condominium_id = y.condominium_id`. The composite foreign keys from Phase 1 reject cross-tenant links in the database itself. |
| **Ownership (IDOR)** | Residents: `UnitResident::activeUnitIds(Auth::id())` gives the units they may act on, and queries filter by them, or by `reported_by_user_id = me` for tickets, **inside SQL**. A record id from the URL is only a lookup key, so a tampered id returns no row and the request gets a 404. |
| **Server-side authorization** | Each route has a `role:` middleware built from controller constants (`VIEWERS`, `OPERATORS`, `BOOKERS`, `MANAGERS`, `HANDLERS`). Every action calls `requireRole()` again. The sidebar is built from the same constants. |
| **CSRF** | The `csrf` middleware runs on every POST. HTML forms carry it in the `_csrf` field and `fetch()` sends it in the `X-CSRF-Token` header (`http.js`). |
| **Output** | Views print everything through `e()`, and JavaScript writes only with `textContent`. |
| **Race conditions** | Area-row lock (reservations), `SELECT … FOR UPDATE` on packages (pickup), conditional `UPDATE … WHERE status = …` (visit exit, reservation decision, ticket status) and counter-row locks (numbering). |

---

## 0. Shared foundation changes (Phase 2 files)

These are small additions that every module reuses. For files that only gained methods, only the new code is shown.

New classes:

`app/Core/Validator.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

/**
 * Shared input validation for controllers.
 *
 * Each method reads one field from the request, records a user-facing error
 * when the value is invalid, and returns the normalised value (or null). The
 * controller then checks fails() once:
 *
 *   $v = new Validator($this->request);
 *   $unitId = $v->id('unit_id', 'Unidade');
 *   $amount = $v->money('amount', 'Valor');
 *   if ($v->fails()) {
 *       return $this->invalid($v->errors(), '/finance');
 *   }
 *
 * Validation produces typed, canonical values (int, 'Y-m-d', '1234.50').
 * It does not HTML-escape anything: escaping happens at output time with e(),
 * so data is stored as the user typed it and rendered safely everywhere.
 */
final class Validator
{
    /** @var array<string, string> field => message */
    private array $errors = [];

    public function __construct(private readonly Request $request)
    {
    }

    /** Trimmed text with a length range (in characters, not bytes). */
    public function string(string $field, string $label, int $min = 1, int $max = 255, bool $required = true): ?string
    {
        $value = trim($this->request->string($field));
        if ($value === '') {
            if ($required) {
                $this->errors[$field] = "{$label} é obrigatório.";
            }

            return null;
        }
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            $this->errors[$field] = "{$label} deve ter entre {$min} e {$max} caracteres.";

            return null;
        }

        return $value;
    }

    /** A positive integer id (e.g. a unit or area chosen in a <select>). */
    public function id(string $field, string $label): ?int
    {
        $value = $this->request->string($field);
        if (preg_match('/^[1-9]\d{0,9}$/', $value) !== 1) {
            $this->errors[$field] = "Selecione {$label}.";

            return null;
        }

        return (int) $value;
    }

    /** An integer within a range. */
    public function integer(string $field, string $label, int $min, int $max, ?int $default = null): ?int
    {
        $value = $this->request->string($field);
        if ($value === '' && $default !== null) {
            return $default;
        }
        if (preg_match('/^-?\d{1,9}$/', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
            $this->errors[$field] = "{$label} deve ser um número entre {$min} e {$max}.";

            return null;
        }

        return (int) $value;
    }

    /**
     * One of a fixed list of values (whitelist).
     *
     * @param list<string> $allowed
     */
    public function enum(string $field, string $label, array $allowed): ?string
    {
        $value = $this->request->string($field);
        if (!in_array($value, $allowed, true)) {
            $this->errors[$field] = "{$label} inválido.";

            return null;
        }

        return $value;
    }

    /** A calendar date in Y-m-d (the format of <input type="date">). */
    public function date(string $field, string $label): ?string
    {
        return $this->dateTimeFormat($field, $label, 'Y-m-d');
    }

    /** A time in H:i (the format of <input type="time">). */
    public function time(string $field, string $label): ?string
    {
        return $this->dateTimeFormat($field, $label, 'H:i');
    }

    /** A month in Y-m (the format of <input type="month">); returned as the first day, Y-m-01. */
    public function month(string $field, string $label): ?string
    {
        $value = $this->dateTimeFormat($field, $label, 'Y-m');

        return $value === null ? null : $value . '-01';
    }

    /**
     * A money amount, returned as a canonical decimal STRING ("1234.50").
     *
     * Floats are never involved: 0.1 + 0.2 !== 0.3 in binary floating point,
     * which is unacceptable for money. The string is bound to the DECIMAL(12,2)
     * column as-is. Accepts "1234.5", "1234,50" and "1.234,50".
     */
    public function money(string $field, string $label, string $min = '0.01', string $max = '9999999999.99'): ?string
    {
        $raw = str_replace([' ', "\u{00A0}", 'R$'], '', $this->request->string($field));
        if (str_contains($raw, ',')) {
            $raw = str_replace(['.', ','], ['', '.'], $raw); // pt-BR: "." thousands, "," decimals
        }

        if (preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $raw, $m) !== 1) {
            $this->errors[$field] = "{$label} inválido. Use o formato 1234,56.";

            return null;
        }

        $normalized = ltrim($m[1], '0') ?: '0';
        $normalized .= '.' . str_pad($m[2] ?? '', 2, '0');

        if (self::cents($normalized) < self::cents($min) || self::cents($normalized) > self::cents($max)) {
            $this->errors[$field] = "{$label} deve estar entre R$ " . self::formatBr($min) . ' e R$ ' . self::formatBr($max) . '.';

            return null;
        }

        return $normalized;
    }

    /** Adds an error found by a later business check (e.g. "date in the past"). */
    public function addError(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Integer cents of a canonical decimal string; exact, no float. */
    private static function cents(string $decimal): int
    {
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private static function formatBr(string $decimal): string
    {
        [$whole, $fraction] = explode('.', $decimal);

        return number_format((int) $whole, 0, ',', '.') . ',' . $fraction;
    }

    private function dateTimeFormat(string $field, string $label, string $format): ?string
    {
        $value = $this->request->string($field);
        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value);
        // Round-trip check rejects overflow such as 2026-02-31 (which PHP would roll into March).
        if ($parsed === false || $parsed->format($format) !== $value) {
            $this->errors[$field] = "{$label} inválido.";

            return null;
        }

        return $value;
    }
}
```

`app/Services/BusinessRuleException.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * A request that is well-formed but breaks a business rule (area already
 * booked, package already picked up, wrong pickup code...).
 *
 * Thrown by services, caught by controllers and shown to the user. The
 * message must therefore be safe to display. $status is the HTTP code for
 * JSON clients: 409 for conflicts with the current state, 422 for invalid data.
 */
final class BusinessRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 422,
        private readonly ?string $field = null
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** The form field the error belongs to, or null for a general error. */
    public function field(): ?string
    {
        return $this->field;
    }
}
```

`app/Core/Navigation.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\ConciergeController;
use App\Controllers\FinanceController;
use App\Controllers\OccurrenceController;
use App\Controllers\ReservationController;

/**
 * Sidebar entries visible to the current role.
 *
 * Built from the same role constants the routes use, so a link is shown
 * exactly when the route would let the user in. This is convenience only;
 * the routes and controllers enforce access.
 */
final class Navigation
{
    /** @return list<array{key: string, label: string, href: string}> */
    public static function items(): array
    {
        $items = [['key' => 'notices', 'label' => 'Mural de avisos', 'href' => '/dashboard']];

        $modules = [
            ['concierge', 'Portaria', '/concierge', ConciergeController::VIEWERS],
            ['reservations', 'Reservas', '/reservations', ReservationController::VIEWERS],
            ['occurrences', 'Ocorrências', '/occurrences', OccurrenceController::VIEWERS],
            ['finance', 'Financeiro', '/finance', FinanceController::VIEWERS],
        ];
        foreach ($modules as [$key, $label, $href, $roles]) {
            if (Auth::hasRole($roles)) {
                $items[] = ['key' => $key, 'label' => $label, 'href' => $href];
            }
        }

        return $items;
    }
}
```


`app/Core/TenantContext.php` now also carries the condominium's time zone, used for "today", "in the past" and local display. The full file:

`app/Core/TenantContext.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
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
    private static string $timezone = 'UTC';

    public static function set(int $condominiumId, string $condominiumName, string $timezone = 'UTC'): void
    {
        self::$condominiumId = $condominiumId;
        self::$condominiumName = $condominiumName;
        self::$timezone = in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
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

    /** The condominium's time zone (condominiums.timezone), e.g. "America/Sao_Paulo". */
    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::$timezone);
    }

    /**
     * Current local time of the condominium. Reservation dates/times and invoice
     * due dates are local wall-clock values (Phase 1, A-09), so "today" and
     * "in the past" must be judged in the condominium's zone, not in UTC.
     */
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::timezone());
    }

    /** Today's local date as Y-m-d. */
    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }
}
```


**Added to `app/Core/Controller.php`.** `view()` now also shares `navigation`, `errors` and `old`. New methods `invalid()` and `done()` answer HTML forms and JSON clients with one call:

```php
// … excerpt from app/Core/Controller.php
            'navigation'      => $user === null ? [] : Navigation::items(),
            'flashes'         => Session::pullFlashes(['success', 'warning', 'error']),
            // Field errors and previous input after a failed form POST (see invalid()).
            'errors'          => Session::pullFlashData('errors'),
            'old'             => Session::pullFlashData('old'),
```

```php
// … excerpt from app/Core/Controller.php
    /**
     * Answers a request whose input failed validation or a business rule.
     *
     * - fetch()/API clients get JSON {"error": ..., "errors": {field: message}}
     *   with the given status (422 invalid data, 409 conflict with current state).
     * - HTML forms are redirected back with the errors and the submitted input
     *   flashed, so the form is redisplayed filled in (Post/Redirect/Get).
     *
     * @param array<string, string> $errors field => message
     * @param list<string>          $keepInput fields to re-fill (never passwords or documents)
     */
    protected function invalid(array $errors, string $redirectTo, int $status = 422, array $keepInput = []): Response
    {
        $summary = $status === 409
            ? (string) (reset($errors) ?: 'A operação conflita com o estado atual.')
            : 'Corrija os campos destacados e tente novamente.';

        if ($this->request->wantsJson()) {
            return $this->json(['error' => $summary, 'errors' => $errors], $status);
        }

        $old = [];
        foreach ($keepInput as $field) {
            $old[$field] = $this->request->string($field);
        }
        Session::flashData('errors', $errors);
        Session::flashData('old', $old);
        Session::flash('error', $summary);

        return $this->redirect($redirectTo);
    }
```

```php
// … excerpt from app/Core/Controller.php
    /**
     * Success answer for both kinds of client: JSON payload, or flash + redirect.
     *
     * @param array<string, mixed> $payload
     */
    protected function done(string $message, string $redirectTo, array $payload = [], int $status = 200): Response
    {
        if ($this->request->wantsJson()) {
            return $this->json(['message' => $message] + $payload, $status);
        }
        Session::flash('success', $message);

        return $this->redirect($redirectTo);
    }
```


**Added to `app/Core/Model.php`.** These are bound `IN (...)` lists:

```php
// … excerpt from app/Core/Model.php
    /**
     * Builds an IN (...) list of bound placeholders for integer ids:
     *   [$sql, $params] = $this->inList('unit', [3, 7]);  // ":unit0, :unit1", ['unit0' => 3, 'unit1' => 7]
     * Only placeholder names are generated; the values stay bound parameters.
     * Callers must handle an empty list themselves (IN () is invalid SQL).
     *
     * @param list<int> $ids
     * @return array{0: string, 1: array<string, int>}
     */
    protected function inList(string $prefix, array $ids): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $placeholders[] = ':' . $prefix . $i;
            $params[$prefix . $i] = (int) $id;
        }

        return [implode(', ', $placeholders), $params];
    }
```


**Added to `app/Core/Session.php`.** These flash arrays (validation errors and old input):

```php
// … excerpt from app/Core/Session.php
    /**
     * Flashes structured data for one redirect, e.g. validation errors and the
     * previously submitted input, so a form can be redisplayed after a POST.
     *
     * @param array<string, mixed> $data
     */
    public static function flashData(string $key, array $data): void
    {
        $_SESSION[self::FLASH_KEY . '_data'][$key] = $data;
    }
```

```php
// … excerpt from app/Core/Session.php
    /**
     * Returns and removes flashed structured data ([] when absent).
     *
     * @return array<string, mixed>
     */
    public static function pullFlashData(string $key): array
    {
        $value = $_SESSION[self::FLASH_KEY . '_data'][$key] ?? [];
        unset($_SESSION[self::FLASH_KEY . '_data'][$key]);

        return is_array($value) ? $value : [];
    }
```


**Added to `app/Core/helpers.php`:**

```php
// … excerpt from app/Core/helpers.php
if (!function_exists('money_br')) {
    /**
     * Formats a DECIMAL string from the database as Brazilian currency
     * ("1234.50" -> "R$ 1.234,50") using string arithmetic only, no float.
     */
    function money_br(string $decimal): string
    {
        $negative = str_starts_with($decimal, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($decimal, '-'), 2), 2, '00');
        $whole = number_format((int) $whole, 0, ',', '.');

        return ($negative ? '-' : '') . 'R$ ' . $whole . ',' . str_pad(substr($fraction, 0, 2), 2, '0');
    }
}

if (!function_exists('date_br')) {
    /** Formats "Y-m-d" or "Y-m-d H:i:s" as "d/m/Y" or "d/m/Y H:i" (no time-zone conversion). */
    function date_br(?string $value, bool $withTime = false): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $date = new DateTimeImmutable($value);

        return $date->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
    }
}

if (!function_exists('local_datetime')) {
    /**
     * Converts a UTC DATETIME from the database (created_at, entry_at...) to the
     * condominium's local time for display. Do NOT use it for reservation times,
     * which are already stored as local wall-clock values.
     */
    function local_datetime(?string $utc, string $format = 'd/m/Y H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        $date = new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        return $date->setTimezone(App\Core\TenantContext::timezone())->format($format);
    }
}

if (!function_exists('mask_document')) {
    /**
     * Masks an ID number (RG) for display: only the last 3 characters remain.
     *
     * LGPD data minimisation: lists and screens need to tell visitors apart, not
     * to expose their full ID. The full number is only compared server-side.
     */
    function mask_document(string $document): string
    {
        $visible = mb_substr($document, -3);

        return str_repeat('•', max(3, mb_strlen($document) - 3)) . $visible;
    }
}

if (!function_exists('field_error')) {
    /**
     * Renders the error message of one form field, or nothing.
     *
     * @param array<string, string> $errors
     */
    function field_error(array $errors, string $field): string
    {
        return isset($errors[$field])
            ? '<span class="form__error">' . e($errors[$field]) . '</span>'
            : '';
    }
}
```


**Changed in `app/Models/Membership.php`, `app/Models/Condominium.php` and `app/Middleware/TenantMiddleware.php`.** These now select `c.timezone` and pass it on, e.g. in `TenantMiddleware::handle()`:

```php
// … excerpt from app/Middleware/TenantMiddleware.php
        Auth::enterMembership($membership); // refresh role from the database
        TenantContext::set(
            (int) $membership['condominium_id'],
            (string) $membership['condominium_name'],
            (string) $membership['condominium_timezone']
        );
```


**Changed in `app/Views/layouts/app.php`.** The sidebar comes from `Navigation::items()`, which lists only the modules the role may open:

```php
// … excerpt from app/Views/layouts/app.php
            <?php foreach ($navigation as $item): ?>
                <li>
                    <a class="sidebar__link<?= $activeNav === $item['key'] ? ' is-active' : '' ?>"
                       href="<?= e($item['href']) ?>"
                       <?= $activeNav === $item['key'] ? 'aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
                </li>
            <?php endforeach; ?>
```


**Shared models used by several modules:**

`app/Models/Unit.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Units (apartments/houses) of the current condominium.
 */
final class Unit extends TenantModel
{
    /** "Bloco B 1203", or just "1203" for single-building condominiums. */
    public const LABEL_SQL = "CONCAT_WS(' ', NULLIF(u.building, ''), u.unit_number)";

    protected string $table = 'units';

    /**
     * Active units of the current tenant, for <select> lists.
     *
     * @return list<array{id: int, label: string}>
     */
    public function active(): array
    {
        return $this->fetchAll(
            'SELECT u.id, ' . self::LABEL_SQL . ' AS label
               FROM units u
              WHERE u.condominium_id = :tenant   -- tenant scope
                AND u.is_active = 1
              ORDER BY u.building, LENGTH(u.unit_number), u.unit_number',
            $this->scoped()
        );
    }

    /**
     * One active unit of the current tenant, or null.
     *
     * This is the "unit belongs to the condominium" check: an id from another
     * condominium is simply not found, whatever the client sent.
     */
    public function findActive(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT u.id, ' . self::LABEL_SQL . ' AS label
               FROM units u
              WHERE u.condominium_id = :tenant AND u.id = :id AND u.is_active = 1',
            $this->scoped(['id' => $id])
        );
    }
}
```

`app/Models/UnitResident.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantContext;
use App\Core\TenantModel;

/**
 * Links between members and units (`unit_residents`).
 *
 * This model answers the Resident ownership question used across modules:
 * "which units does this user currently live in?". Every module filters a
 * resident's data by these unit ids (or by their own user id).
 */
final class UnitResident extends TenantModel
{
    protected string $table = 'unit_residents';

    /**
     * Ids of the units the user is currently linked to in the current tenant.
     *
     * @param bool $billingOnly Only owner/tenant links: dependents do not see the
     *                          unit's bills (Phase 1, FR-FIN-10).
     * @return list<int>
     */
    public function activeUnitIds(int $userId, bool $billingOnly = false): array
    {
        $sql = 'SELECT unit_id
                  FROM unit_residents
                 WHERE condominium_id = :tenant          -- tenant scope
                   AND user_id = :user_id                -- only the links of THIS user
                   AND (move_out_date IS NULL OR move_out_date >= :today)';
        if ($billingOnly) {
            $sql .= " AND relationship IN ('owner', 'tenant')";
        }

        $rows = $this->fetchAll($sql, $this->scoped(['user_id' => $userId, 'today' => TenantContext::today()]));

        return array_map(static fn (array $row): int => (int) $row['unit_id'], $rows);
    }

    /**
     * E-mail contacts of the people currently living in a unit (active accounts
     * and memberships only). Used to notify residents about a package.
     *
     * @return list<array{email: string, full_name: string}>
     */
    public function contactsForUnit(int $unitId): array
    {
        return $this->fetchAll(
            "SELECT us.email, us.full_name
               FROM unit_residents ur
               JOIN condominium_users cu
                 ON cu.condominium_id = ur.condominium_id AND cu.user_id = ur.user_id AND cu.status = 'active'
               JOIN users us ON us.id = ur.user_id AND us.status = 'active'
              WHERE ur.condominium_id = :tenant
                AND ur.unit_id = :unit_id
                AND (ur.move_out_date IS NULL OR ur.move_out_date >= :today)",
            $this->scoped(['unit_id' => $unitId, 'today' => TenantContext::today()])
        );
    }
}
```

`app/Models/TenantCounter.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;
use LogicException;

/**
 * Gap-free per-condominium numbering (`tenant_counters`): invoice numbers and
 * occurrence protocol numbers.
 */
final class TenantCounter extends TenantModel
{
    protected string $table = 'tenant_counters';

    /**
     * Returns the next number of $counter ('invoice' | 'occurrence') for the
     * current tenant and advances it.
     *
     * Must run inside the transaction that inserts the numbered row. The
     * counter row stays locked (FOR UPDATE) until commit, so concurrent
     * requests get distinct numbers, and a rollback also rolls the counter
     * back, so no number is ever skipped.
     */
    public function next(string $counter): int
    {
        if (!$this->db()->inTransaction()) {
            throw new LogicException('TenantCounter::next() must run inside a transaction.');
        }

        // Creates the row the first time a tenant needs this counter.
        $this->execute(
            'INSERT INTO tenant_counters (condominium_id, counter_name, next_value)
             VALUES (:tenant, :counter, 1)
             ON DUPLICATE KEY UPDATE next_value = next_value',
            $this->scoped(['counter' => $counter])
        );

        $row = $this->fetchOne(
            'SELECT next_value FROM tenant_counters
              WHERE condominium_id = :tenant AND counter_name = :counter
              FOR UPDATE',
            $this->scoped(['counter' => $counter])
        );

        $this->execute(
            'UPDATE tenant_counters SET next_value = next_value + 1
              WHERE condominium_id = :tenant AND counter_name = :counter',
            $this->scoped(['counter' => $counter])
        );

        return (int) $row['next_value'];
    }
}
```


---

## 1. Concierge (Portaria)

Roles: **Concierge and Property Manager** can act (`OPERATORS`). The **Super Admin** can view. **Residents** have no access.

### Models

`app/Models/Visitor.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Registry of people who visited the current condominium (`visitors`).
 *
 * LGPD: document_number (RG) is personal data. It is stored because the desk
 * must identify returning and blocked visitors, but it is never sent to the
 * browser in full: views show mask_document(), and no JSON payload includes it.
 * Each condominium has its own registry; a visitor known in one condominium is
 * unknown in another.
 */
final class Visitor extends TenantModel
{
    protected string $table = 'visitors';

    protected array $fillable = [
        'full_name',
        'document_type',
        'document_number',
        'phone',
        'created_by_user_id',
    ];

    /**
     * The visitor of THIS condominium with this document, or null.
     * Matches the unique key uq_visitors_document (condominium_id, document_type, document_number).
     */
    public function findByDocument(string $documentType, string $documentNumber): ?array
    {
        return $this->fetchOne(
            'SELECT id, full_name, is_blocked, block_reason
               FROM visitors
              WHERE condominium_id = :tenant
                AND document_type = :document_type
                AND document_number = :document_number',
            $this->scoped(['document_type' => $documentType, 'document_number' => $documentNumber])
        );
    }
}
```

`app/Models/Visit.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Entry/exit events at the gate (`visits`). entry_at/exit_at are UTC.
 */
final class Visit extends TenantModel
{
    public const TYPES = [
        'guest'            => 'Visitante',
        'service_provider' => 'Prestador de serviço',
        'delivery'         => 'Entregador',
        'other'            => 'Outro',
    ];

    protected string $table = 'visits';

    protected array $fillable = [
        'visitor_id',
        'unit_id',
        'visit_type',
        'vehicle_plate',
        'status',
        'entry_at',
        'created_by_user_id',
        'entry_registered_by_user_id',
        'notes',
    ];

    /**
     * Shared SELECT. Every JOIN repeats the condominium_id equality: the composite
     * foreign keys already guarantee it, and repeating it means the query stays
     * tenant-safe even if copied somewhere without those guarantees.
     */
    private const LIST_SELECT = 'SELECT vi.id, vi.visit_type, vi.vehicle_plate, vi.status, vi.entry_at, vi.exit_at,
               v.full_name, v.document_number, ' . Unit::LABEL_SQL . ' AS unit_label
          FROM visits vi
          JOIN visitors v ON v.condominium_id = vi.condominium_id AND v.id = vi.visitor_id
          JOIN units u    ON u.condominium_id = vi.condominium_id AND u.id = vi.unit_id
         WHERE vi.condominium_id = :tenant ';

    /**
     * Visitors currently inside (served by ix_visits_gate).
     *
     * @return list<array<string, mixed>>
     */
    public function currentlyInside(): array
    {
        return $this->fetchAll(
            self::LIST_SELECT . "AND vi.status = 'inside' ORDER BY vi.entry_at DESC LIMIT 200",
            $this->scoped()
        );
    }

    /**
     * Most recent exits, for the desk's history panel.
     *
     * @return list<array<string, mixed>>
     */
    public function recentExits(int $limit = 15): array
    {
        return $this->fetchAll(
            self::LIST_SELECT . "AND vi.status = 'exited' ORDER BY vi.exit_at DESC LIMIT :limit",
            $this->scoped(['limit' => $limit])
        );
    }

    /**
     * Records the exit atomically.
     *
     * The WHERE clause carries the whole rule: same tenant, and still inside.
     * A second click, or a tampered id from another condominium, changes zero
     * rows, so the caller can tell "already exited / not found" apart from success.
     */
    public function registerExit(int $id, int $userId): bool
    {
        return $this->execute(
            "UPDATE visits
                SET status = 'exited',
                    exit_at = UTC_TIMESTAMP(),
                    exit_registered_by_user_id = :user_id
              WHERE id = :id
                AND condominium_id = :tenant
                AND status = 'inside'",
            $this->scoped(['id' => $id, 'user_id' => $userId])
        ) === 1;
    }
}
```

`app/Models/Package.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Deliveries held at the concierge desk (`packages`).
 */
final class Package extends TenantModel
{
    public const SIZES = [
        'envelope' => 'Envelope',
        'small'    => 'Pequeno',
        'medium'   => 'Médio',
        'large'    => 'Grande',
    ];

    protected string $table = 'packages';

    protected array $fillable = [
        'unit_id',
        'carrier',
        'tracking_code',
        'description',
        'package_size',
        'storage_location',
        'pickup_code',
        'received_by_user_id',
    ];

    /**
     * Packages waiting at the desk (served by ix_packages_desk).
     * pickup_code is NOT selected: the desk asks the resident for it instead of reading it.
     *
     * @return list<array<string, mixed>>
     */
    public function awaitingPickup(): array
    {
        return $this->fetchAll(
            "SELECT p.id, p.carrier, p.tracking_code, p.description, p.package_size, p.storage_location,
                    p.received_at, " . Unit::LABEL_SQL . " AS unit_label
               FROM packages p
               JOIN units u ON u.condominium_id = p.condominium_id AND u.id = p.unit_id
              WHERE p.condominium_id = :tenant
                AND p.status = 'awaiting_pickup'
              ORDER BY p.received_at",
            $this->scoped()
        );
    }

    /**
     * Loads a package of the current tenant and LOCKS it until the transaction
     * ends, so two simultaneous pickups of the same package cannot both pass
     * the status and code checks.
     */
    public function lockForPickup(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT id, status, pickup_code
               FROM packages
              WHERE id = :id AND condominium_id = :tenant
              FOR UPDATE',
            $this->scoped(['id' => $id])
        );
    }

    /** Marks the package as collected. Only changes a package of this tenant that is still waiting. */
    public function markPickedUp(int $id, string $pickedUpByName, int $userId): bool
    {
        return $this->execute(
            "UPDATE packages
                SET status = 'picked_up',
                    picked_up_at = UTC_TIMESTAMP(),
                    picked_up_by_name = :picked_up_by_name,
                    handed_over_by_user_id = :user_id
              WHERE id = :id
                AND condominium_id = :tenant
                AND status = 'awaiting_pickup'",
            $this->scoped(['id' => $id, 'picked_up_by_name' => $pickedUpByName, 'user_id' => $userId])
        ) === 1;
    }

    /** Records that at least one resident was e-mailed. */
    public function markNotified(int $id): void
    {
        $this->execute(
            'UPDATE packages SET resident_notified_at = UTC_TIMESTAMP()
              WHERE id = :id AND condominium_id = :tenant',
            $this->scoped(['id' => $id])
        );
    }

    /** Pickup details after a successful pickup (for the JSON response). */
    public function pickupSummary(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT id, status, picked_up_at, picked_up_by_name
               FROM packages
              WHERE id = :id AND condominium_id = :tenant',
            $this->scoped(['id' => $id])
        );
    }
}
```


### Service

Business rules for entries, exits, packages and pickups. The notification uses the Phase 2 mailer; this new method was added to `app/Mail/Mailer.php`:

```php
// … excerpt from app/Mail/Mailer.php
    /**
     * Tells a resident that a package is waiting at the desk, with the pickup code.
     * All values are escaped with e(): carrier and description are typed by staff
     * and could contain HTML.
     */
    public function sendPackageNotice(
        string $toEmail,
        string $toName,
        string $condominiumName,
        string $unitLabel,
        ?string $carrier,
        string $pickupCode
    ): bool {
        $from = $carrier !== null && $carrier !== '' ? " ({$carrier})" : '';
        $text = "Olá, {$toName}!\n\n"
            . "Chegou uma encomenda{$from} para a unidade {$unitLabel} no {$condominiumName}.\n"
            . "Retire na portaria informando o código: {$pickupCode}\n\n"
            . "Não compartilhe este código com quem não deve retirar a encomenda.\n";
        $html = '<p>Olá, ' . e($toName) . '!</p>'
            . '<p>Chegou uma encomenda' . e($from) . ' para a unidade <strong>' . e($unitLabel)
            . '</strong> no ' . e($condominiumName) . '.</p>'
            . '<p>Retire na portaria informando o código: <strong style="font-size:20px;letter-spacing:3px;">'
            . e($pickupCode) . '</strong></p>'
            . '<p style="color:#5f6b7a;font-size:13px;">Não compartilhe este código com quem não deve retirar a encomenda.</p>';

        return $this->send($toEmail, $toName, 'Encomenda na portaria - Koinon', $html, $text);
    }
```

`app/Services/ConciergeService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Mail\Mailer;
use App\Models\Package;
use App\Models\Unit;
use App\Models\UnitResident;
use App\Models\Visit;
use App\Models\Visitor;

/**
 * Business rules of the concierge desk: visitor entries/exits and packages.
 *
 * Every model used here is a TenantModel, so all reads and writes are scoped
 * to TenantContext::id(); this class never handles a condominium id itself.
 */
final class ConciergeService
{
    public function __construct(
        private readonly Visitor $visitors = new Visitor(),
        private readonly Visit $visits = new Visit(),
        private readonly Package $packages = new Package(),
        private readonly Unit $units = new Unit(),
        private readonly UnitResident $residents = new UnitResident(),
        private readonly Mailer $mailer = new Mailer()
    ) {
    }

    /**
     * Registers a visitor entering now. Reuses the visitor record when the same
     * RG is already known in this condominium, and refuses blocked visitors.
     *
     * @param array{full_name: string, document_number: string, unit_id: int, visit_type: string,
     *              vehicle_plate: ?string, notes: ?string} $data
     * @return int The visit id.
     * @throws BusinessRuleException
     */
    public function registerEntry(array $data, int $staffUserId): int
    {
        // Ownership of the destination: the unit must belong to THIS condominium.
        if ($this->units->findActive($data['unit_id']) === null) {
            throw new BusinessRuleException('Unidade não encontrada.', 422, 'unit_id');
        }

        return Database::transaction(function () use ($data, $staffUserId): int {
            $visitor = $this->visitors->findByDocument('national_id', $data['document_number']);

            if ($visitor !== null && (bool) $visitor['is_blocked']) {
                throw new BusinessRuleException(
                    'Entrada não permitida: visitante bloqueado. Motivo: ' . $visitor['block_reason'],
                    409,
                    'document_number'
                );
            }

            $visitorId = $visitor !== null
                ? (int) $visitor['id']
                : $this->visitors->insert([
                    'full_name'          => $data['full_name'],
                    'document_type'      => 'national_id',
                    'document_number'    => $data['document_number'],
                    'created_by_user_id' => $staffUserId,
                ]);

            return $this->visits->insert([
                'visitor_id'                  => $visitorId,
                'unit_id'                     => $data['unit_id'],
                'visit_type'                  => $data['visit_type'],
                'vehicle_plate'               => $data['vehicle_plate'],
                'notes'                       => $data['notes'],
                'status'                      => 'inside',
                'entry_at'                    => gmdate('Y-m-d H:i:s'),
                'created_by_user_id'          => $staffUserId,
                'entry_registered_by_user_id' => $staffUserId,
            ]);
        });
    }

    /**
     * Registers the exit of a visitor who is inside.
     *
     * @throws BusinessRuleException 404 when the visit is not in this condominium, 409 when it is not "inside".
     */
    public function registerExit(int $visitId, int $staffUserId): void
    {
        if ($this->visits->registerExit($visitId, $staffUserId)) {
            return;
        }
        // Zero rows changed: tell "unknown here" apart from "already out".
        $visit = $this->visits->find($visitId);
        if ($visit === null) {
            throw new BusinessRuleException('Registro de visita não encontrado.', 404);
        }
        throw new BusinessRuleException('A saída deste visitante já foi registrada.', 409);
    }

    /**
     * Logs a delivery and e-mails the unit's residents their pickup code.
     *
     * @param array{unit_id: int, carrier: ?string, tracking_code: ?string, description: ?string,
     *              package_size: string, storage_location: ?string} $data
     * @return array{id: int, notified: int} Package id and number of e-mails sent.
     * @throws BusinessRuleException
     */
    public function registerPackage(array $data, int $staffUserId): array
    {
        $unit = $this->units->findActive($data['unit_id'])
            ?? throw new BusinessRuleException('Unidade não encontrada.', 422, 'unit_id');

        // 6 random digits from a CSPRNG. The code proves at the desk that the
        // person collecting received the notification.
        $pickupCode = sprintf('%06d', random_int(0, 999999));

        $packageId = $this->packages->insert($data + [
            'pickup_code'         => $pickupCode,
            'received_by_user_id' => $staffUserId,
        ]);

        // Mail goes out after the insert has committed (it is a single statement),
        // and a mail failure never undoes the registration: the package IS at the desk.
        $sent = 0;
        foreach ($this->residents->contactsForUnit($data['unit_id']) as $contact) {
            $ok = $this->mailer->sendPackageNotice(
                $contact['email'],
                $contact['full_name'],
                (string) TenantContext::name(),
                (string) $unit['label'],
                $data['carrier'],
                $pickupCode
            );
            $sent += $ok ? 1 : 0;
        }
        if ($sent > 0) {
            $this->packages->markNotified($packageId);
        } else {
            Logger::warning('Package registered without resident notification', ['package_id' => $packageId]);
        }

        return ['id' => $packageId, 'notified' => $sent];
    }

    /**
     * Hands a package over, after checking the pickup code.
     *
     * The package row is locked (FOR UPDATE) while status and code are checked
     * and the update is written, so a double click or two desks acting at once
     * cannot both succeed.
     *
     * @return array<string, mixed> The updated package summary.
     * @throws BusinessRuleException 404 unknown here, 409 already handled, 422 wrong code
     */
    public function confirmPickup(int $packageId, string $pickupCode, string $pickedUpByName, int $staffUserId): array
    {
        return Database::transaction(function () use ($packageId, $pickupCode, $pickedUpByName, $staffUserId): array {
            $package = $this->packages->lockForPickup($packageId)
                ?? throw new BusinessRuleException('Encomenda não encontrada.', 404);

            if ($package['status'] !== 'awaiting_pickup') {
                throw new BusinessRuleException('Esta encomenda já foi retirada ou devolvida.', 409);
            }
            // Constant-time comparison, like any secret.
            if (!hash_equals((string) $package['pickup_code'], $pickupCode)) {
                throw new BusinessRuleException('Código de retirada incorreto.', 422, 'pickup_code');
            }

            $this->packages->markPickedUp($packageId, $pickedUpByName, $staffUserId);

            return (array) $this->packages->pickupSummary($packageId);
        });
    }
}
```


### Controller

`app/Controllers/ConciergeController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Core\TenantContext;
use App\Core\Validator;
use App\Models\Package;
use App\Models\Unit;
use App\Models\Visit;
use App\Services\BusinessRuleException;
use App\Services\ConciergeService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Concierge desk (Portaria): visitor entries/exits and packages.
 *
 * Residents have no access at all: the desk shows visitors' personal data
 * (names, masked RG) that residents do not need (LGPD).
 */
final class ConciergeController extends Controller
{
    /** May open the desk screen. The Super Admin only reads (see Phase 3 assumptions). */
    public const VIEWERS = [Auth::SUPER_ADMIN, 'manager', 'concierge'];

    /** May register entries, exits, packages and pickups. */
    public const OPERATORS = ['manager', 'concierge'];

    /** GET /concierge */
    public function index(): Response
    {
        $this->requireRole(self::VIEWERS);

        return $this->view('concierge/index', [
            'title'       => 'Portaria',
            'activeNav'   => 'concierge',
            'canOperate'  => Auth::hasRole(self::OPERATORS),
            'units'       => (new Unit())->active(),
            'inside'      => (new Visit())->currentlyInside(),
            'recentExits' => (new Visit())->recentExits(),
            'packages'    => (new Package())->awaitingPickup(),
            'visitTypes'  => Visit::TYPES,
            'sizes'       => Package::SIZES,
            'scripts'     => ['js/concierge.js'],
        ]);
    }

    /**
     * POST /concierge/visits (HTML form; CSRF checked by the route middleware).
     * Registers a visitor entering now.
     */
    public function storeVisit(): Response
    {
        $this->requireRole(self::OPERATORS);

        $v = new Validator($this->request);
        $name = $v->string('full_name', 'Nome', 3, 150);
        $document = self::normalizeDocument($this->request->string('document_number'));
        if ($document === null) {
            $v->addError('document_number', 'RG inválido: use de 5 a 20 dígitos (pode terminar em X).');
        }
        $unitId = $v->id('unit_id', 'a unidade visitada');
        $type = $v->enum('visit_type', 'Tipo de visita', array_keys(Visit::TYPES));
        $plate = self::normalizePlate($this->request->string('vehicle_plate'));
        $notes = $v->string('notes', 'Observações', 1, 500, required: false);

        // The RG is deliberately NOT kept for re-display (personal data stays out of the session).
        $keep = ['full_name', 'unit_id', 'visit_type', 'vehicle_plate', 'notes'];
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/concierge', 422, $keep);
        }

        try {
            (new ConciergeService())->registerEntry([
                'full_name'       => $name,
                'document_number' => $document,
                'unit_id'         => $unitId,
                'visit_type'      => $type,
                'vehicle_plate'   => $plate,
                'notes'           => $notes,
            ], (int) Auth::id());
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], '/concierge', $e->status(), $keep);
        }

        return $this->done('Entrada registrada.', '/concierge');
    }

    /** POST /api/concierge/visits/{id}/exit (fetch, JSON). */
    public function exitVisit(string $id): Response
    {
        $this->requireRole(self::OPERATORS);

        try {
            (new ConciergeService())->registerExit((int) $id, (int) Auth::id());
        } catch (BusinessRuleException $e) {
            return $this->json(['error' => $e->getMessage()], $e->status());
        }

        return $this->json([
            'message' => 'Saída registrada.',
            'visit'   => ['id' => (int) $id, 'status' => 'exited', 'exit_time' => TenantContext::now()->format('H:i')],
        ]);
    }

    /** POST /concierge/packages (HTML form): logs a delivery and notifies the unit. */
    public function storePackage(): Response
    {
        $this->requireRole(self::OPERATORS);

        $v = new Validator($this->request);
        $unitId = $v->id('unit_id', 'a unidade de destino');
        $carrier = $v->string('carrier', 'Transportadora', 2, 80, required: false);
        $tracking = $v->string('tracking_code', 'Código de rastreio', 3, 60, required: false);
        $description = $v->string('description', 'Descrição', 2, 255, required: false);
        $size = $v->enum('package_size', 'Tamanho', array_keys(Package::SIZES));
        $location = $v->string('storage_location', 'Local de armazenamento', 1, 60, required: false);

        $keep = ['unit_id', 'carrier', 'tracking_code', 'description', 'package_size', 'storage_location'];
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/concierge', 422, $keep);
        }

        try {
            $result = (new ConciergeService())->registerPackage([
                'unit_id'          => $unitId,
                'carrier'          => $carrier,
                'tracking_code'    => $tracking,
                'description'      => $description,
                'package_size'     => $size,
                'storage_location' => $location,
            ], (int) Auth::id());
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], '/concierge', $e->status(), $keep);
        }

        $message = $result['notified'] > 0
            ? "Encomenda registrada. {$result['notified']} morador(es) avisado(s) por e-mail."
            : 'Encomenda registrada. Nenhum morador com e-mail ativo foi encontrado para esta unidade: avise pelo interfone.';

        return $this->done($message, '/concierge');
    }

    /**
     * POST /api/concierge/packages/{id}/pickup (fetch, JSON).
     * Body: {"pickup_code": "123456", "picked_up_by_name": "Maria Silva"}
     *
     * Status codes: 200 done, 404 not in this condominium, 409 already picked
     * up, 422 invalid input or wrong code. 401/403/419 come from middleware.
     */
    public function pickupPackage(string $id): Response
    {
        $this->requireRole(self::OPERATORS);

        $v = new Validator($this->request);
        $name = $v->string('picked_up_by_name', 'Nome de quem retirou', 3, 150);
        $code = $this->request->string('pickup_code');
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            $v->addError('pickup_code', 'Informe o código de 6 dígitos.');
        }
        if ($v->fails()) {
            return $this->json(['error' => 'Verifique os campos destacados.', 'errors' => $v->errors()], 422);
        }

        try {
            $package = (new ConciergeService())->confirmPickup((int) $id, $code, (string) $name, (int) Auth::id());
        } catch (BusinessRuleException $e) {
            $errors = $e->field() !== null ? [$e->field() => $e->getMessage()] : [];

            return $this->json(['error' => $e->getMessage(), 'errors' => $errors], $e->status());
        }

        $pickedUpAt = (new DateTimeImmutable((string) $package['picked_up_at'], new DateTimeZone('UTC')))
            ->setTimezone(TenantContext::timezone());

        return $this->json([
            'message' => 'Retirada registrada.',
            'package' => [
                'id'                => (int) $package['id'],
                'status'            => (string) $package['status'],
                'picked_up_by_name' => (string) $package['picked_up_by_name'],
                'picked_up_at'      => $pickedUpAt->format('d/m/Y H:i'),
            ],
        ]);
    }

    /**
     * Normalises an RG: keeps digits and a final X (check digit), so "12.345.678-x"
     * and "12345678X" are the same visitor. Returns null when invalid.
     */
    private static function normalizeDocument(string $raw): ?string
    {
        $value = strtoupper(preg_replace('/[^0-9Xx]/', '', $raw) ?? '');

        return preg_match('/^\d{4,19}[0-9X]$/', $value) === 1 ? $value : null;
    }

    /** Uppercase letters and digits only (Mercosul and old formats); null when empty or invalid. */
    private static function normalizePlate(string $raw): ?string
    {
        $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');

        return preg_match('/^[A-Z0-9]{5,8}$/', $value) === 1 ? $value : null;
    }
}
```


### View

`app/Views/concierge/index.php`

```php
<?php
/**
 * Concierge desk. Async actions (register exit, confirm pickup) are plain
 * <form data-async> elements posting to /api/... and are handled by
 * public/assets/js/core/async-form.js. Without JavaScript they still render,
 * but those buttons need JS to submit (the API answers JSON).
 *
 * LGPD: visitor RGs are only shown masked (mask_document).
 *
 * @var bool                                $canOperate False for the read-only Super Admin.
 * @var list<array{id: int, label: string}> $units
 * @var list<array<string, mixed>>          $inside
 * @var list<array<string, mixed>>          $recentExits
 * @var list<array<string, mixed>>          $packages
 * @var array<string, string>               $visitTypes
 * @var array<string, string>               $sizes
 * @var array<string, string>               $errors
 * @var array<string, string>               $old
 */
$selected = static fn (string $field, string|int $value): string
    => (string) ($old[$field] ?? '') === (string) $value ? ' selected' : '';
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Portaria</h1>
        <p class="page-header__subtitle">Controle de visitantes e encomendas</p>
    </div>
</section>

<div class="columns">
    <?php if ($canOperate): ?>
        <div class="columns__side">
            <section class="panel panel--padded">
                <h2 class="panel__title">Registrar entrada de visitante</h2>
                <form method="post" action="/concierge/visits" class="form" novalidate>
                    <?= csrf_field() ?>
                    <?= field_error($errors, 'general') ?>
                    <label class="form__field">
                        <span class="form__label">Nome completo</span>
                        <input type="text" name="full_name" maxlength="150" required value="<?= e($old['full_name'] ?? '') ?>">
                        <?= field_error($errors, 'full_name') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">RG</span>
                        <!-- autocomplete off: the desk computer is shared; the browser must not remember IDs. -->
                        <input type="text" name="document_number" maxlength="20" required autocomplete="off" inputmode="numeric">
                        <?= field_error($errors, 'document_number') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">Unidade visitada</span>
                        <select name="unit_id" required>
                            <option value="">Selecione…</option>
                            <?php foreach ($units as $unit): ?>
                                <option value="<?= e($unit['id']) ?>"<?= $selected('unit_id', $unit['id']) ?>><?= e($unit['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= field_error($errors, 'unit_id') ?>
                    </label>
                    <div class="form__row">
                        <label class="form__field">
                            <span class="form__label">Tipo</span>
                            <select name="visit_type">
                                <?php foreach ($visitTypes as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= $selected('visit_type', $value) ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="form__field">
                            <span class="form__label">Placa (opcional)</span>
                            <input type="text" name="vehicle_plate" maxlength="8" value="<?= e($old['vehicle_plate'] ?? '') ?>">
                        </label>
                    </div>
                    <button type="submit" class="btn btn--primary">Registrar entrada</button>
                </form>
            </section>

            <section class="panel panel--padded">
                <h2 class="panel__title">Registrar encomenda</h2>
                <form method="post" action="/concierge/packages" class="form" novalidate>
                    <?= csrf_field() ?>
                    <label class="form__field">
                        <span class="form__label">Unidade de destino</span>
                        <select name="unit_id" required>
                            <option value="">Selecione…</option>
                            <?php foreach ($units as $unit): ?>
                                <option value="<?= e($unit['id']) ?>"><?= e($unit['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="form__row">
                        <label class="form__field">
                            <span class="form__label">Transportadora</span>
                            <input type="text" name="carrier" maxlength="80">
                        </label>
                        <label class="form__field">
                            <span class="form__label">Tamanho</span>
                            <select name="package_size">
                                <?php foreach ($sizes as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= $value === 'small' ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="form__row">
                        <label class="form__field">
                            <span class="form__label">Rastreio</span>
                            <input type="text" name="tracking_code" maxlength="60">
                        </label>
                        <label class="form__field">
                            <span class="form__label">Local (prateleira)</span>
                            <input type="text" name="storage_location" maxlength="60">
                        </label>
                    </div>
                    <label class="form__field">
                        <span class="form__label">Descrição</span>
                        <input type="text" name="description" maxlength="255">
                    </label>
                    <button type="submit" class="btn btn--primary">Registrar e avisar morador</button>
                </form>
            </section>
        </div>
    <?php endif; ?>

    <div class="columns__main">
        <section class="panel">
            <h2 class="panel__title panel__title--bar">
                Visitantes no condomínio <span class="counter" data-counter="inside"><?= e(count($inside)) ?></span>
            </h2>
            <?php if ($inside === []): ?>
                <p class="state">Nenhum visitante no condomínio.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th>Nome</th><th>RG</th><th>Unidade</th><th>Tipo</th><th>Entrada</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($inside as $visit): ?>
                            <tr data-row>
                                <td><?= e($visit['full_name']) ?></td>
                                <td class="mono"><?= e(mask_document((string) $visit['document_number'])) ?></td>
                                <td><?= e($visit['unit_label']) ?></td>
                                <td><?= e($visitTypes[$visit['visit_type']] ?? $visit['visit_type']) ?></td>
                                <td><?= e(local_datetime($visit['entry_at'], 'H:i')) ?></td>
                                <td class="table__action" data-status-cell>
                                    <?php if ($canOperate): ?>
                                        <form method="post" action="/api/concierge/visits/<?= e($visit['id']) ?>/exit"
                                              data-async="visit-exit" data-decrement="inside">
                                            <button type="submit" class="btn btn--small">Registrar saída</button>
                                            <span class="feedback" data-feedback role="status"></span>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2 class="panel__title panel__title--bar">
                Encomendas aguardando retirada <span class="counter" data-counter="packages"><?= e(count($packages)) ?></span>
            </h2>
            <?php if ($packages === []): ?>
                <p class="state">Nenhuma encomenda na portaria.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th>Unidade</th><th>Encomenda</th><th>Recebida</th><th>Local</th><th>Retirada</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($packages as $package): ?>
                            <tr data-row>
                                <td><?= e($package['unit_label']) ?></td>
                                <td>
                                    <?= e($package['carrier'] ?? 'Sem transportadora') ?>
                                    <span class="muted">· <?= e($sizes[$package['package_size']] ?? '') ?></span>
                                    <?php if (!empty($package['description'])): ?>
                                        <div class="muted small"><?= e($package['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(local_datetime($package['received_at'])) ?></td>
                                <td><?= e($package['storage_location'] ?? '—') ?></td>
                                <td class="table__action" data-status-cell>
                                    <?php if ($canOperate): ?>
                                        <form method="post" action="/api/concierge/packages/<?= e($package['id']) ?>/pickup"
                                              class="inline-form" data-async="package-pickup" data-decrement="packages" novalidate>
                                            <input type="text" name="pickup_code" placeholder="Código" required
                                                   inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="off"
                                                   aria-label="Código de retirada">
                                            <input type="text" name="picked_up_by_name" placeholder="Quem retirou" required
                                                   maxlength="150" aria-label="Nome de quem retirou">
                                            <button type="submit" class="btn btn--small btn--primary">Confirmar</button>
                                            <span class="feedback" data-feedback role="status"></span>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">Aguardando</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2 class="panel__title panel__title--bar">Últimas saídas</h2>
            <?php if ($recentExits === []): ?>
                <p class="state">Nenhuma saída registrada.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table table--compact">
                        <thead><tr><th>Nome</th><th>Unidade</th><th>Entrada</th><th>Saída</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentExits as $visit): ?>
                            <tr>
                                <td><?= e($visit['full_name']) ?></td>
                                <td><?= e($visit['unit_label']) ?></td>
                                <td><?= e(local_datetime($visit['entry_at'])) ?></td>
                                <td><?= e(local_datetime($visit['exit_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
```


---

## 2. Reservations (Reservas)

Roles: **Residents** book for their own units, **Property Managers** for any unit, and managers also approve, reject and cancel. **Concierge** staff and the **Super Admin** can view.

### Migration

`database/migrations/0002_phase3_reservation_time_ranges.sql`

```sql
-- =====================================================================================
-- Migration 0002 - Phase 3: reservations by free time range
--
-- Phase 1 modelled bookings as fixed daily slots (common_area_slots) and blocked
-- double booking with a UNIQUE index on (slot_id, reservation_date, seat_number,
-- occupies_slot). Phase 3 requires residents to choose any start and end time, with
-- the overlap rule  existing.start < new.end AND existing.end > new.start.
--
-- Changes:
--   1. starts_at / ends_at (DATETIME, condominium LOCAL wall-clock time, like
--      reservation_date) hold the booked range. CHECK ends_at > starts_at.
--   2. slot_id becomes optional (NULL for free-range bookings). Slots can still be
--      offered later as presets; the composite FK keeps them tenant-safe when set.
--   3. Index ix_reservations_overlap serves the locked overlap query.
--
-- Double-booking protection for ranges lives in ReservationService::book(): it locks
-- the common_areas row (SELECT ... FOR UPDATE) so that bookings of one area are
-- serialised, then checks overlaps and inserts in the same transaction. MySQL has no
-- exclusion constraint for overlapping ranges, so the schema alone cannot enforce it.
--
-- reservation_date is kept and must equal DATE(starts_at): bookings never cross
-- midnight, which lets the overlap query use an equality on the date.
--
-- Run after 0001. The reservations table must be empty (no UI wrote to it before
-- Phase 3); the NOT NULL columns have no meaningful default for existing rows.
-- =====================================================================================

USE koinon;

-- The FK must be dropped before slot_id can become NULLable, then re-created.
ALTER TABLE reservations DROP FOREIGN KEY fk_reservations_slot;

ALTER TABLE reservations
  MODIFY COLUMN slot_id INT UNSIGNED NULL
    COMMENT 'Optional preset slot; NULL for free time-range bookings',
  ADD COLUMN starts_at DATETIME NOT NULL
    COMMENT 'Local wall-clock start (condominiums.timezone)' AFTER reservation_date,
  ADD COLUMN ends_at DATETIME NOT NULL
    COMMENT 'Local wall-clock end; same calendar day as starts_at' AFTER starts_at,
  ADD KEY ix_reservations_overlap (condominium_id, common_area_id, reservation_date, starts_at, ends_at),
  ADD CONSTRAINT ck_reservations_range CHECK (ends_at > starts_at),
  ADD CONSTRAINT ck_reservations_same_day CHECK (DATE(starts_at) = reservation_date AND DATE(ends_at) = reservation_date);

ALTER TABLE reservations
  ADD CONSTRAINT fk_reservations_slot
    FOREIGN KEY (condominium_id, common_area_id, slot_id)
    REFERENCES common_area_slots (condominium_id, common_area_id, id) ON DELETE RESTRICT;
```


### Models

`app/Models/CommonArea.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Bookable common areas of the current condominium (`common_areas`).
 */
final class CommonArea extends TenantModel
{
    /**
     * Areas every condominium starts with (Phase 3 scope: BBQ, Party Room, Gym).
     * bookings_per_slot: 1 = exclusive use; the gym accepts several people at once.
     */
    private const DEFAULTS = [
        ['Churrasqueira', 'bbq', 30, 1, 1, 24, 90],
        ['Salão de festas', 'party_room', 80, 1, 1, 48, 120],
        ['Academia', 'gym', 10, 8, 0, 1, 7],
    ];

    protected string $table = 'common_areas';

    /**
     * Active areas, for the booking form.
     *
     * @return list<array<string, mixed>>
     */
    public function active(): array
    {
        return $this->fetchAll(
            'SELECT id, name, area_type, max_people, bookings_per_slot, requires_approval,
                    booking_fee, min_advance_hours, max_advance_days
               FROM common_areas
              WHERE condominium_id = :tenant AND is_active = 1
              ORDER BY name',
            $this->scoped()
        );
    }

    /**
     * Locks the area row for the rest of the transaction.
     *
     * This is the serialisation point against double booking: every booking
     * of the same area must take this lock first, so two concurrent requests
     * for overlapping times run one after the other, and the second one sees
     * the first one's reservation in its overlap check. Locking only the
     * existing reservation rows would not be enough: when no reservation
     * exists yet there is nothing to lock, and both inserts would succeed.
     */
    public function lockForBooking(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT id, name, is_active, max_people, bookings_per_slot, requires_approval,
                    min_advance_hours, max_advance_days, max_active_per_unit, cancel_deadline_hours
               FROM common_areas
              WHERE id = :id AND condominium_id = :tenant
              FOR UPDATE',
            $this->scoped(['id' => $id])
        );
    }

    /**
     * Creates the default areas for this tenant if they are missing. Idempotent:
     * the unique key (condominium_id, name) turns repeats into no-ops.
     */
    public function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as [$name, $type, $maxPeople, $perSlot, $approval, $minHours, $maxDays]) {
            $this->execute(
                'INSERT INTO common_areas
                    (condominium_id, name, area_type, max_people, bookings_per_slot, requires_approval,
                     min_advance_hours, max_advance_days)
                 VALUES (:tenant, :name, :area_type, :max_people, :per_slot, :approval, :min_hours, :max_days)
                 ON DUPLICATE KEY UPDATE id = id',
                $this->scoped([
                    'name'       => $name,
                    'area_type'  => $type,
                    'max_people' => $maxPeople,
                    'per_slot'   => $perSlot,
                    'approval'   => $approval,
                    'min_hours'  => $minHours,
                    'max_days'   => $maxDays,
                ])
            );
        }
    }
}
```

`app/Models/Reservation.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Bookings of common areas (`reservations`). starts_at/ends_at and
 * reservation_date are LOCAL wall-clock values of the condominium (migration 0002).
 */
final class Reservation extends TenantModel
{
    /** Statuses that hold the area (a cancelled or rejected booking frees it). */
    public const ACTIVE_STATUSES = ['pending', 'approved'];

    public const STATUS_LABELS = [
        'pending'   => 'Aguardando aprovação',
        'approved'  => 'Confirmada',
        'rejected'  => 'Recusada',
        'cancelled' => 'Cancelada',
        'completed' => 'Concluída',
    ];

    protected string $table = 'reservations';

    protected array $fillable = [
        'common_area_id',
        'reservation_date',
        'starts_at',
        'ends_at',
        'seat_number',
        'unit_id',
        'requested_by_user_id',
        'guest_count',
        'status',
        'decided_at',
        'notes',
    ];

    /**
     * Active reservations of an area that OVERLAP the requested range, locked
     * until the transaction ends.
     *
     * Overlap rule: existing.start < new.end AND existing.end > new.start.
     * Touching ranges (one ends 14:00, the next starts 14:00) do not overlap.
     * Uses ix_reservations_overlap (condominium_id, common_area_id, reservation_date, ...).
     * Call only after CommonArea::lockForBooking() in the same transaction.
     *
     * @return list<array{id: int}>
     */
    public function lockOverlapping(int $areaId, string $date, string $startsAt, string $endsAt): array
    {
        return $this->fetchAll(
            "SELECT id
               FROM reservations
              WHERE condominium_id = :tenant
                AND common_area_id = :area_id
                AND reservation_date = :reservation_date
                AND status IN ('pending', 'approved')
                AND starts_at < :new_end
                AND ends_at > :new_start
              FOR UPDATE",
            $this->scoped([
                'area_id'          => $areaId,
                'reservation_date' => $date,
                'new_end'          => $endsAt,
                'new_start'        => $startsAt,
            ])
        );
    }

    /** Future active bookings a unit already holds for an area (per-unit limit). */
    public function countUpcomingForUnit(int $areaId, int $unitId, string $nowLocal): int
    {
        $row = $this->fetchOne(
            "SELECT COUNT(*) AS total
               FROM reservations
              WHERE condominium_id = :tenant
                AND common_area_id = :area_id
                AND unit_id = :unit_id
                AND status IN ('pending', 'approved')
                AND ends_at > :now_local",
            $this->scoped(['area_id' => $areaId, 'unit_id' => $unitId, 'now_local' => $nowLocal])
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Upcoming reservations (today onwards).
     *
     * @param list<int>|null $unitIds null = every unit of the tenant (staff);
     *                                a list = only these units (a resident's own units).
     * @return list<array<string, mixed>>
     */
    public function upcoming(string $today, ?array $unitIds): array
    {
        if ($unitIds === []) {
            return []; // a resident with no linked unit sees nothing, never "everything"
        }

        $params = $this->scoped(['today' => $today]);
        $unitFilter = '';
        if ($unitIds !== null) {
            [$placeholders, $unitParams] = $this->inList('unit', $unitIds);
            $unitFilter = " AND r.unit_id IN ({$placeholders})";
            $params += $unitParams;
        }

        return $this->fetchAll(
            'SELECT r.id, r.unit_id, r.reservation_date, r.starts_at, r.ends_at, r.guest_count, r.status,
                    r.decision_note, a.name AS area_name, ' . Unit::LABEL_SQL . ' AS unit_label,
                    us.full_name AS requested_by_name
               FROM reservations r
               JOIN common_areas a ON a.condominium_id = r.condominium_id AND a.id = r.common_area_id
               JOIN units u        ON u.condominium_id = r.condominium_id AND u.id = r.unit_id
               JOIN users us       ON us.id = r.requested_by_user_id
              WHERE r.condominium_id = :tenant
                AND r.reservation_date >= :today' . $unitFilter . '
              ORDER BY r.starts_at
              LIMIT 300',
            $params
        );
    }

    /** One reservation of the current tenant with its area rules, locked for a status change. */
    public function lockWithArea(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT r.id, r.unit_id, r.status, r.starts_at, a.cancel_deadline_hours
               FROM reservations r
               JOIN common_areas a ON a.condominium_id = r.condominium_id AND a.id = r.common_area_id
              WHERE r.id = :id AND r.condominium_id = :tenant
              FOR UPDATE',
            $this->scoped(['id' => $id])
        );
    }

    /** Cancels an active reservation of this tenant. */
    public function cancel(int $id, int $userId, ?string $reason): bool
    {
        return $this->execute(
            "UPDATE reservations
                SET status = 'cancelled',
                    cancelled_at = UTC_TIMESTAMP(),
                    cancelled_by_user_id = :user_id,
                    cancellation_reason = :reason
              WHERE id = :id
                AND condominium_id = :tenant
                AND status IN ('pending', 'approved')",
            $this->scoped(['id' => $id, 'user_id' => $userId, 'reason' => $reason])
        ) === 1;
    }

    /** Approves or rejects a PENDING reservation of this tenant ($status: approved|rejected). */
    public function decide(int $id, string $status, int $userId, ?string $note): bool
    {
        return $this->execute(
            "UPDATE reservations
                SET status = :status,
                    decided_by_user_id = :user_id,
                    decided_at = UTC_TIMESTAMP(),
                    decision_note = :note
              WHERE id = :id
                AND condominium_id = :tenant
                AND status = 'pending'",
            $this->scoped(['id' => $id, 'status' => $status, 'user_id' => $userId, 'note' => $note])
        ) === 1;
    }
}
```


### Service: double-booking protection

```
BEGIN
  SELECT … FROM common_areas WHERE id = :area AND condominium_id = :tenant FOR UPDATE   ← per-area mutex
  (area rules: active, advance window, capacity, per-unit limit)
  SELECT id FROM reservations
   WHERE condominium_id = :tenant AND common_area_id = :area AND reservation_date = :date
     AND status IN ('pending','approved')
     AND starts_at < :new_end AND ends_at > :new_start            ← overlap rule
   FOR UPDATE
  count ≥ bookings_per_slot ?  → 409 "Este horário já está reservado"
  INSERT INTO reservations …
COMMIT
```

Request B, arriving at the same moment as A, blocks on the first `SELECT … FOR UPDATE` until A commits. B's overlap query then sees A's row and is rejected with 409.

`app/Services/ReservationService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\TenantContext;
use App\Models\CommonArea;
use App\Models\Reservation;
use DateTimeImmutable;

/**
 * Booking rules for common areas, including the double-booking guarantee.
 */
final class ReservationService
{
    private const MIN_MINUTES = 30;

    public function __construct(
        private readonly CommonArea $areas = new CommonArea(),
        private readonly Reservation $reservations = new Reservation()
    ) {
    }

    /**
     * Books an area for a unit.
     *
     * Concurrency: the overlap check and the insert happen in ONE transaction
     * that first locks the area row (CommonArea::lockForBooking). Two
     * simultaneous requests for the same area therefore run one after the
     * other: the second waits for the first to commit, then its overlap query
     * sees the new reservation and is rejected. MySQL cannot express "no
     * overlapping ranges" as a constraint, so this lock is what guarantees it.
     *
     * @param string $date  Y-m-d (local date of the condominium)
     * @param string $start H:i
     * @param string $end   H:i
     * @return array{id: int, status: string}
     * @throws BusinessRuleException
     */
    public function book(
        int $areaId,
        int $unitId,
        int $userId,
        string $date,
        string $start,
        string $end,
        int $guests,
        ?string $notes
    ): array {
        $timezone = TenantContext::timezone();
        $startsAt = new DateTimeImmutable("{$date} {$start}:00", $timezone);
        $endsAt = new DateTimeImmutable("{$date} {$end}:00", $timezone);
        $now = TenantContext::now();

        // Same calendar day is implied: both times are on $date.
        if ($endsAt <= $startsAt) {
            throw new BusinessRuleException('O horário de término deve ser depois do início.', 422, 'end_time');
        }
        if (($endsAt->getTimestamp() - $startsAt->getTimestamp()) < self::MIN_MINUTES * 60) {
            throw new BusinessRuleException('A reserva deve durar pelo menos ' . self::MIN_MINUTES . ' minutos.', 422, 'end_time');
        }
        if ($startsAt <= $now) {
            throw new BusinessRuleException('Não é possível reservar uma data ou horário no passado.', 422, 'reservation_date');
        }

        return Database::transaction(function () use ($areaId, $unitId, $userId, $date, $startsAt, $endsAt, $now, $guests, $notes): array {
            // 1. Serialise all bookings of this area (and confirm it is ours and active).
            $area = $this->areas->lockForBooking($areaId);
            if ($area === null || !(bool) $area['is_active']) {
                throw new BusinessRuleException('Área comum não encontrada.', 422, 'common_area_id');
            }

            // 2. Area rules.
            $hoursAhead = ($startsAt->getTimestamp() - $now->getTimestamp()) / 3600;
            if ($hoursAhead < (int) $area['min_advance_hours']) {
                throw new BusinessRuleException(
                    "Esta área exige reserva com pelo menos {$area['min_advance_hours']} hora(s) de antecedência.",
                    422,
                    'start_time'
                );
            }
            if ($startsAt > $now->modify('+' . (int) $area['max_advance_days'] . ' days')) {
                throw new BusinessRuleException(
                    "Esta área aceita reservas com até {$area['max_advance_days']} dias de antecedência.",
                    422,
                    'reservation_date'
                );
            }
            if ($area['max_people'] !== null && $guests > (int) $area['max_people']) {
                throw new BusinessRuleException("Capacidade máxima: {$area['max_people']} pessoas.", 422, 'guest_count');
            }
            $nowLocal = $now->format('Y-m-d H:i:s');
            if ($this->reservations->countUpcomingForUnit($areaId, $unitId, $nowLocal) >= (int) $area['max_active_per_unit']) {
                throw new BusinessRuleException(
                    "Sua unidade já tem o máximo de {$area['max_active_per_unit']} reserva(s) futura(s) nesta área.",
                    409
                );
            }

            // 3. Overlap check (rows locked; the area lock above prevents phantoms).
            $startsSql = $startsAt->format('Y-m-d H:i:s');
            $endsSql = $endsAt->format('Y-m-d H:i:s');
            $overlapping = $this->reservations->lockOverlapping($areaId, $date, $startsSql, $endsSql);

            // Exclusive areas (BBQ, party room) allow 1; shared areas (gym) up to bookings_per_slot.
            // Counting every overlapping booking is deliberately conservative for shared areas.
            if (count($overlapping) >= (int) $area['bookings_per_slot']) {
                throw new BusinessRuleException('Este horário já está reservado. Escolha outro horário.', 409, 'start_time');
            }

            // 4. Insert. Approval-free areas are confirmed immediately.
            $status = (bool) $area['requires_approval'] ? 'pending' : 'approved';
            $id = $this->reservations->insert([
                'common_area_id'       => $areaId,
                'reservation_date'     => $date,
                'starts_at'            => $startsSql,
                'ends_at'              => $endsSql,
                'seat_number'          => count($overlapping) + 1,
                'unit_id'              => $unitId,
                'requested_by_user_id' => $userId,
                'guest_count'          => $guests,
                'status'               => $status,
                'decided_at'           => $status === 'approved' ? gmdate('Y-m-d H:i:s') : null,
                'notes'                => $notes,
            ]);

            return ['id' => $id, 'status' => $status];
        });
    }

    /**
     * Cancels a reservation. Ownership has already been checked by the caller;
     * $enforceDeadline applies the area's cancellation deadline (residents only).
     *
     * @throws BusinessRuleException
     */
    public function cancel(int $reservationId, int $userId, ?string $reason, bool $enforceDeadline): void
    {
        Database::transaction(function () use ($reservationId, $userId, $reason, $enforceDeadline): void {
            $reservation = $this->reservations->lockWithArea($reservationId)
                ?? throw new BusinessRuleException('Reserva não encontrada.', 404);

            if (!in_array($reservation['status'], Reservation::ACTIVE_STATUSES, true)) {
                throw new BusinessRuleException('Esta reserva não está mais ativa.', 409);
            }

            if ($enforceDeadline) {
                $startsAt = new DateTimeImmutable((string) $reservation['starts_at'], TenantContext::timezone());
                $deadline = $startsAt->modify('-' . (int) $reservation['cancel_deadline_hours'] . ' hours');
                if (TenantContext::now() > $deadline) {
                    throw new BusinessRuleException(
                        "O cancelamento só é permitido até {$reservation['cancel_deadline_hours']} horas antes do início. Fale com a administração.",
                        409
                    );
                }
            }

            $this->reservations->cancel($reservationId, $userId, $reason);
        });
    }
}
```


### Controller

`app/Controllers/ReservationController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\TenantContext;
use App\Core\Validator;
use App\Models\CommonArea;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\UnitResident;
use App\Services\BusinessRuleException;
use App\Services\ReservationService;

/**
 * Common-area reservations (Reservas).
 */
final class ReservationController extends Controller
{
    /** May see the reservations screen (concierge: to hand over keys; Super Admin: read-only). */
    public const VIEWERS = [Auth::SUPER_ADMIN, 'manager', 'concierge', 'resident'];

    /** May create bookings: residents for their own units, managers for any unit. */
    public const BOOKERS = ['manager', 'resident'];

    /** May approve/reject and cancel any booking. */
    public const MANAGERS = ['manager'];

    /** Roles that see every unit's bookings instead of only their own. */
    private const SEE_ALL = [Auth::SUPER_ADMIN, 'manager', 'concierge'];

    /** Input fields re-filled after a failed submission. */
    private const KEEP = ['common_area_id', 'unit_id', 'reservation_date', 'start_time', 'end_time', 'guest_count', 'notes'];

    /** GET /reservations: booking form + upcoming reservations. */
    public function index(): Response
    {
        $this->requireRole(self::VIEWERS);

        $areaModel = new CommonArea();
        $areas = $areaModel->active();
        if ($areas === []) {
            $areaModel->ensureDefaults(); // first visit of a new condominium
            $areas = $areaModel->active();
        }

        $seeAll = Auth::hasRole(self::SEE_ALL);
        $myUnitIds = $seeAll ? null : (new UnitResident())->activeUnitIds((int) Auth::id());

        return $this->view('reservations/index', [
            'title'        => 'Reservas',
            'activeNav'    => 'reservations',
            'areas'        => $areas,
            'units'        => $this->bookableUnits(),
            'reservations' => (new Reservation())->upcoming(TenantContext::today(), $myUnitIds),
            'canBook'      => Auth::hasRole(self::BOOKERS),
            'canManage'    => Auth::hasRole(self::MANAGERS),
            'myUnitIds'    => $myUnitIds ?? [],
            'today'        => TenantContext::today(),
            'statusLabels' => Reservation::STATUS_LABELS,
            'scripts'      => ['js/reservations.js'],
        ]);
    }

    /**
     * POST /reservations (HTML form, also validated client-side by reservations.js).
     *
     * Order of checks: CSRF (middleware) → role → input validation → unit
     * ownership → business rules and the locked overlap check (service).
     */
    public function store(): Response
    {
        $this->requireRole(self::BOOKERS);

        $v = new Validator($this->request);
        $areaId = $v->id('common_area_id', 'a área comum');
        $unitId = $v->id('unit_id', 'a unidade');
        $date = $v->date('reservation_date', 'Data');
        $start = $v->time('start_time', 'Horário de início');
        $end = $v->time('end_time', 'Horário de término');
        $guests = $v->integer('guest_count', 'Número de convidados', 0, 500, 0);
        $notes = $v->string('notes', 'Observações', 1, 500, required: false);

        if ($date !== null && $date < TenantContext::today()) {
            $v->addError('reservation_date', 'Não é possível reservar uma data no passado.');
        }
        if ($start !== null && $end !== null && $end <= $start) {
            $v->addError('end_time', 'O horário de término deve ser depois do início.');
        }
        // Ownership: the unit must be one the user may book for. For a resident it must
        // be their own unit; an id typed into the form for someone else's unit fails here.
        if ($unitId !== null && !$this->canBookForUnit($unitId)) {
            $v->addError('unit_id', 'Você só pode reservar para a sua unidade.');
        }
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/reservations', 422, self::KEEP);
        }

        try {
            $result = (new ReservationService())->book(
                (int) $areaId,
                (int) $unitId,
                (int) Auth::id(),
                (string) $date,
                (string) $start,
                (string) $end,
                (int) $guests,
                $notes
            );
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], '/reservations', $e->status(), self::KEEP);
        }

        $message = $result['status'] === 'approved'
            ? 'Reserva confirmada!'
            : 'Reserva solicitada. Ela ficará pendente até a aprovação da administração.';

        return $this->done($message, '/reservations', ['reservation' => $result], 201);
    }

    /**
     * POST /reservations/{id}/cancel
     *
     * Residents may cancel only bookings of their own units, before the area's
     * deadline; managers may cancel any booking of the condominium.
     */
    public function cancel(string $id): Response
    {
        $this->requireRole(self::BOOKERS);

        $reservation = (new Reservation())->find((int) $id) ?? throw new HttpException(404);
        $isManager = Auth::hasRole(self::MANAGERS);

        // IDOR check: someone else's booking answers 404, exactly like a non-existent id.
        if (!$isManager && !in_array((int) $reservation['unit_id'], (new UnitResident())->activeUnitIds((int) Auth::id()), true)) {
            throw new HttpException(404);
        }

        $reason = (new Validator($this->request))->string('reason', 'Motivo', 1, 255, required: false);
        try {
            (new ReservationService())->cancel((int) $id, (int) Auth::id(), $reason, enforceDeadline: !$isManager);
        } catch (BusinessRuleException $e) {
            return $this->invalid(['general' => $e->getMessage()], '/reservations', $e->status());
        }

        return $this->done('Reserva cancelada.', '/reservations');
    }

    /** POST /reservations/{id}/decision: manager approves or rejects a pending booking. */
    public function decide(string $id): Response
    {
        $this->requireRole(self::MANAGERS);

        $v = new Validator($this->request);
        $decision = $v->enum('decision', 'Decisão', ['approved', 'rejected']);
        $note = $v->string('decision_note', 'Observação', 1, 255, required: false);
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/reservations');
        }

        // Tenant-scoped UPDATE: an id from another condominium changes nothing.
        if (!(new Reservation())->decide((int) $id, (string) $decision, (int) Auth::id(), $note)) {
            return $this->invalid(['general' => 'Esta reserva não está mais pendente.'], '/reservations', 409);
        }

        return $this->done($decision === 'approved' ? 'Reserva aprovada.' : 'Reserva recusada.', '/reservations');
    }

    /**
     * Units offered in the form: all active units for managers, the user's own
     * units for residents.
     *
     * @return list<array{id: int, label: string}>
     */
    private function bookableUnits(): array
    {
        $units = (new Unit())->active();
        if (Auth::hasRole(self::MANAGERS)) {
            return $units;
        }
        if (!Auth::hasRole(self::BOOKERS)) {
            return [];
        }
        $mine = (new UnitResident())->activeUnitIds((int) Auth::id());

        return array_values(array_filter($units, static fn (array $u): bool => in_array((int) $u['id'], $mine, true)));
    }

    /** Server-side ownership rule behind the unit <select>. */
    private function canBookForUnit(int $unitId): bool
    {
        if (Auth::hasRole(self::MANAGERS)) {
            return (new Unit())->findActive($unitId) !== null; // any unit, but of THIS condominium
        }

        return in_array($unitId, (new UnitResident())->activeUnitIds((int) Auth::id()), true);
    }
}
```


### View and client-side validation

`app/Views/reservations/index.php`

```php
<?php
/**
 * Reservations: booking form (validated client-side by reservations.js and
 * again on the server) and the list of upcoming reservations.
 *
 * Area rules are exposed as data-* attributes so the client can give instant
 * feedback; the server re-checks every one of them.
 *
 * @var list<array<string, mixed>>          $areas
 * @var list<array{id: int, label: string}> $units        Units the user may book for.
 * @var list<array<string, mixed>>          $reservations
 * @var bool                                $canBook
 * @var bool                                $canManage
 * @var list<int>                           $myUnitIds
 * @var string                              $today        Local Y-m-d.
 * @var array<string, string>               $statusLabels
 * @var array<string, string>               $errors
 * @var array<string, string>               $old
 */
$selected = static fn (string $field, string|int $value): string
    => (string) ($old[$field] ?? '') === (string) $value ? ' selected' : '';
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Reservas</h1>
        <p class="page-header__subtitle">Churrasqueira, salão de festas e academia</p>
    </div>
</section>

<div class="columns">
    <?php if ($canBook): ?>
        <div class="columns__side">
            <section class="panel panel--padded">
                <h2 class="panel__title">Nova reserva</h2>

                <?php if ($units === []): ?>
                    <div class="alert alert--warning">Sua conta ainda não está vinculada a uma unidade. Fale com a administração.</div>
                <?php else: ?>
                    <form method="post" action="/reservations" class="form" id="booking-form" novalidate data-today="<?= e($today) ?>">
                        <?= csrf_field() ?>
                        <div class="alert alert--error" data-form-error <?= isset($errors['general']) ? '' : 'hidden' ?>><?= e($errors['general'] ?? '') ?></div>

                        <label class="form__field">
                            <span class="form__label">Área</span>
                            <select name="common_area_id" required>
                                <option value="">Selecione…</option>
                                <?php foreach ($areas as $area): ?>
                                    <option value="<?= e($area['id']) ?>"
                                            data-max-people="<?= e($area['max_people'] ?? '') ?>"
                                            data-max-days="<?= e($area['max_advance_days']) ?>"
                                            data-min-hours="<?= e($area['min_advance_hours']) ?>"
                                            data-approval="<?= (int) $area['requires_approval'] ?>"
                                        <?= $selected('common_area_id', $area['id']) ?>>
                                        <?= e($area['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="form__hint" data-area-hint></span>
                            <?= field_error($errors, 'common_area_id') ?>
                        </label>

                        <label class="form__field">
                            <span class="form__label">Unidade</span>
                            <select name="unit_id" required>
                                <?php if (count($units) > 1): ?><option value="">Selecione…</option><?php endif; ?>
                                <?php foreach ($units as $unit): ?>
                                    <option value="<?= e($unit['id']) ?>"<?= $selected('unit_id', $unit['id']) ?>><?= e($unit['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error($errors, 'unit_id') ?>
                        </label>

                        <label class="form__field">
                            <span class="form__label">Data</span>
                            <input type="date" name="reservation_date" required min="<?= e($today) ?>"
                                   value="<?= e($old['reservation_date'] ?? '') ?>">
                            <?= field_error($errors, 'reservation_date') ?>
                        </label>

                        <div class="form__row">
                            <label class="form__field">
                                <span class="form__label">Início</span>
                                <input type="time" name="start_time" required step="900" value="<?= e($old['start_time'] ?? '') ?>">
                                <?= field_error($errors, 'start_time') ?>
                            </label>
                            <label class="form__field">
                                <span class="form__label">Término</span>
                                <input type="time" name="end_time" required step="900" value="<?= e($old['end_time'] ?? '') ?>">
                                <?= field_error($errors, 'end_time') ?>
                            </label>
                        </div>

                        <label class="form__field">
                            <span class="form__label">Número de convidados</span>
                            <input type="number" name="guest_count" min="0" max="500" value="<?= e($old['guest_count'] ?? '0') ?>">
                            <?= field_error($errors, 'guest_count') ?>
                        </label>

                        <label class="form__field">
                            <span class="form__label">Observações (opcional)</span>
                            <textarea name="notes" rows="3" maxlength="500"><?= e($old['notes'] ?? '') ?></textarea>
                        </label>

                        <button type="submit" class="btn btn--primary">Reservar</button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>

    <div class="columns__main">
        <section class="panel">
            <h2 class="panel__title panel__title--bar">Próximas reservas</h2>
            <?php if ($reservations === []): ?>
                <p class="state">Nenhuma reserva a partir de hoje.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th>Data</th><th>Horário</th><th>Área</th><th>Unidade</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($reservations as $r): ?>
                            <?php
                            $isActive = in_array($r['status'], ['pending', 'approved'], true);
                            $isMine = in_array((int) $r['unit_id'], $myUnitIds, true);
                            ?>
                            <tr>
                                <td><?= e(date_br($r['reservation_date'])) ?></td>
                                <td><?= e(substr((string) $r['starts_at'], 11, 5)) ?>–<?= e(substr((string) $r['ends_at'], 11, 5)) ?></td>
                                <td><?= e($r['area_name']) ?></td>
                                <td>
                                    <?= e($r['unit_label']) ?>
                                    <?php if ($canManage): ?><div class="muted small"><?= e($r['requested_by_name']) ?></div><?php endif; ?>
                                </td>
                                <td><span class="pill pill--<?= e($r['status']) ?>"><?= e($statusLabels[$r['status']] ?? $r['status']) ?></span></td>
                                <td class="table__action">
                                    <?php if ($isActive && $canManage && $r['status'] === 'pending'): ?>
                                        <form method="post" action="/reservations/<?= e($r['id']) ?>/decision" class="inline-form">
                                            <?= csrf_field() ?>
                                            <button type="submit" name="decision" value="approved" class="btn btn--small btn--primary">Aprovar</button>
                                            <button type="submit" name="decision" value="rejected" class="btn btn--small">Recusar</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($isActive && ($canManage || $isMine)): ?>
                                        <form method="post" action="/reservations/<?= e($r['id']) ?>/cancel" class="inline-form" data-confirm="Cancelar esta reserva?">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn--small btn--danger-ghost">Cancelar</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
```

`public/assets/js/reservations.js`

```javascript
/**
 * Reservations page.
 *
 * Client-side validation of the booking form gives instant feedback (past date,
 * end before start, booking window, capacity). It COMPLEMENTS the server: every
 * rule is checked again in ReservationController/ReservationService, and the
 * overlap check can only be done on the server, under a database lock.
 */

const MIN_MINUTES = 30;

const form = document.getElementById('booking-form');

/** Adds days to a "YYYY-MM-DD" string (local calendar, no time-zone drift). */
function addDays(isoDate, days) {
    const [y, m, d] = isoDate.split('-').map(Number);
    const date = new Date(y, m - 1, d + days);
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

const toMinutes = (hhmm) => {
    const [h, m] = hhmm.split(':').map(Number);
    return h * 60 + m;
};

/** Shows (or clears) the client-side error next to a field. */
function setFieldError(field, message) {
    const container = field.closest('.form__field');
    let slot = container?.querySelector('[data-client-error]');
    if (!slot && container) {
        slot = document.createElement('span');
        slot.className = 'form__error';
        slot.dataset.clientError = '';
        container.append(slot);
    }
    if (slot) {
        slot.textContent = message ?? '';
    }
    if (message) {
        field.setAttribute('aria-invalid', 'true');
    } else {
        field.removeAttribute('aria-invalid');
    }
}

function selectedArea() {
    const option = form.elements.common_area_id.selectedOptions[0];
    return option && option.value !== '' ? option.dataset : null;
}

/** @returns {Map<HTMLElement, string>} field => message */
function validateBooking() {
    const f = form.elements;
    const errors = new Map();
    const today = form.dataset.today; // condominium's local date, from the server
    const area = selectedArea();

    if (!area) {
        errors.set(f.common_area_id, 'Selecione a área.');
    }
    if (f.unit_id.value === '') {
        errors.set(f.unit_id, 'Selecione a unidade.');
    }

    const date = f.reservation_date.value;
    if (date === '') {
        errors.set(f.reservation_date, 'Informe a data.');
    } else if (date < today) {
        errors.set(f.reservation_date, 'A data não pode estar no passado.');
    } else if (area && date > addDays(today, Number(area.maxDays))) {
        errors.set(f.reservation_date, `Reservas com até ${area.maxDays} dias de antecedência.`);
    }

    const start = f.start_time.value;
    const end = f.end_time.value;
    if (start === '') {
        errors.set(f.start_time, 'Informe o início.');
    }
    if (end === '') {
        errors.set(f.end_time, 'Informe o término.');
    }
    if (start !== '' && end !== '') {
        if (toMinutes(end) <= toMinutes(start)) {
            errors.set(f.end_time, 'O término deve ser depois do início.');
        } else if (toMinutes(end) - toMinutes(start) < MIN_MINUTES) {
            errors.set(f.end_time, `A reserva deve durar pelo menos ${MIN_MINUTES} minutos.`);
        }
    }

    const guests = Number(f.guest_count.value || 0);
    if (!Number.isInteger(guests) || guests < 0) {
        errors.set(f.guest_count, 'Número de convidados inválido.');
    } else if (area && area.maxPeople !== '' && guests > Number(area.maxPeople)) {
        errors.set(f.guest_count, `Capacidade máxima: ${area.maxPeople} pessoas.`);
    }

    return errors;
}

function updateAreaHint() {
    const hint = form.querySelector('[data-area-hint]');
    const area = selectedArea();
    if (!hint) {
        return;
    }
    if (!area) {
        hint.textContent = '';
        return;
    }
    const parts = [area.approval === '1' ? 'Requer aprovação da administração' : 'Confirmação imediata'];
    if (area.maxPeople !== '') {
        parts.push(`até ${area.maxPeople} pessoas`);
    }
    parts.push(`antecedência mínima de ${area.minHours}h`);
    hint.textContent = parts.join(' · ');
}

if (form) {
    form.addEventListener('submit', (event) => {
        const errors = validateBooking();
        for (const field of form.querySelectorAll('input, select, textarea')) {
            setFieldError(field, errors.get(field) ?? null);
        }
        if (errors.size > 0) {
            event.preventDefault(); // stop here; nothing invalid reaches the server from this form
            errors.keys().next().value.focus();
            return;
        }
        form.querySelector('[type="submit"]').disabled = true; // prevent double booking requests
    });

    // Re-validate a field as soon as the user fixes it.
    form.addEventListener('change', (event) => {
        if (event.target === form.elements.common_area_id) {
            updateAreaHint();
        }
        if (event.target.getAttribute('aria-invalid') === 'true') {
            const errors = validateBooking();
            setFieldError(event.target, errors.get(event.target) ?? null);
        }
    });

    updateAreaHint();
}

// Confirmation for destructive buttons (cancel reservation).
document.querySelectorAll('form[data-confirm]').forEach((confirmForm) => {
    confirmForm.addEventListener('submit', (event) => {
        if (!window.confirm(confirmForm.dataset.confirm)) {
            event.preventDefault();
        }
    });
});
```


---

## 3. Occurrences (Ocorrências)

Roles: **Residents and Concierge** open tickets and see and reply to **only their own**. **Property Managers** see every ticket, reply, write internal notes and change status. The **Super Admin** can view everything.

### Models

`app/Models/Occurrence.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Tickets of the digital incident book (`occurrences`).
 *
 * Visibility rule, applied inside every read query (not after fetching):
 *  - staff who see everything (manager, Super Admin): any ticket of the tenant;
 *  - everyone else: only tickets they reported (reported_by_user_id = me).
 */
final class Occurrence extends TenantModel
{
    /** Ticket types offered in Phase 3, mapped to the Phase 1 ENUM values. */
    public const TYPES = [
        'complaint'   => 'Reclamação',
        'maintenance' => 'Relato de dano',
    ];

    public const CATEGORIES = [
        'noise'            => 'Barulho',
        'security'         => 'Segurança',
        'maintenance'      => 'Manutenção / dano',
        'cleaning'         => 'Limpeza',
        'parking'          => 'Garagem',
        'pets'             => 'Animais',
        'neighbor_conduct' => 'Conduta de vizinho',
        'other'            => 'Outro',
    ];

    /** Statuses used in Phase 3 (subset of the Phase 1 ENUM). */
    public const STATUSES = [
        'open'        => 'Aberta',
        'in_progress' => 'Em andamento',
        'resolved'    => 'Resolvida',
    ];

    protected string $table = 'occurrences';

    protected array $fillable = [
        'protocol_number',
        'reported_by_user_id',
        'unit_id',
        'occurrence_type',
        'category',
        'title',
        'description',
        'location',
        'occurred_at',
        'status',
    ];

    private const SELECT = 'SELECT o.id, o.protocol_number, o.occurrence_type, o.category, o.title, o.description,
               o.location, o.occurred_at, o.status, o.created_at, o.updated_at, o.resolved_at,
               o.reported_by_user_id, us.full_name AS reporter_name
          FROM occurrences o
          JOIN users us ON us.id = o.reported_by_user_id
         WHERE o.condominium_id = :tenant ';

    /**
     * Tickets visible to the user, newest first.
     *
     * @param bool        $seeAll True only for roles allowed to see every ticket.
     * @param string|null $status Optional filter (already whitelisted by the controller).
     * @return list<array<string, mixed>>
     */
    public function listVisible(int $userId, bool $seeAll, ?string $status): array
    {
        $sql = self::SELECT;
        $params = $this->scoped();

        if (!$seeAll) {
            // Ownership: a resident's query is restricted to their own tickets in SQL,
            // so other people's tickets are never even loaded.
            $sql .= 'AND o.reported_by_user_id = :user_id ';
            $params['user_id'] = $userId;
        }
        if ($status !== null) {
            $sql .= 'AND o.status = :status ';
            $params['status'] = $status;
        }

        return $this->fetchAll($sql . 'ORDER BY o.created_at DESC LIMIT 200', $params);
    }

    /**
     * One ticket if the user may see it, otherwise null.
     *
     * A ticket of another condominium, or another resident's ticket, gives the
     * same null as a non-existent id. The controller answers 404 in all three
     * cases, so editing the id in the URL reveals nothing (IDOR protection).
     */
    public function findVisible(int $id, int $userId, bool $seeAll): ?array
    {
        $sql = self::SELECT . 'AND o.id = :id ';
        $params = $this->scoped(['id' => $id]);
        if (!$seeAll) {
            $sql .= 'AND o.reported_by_user_id = :user_id ';
            $params['user_id'] = $userId;
        }

        return $this->fetchOne($sql, $params);
    }

    /**
     * Changes the status only if it is still $from (optimistic concurrency: if
     * two managers act at once, the second sees 0 rows changed instead of
     * silently overwriting the first). resolved_at is set when it becomes 'resolved'.
     */
    public function changeStatus(int $id, string $from, string $to): bool
    {
        return $this->execute(
            "UPDATE occurrences
                SET status = :to_status,
                    resolved_at = CASE WHEN :to_check = 'resolved' THEN UTC_TIMESTAMP() ELSE resolved_at END
              WHERE id = :id
                AND condominium_id = :tenant
                AND status = :from_status",
            $this->scoped(['id' => $id, 'to_status' => $to, 'to_check' => $to, 'from_status' => $from])
        ) === 1;
    }
}
```

`app/Models/OccurrenceUpdate.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Append-only timeline of a ticket (`occurrence_updates`): replies and status changes.
 */
final class OccurrenceUpdate extends TenantModel
{
    protected string $table = 'occurrence_updates';

    protected array $fillable = [
        'occurrence_id',
        'author_user_id',
        'message',
        'status_from',
        'status_to',
        'is_internal',
    ];

    /**
     * Timeline of a ticket, oldest first.
     *
     * Call only after the ticket itself passed Occurrence::findVisible(): this
     * method scopes by tenant but not by reporter.
     *
     * @param bool $includeInternal Staff notes (is_internal = 1) are only for staff;
     *                              they are filtered out in SQL for everyone else.
     * @return list<array<string, mixed>>
     */
    public function forOccurrence(int $occurrenceId, bool $includeInternal): array
    {
        $sql = 'SELECT ou.id, ou.message, ou.status_from, ou.status_to, ou.is_internal, ou.created_at,
                       ou.author_user_id, us.full_name AS author_name, r.code AS author_role
                  FROM occurrence_updates ou
                  JOIN users us ON us.id = ou.author_user_id
                  JOIN condominium_users cu
                    ON cu.condominium_id = ou.condominium_id AND cu.user_id = ou.author_user_id
                  JOIN roles r ON r.id = cu.role_id
                 WHERE ou.condominium_id = :tenant
                   AND ou.occurrence_id = :occurrence_id';
        if (!$includeInternal) {
            $sql .= ' AND ou.is_internal = 0';
        }

        return $this->fetchAll(
            $sql . ' ORDER BY ou.created_at, ou.id',
            $this->scoped(['occurrence_id' => $occurrenceId])
        );
    }
}
```


### Service

`app/Services/OccurrenceService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Occurrence;
use App\Models\OccurrenceUpdate;
use App\Models\TenantCounter;

/**
 * Ticket lifecycle: opening (with protocol number), replies and status changes.
 */
final class OccurrenceService
{
    public function __construct(
        private readonly Occurrence $occurrences = new Occurrence(),
        private readonly OccurrenceUpdate $updates = new OccurrenceUpdate(),
        private readonly TenantCounter $counters = new TenantCounter()
    ) {
    }

    /**
     * Opens a ticket. The protocol number is taken from the tenant's counter
     * inside the same transaction, so numbers are unique and gap-free.
     *
     * @param array{occurrence_type: string, category: string, title: string, description: string,
     *              location: ?string, unit_id: ?int} $data
     * @return array{id: int, protocol_number: int}
     */
    public function open(array $data, int $reporterUserId): array
    {
        return Database::transaction(function () use ($data, $reporterUserId): array {
            $protocol = $this->counters->next('occurrence');
            $id = $this->occurrences->insert($data + [
                'protocol_number'     => $protocol,
                'reported_by_user_id' => $reporterUserId,
                'status'              => 'open',
            ]);

            return ['id' => $id, 'protocol_number' => $protocol];
        });
    }

    /** Appends a reply. Visibility of the ticket must already be checked by the caller. */
    public function reply(int $occurrenceId, int $authorUserId, string $message, bool $internal): void
    {
        $this->updates->insert([
            'occurrence_id'  => $occurrenceId,
            'author_user_id' => $authorUserId,
            'message'        => $message,
            'is_internal'    => $internal ? 1 : 0,
        ]);
    }

    /**
     * Changes the status and records the change in the timeline, atomically.
     *
     * @throws BusinessRuleException 409 if someone changed the ticket meanwhile.
     */
    public function changeStatus(int $occurrenceId, string $from, string $to, int $authorUserId, ?string $message): void
    {
        if ($from === $to) {
            throw new BusinessRuleException('A ocorrência já está com este status.', 409, 'status');
        }

        Database::transaction(function () use ($occurrenceId, $from, $to, $authorUserId, $message): void {
            if (!$this->occurrences->changeStatus($occurrenceId, $from, $to)) {
                throw new BusinessRuleException('A ocorrência foi alterada por outra pessoa. Recarregue a página.', 409);
            }
            $this->updates->insert([
                'occurrence_id'  => $occurrenceId,
                'author_user_id' => $authorUserId,
                'message'        => $message,
                'status_from'    => $from,
                'status_to'      => $to,
                'is_internal'    => 0,
            ]);
        });
    }
}
```


### Controller

`app/Controllers/OccurrenceController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Occurrence;
use App\Models\OccurrenceUpdate;
use App\Models\Unit;
use App\Models\UnitResident;
use App\Services\BusinessRuleException;
use App\Services\OccurrenceService;

/**
 * Digital incident book (Ocorrências): tickets, replies and status changes.
 */
final class OccurrenceController extends Controller
{
    public const VIEWERS = [Auth::SUPER_ADMIN, 'manager', 'concierge', 'resident'];

    /** May open tickets and reply to their own. */
    public const REPORTERS = ['manager', 'concierge', 'resident'];

    /** May reply to any ticket, write internal notes and change status. */
    public const HANDLERS = ['manager'];

    /** See every ticket of the condominium; everyone else sees only their own. */
    public const SEE_ALL = [Auth::SUPER_ADMIN, 'manager'];

    /** GET /occurrences?status=open */
    public function index(): Response
    {
        $this->requireRole(self::VIEWERS);

        // The filter is whitelisted before it reaches the model.
        $status = (string) $this->request->query('status', '');
        $status = array_key_exists($status, Occurrence::STATUSES) ? $status : null;

        return $this->view('occurrences/index', [
            'title'       => 'Ocorrências',
            'activeNav'   => 'occurrences',
            'occurrences' => (new Occurrence())->listVisible((int) Auth::id(), Auth::hasRole(self::SEE_ALL), $status),
            'filter'      => $status,
            'seeAll'      => Auth::hasRole(self::SEE_ALL),
            'canCreate'   => Auth::hasRole(self::REPORTERS),
            'types'       => Occurrence::TYPES,
            'statuses'    => Occurrence::STATUSES,
        ]);
    }

    /** GET /occurrences/new */
    public function create(): Response
    {
        $this->requireRole(self::REPORTERS);

        return $this->view('occurrences/create', [
            'title'      => 'Nova ocorrência',
            'activeNav'  => 'occurrences',
            'types'      => Occurrence::TYPES,
            'categories' => Occurrence::CATEGORIES,
            'units'      => $this->ownUnits(),
        ]);
    }

    /** POST /occurrences */
    public function store(): Response
    {
        $this->requireRole(self::REPORTERS);

        $v = new Validator($this->request);
        $type = $v->enum('occurrence_type', 'Tipo', array_keys(Occurrence::TYPES));
        $category = $v->enum('category', 'Categoria', array_keys(Occurrence::CATEGORIES));
        $title = $v->string('title', 'Título', 5, 150);
        $description = $v->string('description', 'Descrição', 10, 5000);
        $location = $v->string('location', 'Local', 2, 120, required: false);

        // Optional unit: only one of the reporter's own units is accepted.
        $unitId = null;
        if ($this->request->string('unit_id') !== '') {
            $unitId = $v->id('unit_id', 'a unidade');
            $ownIds = array_map(static fn (array $u): int => (int) $u['id'], $this->ownUnits());
            if ($unitId !== null && !in_array($unitId, $ownIds, true)) {
                $v->addError('unit_id', 'Unidade inválida.');
            }
        }

        $keep = ['occurrence_type', 'category', 'title', 'description', 'location', 'unit_id'];
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/occurrences/new', 422, $keep);
        }

        $result = (new OccurrenceService())->open([
            'occurrence_type' => $type,
            'category'        => $category,
            'title'           => $title,
            'description'     => $description,
            'location'        => $location,
            'unit_id'         => $unitId,
        ], (int) Auth::id());

        return $this->done("Ocorrência nº {$result['protocol_number']} registrada.", '/occurrences/' . $result['id']);
    }

    /** GET /occurrences/{id}: a ticket with its replies, after the visibility check. */
    public function show(string $id): Response
    {
        $this->requireRole(self::VIEWERS);
        $occurrence = $this->findVisibleOr404((int) $id);
        $isHandler = Auth::hasRole(self::HANDLERS);

        return $this->view('occurrences/show', [
            'title'      => 'Ocorrência nº ' . $occurrence['protocol_number'],
            'activeNav'  => 'occurrences',
            'occurrence' => $occurrence,
            // Internal notes are filtered in SQL for non-staff, not just hidden in HTML.
            'updates'    => (new OccurrenceUpdate())->forOccurrence((int) $occurrence['id'], Auth::hasRole(self::SEE_ALL)),
            'canReply'   => Auth::hasRole(self::REPORTERS),
            'isHandler'  => $isHandler,
            'types'      => Occurrence::TYPES,
            'categories' => Occurrence::CATEGORIES,
            'statuses'   => Occurrence::STATUSES,
        ]);
    }

    /** POST /occurrences/{id}/replies */
    public function reply(string $id): Response
    {
        $this->requireRole(self::REPORTERS);
        // Ownership: a resident can only reply to a ticket they can see (their own).
        $occurrence = $this->findVisibleOr404((int) $id);
        $back = '/occurrences/' . $occurrence['id'];

        $v = new Validator($this->request);
        $message = $v->string('message', 'Resposta', 2, 5000);
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back, 422, ['message']);
        }

        // Only handlers can mark a note as internal; for anyone else the flag is ignored.
        $internal = Auth::hasRole(self::HANDLERS) && $this->request->boolean('is_internal');
        (new OccurrenceService())->reply((int) $occurrence['id'], (int) Auth::id(), (string) $message, $internal);

        return $this->done('Resposta enviada.', $back);
    }

    /** POST /occurrences/{id}/status: managers only. */
    public function updateStatus(string $id): Response
    {
        $this->requireRole(self::HANDLERS);
        $occurrence = $this->findVisibleOr404((int) $id);
        $back = '/occurrences/' . $occurrence['id'];

        $v = new Validator($this->request);
        $status = $v->enum('status', 'Status', array_keys(Occurrence::STATUSES));
        $message = $v->string('message', 'Mensagem', 2, 5000, required: false);
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back);
        }

        try {
            (new OccurrenceService())->changeStatus(
                (int) $occurrence['id'],
                (string) $occurrence['status'],
                (string) $status,
                (int) Auth::id(),
                $message
            );
        } catch (BusinessRuleException $e) {
            return $this->invalid(['status' => $e->getMessage()], $back, $e->status());
        }

        return $this->done('Status atualizado para "' . Occurrence::STATUSES[$status] . '".', $back);
    }

    /**
     * Loads a ticket the current user may see, or answers 404.
     *
     * 404 (not 403) for other people's tickets: a 403 would confirm that the id exists.
     *
     * @return array<string, mixed>
     */
    private function findVisibleOr404(int $id): array
    {
        return (new Occurrence())->findVisible($id, (int) Auth::id(), Auth::hasRole(self::SEE_ALL))
            ?? throw new HttpException(404);
    }

    /** @return list<array{id: int, label: string}> Units the user lives in. */
    private function ownUnits(): array
    {
        $mine = (new UnitResident())->activeUnitIds((int) Auth::id());

        return array_values(array_filter(
            (new Unit())->active(),
            static fn (array $u): bool => in_array((int) $u['id'], $mine, true)
        ));
    }
}
```


### Views

`app/Views/occurrences/index.php`

```php
<?php
/**
 * Ticket list. The rows were already filtered by the model: a resident's
 * query only ever returned their own tickets.
 *
 * @var list<array<string, mixed>> $occurrences
 * @var string|null                $filter
 * @var bool                       $seeAll
 * @var bool                       $canCreate
 * @var array<string, string>      $types
 * @var array<string, string>      $statuses
 */
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Ocorrências</h1>
        <p class="page-header__subtitle">
            <?= $seeAll ? 'Todas as ocorrências do condomínio' : 'Suas reclamações e relatos de dano' ?>
        </p>
    </div>
    <?php if ($canCreate): ?>
        <a class="btn btn--primary" href="/occurrences/new">Nova ocorrência</a>
    <?php endif; ?>
</section>

<nav class="tabs" aria-label="Filtrar por status">
    <a class="tabs__item<?= $filter === null ? ' is-active' : '' ?>" href="/occurrences">Todas</a>
    <?php foreach ($statuses as $value => $label): ?>
        <a class="tabs__item<?= $filter === $value ? ' is-active' : '' ?>" href="/occurrences?status=<?= e(urlencode($value)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<section class="panel">
    <?php if ($occurrences === []): ?>
        <p class="state">Nenhuma ocorrência encontrada.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Nº</th><th>Título</th><th>Tipo</th>
                    <?php if ($seeAll): ?><th>Aberta por</th><?php endif; ?>
                    <th>Aberta em</th><th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($occurrences as $o): ?>
                    <tr>
                        <td class="mono"><?= e($o['protocol_number']) ?></td>
                        <td><a href="/occurrences/<?= e($o['id']) ?>"><?= e($o['title']) ?></a></td>
                        <td><?= e($types[$o['occurrence_type']] ?? $o['occurrence_type']) ?></td>
                        <?php if ($seeAll): ?><td><?= e($o['reporter_name']) ?></td><?php endif; ?>
                        <td><?= e(local_datetime($o['created_at'])) ?></td>
                        <td><span class="pill pill--<?= e($o['status']) ?>"><?= e($statuses[$o['status']] ?? $o['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
```

`app/Views/occurrences/create.php`

```php
<?php
/**
 * @var array<string, string>               $types
 * @var array<string, string>               $categories
 * @var list<array{id: int, label: string}> $units Only the reporter's own units.
 * @var array<string, string>               $errors
 * @var array<string, string>               $old
 */
$selected = static fn (string $field, string|int $value): string
    => (string) ($old[$field] ?? '') === (string) $value ? ' selected' : '';
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Nova ocorrência</h1>
        <p class="page-header__subtitle">A administração responderá por aqui. Só você e a administração veem esta ocorrência.</p>
    </div>
</section>

<section class="panel panel--padded panel--narrow">
    <form method="post" action="/occurrences" class="form" novalidate>
        <?= csrf_field() ?>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Tipo</span>
                <select name="occurrence_type" required>
                    <?php foreach ($types as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $selected('occurrence_type', $value) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'occurrence_type') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Categoria</span>
                <select name="category" required>
                    <?php foreach ($categories as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $selected('category', $value) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'category') ?>
            </label>
        </div>

        <label class="form__field">
            <span class="form__label">Título</span>
            <input type="text" name="title" maxlength="150" required value="<?= e($old['title'] ?? '') ?>">
            <?= field_error($errors, 'title') ?>
        </label>

        <label class="form__field">
            <span class="form__label">Descrição</span>
            <textarea name="description" rows="7" maxlength="5000" required><?= e($old['description'] ?? '') ?></textarea>
            <?= field_error($errors, 'description') ?>
        </label>

        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Local (opcional)</span>
                <input type="text" name="location" maxlength="120" placeholder="Ex.: garagem, bloco B" value="<?= e($old['location'] ?? '') ?>">
                <?= field_error($errors, 'location') ?>
            </label>
            <?php if ($units !== []): ?>
                <label class="form__field">
                    <span class="form__label">Sua unidade (opcional)</span>
                    <select name="unit_id">
                        <option value="">—</option>
                        <?php foreach ($units as $unit): ?>
                            <option value="<?= e($unit['id']) ?>"<?= $selected('unit_id', $unit['id']) ?>><?= e($unit['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error($errors, 'unit_id') ?>
                </label>
            <?php endif; ?>
        </div>

        <div class="form__actions">
            <a class="btn" href="/occurrences">Cancelar</a>
            <button type="submit" class="btn btn--primary">Registrar ocorrência</button>
        </div>
    </form>
</section>
```

`app/Views/occurrences/show.php`

```php
<?php
/**
 * One ticket and its timeline. Reaching this view means the visibility check
 * passed. Internal notes are only present in $updates for staff.
 *
 * @var array<string, mixed>       $occurrence
 * @var list<array<string, mixed>> $updates
 * @var bool                       $canReply
 * @var bool                       $isHandler
 * @var array<string, string>      $types
 * @var array<string, string>      $categories
 * @var array<string, string>      $statuses
 * @var array<string, string>      $errors
 * @var array<string, string>      $old
 */
$status = (string) $occurrence['status'];
$statusLabel = static fn (?string $s): string => $s === null ? '' : ($statuses[$s] ?? $s);
?>
<section class="page-header">
    <div>
        <p class="page-header__eyebrow"><a href="/occurrences">← Ocorrências</a></p>
        <h1 class="page-header__title">
            Nº <?= e($occurrence['protocol_number']) ?> · <?= e($occurrence['title']) ?>
        </h1>
        <p class="page-header__subtitle">
            <?= e($types[$occurrence['occurrence_type']] ?? $occurrence['occurrence_type']) ?>
            · <?= e($categories[$occurrence['category']] ?? $occurrence['category']) ?>
            · aberta por <?= e($occurrence['reporter_name']) ?> em <?= e(local_datetime($occurrence['created_at'])) ?>
        </p>
    </div>
    <span class="pill pill--<?= e($status) ?> pill--large"><?= e($statusLabel($status)) ?></span>
</section>

<div class="columns">
    <div class="columns__main">
        <section class="panel panel--padded">
            <h2 class="panel__title">Descrição</h2>
            <p class="prewrap"><?= e($occurrence['description']) ?></p>
            <?php if (!empty($occurrence['location'])): ?>
                <p class="muted">Local: <?= e($occurrence['location']) ?></p>
            <?php endif; ?>
        </section>

        <section class="panel panel--padded">
            <h2 class="panel__title">Histórico</h2>
            <?php if ($updates === []): ?>
                <p class="muted">Ainda não há respostas.</p>
            <?php else: ?>
                <ol class="timeline">
                    <?php foreach ($updates as $u): ?>
                        <li class="timeline__item<?= (bool) $u['is_internal'] ? ' timeline__item--internal' : '' ?>">
                            <div class="timeline__meta">
                                <strong><?= e($u['author_name']) ?></strong>
                                <?php if (in_array($u['author_role'], ['manager', 'concierge'], true)): ?>
                                    <span class="tag">Administração</span>
                                <?php endif; ?>
                                <?php if ((bool) $u['is_internal']): ?>
                                    <span class="tag tag--warning">Nota interna</span>
                                <?php endif; ?>
                                <span class="muted"><?= e(local_datetime($u['created_at'])) ?></span>
                            </div>
                            <?php if ($u['status_to'] !== null): ?>
                                <p class="timeline__status">
                                    Status: <?= e($statusLabel($u['status_from'])) ?> → <strong><?= e($statusLabel($u['status_to'])) ?></strong>
                                </p>
                            <?php endif; ?>
                            <?php if ($u['message'] !== null): ?>
                                <p class="prewrap"><?= e($u['message']) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <?php if ($canReply): ?>
                <form method="post" action="/occurrences/<?= e($occurrence['id']) ?>/replies" class="form form--separated" novalidate>
                    <?= csrf_field() ?>
                    <label class="form__field">
                        <span class="form__label">Responder</span>
                        <textarea name="message" rows="4" maxlength="5000" required><?= e($old['message'] ?? '') ?></textarea>
                        <?= field_error($errors, 'message') ?>
                    </label>
                    <div class="form__actions form__actions--split">
                        <?php if ($isHandler): ?>
                            <label class="form__check">
                                <input type="checkbox" name="is_internal" value="1">
                                Nota interna (não visível para o morador)
                            </label>
                        <?php endif; ?>
                        <button type="submit" class="btn btn--primary">Enviar resposta</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($isHandler): ?>
        <div class="columns__side">
            <section class="panel panel--padded">
                <h2 class="panel__title">Alterar status</h2>
                <form method="post" action="/occurrences/<?= e($occurrence['id']) ?>/status" class="form" novalidate>
                    <?= csrf_field() ?>
                    <label class="form__field">
                        <span class="form__label">Novo status</span>
                        <select name="status">
                            <?php foreach ($statuses as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $value === $status ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= field_error($errors, 'status') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">Mensagem ao morador (opcional)</span>
                        <textarea name="message" rows="3" maxlength="5000"></textarea>
                    </label>
                    <button type="submit" class="btn btn--primary">Atualizar status</button>
                </form>
            </section>
        </div>
    <?php endif; ?>
</div>
```


---

## 4. Financial (Financeiro)

Roles: **Property Managers** create charges and see every unit. **Residents** see only their own units' bills, as owner or tenant. A resident who POSTs to `/finance/charges` is stopped by the route's `role:manager` middleware and again by `requireRole()`, and gets 403. The **Super Admin** can view the overview.

### Models

`app/Models/FinancialCategory.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Income/expense categories of the current condominium (`financial_categories`).
 */
final class FinancialCategory extends TenantModel
{
    private const DEFAULT_INCOME = ['Taxa condominial', 'Taxa extra', 'Multa', 'Taxa de reserva', 'Outras receitas'];

    protected string $table = 'financial_categories';

    /**
     * Active income categories, for the charge form.
     *
     * @return list<array{id: int, name: string}>
     */
    public function activeIncome(): array
    {
        return $this->fetchAll(
            "SELECT id, name FROM financial_categories
              WHERE condominium_id = :tenant AND kind = 'income' AND is_active = 1
              ORDER BY name",
            $this->scoped()
        );
    }

    /** An active income category of this tenant, or null (a tampered id is simply not found). */
    public function findActiveIncome(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT id, name FROM financial_categories
              WHERE id = :id AND condominium_id = :tenant AND kind = 'income' AND is_active = 1",
            $this->scoped(['id' => $id])
        );
    }

    /**
     * Seeds the default income categories (Phase 1, FR-TEN-01) when missing.
     * Idempotent thanks to the unique key (condominium_id, kind, name).
     */
    public function ensureDefaults(): void
    {
        foreach (self::DEFAULT_INCOME as $name) {
            $this->execute(
                "INSERT INTO financial_categories (condominium_id, name, kind)
                 VALUES (:tenant, :name, 'income')
                 ON DUPLICATE KEY UPDATE id = id",
                $this->scoped(['name' => $name])
            );
        }
    }
}
```

`app/Models/Invoice.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Bills issued to units (`invoices`). Amounts are DECIMAL(12,2): PDO returns
 * them as strings ("1234.50") and they are kept as strings in PHP, never floats.
 *
 * "Overdue" is not stored: it is computed as status = 'open' AND due_date < today
 * (the condominium's local date, passed in by the caller).
 */
final class Invoice extends TenantModel
{
    public const TYPES = [
        'monthly_fee'   => 'Taxa condominial',
        'extraordinary' => 'Taxa extra',
        'fine'          => 'Multa',
        'other'         => 'Outro',
    ];

    public const STATUS_LABELS = [
        'draft'     => 'Rascunho',
        'open'      => 'Em aberto',
        'paid'      => 'Paga',
        'cancelled' => 'Cancelada',
    ];

    protected string $table = 'invoices';

    protected array $fillable = [
        'unit_id',
        'invoice_number',
        'invoice_type',
        'reference_month',
        'issue_date',
        'due_date',
        'total_amount',
        'status',
        'notes',
        'created_by_user_id',
    ];

    /**
     * Bills of the given units: a resident's own units only.
     *
     * The unit ids come from UnitResident::activeUnitIds() for the logged-in
     * user, never from the request. That is the Resident ownership filter, on
     * top of the tenant filter.
     *
     * @param list<int> $unitIds
     * @return list<array<string, mixed>>
     */
    public function forUnits(array $unitIds, string $today): array
    {
        if ($unitIds === []) {
            return [];
        }
        [$placeholders, $unitParams] = $this->inList('unit', $unitIds);

        return $this->fetchAll(
            "SELECT i.id, i.invoice_number, i.invoice_type, i.reference_month, i.due_date, i.total_amount,
                    i.status, i.paid_at, " . Unit::LABEL_SQL . " AS unit_label,
                    (i.status = 'open' AND i.due_date < :today) AS is_overdue
               FROM invoices i
               JOIN units u ON u.condominium_id = i.condominium_id AND u.id = i.unit_id
              WHERE i.condominium_id = :tenant
                AND i.unit_id IN ({$placeholders})
                AND i.status IN ('open', 'paid')
              ORDER BY i.status = 'paid', i.due_date DESC
              LIMIT 120",
            $this->scoped(['today' => $today] + $unitParams)
        );
    }

    /**
     * Financial status of every active unit of the tenant (manager overview).
     * Each placeholder is used once: native prepared statements do not allow
     * reusing a named parameter, hence today_a / today_b.
     *
     * @return list<array<string, mixed>>
     */
    public function unitOverview(string $today): array
    {
        return $this->fetchAll(
            "SELECT u.id, " . Unit::LABEL_SQL . " AS unit_label,
                    COALESCE(SUM(i.status = 'open'), 0) AS open_count,
                    COALESCE(SUM(i.status = 'open' AND i.due_date < :today_a), 0) AS overdue_count,
                    COALESCE(SUM(CASE WHEN i.status = 'open' THEN i.total_amount END), 0) AS open_amount,
                    COALESCE(SUM(CASE WHEN i.status = 'open' AND i.due_date < :today_b THEN i.total_amount END), 0)
                        AS overdue_amount,
                    MIN(CASE WHEN i.status = 'open' THEN i.due_date END) AS next_due_date
               FROM units u
               LEFT JOIN invoices i ON i.condominium_id = u.condominium_id AND i.unit_id = u.id
              WHERE u.condominium_id = :tenant
                AND u.is_active = 1
              GROUP BY u.id, u.building, u.unit_number
              ORDER BY u.building, LENGTH(u.unit_number), u.unit_number",
            $this->scoped(['today_a' => $today, 'today_b' => $today])
        );
    }
}
```

`app/Models/InvoiceItem.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Lines of an invoice (`invoice_items`).
 */
final class InvoiceItem extends TenantModel
{
    protected string $table = 'invoice_items';

    protected array $fillable = [
        'invoice_id',
        'category_id',
        'description',
        'amount',
    ];
}
```


### Service

`app/Services/InvoiceService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\TenantContext;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TenantCounter;
use App\Models\Unit;
use PDOException;

/**
 * Creating charges (invoices) for units.
 */
final class InvoiceService
{
    public function __construct(
        private readonly Invoice $invoices = new Invoice(),
        private readonly InvoiceItem $items = new InvoiceItem(),
        private readonly Unit $units = new Unit(),
        private readonly FinancialCategory $categories = new FinancialCategory(),
        private readonly TenantCounter $counters = new TenantCounter()
    ) {
    }

    /**
     * Creates an open invoice with one line for a unit of the current condominium.
     *
     * @param array{unit_id: int, category_id: int, invoice_type: string, reference_month: string,
     *              due_date: string, amount: string, description: string, notes: ?string} $data
     *        amount is a canonical decimal string from Validator::money(), never a float.
     * @return array{id: int, invoice_number: int}
     * @throws BusinessRuleException
     */
    public function createCharge(array $data, int $managerUserId): array
    {
        // Ownership checks: both ids must belong to THIS condominium. TenantModel
        // scoping makes another tenant's id behave exactly like a missing one.
        if ($this->units->findActive($data['unit_id']) === null) {
            throw new BusinessRuleException('Unidade não encontrada neste condomínio.', 422, 'unit_id');
        }
        if ($this->categories->findActiveIncome($data['category_id']) === null) {
            throw new BusinessRuleException('Categoria inválida.', 422, 'category_id');
        }

        try {
            return Database::transaction(function () use ($data, $managerUserId): array {
                $number = $this->counters->next('invoice');
                $invoiceId = $this->invoices->insert([
                    'unit_id'            => $data['unit_id'],
                    'invoice_number'     => $number,
                    'invoice_type'       => $data['invoice_type'],
                    'reference_month'    => $data['reference_month'],
                    'issue_date'         => TenantContext::today(),
                    'due_date'           => $data['due_date'],
                    'total_amount'       => $data['amount'], // decimal string, bound as-is
                    'status'             => 'open',
                    'notes'              => $data['notes'],
                    'created_by_user_id' => $managerUserId,
                ]);
                $this->items->insert([
                    'invoice_id'  => $invoiceId,
                    'category_id' => $data['category_id'],
                    'description' => $data['description'],
                    'amount'      => $data['amount'],
                ]);

                return ['id' => $invoiceId, 'invoice_number' => $number];
            });
        } catch (PDOException $e) {
            // 1062 = duplicate key. The only reachable one is uq_invoices_one_monthly_fee:
            // the schema allows one live monthly fee per unit per month.
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new BusinessRuleException(
                    'Esta unidade já tem uma taxa condominial para o mês de referência informado.',
                    409,
                    'reference_month'
                );
            }
            throw $e;
        }
    }
}
```


### Controller

`app/Controllers/FinanceController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Core\TenantContext;
use App\Core\Validator;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\UnitResident;
use App\Services\BusinessRuleException;
use App\Services\InvoiceService;

/**
 * Financial module (Financeiro): charges per unit and each unit's status.
 */
final class FinanceController extends Controller
{
    public const VIEWERS = [Auth::SUPER_ADMIN, 'manager', 'resident'];

    /** Only managers create charges. Residents and the read-only Super Admin get 403. */
    public const MANAGERS = ['manager'];

    /** See the whole condominium's financial overview. */
    private const SEE_ALL = [Auth::SUPER_ADMIN, 'manager'];

    private const KEEP = ['unit_id', 'category_id', 'invoice_type', 'reference_month', 'due_date', 'amount', 'description', 'notes'];

    /**
     * GET /finance
     * Staff: overview of every unit + charge form. Resident: their own unit's bills.
     */
    public function index(): Response
    {
        $this->requireRole(self::VIEWERS);
        $today = TenantContext::today();

        if (!Auth::hasRole(self::SEE_ALL)) {
            // Resident: bills of the units they own or rent (dependents excluded),
            // looked up from THEIR user id, never from the request.
            $unitIds = (new UnitResident())->activeUnitIds((int) Auth::id(), billingOnly: true);
            $invoices = (new Invoice())->forUnits($unitIds, $today);

            return $this->view('finance/resident', [
                'title'        => 'Minhas cobranças',
                'activeNav'    => 'finance',
                'hasUnits'     => $unitIds !== [],
                'pending'      => array_values(array_filter($invoices, static fn (array $i): bool => $i['status'] === 'open')),
                'paid'         => array_values(array_filter($invoices, static fn (array $i): bool => $i['status'] === 'paid')),
                'types'        => Invoice::TYPES,
            ]);
        }

        $canManage = Auth::hasRole(self::MANAGERS);
        $categories = new FinancialCategory();
        if ($canManage && $categories->activeIncome() === []) {
            $categories->ensureDefaults();
        }

        return $this->view('finance/manager', [
            'title'      => 'Financeiro',
            'activeNav'  => 'finance',
            'canManage'  => $canManage,
            'overview'   => (new Invoice())->unitOverview($today),
            'units'      => (new Unit())->active(),
            'categories' => $categories->activeIncome(),
            'types'      => Invoice::TYPES,
            'today'      => $today,
        ]);
    }

    /**
     * POST /finance/charges: creates a charge for a unit.
     *
     * The route already requires role "manager"; requireRole() repeats it here
     * so the rule holds even if the route table is edited by mistake.
     */
    public function storeCharge(): Response
    {
        $this->requireRole(self::MANAGERS);

        $v = new Validator($this->request);
        $unitId = $v->id('unit_id', 'a unidade');
        $categoryId = $v->id('category_id', 'a categoria');
        $type = $v->enum('invoice_type', 'Tipo de cobrança', array_keys(Invoice::TYPES));
        $month = $v->month('reference_month', 'Mês de referência');
        $dueDate = $v->date('due_date', 'Vencimento');
        $amount = $v->money('amount', 'Valor'); // canonical decimal string, never float
        $description = $v->string('description', 'Descrição', 3, 200);
        $notes = $v->string('notes', 'Observações', 1, 500, required: false);

        if ($dueDate !== null && $dueDate < TenantContext::today()) {
            $v->addError('due_date', 'O vencimento não pode ser anterior a hoje.');
        }
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/finance', 422, self::KEEP);
        }

        try {
            $result = (new InvoiceService())->createCharge([
                'unit_id'         => (int) $unitId,
                'category_id'     => (int) $categoryId,
                'invoice_type'    => (string) $type,
                'reference_month' => (string) $month,
                'due_date'        => (string) $dueDate,
                'amount'          => (string) $amount,
                'description'     => (string) $description,
                'notes'           => $notes,
            ], (int) Auth::id());
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], '/finance', $e->status(), self::KEEP);
        }

        return $this->done("Cobrança nº {$result['invoice_number']} criada.", '/finance', ['invoice' => $result], 201);
    }
}
```


### Views

The resident view lists the resident's own pending and paid bills, with overdue rows highlighted:

`app/Views/finance/resident.php`

```php
<?php
/**
 * A resident's own bills. $pending and $paid only contain invoices of units
 * the resident owns or rents (filtered in SQL by FinanceController/Invoice::forUnits).
 * Amounts arrive as DECIMAL strings and are formatted with money_br(), never cast to float.
 *
 * @var bool                       $hasUnits
 * @var list<array<string, mixed>> $pending
 * @var list<array<string, mixed>> $paid
 * @var array<string, string>      $types
 */
$overdueCount = count(array_filter($pending, static fn (array $i): bool => (bool) $i['is_overdue']));
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Minhas cobranças</h1>
        <p class="page-header__subtitle">Boletos e taxas da sua unidade</p>
    </div>
</section>

<?php if (!$hasUnits): ?>
    <div class="alert alert--warning">
        Nenhuma unidade vinculada à sua conta como proprietário ou inquilino. Fale com a administração.
    </div>
<?php else: ?>
    <?php if ($overdueCount > 0): ?>
        <div class="alert alert--error" role="alert">
            Você tem <?= e($overdueCount) ?> cobrança(s) vencida(s). Regularize para evitar multa e juros.
        </div>
    <?php endif; ?>

    <section class="panel">
        <h2 class="panel__title panel__title--bar">Em aberto</h2>
        <?php if ($pending === []): ?>
            <p class="state">Nenhuma cobrança em aberto. Tudo em dia!</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr><th>Nº</th><th>Unidade</th><th>Descrição</th><th>Referência</th><th>Vencimento</th><th class="num">Valor</th><th>Situação</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pending as $invoice): ?>
                        <?php $overdue = (bool) $invoice['is_overdue']; ?>
                        <tr class="<?= $overdue ? 'row--overdue' : '' ?>">
                            <td class="mono"><?= e($invoice['invoice_number']) ?></td>
                            <td><?= e($invoice['unit_label']) ?></td>
                            <td><?= e($types[$invoice['invoice_type']] ?? $invoice['invoice_type']) ?></td>
                            <td><?= e(substr(date_br((string) $invoice['reference_month']), 3)) /* "mm/YYYY" */ ?></td>
                            <td><?= e(date_br($invoice['due_date'])) ?></td>
                            <td class="num"><?= e(money_br((string) $invoice['total_amount'])) ?></td>
                            <td>
                                <?php if ($overdue): ?>
                                    <span class="pill pill--overdue">Vencida</span>
                                <?php else: ?>
                                    <span class="pill pill--open">A vencer</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2 class="panel__title panel__title--bar">Pagas</h2>
        <?php if ($paid === []): ?>
            <p class="state">Nenhuma cobrança paga ainda.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--compact">
                    <thead>
                    <tr><th>Nº</th><th>Unidade</th><th>Descrição</th><th>Vencimento</th><th>Pago em</th><th class="num">Valor</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($paid as $invoice): ?>
                        <tr>
                            <td class="mono"><?= e($invoice['invoice_number']) ?></td>
                            <td><?= e($invoice['unit_label']) ?></td>
                            <td><?= e($types[$invoice['invoice_type']] ?? $invoice['invoice_type']) ?></td>
                            <td><?= e(date_br($invoice['due_date'])) ?></td>
                            <td><?= e(local_datetime($invoice['paid_at'], 'd/m/Y')) ?></td>
                            <td class="num"><?= e(money_br((string) $invoice['total_amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
```


The manager view shows the status of every unit plus the charge form:

`app/Views/finance/manager.php`

```php
<?php
/**
 * Manager view: financial status of every unit and the "new charge" form.
 * The Super Admin sees the table but not the form ($canManage = false).
 *
 * @var bool                                $canManage
 * @var list<array<string, mixed>>          $overview
 * @var list<array{id: int, label: string}> $units
 * @var list<array{id: int, name: string}>  $categories
 * @var array<string, string>               $types
 * @var string                              $today
 * @var array<string, string>               $errors
 * @var array<string, string>               $old
 */
$selected = static fn (string $field, string|int $value): string
    => (string) ($old[$field] ?? '') === (string) $value ? ' selected' : '';
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Financeiro</h1>
        <p class="page-header__subtitle">Situação das unidades e lançamento de cobranças</p>
    </div>
</section>

<div class="columns">
    <?php if ($canManage): ?>
        <div class="columns__side">
            <section class="panel panel--padded">
                <h2 class="panel__title">Nova cobrança</h2>
                <form method="post" action="/finance/charges" class="form" novalidate>
                    <?= csrf_field() ?>
                    <?= field_error($errors, 'general') ?>
                    <label class="form__field">
                        <span class="form__label">Unidade</span>
                        <select name="unit_id" required>
                            <option value="">Selecione…</option>
                            <?php foreach ($units as $unit): ?>
                                <option value="<?= e($unit['id']) ?>"<?= $selected('unit_id', $unit['id']) ?>><?= e($unit['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= field_error($errors, 'unit_id') ?>
                    </label>
                    <div class="form__row">
                        <label class="form__field">
                            <span class="form__label">Tipo</span>
                            <select name="invoice_type">
                                <?php foreach ($types as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= $selected('invoice_type', $value) ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error($errors, 'invoice_type') ?>
                        </label>
                        <label class="form__field">
                            <span class="form__label">Categoria</span>
                            <select name="category_id" required>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= e($category['id']) ?>"<?= $selected('category_id', $category['id']) ?>><?= e($category['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error($errors, 'category_id') ?>
                        </label>
                    </div>
                    <label class="form__field">
                        <span class="form__label">Descrição</span>
                        <input type="text" name="description" maxlength="200" required value="<?= e($old['description'] ?? '') ?>">
                        <?= field_error($errors, 'description') ?>
                    </label>
                    <div class="form__row">
                        <label class="form__field">
                            <span class="form__label">Mês de referência</span>
                            <input type="month" name="reference_month" required
                                   value="<?= e($old['reference_month'] ?? substr($today, 0, 7)) ?>">
                            <?= field_error($errors, 'reference_month') ?>
                        </label>
                        <label class="form__field">
                            <span class="form__label">Vencimento</span>
                            <input type="date" name="due_date" required min="<?= e($today) ?>" value="<?= e($old['due_date'] ?? '') ?>">
                            <?= field_error($errors, 'due_date') ?>
                        </label>
                    </div>
                    <label class="form__field">
                        <span class="form__label">Valor (R$)</span>
                        <!-- type="text" + inputmode: accepts "1.234,56"; the server parses it as an exact decimal. -->
                        <input type="text" name="amount" inputmode="decimal" placeholder="0,00" required value="<?= e($old['amount'] ?? '') ?>">
                        <?= field_error($errors, 'amount') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">Observações (opcional)</span>
                        <textarea name="notes" rows="2" maxlength="500"><?= e($old['notes'] ?? '') ?></textarea>
                    </label>
                    <button type="submit" class="btn btn--primary">Lançar cobrança</button>
                </form>
            </section>
        </div>
    <?php endif; ?>

    <div class="columns__main">
        <section class="panel">
            <h2 class="panel__title panel__title--bar">Situação por unidade</h2>
            <?php if ($overview === []): ?>
                <p class="state">Nenhuma unidade cadastrada.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Unidade</th><th class="num">Em aberto</th><th class="num">Valor em aberto</th>
                            <th class="num">Vencidas</th><th class="num">Valor vencido</th><th>Próx. vencimento</th><th>Situação</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($overview as $row): ?>
                            <?php $overdue = (int) $row['overdue_count'] > 0; ?>
                            <tr class="<?= $overdue ? 'row--overdue' : '' ?>">
                                <td><?= e($row['unit_label']) ?></td>
                                <td class="num"><?= e((int) $row['open_count']) ?></td>
                                <td class="num"><?= e(money_br((string) $row['open_amount'])) ?></td>
                                <td class="num"><?= e((int) $row['overdue_count']) ?></td>
                                <td class="num"><?= e(money_br((string) $row['overdue_amount'])) ?></td>
                                <td><?= e(date_br($row['next_due_date'])) ?></td>
                                <td>
                                    <?php if ($overdue): ?>
                                        <span class="pill pill--overdue">Inadimplente</span>
                                    <?php elseif ((int) $row['open_count'] > 0): ?>
                                        <span class="pill pill--open">Em dia</span>
                                    <?php else: ?>
                                        <span class="pill pill--paid">Sem pendências</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
```


---

## 5. Frontend Integration: confirming a package pickup without reloading

There are three layers:

1. **`core/http.js`** sends the CSRF header and same-origin cookies, times out after 15 seconds, and turns network failures into `HttpError(0)`. A 401 response redirects to the login page.
2. **`core/async-form.js`** provides `bindAsyncForm()`. It works with any `<form data-async action="/api/...">`, disables the button while the request is in flight, ignores double clicks, and supports an optional client-side `validate()`. It maps 0/403/404/409/419/422/5xx responses to readable messages, preferring the server's `{"error"}` text, and marks the fields named in `{"errors"}`.
3. **`concierge.js`** wires the "Confirmar retirada" and "Registrar saída" forms and updates the row and counter in place.

All DOM writes use `textContent` or `createElement`; `innerHTML` is never used.

`public/assets/js/core/http.js`

```javascript
/**
 * Small fetch() wrapper shared by every page script.
 *
 * - Sends cookies (same-origin) and asks for JSON.
 * - Adds the CSRF token (from <meta name="csrf-token">) to state-changing requests.
 * - Redirects to the login page when the session has expired (401).
 * - Always rejects with HttpError, including network failures and timeouts
 *   (status 0), so callers need only one error path.
 */

const TIMEOUT_MS = 15000;

export class HttpError extends Error {
    /**
     * @param {number} status HTTP status, or 0 for network failure / timeout.
     * @param {any} payload Decoded JSON body ({error, errors}), or null.
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
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
    const init = { method, headers, credentials: 'same-origin', signal: controller.signal };

    if (method !== 'GET') {
        headers['X-CSRF-Token'] = csrfToken();
    }
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
    }

    let response;
    try {
        response = await fetch(url, init);
    } catch (cause) {
        // fetch() only rejects when no HTTP answer arrived: offline, DNS, CORS, abort.
        const timedOut = cause?.name === 'AbortError';
        throw new HttpError(0, {
            error: timedOut
                ? 'O servidor demorou para responder. Tente novamente.'
                : 'Sem conexão com o servidor. Verifique sua internet e tente novamente.',
        });
    } finally {
        clearTimeout(timer);
    }

    let payload = null;
    try {
        payload = await response.json();
    } catch {
        payload = null; // empty or non-JSON body (e.g. a proxy error page)
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

`public/assets/js/core/async-form.js`

```javascript
/**
 * Reusable "submit a form without reloading the page".
 *
 * Markup contract:
 *   <form method="post" action="/api/..." data-async="name">
 *       <input name="...">                      fields are sent as a JSON object
 *       <button type="submit">...</button>      disabled while the request is in flight
 *       <span data-feedback role="status"></span>   receives user-friendly messages
 *   </form>
 *
 * Behaviour:
 *   - Sends the CSRF token in the X-CSRF-Token header (via http.js).
 *   - Ignores double submits while a request is running.
 *   - Optional client-side validate() for instant feedback; the server always re-validates.
 *   - Maps network errors and non-2xx JSON answers to readable messages, and marks the
 *     fields named in the server's {"errors": {field: message}} as invalid.
 *   - Everything is written with textContent: server data is never parsed as HTML.
 */
import { postJson, HttpError } from './http.js';

const FALLBACK_MESSAGES = {
    0: 'Sem conexão com o servidor. Tente novamente.',
    403: 'Você não tem permissão para esta ação.',
    404: 'Registro não encontrado. Recarregue a página.',
    409: 'Esta ação já foi feita por outra pessoa. Recarregue a página.',
    419: 'Sua sessão expirou. Recarregue a página e tente novamente.',
    422: 'Verifique os dados informados.',
};
const GENERIC_MESSAGE = 'Não foi possível concluir a ação. Tente novamente em instantes.';

/**
 * Turns any error into a message safe to show to the user.
 * Server messages are used when present: the API only returns user-facing text.
 * @param {unknown} error
 * @returns {string}
 */
export function messageFor(error) {
    if (error instanceof HttpError) {
        const serverMessage = typeof error.payload?.error === 'string' ? error.payload.error : null;
        return serverMessage ?? FALLBACK_MESSAGES[error.status] ?? GENERIC_MESSAGE;
    }
    return GENERIC_MESSAGE;
}

/**
 * @param {HTMLElement|null} element
 * @param {string} text
 * @param {'info'|'success'|'error'} kind
 */
function showFeedback(element, text, kind) {
    if (!element) {
        return;
    }
    element.textContent = text;
    element.dataset.kind = kind;
}

/** Marks fields named by the server as invalid (aria-invalid drives the CSS). */
function markInvalidFields(form, errors) {
    form.querySelectorAll('[aria-invalid="true"]').forEach((field) => field.removeAttribute('aria-invalid'));
    if (!errors || typeof errors !== 'object') {
        return;
    }
    for (const name of Object.keys(errors)) {
        const field = form.elements.namedItem(name);
        if (field instanceof HTMLElement) {
            field.setAttribute('aria-invalid', 'true');
        }
    }
}

/**
 * Wires one form.
 *
 * @param {HTMLFormElement} form
 * @param {{
 *   validate?: (data: Record<string, string>, form: HTMLFormElement) => (string|null),
 *   onSuccess?: (payload: any, form: HTMLFormElement) => void,
 * }} [options]
 */
export function bindAsyncForm(form, { validate, onSuccess } = {}) {
    const feedback = form.querySelector('[data-feedback]');
    const submitButton = form.querySelector('[type="submit"]');
    let inFlight = false;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (inFlight) {
            return; // a double click must not send the action twice
        }

        const data = Object.fromEntries(new FormData(form));
        delete data._csrf; // the token travels in the header

        const clientError = validate ? validate(data, form) : null;
        if (clientError) {
            showFeedback(feedback, clientError, 'error');
            return;
        }

        inFlight = true;
        if (submitButton) {
            submitButton.disabled = true;
        }
        form.setAttribute('aria-busy', 'true');
        showFeedback(feedback, 'Enviando…', 'info');

        try {
            const payload = await postJson(form.getAttribute('action'), data);
            markInvalidFields(form, null);
            showFeedback(feedback, typeof payload?.message === 'string' ? payload.message : 'Concluído.', 'success');
            onSuccess?.(payload, form);
        } catch (error) {
            showFeedback(feedback, messageFor(error), 'error');
            markInvalidFields(form, error instanceof HttpError ? error.payload?.errors : null);
            if (!(error instanceof HttpError)) {
                console.error(error); // programming error, not a server answer
            }
        } finally {
            inFlight = false;
            form.removeAttribute('aria-busy');
            if (submitButton && form.isConnected) {
                submitButton.disabled = false;
            }
        }
    });
}
```

`public/assets/js/concierge.js`

```javascript
/**
 * Concierge desk: "Registrar saída" and "Confirmar retirada" without reloading.
 * Uses the reusable bindAsyncForm() and updates the row in place on success.
 */
import { bindAsyncForm } from './core/async-form.js';

/** Decreases a header counter such as "Visitantes no condomínio (3)". */
function decrementCounter(name) {
    const counter = document.querySelector(`[data-counter="${name}"]`);
    if (counter) {
        counter.textContent = String(Math.max(0, Number(counter.textContent) - 1));
    }
}

/**
 * Replaces the action cell of a row with a confirmation text.
 * textContent only: the name comes from user input (server echo).
 */
function markRowDone(form, text) {
    const cell = form.closest('[data-status-cell]');
    const row = form.closest('[data-row]');
    const label = document.createElement('span');
    label.className = 'done-label';
    label.setAttribute('role', 'status');
    label.textContent = text;
    cell?.replaceChildren(label);
    row?.classList.add('row--done');
}

document.querySelectorAll('form[data-async="visit-exit"]').forEach((form) => {
    bindAsyncForm(form, {
        onSuccess: (payload, f) => {
            markRowDone(f, `Saída às ${payload.visit.exit_time}`);
            decrementCounter(f.dataset.decrement);
        },
    });
});

document.querySelectorAll('form[data-async="package-pickup"]').forEach((form) => {
    bindAsyncForm(form, {
        // Instant feedback; the server repeats every check (and verifies the code).
        validate: (data) => {
            if (!/^\d{6}$/.test(String(data.pickup_code ?? '').trim())) {
                return 'Informe o código de 6 dígitos.';
            }
            if (String(data.picked_up_by_name ?? '').trim().length < 3) {
                return 'Informe o nome de quem está retirando.';
            }
            return null;
        },
        onSuccess: (payload, f) => {
            markRowDone(f, `Retirada por ${payload.package.picked_up_by_name} em ${payload.package.picked_up_at}`);
            decrementCounter(f.dataset.decrement);
        },
    });
});
```


Matching markup, from `app/Views/concierge/index.php`:

```php
// … excerpt from app/Views/concierge/index.php
                                        <form method="post" action="/api/concierge/packages/<?= e($package['id']) ?>/pickup"
                                              class="inline-form" data-async="package-pickup" data-decrement="packages" novalidate>
                                            <input type="text" name="pickup_code" placeholder="Código" required
                                                   inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="off"
                                                   aria-label="Código de retirada">
                                            <input type="text" name="picked_up_by_name" placeholder="Quem retirou" required
                                                   maxlength="150" aria-label="Nome de quem retirou">
                                            <button type="submit" class="btn btn--small btn--primary">Confirmar</button>
                                            <span class="feedback" data-feedback role="status"></span>
                                        </form>
```


Server contract of `POST /api/concierge/packages/{id}/pickup`:

| Status | Body | When |
|--------|------|------|
| 200 | `{"message": "...", "package": {"id", "status", "picked_up_by_name", "picked_up_at"}}` | Pickup recorded. |
| 401 | `{"error"}` | The session expired. `http.js` redirects to the login page. |
| 403 | `{"error"}` | The role is not concierge or manager. |
| 404 | `{"error"}` | No such package in this condominium. |
| 409 | `{"error"}` | The package was already picked up or returned. |
| 419 | `{"error"}` | The CSRF token is missing or invalid. |
| 422 | `{"error", "errors": {"pickup_code" \| "picked_up_by_name": "..."}}` | Invalid input or wrong code. |

---

## 6. Routes

These were added to `routes/web.php`, together with `use` statements for the four new controllers:

```php
// … excerpt from routes/web.php
// =============================================================================
// Phase 3 - management modules
//
// Every route: auth → tenant (TenantContext from the session) → role → csrf (POST).
// Each controller action ALSO calls requireRole() with the same list, and the
// role lists live as constants on the controllers, so routes, controllers and
// the sidebar can never disagree. URL ids ({id}) are only lookup keys: every
// model query adds condominium_id, plus the user/unit ownership filter for residents.
// =============================================================================

$viewers = static fn (array $roles): string => 'role:' . implode(',', $roles);

// --- Concierge (Portaria) -----------------------------------------------------
$router->get('/concierge', [ConciergeController::class, 'index'], [
    'auth', 'tenant', $viewers(ConciergeController::VIEWERS),
]);
$router->post('/concierge/visits', [ConciergeController::class, 'storeVisit'], [
    'auth', 'tenant', $viewers(ConciergeController::OPERATORS), 'csrf',
]);
$router->post('/api/concierge/visits/{id:\d+}/exit', [ConciergeController::class, 'exitVisit'], [
    'auth', 'tenant', $viewers(ConciergeController::OPERATORS), 'csrf',
]);
$router->post('/concierge/packages', [ConciergeController::class, 'storePackage'], [
    'auth', 'tenant', $viewers(ConciergeController::OPERATORS), 'csrf',
]);
$router->post('/api/concierge/packages/{id:\d+}/pickup', [ConciergeController::class, 'pickupPackage'], [
    'auth', 'tenant', $viewers(ConciergeController::OPERATORS), 'csrf',
]);

// --- Reservations (Reservas) ----------------------------------------------------
$router->get('/reservations', [ReservationController::class, 'index'], [
    'auth', 'tenant', $viewers(ReservationController::VIEWERS),
]);
$router->post('/reservations', [ReservationController::class, 'store'], [
    'auth', 'tenant', $viewers(ReservationController::BOOKERS), 'csrf',
]);
$router->post('/reservations/{id:\d+}/cancel', [ReservationController::class, 'cancel'], [
    'auth', 'tenant', $viewers(ReservationController::BOOKERS), 'csrf',
]);
$router->post('/reservations/{id:\d+}/decision', [ReservationController::class, 'decide'], [
    'auth', 'tenant', $viewers(ReservationController::MANAGERS), 'csrf',
]);

// --- Occurrences (Ocorrências) ---------------------------------------------------
$router->get('/occurrences', [OccurrenceController::class, 'index'], [
    'auth', 'tenant', $viewers(OccurrenceController::VIEWERS),
]);
$router->get('/occurrences/new', [OccurrenceController::class, 'create'], [
    'auth', 'tenant', $viewers(OccurrenceController::REPORTERS),
]);
$router->post('/occurrences', [OccurrenceController::class, 'store'], [
    'auth', 'tenant', $viewers(OccurrenceController::REPORTERS), 'csrf',
]);
$router->get('/occurrences/{id:\d+}', [OccurrenceController::class, 'show'], [
    'auth', 'tenant', $viewers(OccurrenceController::VIEWERS),
]);
$router->post('/occurrences/{id:\d+}/replies', [OccurrenceController::class, 'reply'], [
    'auth', 'tenant', $viewers(OccurrenceController::REPORTERS), 'csrf',
]);
$router->post('/occurrences/{id:\d+}/status', [OccurrenceController::class, 'updateStatus'], [
    'auth', 'tenant', $viewers(OccurrenceController::HANDLERS), 'csrf',
]);

// --- Financial (Financeiro) -------------------------------------------------------
$router->get('/finance', [FinanceController::class, 'index'], [
    'auth', 'tenant', $viewers(FinanceController::VIEWERS),
]);
$router->post('/finance/charges', [FinanceController::class, 'storeCharge'], [
    'auth', 'tenant', $viewers(FinanceController::MANAGERS), 'csrf',
]);

return $router;
```


## Styles

The new components (two-column layout, tables, status pills, overdue rows, timeline and async feedback) were appended to `public/assets/css/app.css`:

```css
// … excerpt from public/assets/css/app.css
/* =====================================================================
 * Phase 3 - management modules
 * ===================================================================== */

/* ---------- Layout ---------- */

.columns {
    display: grid;
    grid-template-columns: 360px minmax(0, 1fr);
    gap: 24px;
    align-items: start;
}

.columns__side,
.columns__main {
    display: flex;
    flex-direction: column;
    gap: 24px;
    min-width: 0;
}

/* Pages without a side form (read-only roles) use the full width. */
.columns > .columns__main:only-child {
    grid-column: 1 / -1;
}

.panel--padded {
    padding: 24px;
}

.panel--narrow {
    max-width: 760px;
}

.panel__title {
    font-size: 16px;
    margin-bottom: 16px;
}

.panel__title--bar {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
    padding: 16px 20px;
    border-bottom: 1px solid var(--color-border);
}

.page-header__eyebrow {
    margin: 0 0 6px;
    font-size: 13px;
}

.counter {
    min-width: 24px;
    padding: 1px 8px;
    border-radius: 999px;
    background: var(--color-primary-soft);
    color: var(--color-primary);
    font-size: 13px;
    text-align: center;
}

.tabs {
    display: flex;
    gap: 4px;
    margin-bottom: 16px;
    border-bottom: 1px solid var(--color-border);
}

.tabs__item {
    padding: 8px 14px;
    color: var(--color-muted);
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
}

.tabs__item.is-active {
    color: var(--color-primary);
    border-bottom-color: var(--color-primary);
    font-weight: 600;
}

/* ---------- Tables ---------- */

.table-wrap {
    overflow-x: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.table th,
.table td {
    padding: 12px 16px;
    text-align: left;
    vertical-align: middle;
    border-bottom: 1px solid var(--color-border);
}

.table th {
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    color: var(--color-muted);
    background: #fafbfc;
}

.table tbody tr:last-child td {
    border-bottom: 0;
}

.table tbody tr:hover {
    background: #fafbfc;
}

.table--compact th,
.table--compact td {
    padding: 8px 16px;
}

.table .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.table__action {
    text-align: right;
    white-space: nowrap;
}

.row--overdue td {
    background: var(--color-danger-soft);
}

.row--overdue td:first-child {
    box-shadow: inset 3px 0 0 var(--color-danger);
}

.row--done td {
    color: var(--color-muted);
}

.done-label {
    color: var(--color-success);
    font-weight: 600;
    white-space: normal;
}

/* ---------- Status pills ---------- */

.pill {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    background: var(--color-bg);
    color: var(--color-muted);
}

.pill--large {
    font-size: 14px;
    padding: 4px 14px;
}

.pill--pending,
.pill--in_progress {
    background: var(--color-warning-soft);
    color: var(--color-warning);
}

.pill--approved,
.pill--resolved,
.pill--paid {
    background: var(--color-success-soft);
    color: var(--color-success);
}

.pill--open {
    background: var(--color-primary-soft);
    color: var(--color-primary);
}

.pill--overdue,
.pill--rejected {
    background: var(--color-danger-soft);
    color: var(--color-danger);
}

.tag--warning {
    background: var(--color-warning-soft);
    color: var(--color-warning);
}

/* ---------- Forms (additions) ---------- */

.form__hint {
    font-size: 12px;
    color: var(--color-muted);
}

.form__actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.form__actions--split {
    justify-content: space-between;
    align-items: center;
}

.form--separated {
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid var(--color-border);
}

[aria-invalid="true"] {
    border-color: var(--color-danger) !important;
    background: var(--color-danger-soft);
}

input[type="date"],
input[type="time"],
input[type="month"],
input[type="number"] {
    width: 100%;
    padding: 9px 12px;
    font: inherit;
    color: var(--color-text);
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
}

.inline-form {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
}

.inline-form input[type="text"] {
    width: 130px;
    padding: 6px 8px;
}

.btn--small {
    padding: 5px 10px;
    font-size: 13px;
}

.btn--danger-ghost {
    color: var(--color-danger);
    border-color: transparent;
    background: transparent;
}

.btn--danger-ghost:hover {
    background: var(--color-danger-soft);
}

/* Async-form feedback text (async-form.js sets data-kind). */
.feedback {
    flex-basis: 100%;
    font-size: 12px;
    text-align: right;
}

.feedback:empty {
    display: none;
}

.feedback[data-kind="error"] {
    color: var(--color-danger);
}

.feedback[data-kind="success"] {
    color: var(--color-success);
}

.feedback[data-kind="info"] {
    color: var(--color-muted);
}

/* ---------- Occurrence timeline ---------- */

.timeline {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.timeline__item {
    padding: 14px 16px;
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
}

.timeline__item--internal {
    background: var(--color-warning-soft);
    border-color: #fedf89;
}

.timeline__meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    font-size: 13px;
    margin-bottom: 6px;
}

.timeline__status {
    margin: 0 0 6px;
    font-size: 13px;
}

/* ---------- Utilities ---------- */

.prewrap {
    white-space: pre-line;
    overflow-wrap: anywhere;
    margin: 0 0 8px;
}

.mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.small {
    font-size: 12px;
}

/* End of Phase 3 styles */
```


---

## Setup (in addition to Phase 2)

```
mysql -u root -p < database/migrations/0002_phase3_reservation_time_ranges.sql
```

Data that has no management screen yet must be inserted directly. Units are an example:

```sql
INSERT INTO koinon.units (condominium_id, building, unit_number) VALUES (1, 'A', '101'), (1, 'A', '102');
INSERT INTO koinon.unit_residents (condominium_id, unit_id, user_id, relationship, is_billing_contact)
VALUES (1, 1, <resident user id>, 'owner', 1);
```
