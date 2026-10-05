# Koinon: Phase 5 Administration

Platform administration and account lifecycle, built on the Phase 1–4 foundation:

- the Super Admin registers, edits, suspends and reactivates condominiums, and invites their first Property Manager;
- the Property Manager sets up the condominium (units, common areas, notices), manages its users, reports on finances and reads its audit log;
- every role can recover its account and maintain its own profile, password and e-mail.

This document is generated from the files in the repository. New files are shown in full. Files that already existed are shown as a `diff` against Phase 4 with only the added or changed lines, as the brief asks.

## Verification so far

- All PHP passes `php -l` (PHP 8.4) and all JavaScript passes `node --check`.
- Migrations 0001 → 0003 were applied on top of `database/schema.sql` on a throwaway **MariaDB 11.8** server, not MySQL 8.0. The append-only triggers rejected `UPDATE` and `DELETE` on `audit_logs`.
- The application was run with PHP's built-in server against that database and driven over HTTP by a scripted client, about 190 checks in all. They covered:
  - the platform: creating condominiums (CNPJ check digits, duplicates), inviting managers, suspending a condominium (open sessions end at once) and reactivating it;
  - invitations: accepting, single use, expiry, resend with throttling, and cancelling;
  - the privilege boundaries from the checklist at the end of this document: cross-tenant ids → 404, `super_admin` role → 422, self-change → 403, last manager → 409, a resident on `/admin` → 403, a manager on `/platform` → 403;
  - bulk units, opening hours and maximum duration enforced on real bookings, notice expiry, edit and delete;
  - manual payment and cancellation (row lock against double payment), period totals checked to the cent, CSV with BOM and formula injection neutralised;
  - password change (other sessions end, the current one continues under a new id), forgot/reset (identical answers, older links revoked, single use) and the e-mail change flow;
  - login rate limiting (429), audit isolation per tenant, and every new page for inline `<script>`/`style=` (none, as the CSP requires).
- The last-manager guard was also exercised directly through `MemberService`. Over HTTP it can only be reached in a race, because the acting manager is an active manager too.
- The e-mail templates were rendered with hostile values: every `<img onerror>` / `<script>` came out escaped.
- **Not tested:** MySQL 8.0 itself (the SQL avoids MariaDB- and MySQL-only syntax, see A-22), a real browser for the three new JavaScript modules, and real SMTP delivery (mail went to `storage/logs/mail-dev.log`).

## Assumptions

| # | Decision |
|---|----------|
| A-01 | **Language.** The UI and e-mails are in Brazilian Portuguese, and code, comments and this document are in English, as in Phases 1–4. Route prefixes follow the brief: `/platform` (Super Admin), `/admin` (Property Manager) and `/account` (all roles). |
| A-02 | **The Super Admin uses `/platform`, not `/admin`.** `/admin` routes are `auth → tenant → role:manager`. A Super Admin who entered a condominium through the selector (Phase 2) still gets 403 there. Platform actions on a tenant take the condominium id from the URL, load the row (404 if missing) and audit the action with that id. |
| A-03 | **Property Managers may invite and manage other Property Managers of the same condominium** (co-managers are common, and the brief's "last active Property Manager" rule implies several). The allowlist is `MemberService::MANAGEABLE_ROLES = ['manager', 'concierge', 'resident']`, compared as role **codes**. Super Admin is not a role (`users.is_super_admin`), and no screen or code path writes that column. |
| A-04 | **"Deactivate a user" deactivates the membership** (`condominium_users.status = 'inactive'`), not the global account. One person may belong to several condominiums, so a manager of condominium A must not be able to lock them out of B. Blocking a global account (`users.status = 'blocked'`) remains a platform-level database operation. History (posts, invoices, occurrences) is untouched because nothing is deleted. |
| A-05 | **Managers do not edit names or e-mails of their members.** Those columns belong to the global account and are changed only by the person (`/account`). A manager edits role, unit and status only. This also closes the obvious takeover path where a manager changes someone's e-mail and then resets the password. |
| A-06 | **Invitations.** A new e-mail gets a `users` row in `pending_verification` with no password. An existing account gets only the membership, and its password is never touched. The membership is created with the new status **`'invited'`** (migration 0003), which every "active" query already excludes. Accepting sets `email_verified_at` through `User::markEmailVerified()`, the same call Phase 2's `EmailVerificationService::verify()` makes, and revokes outstanding verification tokens. For a resident, the unit link is written at invitation time. |
| A-07 | **Token lifetimes:** invitation 72 h, password reset 60 min (the Phase 1 table comment), e-mail change 24 h; verification is unchanged (24 h). An invitation can be re-sent once per 60 s and 5 times per 24 h, and a resend revokes the previous link. Every token is 32 bytes from `random_bytes`, only `SHA-256` is stored, it is compared with `hash_equals`, it is single use (`consumed_at`) and it is revoked on resend or use. GET landing pages never consume a token (mail scanners open links). |
| A-08 | **Rate limits (database, per IP and per account):**<br>login 20/15 min per IP + 10/15 min per e-mail;<br>forgot password 5/15 min per IP + 3/h per e-mail;<br>reset submit 10/15 min per IP;<br>invitation resend 30/h per IP + 10/h per acting manager;<br>invitation accept 10/15 min per IP;<br>e-mail change 5/h per account.<br>Subjects are stored as SHA-256 hashes; rows older than 2 days are purged by the limiter itself. The Phase 1 account lockout (5 wrong passwords = 15 min) still applies. Login over the limit renders the form with **429**. "Forgot password" over the limit still shows the standard message. |
| A-09 | **Sessions.** Phase 2 already re-reads the user on every request and compares `session_version`. Phase 5 adds two things. First, a tenant user must still have **at least one active membership in an active condominium**, or the session is destroyed (this covers routes without the `tenant` middleware, like `/account`); `TenantMiddleware` still re-checks the current condominium. Second, credential changes now differ by kind:<br>password change: `session_version + 1`, so every other session ends; the current one continues under a regenerated id and CSRF token;<br>password reset and e-mail change: every session ends.<br>Suspending a condominium or deactivating a member takes effect on the next request. |
| A-10 | **Report definitions.** "Billed", "pending" and "overdue" use **invoices with a due date in the period** (`open` + `paid`; cancelled and draft excluded). Pending = open and not yet due; overdue = open and past due (derived, never stored). "Received" uses **confirmed payments with a payment date in the period** (cash view). The local days of the period are converted to UTC bounds for `payments.paid_at`. A period may cover at most 366 days. All sums are `SUM()` over `DECIMAL` columns and travel as strings. The browser also formats them as strings, never via `Number()`. |
| A-11 | **Manual payment = the invoice's full amount.** Partial payments and reversals are outside this brief. The payment date is a local date stored as local midnight in UTC; it cannot be in the future or before the issue date. Only `open` invoices can be paid or cancelled, under a row lock. Invoices are never deleted: cancellation keeps the row with `cancelled_at` and the reason. |
| A-12 | **CSV.** UTF-8 with BOM, `;` separator and `1234,56` decimals, which is what Excel expects in pt-BR. Formula injection: cells starting with `=`, `+`, `-`, `@`, TAB or CR get a leading `'`. Downloads require the same `auth → tenant → role:manager` chain as the pages. |
| A-13 | **Notices.** Creating stays on the Phase 2 dashboard dialog (`POST /api/notices`), which now accepts an optional expiry (local `datetime-local` → UTC). `/admin/notices` lists every notice with its state (visible, scheduled, expired) and edits title, body, priority, pin and expiry. **Delete is a real `DELETE`** (read receipts cascade), audited in the same transaction with the title kept. Managers may edit or delete notices written by the concierge. |
| A-14 | **Common areas.** Migration 0003 adds `opens_at`, `closes_at` (both or neither) and `max_duration_minutes`; `ReservationService::book()` enforces them under the area's row lock. The manager can also edit capacity, simultaneous bookings, approval and advance windows. `booking_fee` is not exposed because billing booking fees is outside this phase. Phase 3 seeded default areas whenever no **active** area existed; now it seeds only when the condominium has **no** area at all, so deactivating or renaming them sticks. |
| A-15 | **Audit log.** The Property Manager sees only rows with their `condominium_id`. Platform-level events (logins, password resets: `condominium_id NULL`) are visible to the Super Admin only. The table is append-only three times over: no model method updates or deletes, migration 0003 adds triggers that reject `UPDATE`/`DELETE`, and the recommended grant is `INSERT, SELECT`. `AuditLogger` redacts any detail key that looks like a password, token, secret or hash. The platform view shows times in UTC because entries span several time zones. |
| A-16 | **Avatar.** JPG, PNG or WebP of at most 1 MB and 4000×4000 px, detected by content (`finfo` + `getimagesize`), stored under a random name in `storage/uploads/avatars` (outside the web root), and served only to its owner at `GET /account/avatar` with a sandbox CSP. Avatars are not shown to other users in this phase. |
| A-17 | **E-mail change** requires the current password and e-mails a link to the **new** address; `users.email` changes only after confirmation. Whether the new address is already taken is checked only at confirmation, so the request form cannot be used to discover registered e-mails. The old address receives a security notice, as it does after a password change. |
| A-18 | **Token pages live under `/account` but work logged out:**<br>`/account/forgot-password` (guest);<br>`/account/reset-password`;<br>`/account/invitation`;<br>`/account/email/confirm`. |
| A-19 | **`condominiums.plan`** (trial, basic, professional) is informative in Phase 5; nothing is gated by plan. Each new condominium gets a unique slug and a random 8-character signup code (Phase 1 columns). |
| A-20 | **The "last active Property Manager"** cannot be deactivated or demoted. This is checked with every active manager row of the tenant locked (`SELECT … FOR UPDATE`), so two managers removing each other at the same moment are serialised. Nobody can change their own role or deactivate themselves (403). |
| A-21 | **Out of scope:** self-registration with the signup code, partial payments, payment reversal, a purge job for archived tenants, and gating features by plan. |
| A-22 | **Portability.** Locks never use `FOR UPDATE OF` (unsupported by MariaDB). `GROUP BY` lists are valid under `ONLY_FULL_GROUP_BY` (grouped by primary key). The triggers are single statements, so no `DELIMITER` is needed. |

---

## 1. Schema changes

One migration, `database/migrations/0003_phase5_administration.sql`, run after 0002. It adds only what Phase 1 does not already have: Phase 1 already has `condominiums.status`, `users.status`/`session_version`, `password_reset_tokens`, `payments` and `audit_logs`.

**`database/migrations/0003_phase5_administration.sql`**

```sql
-- =====================================================================================
-- Migration 0003 - Phase 5: platform administration, invitations, account recovery
--
-- Phase 1 already has most of what this phase needs: condominiums.status,
-- users.status / session_version / failed_login_count, password_reset_tokens,
-- payments, invoices.cancelled_at and audit_logs. This migration adds only what is
-- missing:
--
--   1. condominiums: plan, contact name and suspension metadata.
--   2. condominium_users: 'invited' status (membership waiting for the invitee to
--      accept) and who/when deactivated a membership.
--   3. common_areas: opening hours and maximum booking duration (enforced by
--      ReservationService::book()).
--   4. invitations: single-use invitation tokens (hash only), one membership each.
--   5. email_change_tokens: pending e-mail address changes (hash only).
--   6. rate_limit_hits: database-backed rate limiting (login, forgot password,
--      invitation resend), per IP and per account.
--   7. audit_logs: index for the platform-wide newest-first listing, plus triggers
--      that make the table append-only even for a user with UPDATE/DELETE grants.
--
-- Run once, after 0002, with the migration user (it needs ALTER, CREATE, INDEX and
-- TRIGGER; with binary logging enabled, CREATE TRIGGER also needs SUPER or
-- log_bin_trust_function_creators = 1).
--
-- Grants for the application user (NFR-SEC-12) on the new tables:
--   GRANT SELECT, INSERT, UPDATE ON koinon.invitations TO 'koinon_app'@'%';
--   GRANT SELECT, INSERT, UPDATE ON koinon.email_change_tokens TO 'koinon_app'@'%';
--   GRANT SELECT, INSERT, DELETE ON koinon.rate_limit_hits TO 'koinon_app'@'%';
-- (Not needed when the user already has SELECT/INSERT/UPDATE/DELETE on koinon.*.)
-- =====================================================================================

USE koinon;

-- -------------------------------------------------------------------------------------
-- 1. condominiums
-- Existing rows are all 'active', so the new CHECK holds for them.
-- -------------------------------------------------------------------------------------
ALTER TABLE condominiums
  ADD COLUMN plan ENUM('trial','basic','professional') NOT NULL DEFAULT 'basic'
    COMMENT 'Commercial plan; informative in Phase 5 (no feature gating yet)' AFTER billing_due_day,
  ADD COLUMN contact_name VARCHAR(150) NULL
    COMMENT 'Person the SaaS owner talks to (usually the first Property Manager)' AFTER email,
  ADD COLUMN suspended_at DATETIME NULL AFTER status,
  ADD COLUMN suspension_reason VARCHAR(255) NULL AFTER suspended_at,
  ADD CONSTRAINT ck_condominiums_suspended CHECK (status <> 'suspended' OR suspended_at IS NOT NULL);

-- -------------------------------------------------------------------------------------
-- 2. condominium_users
-- 'invited' memberships are excluded by every "status = 'active'" query, so an
-- invitee cannot enter the condominium before accepting.
-- -------------------------------------------------------------------------------------
ALTER TABLE condominium_users
  MODIFY COLUMN status ENUM('invited','pending_approval','active','inactive','rejected')
    NOT NULL DEFAULT 'pending_approval',
  ADD COLUMN deactivated_at DATETIME NULL AFTER approved_at,
  ADD COLUMN deactivated_by_user_id INT UNSIGNED NULL AFTER deactivated_at,
  ADD KEY ix_cu_deactivated_by (deactivated_by_user_id),
  ADD CONSTRAINT fk_cu_deactivated_by
    FOREIGN KEY (deactivated_by_user_id) REFERENCES users (id) ON DELETE RESTRICT;

-- -------------------------------------------------------------------------------------
-- 3. common_areas
-- Opening hours are local wall-clock times, like reservations.starts_at/ends_at.
-- Both NULL = open all day. Bookings never cross midnight (migration 0002), so
-- closes_at must be after opens_at.
-- -------------------------------------------------------------------------------------
ALTER TABLE common_areas
  ADD COLUMN opens_at TIME NULL COMMENT 'Local time; NULL with closes_at = open all day' AFTER rules,
  ADD COLUMN closes_at TIME NULL AFTER opens_at,
  ADD COLUMN max_duration_minutes SMALLINT UNSIGNED NULL
    COMMENT 'Longest single booking; NULL = no limit' AFTER closes_at,
  ADD CONSTRAINT ck_common_areas_hours
    CHECK ((opens_at IS NULL AND closes_at IS NULL)
        OR (opens_at IS NOT NULL AND closes_at IS NOT NULL AND closes_at > opens_at)),
  ADD CONSTRAINT ck_common_areas_duration
    CHECK (max_duration_minutes IS NULL OR max_duration_minutes BETWEEN 30 AND 1440);

-- -------------------------------------------------------------------------------------
-- 4. invitations
-- One row per invitation e-mail sent. The membership (status 'invited') is created
-- together with the first invitation; a resend revokes the previous row and adds a
-- new one, so at most one usable token exists per membership.
-- CASCADE from the membership: a token means nothing without it (memberships are
-- never deleted in practice, so this only fires on a tenant purge).
-- invited_by_user_id points at users, not condominium_users: the Super Admin, who
-- invites a condominium's first Property Manager, has no membership.
-- -------------------------------------------------------------------------------------
CREATE TABLE invitations (
  id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  condominium_id      INT UNSIGNED  NOT NULL,
  user_id             INT UNSIGNED  NOT NULL,
  token_hash          CHAR(64)      CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'hex(SHA-256(raw token))',
  expires_at          DATETIME      NOT NULL,
  consumed_at         DATETIME      NULL COMMENT 'Set when the invitation is accepted',
  revoked_at          DATETIME      NULL COMMENT 'Set by a resend or when the membership is deactivated',
  invited_by_user_id  INT UNSIGNED  NOT NULL,
  request_ip          VARBINARY(16) NULL,
  created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invitations_token_hash (token_hash),
  KEY ix_invitations_member (condominium_id, user_id, created_at),  -- latest invitation + resend throttling
  KEY ix_invitations_expires (expires_at),                          -- housekeeping purge
  KEY ix_invitations_invited_by (invited_by_user_id),
  CONSTRAINT fk_invitations_member
    FOREIGN KEY (condominium_id, user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE CASCADE,
  CONSTRAINT fk_invitations_invited_by
    FOREIGN KEY (invited_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT ck_invitations_expiry CHECK (expires_at > created_at),
  CONSTRAINT ck_invitations_single_outcome CHECK (consumed_at IS NULL OR revoked_at IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- 5. email_change_tokens
-- The new address is only written to users.email after its owner clicks the link,
-- so a typo (or a hijacked session) cannot move the account to an address nobody
-- controls. Same token design as password_reset_tokens.
-- -------------------------------------------------------------------------------------
CREATE TABLE email_change_tokens (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED  NOT NULL,
  new_email    VARCHAR(254)  NOT NULL COMMENT 'Trimmed and lower-cased',
  token_hash   CHAR(64)      CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at   DATETIME      NOT NULL,
  consumed_at  DATETIME      NULL,
  revoked_at   DATETIME      NULL,
  request_ip   VARBINARY(16) NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ect_token_hash (token_hash),
  KEY ix_ect_user_created (user_id, created_at),
  KEY ix_ect_expires (expires_at),
  CONSTRAINT fk_ect_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT ck_ect_expiry CHECK (expires_at > created_at),
  CONSTRAINT ck_ect_single_outcome CHECK (consumed_at IS NULL OR revoked_at IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- 6. rate_limit_hits
-- One row per attempt. bucket names the action ("login.ip", "login.account",
-- "password_forgot.ip", ...). subject_hash is SHA-256 of the IP address or the
-- e-mail, so the table never holds raw addresses. A sliding window is a COUNT over
-- ix_rate_limit_window. Rows older than the longest window are purged by the
-- application (App\Services\RateLimiter), so the table stays small.
-- No foreign keys: subjects may be e-mails that do not exist (that is the point).
-- -------------------------------------------------------------------------------------
CREATE TABLE rate_limit_hits (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket        VARCHAR(40)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  subject_hash  CHAR(64)        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_rate_limit_window (bucket, subject_hash, created_at),
  KEY ix_rate_limit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- 7. audit_logs
-- ix_audit_time serves the Super Admin's platform-wide "newest first" list (no
-- condominium filter); ix_audit_tenant_time already serves the tenant view.
-- The triggers reject every UPDATE and DELETE: evidence cannot be rewritten
-- through the application, whatever privileges its database user has.
-- -------------------------------------------------------------------------------------
ALTER TABLE audit_logs
  ADD KEY ix_audit_time (created_at),
  ADD KEY ix_audit_action_time (action_code, created_at);

CREATE TRIGGER trg_audit_logs_no_update BEFORE UPDATE ON audit_logs
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';

CREATE TRIGGER trg_audit_logs_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';

-- -------------------------------------------------------------------------------------
-- 8. Permission catalogue, kept in sync with the role-based checks (Phase 2, A-04).
-- -------------------------------------------------------------------------------------
INSERT INTO permissions (code, module, description) VALUES
  ('common_areas.configure', 'reservations', 'Set opening hours and booking limits of common areas'),
  ('finance.payments',       'financial',    'Record manual payments and cancel invoices');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.code = 'manager'
  AND p.code IN ('common_areas.configure', 'finance.payments');
```


## 2. Shared services

Small reusable pieces used by every feature below.

- **`TokenService`**: generate (32 bytes, `random_bytes`), hash (SHA-256), shape check, and constant-time match (`hash_equals`). Expiry, single use and revocation are columns of each token table, computed by MySQL.
- **`UserToken`**: the shared queries of the three per-user token tables. Phase 2's `EmailVerificationToken` now extends it, with its public API unchanged.
- **`AuditLogger`**: `tenant()` (condominium = `TenantContext::id()`), `platform()` (explicit condominium id validated by the caller) and `record()`. Secrets are redacted.
- **`RateLimiter`**: a sliding window in the database, per IP and per account. Phase 4's session-based `App\Core\RateLimiter` stays for social-feed flood control.
- **Middleware and session rules:**
  - `SuperAdminMiddleware` (`platform`);
  - `Auth::user()` now also ends sessions of users without an active membership in an active condominium;
  - `Auth::refreshAfterCredentialChange()` keeps the current session alive after a password change.
- **Core helpers:**
  - `Request::queryString()` / `file()`;
  - `Response::file()`;
  - new `Validator` rules: e-mail, new password, phone, `datetime-local`;
  - `Pagination` and the `pagination` partial;
  - `Model::likeContains()` (escaped `LIKE`);
  - `TenantContext::localToUtc()` / `utcToLocal()`;
  - `pill()`;
  - `Controller::invalid()` now shows the rule's own message for 403/409/429.

**`app/Services/TokenService.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * One implementation of the single-use e-mail token design shared by e-mail
 * verification (Phase 2), invitations, password resets and e-mail changes.
 *
 *  - generate(): 32 bytes from random_bytes() (a CSPRNG) → 64 hex characters.
 *    256 bits cannot be guessed or brute-forced over HTTP.
 *  - hash(): only SHA-256 of the raw token is stored. A database leak, backup or
 *    log of the token tables therefore contains no usable link. A fast hash is
 *    fine here (unlike passwords): the input is already high-entropy random.
 *  - matches(): constant-time comparison with hash_equals(), as a second check
 *    after the indexed lookup by hash.
 *  - Expiry, single use and revocation are columns of each token table
 *    (expires_at, consumed_at, revoked_at) and are computed by MySQL with
 *    UTC_TIMESTAMP(), so they never depend on the PHP server's clock.
 */
final class TokenService
{
    private const BYTES = 32;

    /**
     * A new raw token and its hash. The raw value goes into the e-mail only;
     * the hash goes into the database.
     *
     * @return array{raw: string, hash: string}
     */
    public function generate(): array
    {
        $raw = bin2hex(random_bytes(self::BYTES));

        return ['raw' => $raw, 'hash' => $this->hash($raw)];
    }

    /** hex(SHA-256(raw)), the value stored in the token_hash columns. */
    public function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /**
     * Rejects anything that is not exactly 64 lowercase hex characters before a
     * query runs, so malformed or oversized input never reaches the database.
     */
    public function isWellFormed(string $raw): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $raw) === 1;
    }

    /** Constant-time check that $raw hashes to the stored value. */
    public function matches(string $raw, string $storedHash): bool
    {
        return $this->isWellFormed($raw) && hash_equals($storedHash, $this->hash($raw));
    }
}
```


**`app/Models/UserToken.php`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Base class of the per-user token tables that share the Phase 1 design:
 * email_verification_tokens, password_reset_tokens and email_change_tokens.
 *
 * Columns: user_id, token_hash, expires_at, consumed_at, revoked_at,
 * request_ip, created_at. Only hashes are stored (see TokenService). Every time
 * comparison is done by MySQL with UTC_TIMESTAMP(), so expiry does not depend on
 * the PHP server's clock. The table name comes from the subclass (code), never
 * from input.
 */
abstract class UserToken extends Model
{
    /**
     * Finds a token by hash. `is_expired` is computed by MySQL. With $forUpdate
     * the row stays locked until the transaction ends, so two simultaneous
     * submits cannot both consume the same token.
     *
     * @return array<string, mixed>|null
     */
    public function findByHash(string $tokenHash, bool $forUpdate = false): ?array
    {
        $sql = sprintf(
            'SELECT t.*, (t.expires_at <= UTC_TIMESTAMP()) AS is_expired FROM `%s` t WHERE t.token_hash = :token_hash%s',
            $this->table,
            $forUpdate ? ' FOR UPDATE' : ''
        );

        return $this->fetchOne($sql, ['token_hash' => $tokenHash]);
    }

    /** Marks a token as used (single use). */
    public function markConsumed(int $id): void
    {
        $this->execute(
            sprintf('UPDATE `%s` SET consumed_at = UTC_TIMESTAMP() WHERE id = :id AND consumed_at IS NULL', $this->table),
            ['id' => $id]
        );
    }

    /**
     * Revokes every still-usable token of a user: on resend, after use, and
     * after a password change (Phase 5 rule: a reset invalidates the others).
     */
    public function revokeOutstanding(int $userId): void
    {
        $this->execute(
            sprintf(
                'UPDATE `%s` SET revoked_at = UTC_TIMESTAMP()
                  WHERE user_id = :user_id AND consumed_at IS NULL AND revoked_at IS NULL',
                $this->table
            ),
            ['user_id' => $userId]
        );
    }

    /** Seconds since the user's most recent token, or null if there is none. */
    public function secondsSinceLast(int $userId): ?int
    {
        $row = $this->fetchOne(
            sprintf(
                'SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), UTC_TIMESTAMP()) AS seconds FROM `%s` WHERE user_id = :user_id',
                $this->table
            ),
            ['user_id' => $userId]
        );

        return ($row === null || $row['seconds'] === null) ? null : (int) $row['seconds'];
    }

    /** Number of tokens created for the user in the last $hours hours. */
    public function countSince(int $userId, int $hours): int
    {
        $row = $this->fetchOne(
            sprintf(
                'SELECT COUNT(*) AS total FROM `%s`
                  WHERE user_id = :user_id AND created_at > UTC_TIMESTAMP() - INTERVAL :hours HOUR',
                $this->table
            ),
            ['user_id' => $userId, 'hours' => $hours]
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Stores a new token hash valid for $ttlMinutes.
     *
     * @param array<string, string> $extra Additional columns of the subclass
     *                                     (keys from code, values bound).
     */
    protected function insertToken(int $userId, string $tokenHash, string $ip, int $ttlMinutes, array $extra = []): void
    {
        $columns = '';
        $values = '';
        foreach (array_keys($extra) as $column) {
            $columns .= ', `' . $column . '`';
            $values .= ', :' . $column;
        }

        $this->execute(
            sprintf(
                'INSERT INTO `%s` (user_id, token_hash, expires_at, request_ip%s)
                 VALUES (:user_id, :token_hash, UTC_TIMESTAMP() + INTERVAL :ttl MINUTE, INET6_ATON(:ip)%s)',
                $this->table,
                $columns,
                $values
            ),
            ['user_id' => $userId, 'token_hash' => $tokenHash, 'ttl' => $ttlMinutes, 'ip' => $ip] + $extra
        );
    }
}
```


**`app/Models/EmailVerificationToken.php`** (existing file: changes only)

```diff
@@ -4,87 +4,21 @@ declare(strict_types=1);
 
 namespace App\Models;
 
-use App\Core\Model;
-
 /**
  * Activation tokens `email_verification_tokens` (global: they belong to a user,
  * not to a tenant).
  *
  * Only SHA-256 hashes are stored. A database leak therefore does not reveal any
- * usable activation link. Times are computed by MySQL (UTC_TIMESTAMP()) so that
- * expiry never depends on the PHP server's clock.
+ * usable activation link. Lookup, consumption, revocation and throttling
+ * queries are shared with the other token tables (UserToken, Phase 5).
  */
-final class EmailVerificationToken extends Model
+final class EmailVerificationToken extends UserToken
 {
     protected string $table = 'email_verification_tokens';
 
     /** Stores a new token hash valid for $ttlHours. */
     public function create(int $userId, string $tokenHash, string $ip, int $ttlHours): void
     {
-        $this->execute(
-            'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, request_ip)
-             VALUES (:user_id, :token_hash, UTC_TIMESTAMP() + INTERVAL :ttl HOUR, INET6_ATON(:ip))',
-            ['user_id' => $userId, 'token_hash' => $tokenHash, 'ttl' => $ttlHours, 'ip' => $ip]
-        );
-    }
-
-    /**
-     * Finds a token by hash. `is_expired` is computed by MySQL. With $forUpdate
-     * the row stays locked until the transaction ends, so two simultaneous
-     * clicks cannot both consume the same token.
-     */
-    public function findByHash(string $tokenHash, bool $forUpdate = false): ?array
-    {
-        $sql = 'SELECT id, user_id, consumed_at, revoked_at,
-                       (expires_at <= UTC_TIMESTAMP()) AS is_expired
-                  FROM email_verification_tokens
-                 WHERE token_hash = :token_hash'
-            . ($forUpdate ? ' FOR UPDATE' : '');
-
-        return $this->fetchOne($sql, ['token_hash' => $tokenHash]);
-    }
-
-    /** Marks a token as used (single use). */
-    public function markConsumed(int $id): void
-    {
-        $this->execute(
-            'UPDATE email_verification_tokens SET consumed_at = UTC_TIMESTAMP() WHERE id = :id',
-            ['id' => $id]
-        );
-    }
-
-    /** Revokes every still-usable token of a user (on resend and after activation). */
-    public function revokeOutstanding(int $userId): void
-    {
-        $this->execute(
-            'UPDATE email_verification_tokens
-                SET revoked_at = UTC_TIMESTAMP()
-              WHERE user_id = :user_id AND consumed_at IS NULL AND revoked_at IS NULL',
-            ['user_id' => $userId]
-        );
-    }
-
-    /** Seconds since the user's most recent token, or null if there is none. */
-    public function secondsSinceLast(int $userId): ?int
-    {
-        $row = $this->fetchOne(
-            'SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), UTC_TIMESTAMP()) AS seconds
-               FROM email_verification_tokens WHERE user_id = :user_id',
-            ['user_id' => $userId]
-        );
-
-        return ($row === null || $row['seconds'] === null) ? null : (int) $row['seconds'];
-    }
-
-    /** Number of tokens created for the user in the last $hours hours. */
-    public function countSince(int $userId, int $hours): int
-    {
-        $row = $this->fetchOne(
-            'SELECT COUNT(*) AS total FROM email_verification_tokens
-              WHERE user_id = :user_id AND created_at > UTC_TIMESTAMP() - INTERVAL :hours HOUR',
-            ['user_id' => $userId, 'hours' => $hours]
-        );
-
-        return (int) ($row['total'] ?? 0);
+        $this->insertToken($userId, $tokenHash, $ip, $ttlHours * 60);
     }
 }
```


**`app/Models/PasswordResetToken.php`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Password reset tokens `password_reset_tokens` (Phase 1 table, 60-minute lifetime
 * by default). Same design as the verification tokens: hash only, single use.
 */
final class PasswordResetToken extends UserToken
{
    protected string $table = 'password_reset_tokens';

    /** Stores a new token hash valid for $ttlMinutes. */
    public function create(int $userId, string $tokenHash, string $ip, int $ttlMinutes): void
    {
        $this->insertToken($userId, $tokenHash, $ip, $ttlMinutes);
    }
}
```


**`app/Models/EmailChangeToken.php`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Pending e-mail address changes `email_change_tokens` (migration 0003).
 * The new address waits here until its owner confirms it through the link.
 */
final class EmailChangeToken extends UserToken
{
    protected string $table = 'email_change_tokens';

    /** Stores a new token hash for $newEmail (already normalised), valid for $ttlMinutes. */
    public function create(int $userId, string $newEmail, string $tokenHash, string $ip, int $ttlMinutes): void
    {
        $this->insertToken($userId, $tokenHash, $ip, $ttlMinutes, ['new_email' => $newEmail]);
    }
}
```


**`app/Services/EmailVerificationService.php`** (existing file: changes only)

```diff
@@ -15,8 +15,8 @@ use App\Models\User;
 /**
  * Account activation by e-mail (Phase 1, FR-AUTH-12 to FR-AUTH-17).
  *
- *  1. sendNew(): 32 random bytes -> 64 hex chars. Only hash('sha256', raw) is
- *     stored; the raw token exists only in the e-mailed link.
+ *  1. sendNew(): 32 random bytes -> 64 hex chars (TokenService). Only the
+ *     SHA-256 hash is stored; the raw token exists only in the e-mailed link.
  *  2. inspect(): used by GET /verify-email to show the right page. Read-only:
  *     link scanners in mail clients may open the link, so GET never consumes.
  *  3. verify(): POST /verify-email. In one transaction, locks the token row,
@@ -30,7 +30,8 @@ final class EmailVerificationService
         private readonly EmailVerificationToken $tokens = new EmailVerificationToken(),
         private readonly User $users = new User(),
         private readonly Mailer $mailer = new Mailer(),
-        private readonly AuditLog $audit = new AuditLog()
+        private readonly AuditLog $audit = new AuditLog(),
+        private readonly TokenService $tokenService = new TokenService()
     ) {
     }
 
@@ -43,13 +44,13 @@ final class EmailVerificationService
     public function sendNew(array $user, string $ip): bool
     {
         $userId = (int) $user['id'];
-        $rawToken = bin2hex(random_bytes(32));
+        ['raw' => $rawToken, 'hash' => $tokenHash] = $this->tokenService->generate();
 
-        Database::transaction(function () use ($userId, $rawToken, $ip): void {
+        Database::transaction(function () use ($userId, $tokenHash, $ip): void {
             $this->tokens->revokeOutstanding($userId);
             $this->tokens->create(
                 $userId,
-                hash('sha256', $rawToken),
+                $tokenHash,
                 $ip,
                 (int) Config::get('security.verification_ttl_hours', 24)
             );
@@ -88,11 +89,11 @@ final class EmailVerificationService
     /** Read-only check of a raw token (for the GET landing page). */
     public function inspect(string $rawToken): VerificationResult
     {
-        if (!self::isWellFormed($rawToken)) {
+        if (!$this->tokenService->isWellFormed($rawToken)) {
             return new VerificationResult(VerificationResult::INVALID);
         }
 
-        $token = $this->tokens->findByHash(hash('sha256', $rawToken));
+        $token = $this->tokens->findByHash($this->tokenService->hash($rawToken));
         $user = $token === null ? null : $this->users->find((int) $token['user_id']);
 
         return new VerificationResult($this->evaluate($token, $user), $user);
@@ -106,14 +107,14 @@ final class EmailVerificationService
      */
     public function verify(string $rawToken, ?string $newPasswordHash, Request $request): VerificationResult
     {
-        if (!self::isWellFormed($rawToken)) {
+        if (!$this->tokenService->isWellFormed($rawToken)) {
             return new VerificationResult(VerificationResult::INVALID);
         }
 
         return Database::transaction(function () use ($rawToken, $newPasswordHash, $request): VerificationResult {
             // Lock the token, then the user: concurrent submits are serialised,
             // and the second one sees consumed_at set and gets ALREADY_USED.
-            $token = $this->tokens->findByHash(hash('sha256', $rawToken), true);
+            $token = $this->tokens->findByHash($this->tokenService->hash($rawToken), true);
             $user = $token === null ? null : $this->users->findForUpdate((int) $token['user_id']);
 
             $state = $this->evaluate($token, $user);
@@ -136,12 +137,6 @@ final class EmailVerificationService
         });
     }
 
-    /** Tokens are exactly 64 lowercase hex chars; anything else is rejected without a query. */
-    private static function isWellFormed(string $rawToken): bool
-    {
-        return preg_match('/^[a-f0-9]{64}$/', $rawToken) === 1;
-    }
-
     /**
      * @param array<string, mixed>|null $token
      * @param array<string, mixed>|null $user
```


**`app/Services/AuditLogger.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\AuditLog;

/**
 * Writes security and administrative events to `audit_logs` (append-only).
 *
 * Three entry points make the tenant of every entry explicit:
 *  - tenant():   a Property Manager (or other member) acting inside the session's
 *                condominium. The condominium id is TenantContext::id(), never
 *                a value from the request.
 *  - platform(): a Super Admin acting on the platform, optionally on one
 *                condominium that the caller has already validated to exist.
 *  - record():   anything else (public flows such as a password reset, where the
 *                actor is the account the token belongs to).
 *
 * Details are passed through redact() so a careless caller cannot write a
 * password or a raw token into the log (Phase 1, NFR-OPS-02).
 */
final class AuditLogger
{
    /** Detail keys whose values are never stored, at any nesting depth. */
    private const SECRET_KEYS = '/pass(word)?|token|secret|hash/i';

    public function __construct(
        private readonly Request $request,
        private readonly AuditLog $log = new AuditLog()
    ) {
    }

    /**
     * An action inside the current condominium by the logged-in user.
     *
     * @param array<string, mixed> $details
     */
    public function tenant(string $action, ?string $entityType = null, ?int $entityId = null, array $details = []): void
    {
        $this->record($action, Auth::id(), TenantContext::id(), $entityType, $entityId, $details);
    }

    /**
     * A Super Admin action. $condominiumId is the target tenant (already
     * validated by the caller) or null for purely platform-level events.
     *
     * @param array<string, mixed> $details
     */
    public function platform(
        string $action,
        ?int $condominiumId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = []
    ): void {
        $this->record($action, Auth::id(), $condominiumId, $entityType, $entityId, $details);
    }

    /**
     * Generic entry with an explicit actor and condominium.
     *
     * @param array<string, mixed> $details
     */
    public function record(
        string $action,
        ?int $actorUserId,
        ?int $condominiumId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = []
    ): void {
        $this->log->record($action, $this->request, $actorUserId, $condominiumId, $entityType, $entityId, self::redact($details));
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private static function redact(array $details): array
    {
        foreach ($details as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEYS, $key) === 1) {
                $details[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $details[$key] = self::redact($value);
            }
        }

        return $details;
    }
}
```


**`app/Models/AuditLog.php`** (existing file: changes only)

```diff
@@ -5,14 +5,35 @@ declare(strict_types=1);
 namespace App\Models;
 
 use App\Core\Model;
+use App\Core\Pagination;
 use App\Core\Request;
 
 /**
  * Append-only security trail `audit_logs` (mixed scope: condominium_id is NULL
  * for platform-level events such as logins).
+ *
+ * Append-only three times over: this class has no update/delete method ($fillable
+ * is empty, so the inherited insert()/update() refuse to run), the migration 0003
+ * triggers reject UPDATE and DELETE in the database, and the application user
+ * should only be granted INSERT and SELECT on the table.
  */
 final class AuditLog extends Model
 {
+    /** Action prefixes offered in the filter (the part before the first dot). */
+    public const MODULES = [
+        'auth'          => 'Autenticação',
+        'account'       => 'Conta',
+        'invitation'    => 'Convites',
+        'member'        => 'Usuários',
+        'condominium'   => 'Condomínios',
+        'unit'          => 'Unidades',
+        'common_area'   => 'Áreas comuns',
+        'notice'        => 'Avisos',
+        'invoice'       => 'Financeiro',
+        'community'     => 'Comunidade',
+        'support'       => 'Acesso de suporte',
+    ];
+
     protected string $table = 'audit_logs';
 
     /**
@@ -45,4 +66,108 @@ final class AuditLog extends Model
             ]
         );
     }
+
+    /**
+     * One page of entries, newest first, with the actor and condominium names.
+     *
+     * TENANT ISOLATION: a Property Manager's view passes TenantContext::id() as
+     * $condominiumId, which becomes a mandatory "condominium_id = :condominium"
+     * condition. Only the Super Admin's view passes null (all entries), and the
+     * Super Admin may then narrow it with $filters['condominium_id'].
+     *
+     * @param array{module?: string, from_utc?: string, to_utc?: string, condominium_id?: int, q?: string} $filters
+     *        Already validated: module is a key of MODULES, dates are UTC "Y-m-d H:i:s".
+     * @return list<array<string, mixed>>
+     */
+    public function search(?int $condominiumId, array $filters, Pagination $pagination): array
+    {
+        [$where, $params] = $this->filterSql($condominiumId, $filters);
+
+        return $this->fetchAll(
+            "SELECT a.id, a.created_at, a.action_code, a.entity_type, a.entity_id, a.details,
+                    INET6_NTOA(a.ip_address) AS ip, a.condominium_id,
+                    c.name AS condominium_name, u.full_name AS actor_name, u.email AS actor_email
+               FROM audit_logs a
+               LEFT JOIN users u ON u.id = a.actor_user_id
+               LEFT JOIN condominiums c ON c.id = a.condominium_id
+              WHERE {$where}
+              ORDER BY a.created_at DESC, a.id DESC
+              LIMIT :limit OFFSET :offset",
+            $params + ['limit' => $pagination->limit(), 'offset' => $pagination->offset()]
+        );
+    }
+
+    /**
+     * Total for the same filters as search().
+     *
+     * @param array{module?: string, from_utc?: string, to_utc?: string, condominium_id?: int, q?: string} $filters
+     */
+    public function count(?int $condominiumId, array $filters): int
+    {
+        [$where, $params] = $this->filterSql($condominiumId, $filters);
+        $row = $this->fetchOne(
+            "SELECT COUNT(*) AS total FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id WHERE {$where}",
+            $params
+        );
+
+        return (int) ($row['total'] ?? 0);
+    }
+
+    /**
+     * Newest entries for the platform overview.
+     *
+     * @return list<array<string, mixed>>
+     */
+    public function latest(int $limit): array
+    {
+        return $this->fetchAll(
+            'SELECT a.id, a.created_at, a.action_code, a.condominium_id, c.name AS condominium_name,
+                    u.full_name AS actor_name
+               FROM audit_logs a
+               LEFT JOIN users u ON u.id = a.actor_user_id
+               LEFT JOIN condominiums c ON c.id = a.condominium_id
+              ORDER BY a.created_at DESC, a.id DESC
+              LIMIT :limit',
+            ['limit' => $limit]
+        );
+    }
+
+    /**
+     * WHERE clause from fixed SQL fragments; every value is a bound parameter.
+     *
+     * @param array<string, mixed> $filters
+     * @return array{0: string, 1: array<string, mixed>}
+     */
+    private function filterSql(?int $condominiumId, array $filters): array
+    {
+        $conditions = ['1 = 1'];
+        $params = [];
+
+        $tenant = $condominiumId ?? ($filters['condominium_id'] ?? null);
+        if ($tenant !== null) {
+            $conditions[] = 'a.condominium_id = :condominium';
+            $params['condominium'] = (int) $tenant;
+        }
+        if (isset($filters['module']) && array_key_exists($filters['module'], self::MODULES)) {
+            $conditions[] = 'a.action_code LIKE :module';
+            $params['module'] = $filters['module'] . '.%';
+        }
+        if (isset($filters['from_utc'])) {
+            $conditions[] = 'a.created_at >= :from_utc';
+            $params['from_utc'] = $filters['from_utc'];
+        }
+        if (isset($filters['to_utc'])) {
+            $conditions[] = 'a.created_at < :to_utc';
+            $params['to_utc'] = $filters['to_utc'];
+        }
+        if (isset($filters['q']) && $filters['q'] !== '') {
+            // Actor name or e-mail. LIKE wildcards typed by the user are escaped.
+            $conditions[] = '(u.full_name LIKE :q_name OR u.email LIKE :q_email)';
+            $like = self::likeContains($filters['q']);
+            $params['q_name'] = $like;
+            $params['q_email'] = $like;
+        }
+
+        return [implode(' AND ', $conditions), $params];
+    }
 }
```


**`app/Services/RateLimiter.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\RateLimitHit;
use LogicException;

/**
 * Database-backed sliding-window rate limiter (Phase 5).
 *
 * Unlike App\Core\RateLimiter (Phase 4, kept for flood control of the social
 * feed), which lives in the session, this one is stored in `rate_limit_hits`.
 * That matters for logged-out actions: an attacker simply drops the session
 * cookie to reset a session-based counter, but cannot reset a database row.
 *
 * Limits are configured per action and per subject kind in
 * config/security.php ("rate_limits"), e.g.:
 *
 *   'login' => ['ip' => [20, 900], 'account' => [10, 900]]   // 20 per IP / 10 per e-mail in 15 min
 *
 *   if (!$limiter->attempt('login', ['ip' => $request->ip(), 'account' => $email])) { ... 429 ... }
 *
 * Every subject is counted (no short-circuit), so an attacker rotating e-mail
 * addresses still runs into the per-IP limit, and one rotating IPs still runs
 * into the per-account limit.
 */
final class RateLimiter
{
    /** One in this many calls purges rows older than the longest window. */
    private const PURGE_ONE_IN = 50;

    public function __construct(private readonly RateLimitHit $hits = new RateLimitHit())
    {
    }

    /**
     * Records one attempt for each subject and reports whether ALL of them are
     * still within their limits.
     *
     * The hit is recorded BEFORE counting, so concurrent requests can only
     * over-count (and be refused), never slip through together: fail-closed.
     *
     * @param array<string, string> $subjects kind => value, e.g. ['ip' => '203.0.113.9']
     */
    public function attempt(string $action, array $subjects): bool
    {
        $limits = Config::get('security.rate_limits.' . $action);
        if (!is_array($limits)) {
            throw new LogicException(sprintf('No rate limit configured for "%s".', $action));
        }

        $allowed = true;
        foreach ($subjects as $kind => $value) {
            [$max, $seconds] = $limits[$kind] ?? throw new LogicException(sprintf('No "%s" limit for "%s".', $kind, $action));
            $bucket = $action . '.' . $kind;
            $subjectHash = self::subjectHash($value);

            $this->hits->record($bucket, $subjectHash);
            if ($this->hits->countSince($bucket, $subjectHash, (int) $seconds) > (int) $max) {
                $allowed = false;
            }
        }

        if (random_int(1, self::PURGE_ONE_IN) === 1) {
            $this->hits->purgeOlderThan(2 * 86400);
        }

        return $allowed;
    }

    /**
     * Subjects are stored as SHA-256 of the normalised value, so the table holds
     * no raw e-mail or IP address. (This is pseudonymisation, not anonymity:
     * IPv4 addresses are few enough to brute-force. The rows expire in 2 days.)
     */
    private static function subjectHash(string $value): string
    {
        return hash('sha256', mb_strtolower(trim($value)));
    }
}
```


**`app/Models/RateLimitHit.php`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Attempts counted by App\Services\RateLimiter (`rate_limit_hits`, migration 0003).
 * Global table: the subjects (IPs, e-mail addresses) are not tenant data.
 */
final class RateLimitHit extends Model
{
    protected string $table = 'rate_limit_hits';

    public function record(string $bucket, string $subjectHash): void
    {
        $this->execute(
            'INSERT INTO rate_limit_hits (bucket, subject_hash) VALUES (:bucket, :subject_hash)',
            ['bucket' => $bucket, 'subject_hash' => $subjectHash]
        );
    }

    /** Hits of one subject in one bucket during the last $seconds (index ix_rate_limit_window). */
    public function countSince(string $bucket, string $subjectHash, int $seconds): int
    {
        $row = $this->fetchOne(
            'SELECT COUNT(*) AS total
               FROM rate_limit_hits
              WHERE bucket = :bucket
                AND subject_hash = :subject_hash
                AND created_at > UTC_TIMESTAMP() - INTERVAL :seconds SECOND',
            ['bucket' => $bucket, 'subject_hash' => $subjectHash, 'seconds' => $seconds]
        );

        return (int) ($row['total'] ?? 0);
    }

    /** Housekeeping: deletes rows older than every configured window (bounded batch). */
    public function purgeOlderThan(int $seconds): void
    {
        $this->execute(
            'DELETE FROM rate_limit_hits WHERE created_at < UTC_TIMESTAMP() - INTERVAL :seconds SECOND LIMIT 5000',
            ['seconds' => $seconds]
        );
    }
}
```


**`config/security.php`** (existing file: changes only)

```diff
@@ -18,4 +18,27 @@ return [
     'verification_ttl_hours'        => 24,
     'verification_resend_cooldown'  => 60, // seconds between two resends
     'verification_max_per_day'      => 5,
+
+    // ---- Phase 5 ---------------------------------------------------------------
+    // Token lifetimes. Invitations last longer than resets because people often
+    // open them days later; a reset link is only useful right now.
+    'invitation_ttl_hours'        => 72,
+    'invitation_resend_cooldown'  => 60, // seconds between two resends of one invitation
+    'invitation_max_per_day'      => 5,  // e-mails per invitation per 24 h
+    'password_reset_ttl_minutes'  => 60,
+    'email_change_ttl_hours'      => 24,
+
+    // Avatar uploads (stored outside the web root, in storage/uploads/avatars).
+    'avatar_max_bytes' => 1_048_576,
+
+    // Database-backed rate limits: action => subject kind => [max attempts, window seconds].
+    // Login also keeps the per-account lockout above (5 wrong passwords = 15 min).
+    'rate_limits' => [
+        'login'             => ['ip' => [20, 900], 'account' => [10, 900]],
+        'password_forgot'   => ['ip' => [5, 900], 'account' => [3, 3600]],
+        'password_reset'    => ['ip' => [10, 900]],
+        'invitation_resend' => ['ip' => [30, 3600], 'account' => [10, 3600]],
+        'invitation_accept' => ['ip' => [10, 900]],
+        'email_change'      => ['account' => [5, 3600]],
+    ],
 ];
```


**`app/Middleware/SuperAdminMiddleware.php`**

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
 * Route spec "platform" (always after "auth"): only the Super Admin gets through.
 *
 * /platform routes have no "tenant" middleware: the Super Admin has no
 * session condominium_id. A platform action on one condominium takes the id
 * from the URL, loads that row (404 when missing) and writes an audit entry
 * with it. Auth::isSuperAdmin() reads users.is_super_admin from the database
 * row re-loaded on every request, never from the session or the request.
 */
final class SuperAdminMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Auth::isSuperAdmin()) {
            Logger::warning('Platform access refused', ['user_id' => Auth::id(), 'path' => $request->path()]);
            throw new HttpException(403);
        }

        return $next($request);
    }
}
```


**`app/Core/Auth.php`** (existing file: changes only)

```diff
@@ -4,6 +4,7 @@ declare(strict_types=1);
 
 namespace App\Core;
 
+use App\Models\Membership;
 use App\Models\User;
 
 /**
@@ -105,9 +106,20 @@ final class Auth
             && $user['status'] === 'active'
             && (int) $user['session_version'] === Session::get('session_version');
 
-        if (!$valid) {
-            Logger::info('Session invalidated', ['user_id' => $userId]);
+        // Phase 5: a tenant user whose last active membership was deactivated, or
+        // whose condominium(s) were suspended, loses the session on the next
+        // request, not only at the next login. Super Admins have no membership.
+        // TenantMiddleware still re-checks the CURRENT condominium on tenant routes;
+        // this check also covers routes without "tenant" (e.g. /account).
+        $hasAccess = $valid
+            && ((bool) $user['is_super_admin'] || (new Membership())->hasAnyActive($userId));
+
+        if (!$valid || !$hasAccess) {
+            Logger::info('Session invalidated', ['user_id' => $userId, 'reason' => $valid ? 'no_active_membership' : 'user_state']);
             self::logout();
+            if ($valid) {
+                Session::flash('warning', 'Seu acesso foi desativado ou o condomínio está suspenso. Fale com a administração.');
+            }
 
             return null;
         }
@@ -115,6 +127,31 @@ final class Auth
         return self::$user = $user;
     }
 
+    /**
+     * Keeps the CURRENT session valid after the user's own password or e-mail
+     * change, which incremented users.session_version and so ended every other
+     * session.
+     *
+     * The session id is regenerated (an id observed before the change is now
+     * useless) and the CSRF token is rotated. The tenant and role stay as they
+     * were; TenantMiddleware re-validates them on the next request anyway.
+     */
+    public static function refreshAfterCredentialChange(int $userId): void
+    {
+        $user = (new User())->find($userId);
+        if ($user === null) {
+            self::logout();
+
+            return;
+        }
+
+        Session::regenerate();
+        Csrf::rotate();
+        Session::set('session_version', (int) $user['session_version']);
+        self::$user = $user;
+        self::$resolved = true;
+    }
+
     public static function check(): bool
     {
         return self::user() !== null;
@@ -159,6 +196,12 @@ final class Auth
         return $role !== null && in_array($role, $roles, true);
     }
 
+    /** Human-readable name of any role code (lists, e-mails). */
+    public static function labelFor(string $roleCode): string
+    {
+        return self::ROLE_LABELS[$roleCode] ?? $roleCode;
+    }
+
     /** Human-readable name of the current role, for the header. */
     public static function roleLabel(): ?string
     {
```


**`app/Models/Membership.php`** (existing file: changes only)

```diff
@@ -63,6 +63,15 @@ final class Membership extends Model
         );
     }
 
+    /**
+     * True when the user can still enter at least one condominium (active
+     * membership in an active condominium). Checked on every request by Auth.
+     */
+    public function hasAnyActive(int $userId): bool
+    {
+        return $this->fetchOne(self::ACTIVE_SELECT . ' LIMIT 1', ['user_id' => $userId]) !== null;
+    }
+
     /** True when the user has any membership (any status) in the condominium. */
     public function exists(int $userId, int $condominiumId): bool
     {
@@ -71,4 +80,44 @@ final class Membership extends Model
             ['user_id' => $userId, 'condominium_id' => $condominiumId]
         ) !== null;
     }
+
+    /**
+     * Creates a membership in status 'invited' (Phase 5). It grants nothing
+     * until accepted: every access query requires status = 'active'.
+     */
+    public function createInvited(int $condominiumId, int $userId, int $roleId): void
+    {
+        $this->execute(
+            "INSERT INTO condominium_users (condominium_id, user_id, role_id, status)
+             VALUES (:condominium_id, :user_id, :role_id, 'invited')",
+            ['condominium_id' => $condominiumId, 'user_id' => $userId, 'role_id' => $roleId]
+        );
+    }
+
+    /**
+     * Locks one membership row for the rest of the transaction.
+     *
+     * @return array<string, mixed>|null
+     */
+    public function lockFor(int $condominiumId, int $userId): ?array
+    {
+        return $this->fetchOne(
+            'SELECT * FROM condominium_users
+              WHERE condominium_id = :condominium_id AND user_id = :user_id
+              FOR UPDATE',
+            ['condominium_id' => $condominiumId, 'user_id' => $userId]
+        );
+    }
+
+    /** Turns an accepted invitation into an active membership. */
+    public function activateInvited(int $condominiumId, int $userId, int $approvedBy): void
+    {
+        $this->execute(
+            "UPDATE condominium_users
+                SET status = 'active', approved_at = UTC_TIMESTAMP(), approved_by_user_id = :approved_by,
+                    deactivated_at = NULL, deactivated_by_user_id = NULL
+              WHERE condominium_id = :condominium_id AND user_id = :user_id AND status = 'invited'",
+            ['approved_by' => $approvedBy, 'condominium_id' => $condominiumId, 'user_id' => $userId]
+        );
+    }
 }
```


**`app/Core/Request.php`** (existing file: changes only)

```diff
@@ -19,6 +19,7 @@ final class Request
      * @param array<string, mixed>  $query  Query-string parameters ($_GET).
      * @param array<string, mixed>  $body   Form fields ($_POST) or decoded JSON body.
      * @param array<string, mixed>  $server Server variables ($_SERVER).
+     * @param array<string, mixed>  $files  Uploaded files ($_FILES).
      */
     public function __construct(
         private readonly string $method,
@@ -26,7 +27,8 @@ final class Request
         private readonly array $query,
         private readonly array $body,
         private readonly array $server,
-        private readonly bool $malformedJson = false
+        private readonly bool $malformedJson = false,
+        private readonly array $files = []
     ) {
     }
 
@@ -49,7 +51,7 @@ final class Request
             $malformed = $raw !== false && trim($raw) !== '' && !is_array($decoded);
         }
 
-        return new self($method, $path, $_GET, $body, $_SERVER, $malformed);
+        return new self($method, $path, $_GET, $body, $_SERVER, $malformed, $_FILES);
     }
 
     public function method(): string
@@ -74,6 +76,40 @@ final class Request
         return $this->query[$key] ?? $default;
     }
 
+    /**
+     * Returns a query-string value as a trimmed string, or '' when it is missing
+     * or not a string (e.g. ?q[]=x). Used for list filters and search boxes.
+     */
+    public function queryString(string $key): string
+    {
+        $value = $this->query[$key] ?? '';
+
+        return is_string($value) ? trim($value) : '';
+    }
+
+    /**
+     * One uploaded file from a multipart form, or null when the field is absent,
+     * empty, or was sent as an array (name[]=...). The caller still validates
+     * size and content: the browser-supplied name and type are not trusted.
+     *
+     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
+     */
+    public function file(string $key): ?array
+    {
+        $file = $this->files[$key] ?? null;
+        if (!is_array($file) || !is_int($file['error'] ?? null) || $file['error'] === UPLOAD_ERR_NO_FILE) {
+            return null;
+        }
+
+        return [
+            'name'     => is_string($file['name'] ?? null) ? $file['name'] : '',
+            'type'     => is_string($file['type'] ?? null) ? $file['type'] : '',
+            'tmp_name' => is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '',
+            'error'    => $file['error'],
+            'size'     => is_int($file['size'] ?? null) ? $file['size'] : 0,
+        ];
+    }
+
     /** Returns a body value (form field or JSON property). */
     public function input(string $key, mixed $default = null): mixed
     {
```


**`app/Core/Response.php`** (existing file: changes only)

```diff
@@ -45,6 +45,24 @@ final class Response
         return new self($body, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
     }
 
+    /**
+     * A file body (CSV export, avatar image).
+     *
+     * With $downloadName the browser saves the file instead of displaying it.
+     * The name is reduced to a safe ASCII set, so it cannot inject header
+     * syntax (quotes, CR/LF) into Content-Disposition.
+     */
+    public static function file(string $body, string $contentType, ?string $downloadName = null): self
+    {
+        $headers = ['Content-Type' => $contentType, 'Content-Length' => (string) strlen($body)];
+        if ($downloadName !== null) {
+            $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) ?: 'download';
+            $headers['Content-Disposition'] = 'attachment; filename="' . $safe . '"';
+        }
+
+        return new self($body, 200, $headers);
+    }
+
     /**
      * A redirect to a path inside this application. External URLs are refused,
      * so no code path can be turned into an open redirect.
```


**`app/Core/Validator.php`** (existing file: changes only)

```diff
@@ -152,6 +152,78 @@ final class Validator
         return $normalized;
     }
 
+    /**
+     * An e-mail address, returned trimmed and lower-cased (the form users.email
+     * is stored in). filter_var() also rejects header-injection characters
+     * such as CR/LF, which matters because the address is used by the mailer.
+     */
+    public function email(string $field, string $label = 'E-mail'): ?string
+    {
+        $value = mb_strtolower(trim($this->request->string($field)));
+        if ($value === '' || mb_strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
+            $this->errors[$field] = "{$label} inválido.";
+
+            return null;
+        }
+
+        return $value;
+    }
+
+    /**
+     * A new password and its confirmation (config/security.php limits).
+     *
+     * Returns the raw password for the caller to hash; it is never trimmed,
+     * because spaces are legitimate password characters. The comparison uses
+     * hash_equals() so its timing does not depend on where the strings differ.
+     */
+    public function newPassword(string $field = 'password', string $confirmation = 'password_confirmation'): ?string
+    {
+        $password = $this->request->string($field);
+        $min = (int) Config::get('security.password_min', 10);
+        $max = (int) Config::get('security.password_max', 128);
+
+        if (mb_strlen($password) < $min || mb_strlen($password) > $max) {
+            $this->errors[$field] = "A senha deve ter entre {$min} e {$max} caracteres.";
+
+            return null;
+        }
+        if (!hash_equals($password, $this->request->string($confirmation))) {
+            $this->errors[$confirmation] = 'As senhas não conferem.';
+
+            return null;
+        }
+
+        return $password;
+    }
+
+    /** Optional phone number: digits, spaces and + ( ) - only, 8 to 30 characters. */
+    public function phone(string $field, string $label = 'Telefone'): ?string
+    {
+        $value = trim($this->request->string($field));
+        if ($value === '') {
+            return null;
+        }
+        if (preg_match('/^[0-9+()\s-]{8,30}$/', $value) !== 1) {
+            $this->errors[$field] = "{$label} inválido.";
+
+            return null;
+        }
+
+        return $value;
+    }
+
+    /** A local date and time in Y-m-d\TH:i (the format of <input type="datetime-local">). */
+    public function dateTimeLocal(string $field, string $label): ?string
+    {
+        return $this->dateTimeFormat($field, $label, 'Y-m-d\TH:i');
+    }
+
+    /** True when the field was sent with a non-blank value (for optional fields). */
+    public function filled(string $field): bool
+    {
+        return trim($this->request->string($field)) !== '';
+    }
+
     /** Adds an error found by a later business check (e.g. "date in the past"). */
     public function addError(string $field, string $message): void
     {
```


**`app/Controllers/VerificationController.php`** (existing file: changes only)

```diff
@@ -8,6 +8,7 @@ use App\Core\Config;
 use App\Core\Controller;
 use App\Core\Response;
 use App\Core\Session;
+use App\Core\Validator;
 use App\Services\EmailVerificationService;
 use App\Services\VerificationResult;
 
@@ -85,18 +86,10 @@ final class VerificationController extends Controller
     /** @return array<string, string> Field => message. */
     private function validateNewPassword(): array
     {
-        $password = $this->request->string('password');
-        $min = (int) Config::get('security.password_min', 10);
-        $max = (int) Config::get('security.password_max', 128);
+        $v = new Validator($this->request);
+        $v->newPassword();
 
-        if (mb_strlen($password) < $min || mb_strlen($password) > $max) {
-            return ['password' => "A senha deve ter entre {$min} e {$max} caracteres."];
-        }
-        if (!hash_equals($password, $this->request->string('password_confirmation'))) {
-            return ['password_confirmation' => 'As senhas não conferem.'];
-        }
-
-        return [];
+        return $v->errors();
     }
 
     /** @param array<string, string> $errors */
```


**`app/Core/Pagination.php`**

```php
<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Page number / size arithmetic for paginated lists (users, units, invoices,
 * audit log, condominiums).
 *
 * The page comes from the query string and is clamped to a sane range, so
 * "?page=-5" or "?page=999999999" can never produce a negative or absurd
 * OFFSET. Models bind limit() and offset() as integers.
 */
final class Pagination
{
    /** Hard ceiling for page numbers: OFFSET values beyond this are never useful. */
    private const MAX_PAGE = 10000;

    private int $total = 0;

    private function __construct(
        private readonly int $page,
        private readonly int $perPage
    ) {
    }

    /** Reads ?page= from the request (default 1). */
    public static function fromRequest(Request $request, int $perPage = 25): self
    {
        $raw = $request->queryString('page');
        $page = preg_match('/^\d{1,5}$/', $raw) === 1 ? (int) $raw : 1;

        return new self(max(1, min($page, self::MAX_PAGE)), max(1, $perPage));
    }

    /** Stores the total number of rows (from the model's COUNT query). */
    public function withTotal(int $total): self
    {
        $clone = clone $this;
        $clone->total = max(0, $total);

        return $clone;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function limit(): int
    {
        return $this->perPage;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    /**
     * Shape sent to JavaScript and to the pagination partial.
     *
     * @return array{page: int, per_page: int, total: int, pages: int}
     */
    public function toArray(): array
    {
        return [
            'page'     => $this->page,
            'per_page' => $this->perPage,
            'total'    => $this->total,
            'pages'    => $this->pages(),
        ];
    }
}
```


**`app/Views/partials/pagination.php`**

```php
<?php
/**
 * Previous / next links for a server-rendered list. Keeps the current filters:
 * $query holds the already-validated filter values, and http_build_query()
 * URL-encodes them before e() escapes the attribute.
 *
 * @var array{page: int, per_page: int, total: int, pages: int} $pagination
 * @var string                $basePath e.g. "/admin/units"
 * @var array<string, string> $query    Current filters (without "page").
 */
$link = static fn (int $page): string => $basePath . '?' . http_build_query($query + ['page' => $page]);
?>
<nav class="pager" aria-label="Paginação">
    <span class="pager__info muted">
        <?= e($pagination['total']) ?> registro(s) · página <?= e($pagination['page']) ?> de <?= e($pagination['pages']) ?>
    </span>
    <?php if ($pagination['page'] > 1): ?>
        <a class="btn btn--small" href="<?= e($link($pagination['page'] - 1)) ?>">Anterior</a>
    <?php endif; ?>
    <?php if ($pagination['page'] < $pagination['pages']): ?>
        <a class="btn btn--small" href="<?= e($link($pagination['page'] + 1)) ?>">Próxima</a>
    <?php endif; ?>
</nav>
```


**`app/Core/Model.php`** (existing file: changes only)

```diff
@@ -189,6 +189,16 @@ abstract class Model
         return [implode(', ', $placeholders), $params];
     }
 
+    /**
+     * "%term%" for a LIKE search, with the user's own % and _ escaped so they
+     * match literally instead of acting as wildcards. The result is still bound
+     * as a parameter, never concatenated into SQL.
+     */
+    protected static function likeContains(string $term): string
+    {
+        return '%' . addcslashes($term, '%_\\') . '%';
+    }
+
     /** @param array<string, mixed> $where */
     private function conditions(array $where, string $prefix): string
     {
```


**`app/Core/TenantContext.php`** (existing file: changes only)

```diff
@@ -73,4 +73,22 @@ final class TenantContext
     {
         return self::now()->format('Y-m-d');
     }
+
+    /**
+     * Converts a local wall-clock value of the condominium (e.g. "2026-10-05" or
+     * "2026-10-05T18:30" from a form) into the UTC "Y-m-d H:i:s" stored in
+     * DATETIME columns (Phase 1, NFR-DATA-02). A date alone means local midnight.
+     */
+    public static function localToUtc(string $local): string
+    {
+        $date = new DateTimeImmutable(str_replace('T', ' ', $local), self::timezone());
+
+        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
+    }
+
+    /** The inverse of localToUtc(), in the given format (default: datetime-local input value). */
+    public static function utcToLocal(string $utc, string $format = 'Y-m-d\TH:i'): string
+    {
+        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(self::timezone())->format($format);
+    }
 }
```


**`app/Core/Controller.php`** (existing file: changes only)

```diff
@@ -46,7 +46,8 @@ abstract class Controller
      * Answers a request whose input failed validation or a business rule.
      *
      * - fetch()/API clients get JSON {"error": ..., "errors": {field: message}}
-     *   with the given status (422 invalid data, 409 conflict with current state).
+     *   with the given status (422 invalid data, 409 conflict with current state,
+     *   403/429 for Phase 5 account rules and throttling).
      * - HTML forms are redirected back with the errors and the submitted input
      *   flashed, so the form is redisplayed filled in (Post/Redirect/Get).
      *
@@ -55,7 +56,9 @@ abstract class Controller
      */
     protected function invalid(array $errors, string $redirectTo, int $status = 422, array $keepInput = []): Response
     {
-        $summary = $status === 409
+        // 422 = field errors (generic summary); any other status (409 conflict,
+        // 403 own-account rule, 429 throttled...) shows the rule's own message.
+        $summary = $status !== 422
             ? (string) (reset($errors) ?: 'A operação conflita com o estado atual.')
             : 'Corrija os campos destacados e tente novamente.';
```


**`app/Core/helpers.php`** (existing file: changes only)

```diff
@@ -111,6 +111,33 @@ if (!function_exists('field_error')) {
     }
 }
 
+if (!function_exists('partial')) {
+    /**
+     * Renders a view fragment (app/Views/partials/...) without a layout. The
+     * fragment escapes its own output, so the result is printed as-is.
+     *
+     * @param array<string, mixed> $data
+     */
+    function partial(string $template, array $data = []): string
+    {
+        return App\Core\View::render('partials/' . $template, $data, null);
+    }
+}
+
+if (!function_exists('pill')) {
+    /**
+     * A status badge: <span class="pill pill--{variant}">label</span>.
+     * The variant becomes a CSS class, so it is restricted to [a-z_] (status
+     * codes from the database), and the label is escaped.
+     */
+    function pill(string $variant, string $label): string
+    {
+        $variant = preg_match('/^[a-z_]+$/', $variant) === 1 ? $variant : 'default';
+
+        return '<span class="pill pill--' . $variant . '">' . e($label) . '</span>';
+    }
+}
+
 if (!function_exists('asset')) {
     /**
      * URL of a file in public/assets with a cache-busting version (file mtime),
```


## 3. Feature code

Every controller action follows the same order:

1. CSRF (route middleware);
2. role (route middleware, plus `requireRole()` again in the action);
3. input validation (allowlists for every enum and role);
4. tenant and ownership check (tenant-scoped model lookup → 404);
5. service call (transaction + audit entry);
6. redirect with a flash for HTML forms, or JSON with the right status for `fetch()`.

`AdminController::ruleFailure()` turns a service's 404 into a real 404 page.

### 3.1 Super Admin: tenant management

Routes `auth → platform (→ csrf)`, no `tenant`. The condominium in the URL is loaded first (`findOrFail` → 404) and every change is audited with its id. Suspension needs no extra code at the session level: every membership query already requires `c.status = 'active'`, and `Auth::user()` / `TenantMiddleware` re-run it on each request.

**`app/Models/Condominium.php`** (existing file: changes only)

```diff
@@ -5,14 +5,40 @@ declare(strict_types=1);
 namespace App\Models;
 
 use App\Core\Model;
+use App\Core\Pagination;
+use InvalidArgumentException;
 
 /**
  * The tenant registry `condominiums` (global table, managed by the Super Admin).
+ *
+ * Phase 5 adds the platform queries. They are deliberately NOT tenant-scoped:
+ * only routes behind the "platform" middleware (Super Admin) call them.
  */
 final class Condominium extends Model
 {
+    public const STATUSES = ['active' => 'Ativo', 'suspended' => 'Suspenso', 'archived' => 'Arquivado'];
+
+    public const PLANS = ['trial' => 'Avaliação', 'basic' => 'Básico', 'professional' => 'Profissional'];
+
     protected string $table = 'condominiums';
 
+    protected array $fillable = [
+        'name',
+        'slug',
+        'legal_id',
+        'signup_code',
+        'email',
+        'contact_name',
+        'phone',
+        'address_line',
+        'city',
+        'state_province',
+        'postal_code',
+        'timezone',
+        'billing_due_day',
+        'plan',
+    ];
+
     /** Returns the condominium only if its status is 'active'. */
     public function findActive(int $id): ?array
     {
@@ -33,4 +59,178 @@ final class Condominium extends Model
             "SELECT id, name, city FROM condominiums WHERE status = 'active' ORDER BY name"
         );
     }
+
+    /**
+     * One page of condominiums with member statistics.
+     *
+     * @param array{q?: string, status?: string} $filters Already validated.
+     * @return list<array<string, mixed>>
+     */
+    public function search(array $filters, Pagination $pagination): array
+    {
+        [$where, $params] = $this->filterSql($filters);
+
+        return $this->fetchAll(
+            "SELECT c.id, c.name, c.city, c.state_province, c.legal_id, c.plan, c.status, c.created_at,
+                    COALESCE(SUM(cu.status = 'active'), 0) AS active_members,
+                    COALESCE(SUM(cu.status = 'invited'), 0) AS invited_members,
+                    COALESCE(SUM(cu.status = 'active' AND r.code = 'manager'), 0) AS active_managers
+               FROM condominiums c
+               LEFT JOIN condominium_users cu ON cu.condominium_id = c.id
+               LEFT JOIN roles r ON r.id = cu.role_id
+              WHERE {$where}
+              GROUP BY c.id
+              ORDER BY c.name
+              LIMIT :limit OFFSET :offset",
+            $params + ['limit' => $pagination->limit(), 'offset' => $pagination->offset()]
+        );
+    }
+
+    /**
+     * The condominiums with the most active members (platform overview).
+     *
+     * @return list<array<string, mixed>>
+     */
+    public function largest(int $limit): array
+    {
+        return $this->fetchAll(
+            "SELECT c.id, c.name, c.city, c.status,
+                    COALESCE(SUM(cu.status = 'active'), 0) AS active_members,
+                    COALESCE(SUM(cu.status = 'invited'), 0) AS invited_members,
+                    COALESCE(SUM(cu.status = 'active' AND r.code = 'manager'), 0) AS active_managers
+               FROM condominiums c
+               LEFT JOIN condominium_users cu ON cu.condominium_id = c.id
+               LEFT JOIN roles r ON r.id = cu.role_id
+              GROUP BY c.id
+              ORDER BY active_members DESC, c.name
+              LIMIT :limit",
+            ['limit' => $limit]
+        );
+    }
+
+    /**
+     * Every condominium as id => name, for the audit filter.
+     *
+     * @return array<int, string>
+     */
+    public function options(): array
+    {
+        $options = [];
+        foreach ($this->fetchAll('SELECT id, name FROM condominiums ORDER BY name') as $row) {
+            $options[(int) $row['id']] = (string) $row['name'];
+        }
+
+        return $options;
+    }
+
+    /** @param array{q?: string, status?: string} $filters */
+    public function count(array $filters): int
+    {
+        [$where, $params] = $this->filterSql($filters);
+        $row = $this->fetchOne("SELECT COUNT(*) AS total FROM condominiums c WHERE {$where}", $params);
+
+        return (int) ($row['total'] ?? 0);
+    }
+
+    /**
+     * Number of condominiums per status (platform overview).
+     *
+     * @return array<string, int>
+     */
+    public function countsByStatus(): array
+    {
+        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
+        foreach ($this->fetchAll('SELECT status, COUNT(*) AS total FROM condominiums GROUP BY status') as $row) {
+            $counts[(string) $row['status']] = (int) $row['total'];
+        }
+
+        return $counts;
+    }
+
+    /**
+     * Managers of one condominium (any membership status) with their latest
+     * invitation, for the Super Admin's condominium page.
+     *
+     * @return list<array<string, mixed>>
+     */
+    public function managers(int $condominiumId): array
+    {
+        return $this->fetchAll(
+            "SELECT us.id AS user_id, us.full_name, us.email, cu.status, cu.created_at,
+                    (SELECT MAX(i.expires_at) FROM invitations i
+                      WHERE i.condominium_id = cu.condominium_id AND i.user_id = cu.user_id
+                        AND i.consumed_at IS NULL AND i.revoked_at IS NULL) AS invitation_expires_at,
+                    (SELECT MAX(i.expires_at) <= UTC_TIMESTAMP() FROM invitations i
+                      WHERE i.condominium_id = cu.condominium_id AND i.user_id = cu.user_id
+                        AND i.consumed_at IS NULL AND i.revoked_at IS NULL) AS invitation_expired
+               FROM condominium_users cu
+               JOIN users us ON us.id = cu.user_id
+               JOIN roles r  ON r.id = cu.role_id AND r.code = 'manager'
+              WHERE cu.condominium_id = :condominium_id
+              ORDER BY cu.status = 'active' DESC, us.full_name",
+            ['condominium_id' => $condominiumId]
+        );
+    }
+
+    /** True when $column = $value is used by another condominium (unique fields). */
+    public function isTaken(string $column, string $value, ?int $exceptId = null): bool
+    {
+        if (!in_array($column, ['slug', 'legal_id', 'signup_code'], true)) {
+            throw new InvalidArgumentException('Not a unique column of condominiums.');
+        }
+
+        return $this->fetchOne(
+            sprintf('SELECT 1 FROM condominiums WHERE `%s` = :value AND id <> :except LIMIT 1', $column),
+            ['value' => $value, 'except' => $exceptId ?? 0]
+        ) !== null;
+    }
+
+    public function suspend(int $id, string $reason): void
+    {
+        $this->execute(
+            "UPDATE condominiums SET status = 'suspended', suspended_at = UTC_TIMESTAMP(), suspension_reason = :reason
+              WHERE id = :id AND status = 'active'",
+            ['reason' => $reason, 'id' => $id]
+        );
+    }
+
+    public function reactivate(int $id): void
+    {
+        $this->execute(
+            "UPDATE condominiums SET status = 'active', suspended_at = NULL, suspension_reason = NULL
+              WHERE id = :id AND status = 'suspended'",
+            ['id' => $id]
+        );
+    }
+
+    /**
+     * Locks a condominium row for a status change.
+     *
+     * @return array<string, mixed>|null
+     */
+    public function lockForUpdate(int $id): ?array
+    {
+        return $this->fetchOne('SELECT * FROM condominiums WHERE id = :id FOR UPDATE', ['id' => $id]);
+    }
+
+    /**
+     * @param array<string, mixed> $filters
+     * @return array{0: string, 1: array<string, mixed>}
+     */
+    private function filterSql(array $filters): array
+    {
+        $conditions = ['1 = 1'];
+        $params = [];
+        if (isset($filters['q']) && $filters['q'] !== '') {
+            $conditions[] = '(c.name LIKE :q_name OR c.city LIKE :q_city OR c.legal_id LIKE :q_legal)';
+            $like = self::likeContains($filters['q']);
+            $params += ['q_name' => $like, 'q_city' => $like, 'q_legal' => $like];
+        }
+        if (isset($filters['status'])) {
+            $conditions[] = 'c.status = :status';
+            $params['status'] = $filters['status'];
+        }
+
+        return [implode(' AND ', $conditions), $params];
+    }
 }
```


**`app/Services/CondominiumService.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Models\Condominium;
use PDOException;
use Throwable;

/**
 * Tenant registry operations of the Super Admin (Phase 5).
 *
 * Every method here is reached only through the "platform" middleware
 * (Super Admin). The condominium id comes from the URL; each method loads the
 * row first (404 when missing) and every change is written to the audit log
 * with that condominium id, so platform actions on a tenant are traceable.
 */
final class CondominiumService
{
    /** Signup-code alphabet without look-alikes (0/O, 1/I/L). */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly Request $request,
        private readonly Condominium $condominiums = new Condominium()
    ) {
    }

    /**
     * @param array<string, mixed> $data Validated fields (see Platform\CondominiumController::validated()).
     * @throws BusinessRuleException 409 on a duplicate CNPJ.
     */
    public function create(array $data): int
    {
        if ($data['legal_id'] !== null && $this->condominiums->isTaken('legal_id', $data['legal_id'])) {
            throw new BusinessRuleException('Já existe um condomínio com este CNPJ.', 409, 'legal_id');
        }

        try {
            return Database::transaction(function () use ($data): int {
                $data['slug'] = $this->uniqueSlug((string) $data['name']);
                $data['signup_code'] = $this->uniqueSignupCode();
                $id = $this->condominiums->insert($data);
                (new AuditLogger($this->request))->platform('condominium.created', $id, 'condominium', $id, [
                    'name' => $data['name'],
                    'plan' => $data['plan'],
                ]);

                return $id;
            });
        } catch (PDOException $e) {
            throw self::duplicateOr($e);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @throws BusinessRuleException
     */
    public function update(int $id, array $data): void
    {
        if ($data['legal_id'] !== null && $this->condominiums->isTaken('legal_id', $data['legal_id'], $id)) {
            throw new BusinessRuleException('Já existe um condomínio com este CNPJ.', 409, 'legal_id');
        }

        try {
            Database::transaction(function () use ($id, $data): void {
                $before = $this->condominiums->lockForUpdate($id) ?? throw new BusinessRuleException('Condomínio não encontrado.', 404);
                $this->condominiums->update($id, $data);
                $changed = array_keys(array_filter(
                    $data,
                    static fn (mixed $value, string $column): bool => (string) $before[$column] !== (string) $value,
                    ARRAY_FILTER_USE_BOTH
                ));
                if ($changed !== []) {
                    (new AuditLogger($this->request))->platform('condominium.updated', $id, 'condominium', $id, ['fields' => $changed]);
                }
            });
        } catch (PDOException $e) {
            throw self::duplicateOr($e);
        }
    }

    /**
     * Suspends a tenant. Data is kept; its users are logged out on their next
     * request (Membership queries require c.status = 'active') and cannot log in.
     *
     * @throws BusinessRuleException
     */
    public function suspend(int $id, string $reason): void
    {
        Database::transaction(function () use ($id, $reason): void {
            $condominium = $this->condominiums->lockForUpdate($id) ?? throw new BusinessRuleException('Condomínio não encontrado.', 404);
            if ($condominium['status'] !== 'active') {
                throw new BusinessRuleException('Somente condomínios ativos podem ser suspensos.', 409);
            }
            $this->condominiums->suspend($id, $reason);
            (new AuditLogger($this->request))->platform('condominium.suspended', $id, 'condominium', $id, ['reason' => $reason]);
        });
    }

    /** @throws BusinessRuleException */
    public function reactivate(int $id): void
    {
        Database::transaction(function () use ($id): void {
            $condominium = $this->condominiums->lockForUpdate($id) ?? throw new BusinessRuleException('Condomínio não encontrado.', 404);
            if ($condominium['status'] !== 'suspended') {
                throw new BusinessRuleException('Este condomínio não está suspenso.', 409);
            }
            $this->condominiums->reactivate($id);
            (new AuditLogger($this->request))->platform('condominium.reactivated', $id, 'condominium', $id);
        });
    }

    /**
     * Checks a CNPJ (14 digits, two check digits). Returns the digits only, or
     * null when invalid. Formatting characters (. / -) are accepted on input.
     */
    public static function normalizeCnpj(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits) === 1) {
            return null;
        }
        foreach ([12, 13] as $length) {
            $weights = $length === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $sum = 0;
            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $digits[$i] * $weights[$i];
            }
            $check = $sum % 11 < 2 ? 0 : 11 - $sum % 11;
            if ((int) $digits[$length] !== $check) {
                return null;
            }
        }

        return $digits;
    }

    /** "Residencial Aurora" → "residencial-aurora" (then "-2", "-3"... when taken). */
    private function uniqueSlug(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii === false ? '' : $ascii)), '-');
        $base = substr($base !== '' ? $base : 'condominio', 0, 70);

        $slug = $base;
        for ($n = 2; $this->condominiums->isTaken('slug', $slug); $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

    private function uniqueSignupCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while ($this->condominiums->isTaken('signup_code', $code));

        return $code;
    }

    private static function duplicateOr(PDOException $e): Throwable
    {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return new BusinessRuleException('Já existe um condomínio com estes dados (nome curto ou CNPJ).', 409, 'legal_id');
        }

        return $e;
    }
}
```


**`app/Controllers/Platform/DashboardController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Controller;
use App\Core\Response;
use App\Models\AuditLog;
use App\Models\Condominium;

/**
 * Platform overview of the Super Admin (/platform, Phase 5): condominiums by
 * status, members per condominium and the latest audit events.
 */
final class DashboardController extends Controller
{
    /** GET /platform */
    public function index(): Response
    {
        $condominiums = new Condominium();

        return $this->view('platform/dashboard', [
            'title'        => 'Plataforma',
            'activeNav'    => 'platform',
            'counts'       => $condominiums->countsByStatus(),
            'statuses'     => Condominium::STATUSES,
            'condominiums' => $condominiums->largest(10),
            'events'       => (new AuditLog())->latest(15),
        ]);
    }
}
```


**`app/Controllers/Platform/CondominiumController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Pagination;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Condominium;
use App\Services\BusinessRuleException;
use App\Services\CondominiumService;
use App\Services\InvitationService;
use DateTimeZone;

/**
 * Tenant management by the Super Admin (/platform, Phase 5).
 *
 * Routes: auth → platform (Super Admin only) → csrf on writes. There is no
 * "tenant" middleware: the Super Admin has no session condominium. The target
 * condominium is the {id} in the URL, and every action loads it first
 * (findOrFail → 404 for an unknown id) before doing anything. Each change is
 * audited by the service with that condominium id.
 */
final class CondominiumController extends Controller
{
    private const KEEP = [
        'name', 'legal_id', 'email', 'contact_name', 'phone', 'address_line', 'city',
        'state_province', 'postal_code', 'timezone', 'billing_due_day', 'plan',
    ];

    /** GET /platform/condominiums?q=&status=&page= */
    public function index(): Response
    {
        $filters = [];
        $query = [];
        $q = mb_substr($this->request->queryString('q'), 0, 100);
        if ($q !== '') {
            $filters['q'] = $query['q'] = $q;
        }
        $status = $this->request->queryString('status');
        if (array_key_exists($status, Condominium::STATUSES)) {
            $filters['status'] = $query['status'] = $status;
        }

        $condominiums = new Condominium();
        $pagination = Pagination::fromRequest($this->request, 25)->withTotal($condominiums->count($filters));

        return $this->view('platform/condominiums/index', [
            'title'        => 'Condomínios',
            'activeNav'    => 'platform-condominiums',
            'condominiums' => $condominiums->search($filters, $pagination),
            'statuses'     => Condominium::STATUSES,
            'plans'        => Condominium::PLANS,
            'query'        => $query,
            'pagination'   => $pagination->toArray(),
        ]);
    }

    /** GET /platform/condominiums/new */
    public function create(): Response
    {
        return $this->form(null);
    }

    /** POST /platform/condominiums */
    public function store(): Response
    {
        [$data, $v] = $this->validated();
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/platform/condominiums/new', 422, self::KEEP);
        }

        try {
            $id = (new CondominiumService($this->request))->create($data);
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], '/platform/condominiums/new', $e->status(), self::KEEP);
        }

        return $this->done('Condomínio cadastrado. Agora convide o primeiro síndico.', "/platform/condominiums/{$id}", ['id' => $id], 201);
    }

    /** GET /platform/condominiums/{id}: details, managers, invite form, suspension. */
    public function show(string $id): Response
    {
        $condominium = $this->findOrFail($id);

        return $this->view('platform/condominiums/show', [
            'title'        => (string) $condominium['name'],
            'activeNav'    => 'platform-condominiums',
            'condominium'  => $condominium,
            'managers'     => (new Condominium())->managers((int) $condominium['id']),
            'statuses'     => Condominium::STATUSES,
            'plans'        => Condominium::PLANS,
            'scripts'      => ['js/admin/confirm.js'],
        ]);
    }

    /** GET /platform/condominiums/{id}/edit */
    public function edit(string $id): Response
    {
        return $this->form($this->findOrFail($id));
    }

    /** POST /platform/condominiums/{id} */
    public function update(string $id): Response
    {
        $condominium = $this->findOrFail($id);
        $back = "/platform/condominiums/{$condominium['id']}/edit";
        [$data, $v] = $this->validated();
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back);
        }

        try {
            (new CondominiumService($this->request))->update((int) $condominium['id'], $data);
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], $back, $e->status());
        }

        return $this->done('Dados do condomínio atualizados.', "/platform/condominiums/{$condominium['id']}");
    }

    /** POST /platform/condominiums/{id}/suspend */
    public function suspend(string $id): Response
    {
        $condominium = $this->findOrFail($id);
        $back = "/platform/condominiums/{$condominium['id']}";
        $v = new Validator($this->request);
        $reason = $v->string('suspension_reason', 'Motivo', 5, 255);
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back);
        }

        try {
            (new CondominiumService($this->request))->suspend((int) $condominium['id'], (string) $reason);
        } catch (BusinessRuleException $e) {
            return $this->invalid(['general' => $e->getMessage()], $back, $e->status());
        }

        return $this->done('Condomínio suspenso. Os usuários dele perdem o acesso a partir da próxima ação.', $back);
    }

    /** POST /platform/condominiums/{id}/reactivate */
    public function reactivate(string $id): Response
    {
        $condominium = $this->findOrFail($id);
        $back = "/platform/condominiums/{$condominium['id']}";

        try {
            (new CondominiumService($this->request))->reactivate((int) $condominium['id']);
        } catch (BusinessRuleException $e) {
            return $this->invalid(['general' => $e->getMessage()], $back, $e->status());
        }

        return $this->done('Condomínio reativado.', $back);
    }

    /**
     * POST /platform/condominiums/{id}/managers: invites a Property Manager.
     * The role is fixed in code ("manager"); the form has no role field.
     */
    public function inviteManager(string $id): Response
    {
        $condominium = $this->findOrFail($id);
        $back = "/platform/condominiums/{$condominium['id']}";
        if ($condominium['status'] !== 'active') {
            return $this->invalid(['general' => 'Reative o condomínio antes de convidar síndicos.'], $back, 409);
        }

        $v = new Validator($this->request);
        $name = $v->string('full_name', 'Nome', 3, 150);
        $email = $v->email('email');
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back, 422, ['full_name', 'email']);
        }

        try {
            $result = (new InvitationService($this->request))->invite(
                (int) $condominium['id'],   // from the URL, validated by findOrFail() above
                (string) $email,
                (string) $name,
                'manager',
                null,
                null,
                (int) Auth::id()
            );
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], $back, $e->status(), ['full_name', 'email']);
        }

        return $this->done(
            $result['email_sent'] ? "Convite enviado para {$email}." : 'Síndico cadastrado, mas o e-mail não pôde ser enviado agora. Reenvie o convite.',
            $back,
            ['user_id' => $result['user_id']],
            201
        );
    }

    /** POST /platform/condominiums/{id}/managers/{userId}/resend */
    public function resendManagerInvitation(string $id, string $userId): Response
    {
        $condominium = $this->findOrFail($id);
        $back = "/platform/condominiums/{$condominium['id']}";

        try {
            $sent = (new InvitationService($this->request))->resend((int) $condominium['id'], (int) $userId, (int) Auth::id());
        } catch (BusinessRuleException $e) {
            if ($e->status() === 404) {
                throw new HttpException(404);
            }

            return $this->invalid(['general' => $e->getMessage()], $back, $e->status());
        }

        return $this->done($sent ? 'Convite reenviado.' : 'Novo convite gerado, mas o e-mail não pôde ser enviado agora.', $back);
    }

    /**
     * The condominium in the URL, or 404. The id is only a lookup key; nothing
     * else about the request decides which tenant is affected.
     *
     * @return array<string, mixed>
     */
    private function findOrFail(string $id): array
    {
        return (new Condominium())->find((int) $id) ?? throw new HttpException(404);
    }

    /** @param array<string, mixed>|null $condominium */
    private function form(?array $condominium): Response
    {
        return $this->view('platform/condominiums/form', [
            'title'       => $condominium === null ? 'Novo condomínio' : 'Editar condomínio',
            'activeNav'   => 'platform-condominiums',
            'condominium' => $condominium,
            'plans'       => Condominium::PLANS,
            'timezones'   => DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'BR'),
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: Validator} */
    private function validated(): array
    {
        $v = new Validator($this->request);
        $legalId = null;
        if ($v->filled('legal_id')) {
            $legalId = CondominiumService::normalizeCnpj($this->request->string('legal_id'));
            if ($legalId === null) {
                $v->addError('legal_id', 'CNPJ inválido.');
            }
        }
        $postal = preg_replace('/\D/', '', $this->request->string('postal_code')) ?? '';
        if (strlen($postal) !== 8) {
            $v->addError('postal_code', 'CEP inválido.');
        }
        $timezones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'BR');

        $data = [
            'name'            => $v->string('name', 'Nome', 3, 150),
            'legal_id'        => $legalId,
            'email'           => $v->filled('email') ? $v->email('email', 'E-mail de contato') : null,
            'contact_name'    => $v->string('contact_name', 'Responsável', 3, 150, required: false),
            'phone'           => $v->phone('phone'),
            'address_line'    => $v->string('address_line', 'Endereço', 3, 200),
            'city'            => $v->string('city', 'Cidade', 2, 100),
            'state_province'  => $v->string('state_province', 'UF', 2, 50),
            'postal_code'     => substr($postal, 0, 5) . '-' . substr($postal, 5),
            'timezone'        => $v->enum('timezone', 'Fuso horário', $timezones),
            'billing_due_day' => $v->integer('billing_due_day', 'Dia de vencimento', 1, 28, 10),
            'plan'            => $v->enum('plan', 'Plano', array_keys(Condominium::PLANS)),
        ];

        return [$data, $v];
    }
}
```


**`app/Views/platform/dashboard.php`**

```php
<?php
/**
 * Platform overview (Super Admin).
 *
 * @var array<string, int>         $counts       status => number of condominiums
 * @var array<string, string>      $statuses     status => label
 * @var list<array<string, mixed>> $condominiums Largest condominiums by active members.
 * @var list<array<string, mixed>> $events       Latest audit entries.
 */
$variants = ['active' => 'active', 'suspended' => 'suspended', 'archived' => 'inactive'];
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Visão geral da plataforma</h1>
        <p class="page-header__subtitle">Condomínios, usuários e eventos recentes</p>
    </div>
    <a class="btn btn--primary" href="/platform/condominiums/new">Novo condomínio</a>
</section>

<div class="kpis">
    <?php foreach ($statuses as $status => $label): ?>
        <a class="kpi kpi--link" href="/platform/condominiums?status=<?= e($status) ?>">
            <span class="kpi__label">Condomínios <?= e(mb_strtolower($label)) ?>s</span>
            <strong class="kpi__value"><?= e($counts[$status] ?? 0) ?></strong>
        </a>
    <?php endforeach; ?>
</div>

<div class="grid-2">
    <section class="panel">
        <h2 class="panel__title panel__title--bar">Usuários por condomínio</h2>
        <?php if ($condominiums === []): ?>
            <p class="state">Nenhum condomínio cadastrado.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--compact">
                    <thead><tr><th>Condomínio</th><th class="num">Ativos</th><th class="num">Convites</th><th class="num">Síndicos</th><th>Situação</th></tr></thead>
                    <tbody>
                    <?php foreach ($condominiums as $c): ?>
                        <tr>
                            <td><a href="/platform/condominiums/<?= e($c['id']) ?>"><?= e($c['name']) ?></a></td>
                            <td class="num"><?= e((int) $c['active_members']) ?></td>
                            <td class="num"><?= e((int) $c['invited_members']) ?></td>
                            <td class="num"><?= e((int) $c['active_managers']) ?></td>
                            <td><?= pill($variants[$c['status']] ?? 'inactive', $statuses[$c['status']] ?? (string) $c['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2 class="panel__title panel__title--bar">
            Eventos recentes
            <a class="btn btn--small panel__title-action" href="/platform/audit">Ver auditoria</a>
        </h2>
        <?php if ($events === []): ?>
            <p class="state">Nenhum evento registrado.</p>
        <?php else: ?>
            <ul class="event-list">
                <?php foreach ($events as $event): ?>
                    <li class="event-list__item">
                        <code><?= e($event['action_code']) ?></code>
                        <span class="muted small">
                            <?= e($event['actor_name'] ?? 'anônimo') ?>
                            · <?= e($event['condominium_name'] ?? 'plataforma') ?>
                            · <?= e(date_br((string) $event['created_at'], true)) ?> UTC
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
```


**`app/Views/platform/condominiums/index.php`**

```php
<?php
/**
 * @var list<array<string, mixed>> $condominiums
 * @var array<string, string>      $statuses
 * @var array<string, string>      $plans
 * @var array<string, string>      $query
 * @var array{page: int, per_page: int, total: int, pages: int} $pagination
 */
$variants = ['active' => 'active', 'suspended' => 'suspended', 'archived' => 'inactive'];
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Condomínios</h1>
        <p class="page-header__subtitle">Clientes (tenants) da plataforma</p>
    </div>
    <a class="btn btn--primary" href="/platform/condominiums/new">Novo condomínio</a>
</section>

<section class="panel">
    <form class="filters" method="get" action="/platform/condominiums">
        <label class="filters__field filters__field--grow">
            <span class="sr-only">Buscar</span>
            <input type="search" name="q" maxlength="100" placeholder="Nome, cidade ou CNPJ" value="<?= e($query['q'] ?? '') ?>">
        </label>
        <label class="filters__field">
            <span class="sr-only">Situação</span>
            <select name="status">
                <option value="">Todas as situações</option>
                <?php foreach ($statuses as $code => $label): ?>
                    <option value="<?= e($code) ?>"<?= ($query['status'] ?? '') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn">Filtrar</button>
    </form>

    <?php if ($condominiums === []): ?>
        <p class="state">Nenhum condomínio encontrado.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Condomínio</th><th>Cidade</th><th>Plano</th><th class="num">Usuários ativos</th><th class="num">Síndicos</th><th>Situação</th><th class="table__action"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($condominiums as $c): ?>
                    <tr>
                        <td class="strong"><?= e($c['name']) ?></td>
                        <td><?= e($c['city'] . '/' . $c['state_province']) ?></td>
                        <td><?= e($plans[$c['plan']] ?? $c['plan']) ?></td>
                        <td class="num"><?= e((int) $c['active_members']) ?></td>
                        <td class="num"><?= e((int) $c['active_managers']) ?></td>
                        <td><?= pill($variants[$c['status']] ?? 'inactive', $statuses[$c['status']] ?? (string) $c['status']) ?></td>
                        <td class="table__action"><a class="btn btn--small" href="/platform/condominiums/<?= e($c['id']) ?>">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= partial('pagination', ['pagination' => $pagination, 'basePath' => '/platform/condominiums', 'query' => $query]) ?>
</section>
```


**`app/Views/platform/condominiums/form.php`**

```php
<?php
/**
 * Create / edit a condominium (Super Admin).
 *
 * @var array<string, mixed>|null $condominium null when creating.
 * @var array<string, string>     $plans
 * @var list<string>              $timezones
 * @var array<string, string>     $errors
 * @var array<string, string>     $old
 */
$value = static fn (string $field, string $default = ''): string
    => (string) ($old[$field] ?? ($condominium[$field] ?? $default));
$action = $condominium === null ? '/platform/condominiums' : '/platform/condominiums/' . (int) $condominium['id'];
$back = $condominium === null ? '/platform/condominiums' : '/platform/condominiums/' . (int) $condominium['id'];
?>
<section class="page-header">
    <div>
        <p class="page-header__eyebrow"><a href="<?= e($back) ?>">← Voltar</a></p>
        <h1 class="page-header__title"><?= $condominium === null ? 'Novo condomínio' : 'Editar ' . e($condominium['name']) ?></h1>
    </div>
</section>

<section class="panel panel--padded panel--narrow">
    <form method="post" action="<?= e($action) ?>" class="form" novalidate>
        <?= csrf_field() ?>
        <?= field_error($errors, 'general') ?>
        <label class="form__field">
            <span class="form__label">Nome</span>
            <input type="text" name="name" maxlength="150" required value="<?= e($value('name')) ?>">
            <?= field_error($errors, 'name') ?>
        </label>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">CNPJ (opcional)</span>
                <input type="text" name="legal_id" maxlength="18" inputmode="numeric" placeholder="00.000.000/0000-00" value="<?= e($value('legal_id')) ?>">
                <?= field_error($errors, 'legal_id') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Plano</span>
                <select name="plan">
                    <?php foreach ($plans as $code => $label): ?>
                        <option value="<?= e($code) ?>"<?= $value('plan', 'basic') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'plan') ?>
            </label>
        </div>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Responsável (contato)</span>
                <input type="text" name="contact_name" maxlength="150" value="<?= e($value('contact_name')) ?>">
                <?= field_error($errors, 'contact_name') ?>
            </label>
            <label class="form__field">
                <span class="form__label">E-mail de contato</span>
                <input type="email" name="email" maxlength="254" value="<?= e($value('email')) ?>">
                <?= field_error($errors, 'email') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Telefone</span>
                <input type="tel" name="phone" maxlength="30" value="<?= e($value('phone')) ?>">
                <?= field_error($errors, 'phone') ?>
            </label>
        </div>
        <label class="form__field">
            <span class="form__label">Endereço</span>
            <input type="text" name="address_line" maxlength="200" required value="<?= e($value('address_line')) ?>">
            <?= field_error($errors, 'address_line') ?>
        </label>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Cidade</span>
                <input type="text" name="city" maxlength="100" required value="<?= e($value('city')) ?>">
                <?= field_error($errors, 'city') ?>
            </label>
            <label class="form__field">
                <span class="form__label">UF</span>
                <input type="text" name="state_province" maxlength="50" required value="<?= e($value('state_province')) ?>">
                <?= field_error($errors, 'state_province') ?>
            </label>
            <label class="form__field">
                <span class="form__label">CEP</span>
                <input type="text" name="postal_code" maxlength="9" inputmode="numeric" required value="<?= e($value('postal_code')) ?>">
                <?= field_error($errors, 'postal_code') ?>
            </label>
        </div>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Fuso horário</span>
                <select name="timezone">
                    <?php foreach ($timezones as $zone): ?>
                        <option value="<?= e($zone) ?>"<?= $value('timezone', 'America/Sao_Paulo') === $zone ? ' selected' : '' ?>><?= e($zone) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'timezone') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Dia de vencimento das taxas</span>
                <input type="number" name="billing_due_day" min="1" max="28" value="<?= e($value('billing_due_day', '10')) ?>">
                <?= field_error($errors, 'billing_due_day') ?>
            </label>
        </div>
        <div class="form__actions">
            <a class="btn" href="<?= e($back) ?>">Cancelar</a>
            <button type="submit" class="btn btn--primary">Salvar</button>
        </div>
    </form>
</section>
```


**`app/Views/platform/condominiums/show.php`**

```php
<?php
/**
 * One condominium (Super Admin): data, Property Managers + invitation, and
 * suspension / reactivation.
 *
 * @var array<string, mixed>       $condominium
 * @var list<array<string, mixed>> $managers
 * @var array<string, string>      $statuses
 * @var array<string, string>      $plans
 * @var array<string, string>      $errors
 * @var array<string, string>      $old
 */
$variants = ['active' => 'active', 'suspended' => 'suspended', 'archived' => 'inactive'];
$id = (int) $condominium['id'];
$memberLabel = static function (array $m): array {
    if ($m['status'] === 'invited') {
        return (int) ($m['invitation_expired'] ?? 1) === 1 ? ['expired', 'Convite expirado'] : ['invited', 'Convite pendente'];
    }

    return $m['status'] === 'active' ? ['active', 'Ativo'] : ['inactive', 'Desativado'];
};
?>
<section class="page-header">
    <div>
        <p class="page-header__eyebrow"><a href="/platform/condominiums">← Condomínios</a></p>
        <h1 class="page-header__title"><?= e($condominium['name']) ?></h1>
        <p class="page-header__subtitle">
            <?= pill($variants[$condominium['status']] ?? 'inactive', $statuses[$condominium['status']] ?? (string) $condominium['status']) ?>
            · Plano <?= e($plans[$condominium['plan']] ?? $condominium['plan']) ?>
        </p>
    </div>
    <a class="btn" href="/platform/condominiums/<?= e($id) ?>/edit">Editar dados</a>
</section>

<?= field_error($errors, 'general') ?>

<div class="grid-2">
    <section class="panel panel--padded">
        <h2 class="panel__title">Dados</h2>
        <dl class="details">
            <dt>CNPJ</dt><dd><?= e($condominium['legal_id'] ?? '—') ?></dd>
            <dt>Endereço</dt><dd><?= e($condominium['address_line'] . ', ' . $condominium['city'] . '/' . $condominium['state_province'] . ' · ' . $condominium['postal_code']) ?></dd>
            <dt>Contato</dt><dd><?= e(trim(($condominium['contact_name'] ?? '') . ' ' . ($condominium['email'] ?? '') . ' ' . ($condominium['phone'] ?? '')) ?: '—') ?></dd>
            <dt>Fuso horário</dt><dd><?= e($condominium['timezone']) ?></dd>
            <dt>Código de cadastro</dt><dd class="mono"><?= e($condominium['signup_code']) ?></dd>
            <dt>Criado em</dt><dd><?= e(date_br((string) $condominium['created_at'], true)) ?> UTC</dd>
            <?php if ($condominium['status'] === 'suspended'): ?>
                <dt>Suspenso em</dt><dd><?= e(date_br((string) $condominium['suspended_at'], true)) ?> UTC</dd>
                <dt>Motivo</dt><dd class="prewrap"><?= e($condominium['suspension_reason']) ?></dd>
            <?php endif; ?>
        </dl>
    </section>

    <section class="panel panel--padded">
        <h2 class="panel__title">Síndicos</h2>
        <?php if ($managers === []): ?>
            <p class="muted">Nenhum síndico ainda. Convide o primeiro abaixo.</p>
        <?php else: ?>
            <ul class="person-list">
                <?php foreach ($managers as $manager): ?>
                    <?php [$variant, $label] = $memberLabel($manager); ?>
                    <li class="person-list__item">
                        <div>
                            <div class="strong"><?= e($manager['full_name']) ?></div>
                            <div class="small muted"><?= e($manager['email']) ?></div>
                        </div>
                        <?= pill($variant, $label) ?>
                        <?php if ($manager['status'] === 'invited'): ?>
                            <form method="post" action="/platform/condominiums/<?= e($id) ?>/managers/<?= e($manager['user_id']) ?>/resend">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn--small">Reenviar convite</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($condominium['status'] === 'active'): ?>
            <form method="post" action="/platform/condominiums/<?= e($id) ?>/managers" class="form form--separated" novalidate>
                <?= csrf_field() ?>
                <h3 class="panel__subtitle">Convidar síndico</h3>
                <div class="form__row">
                    <label class="form__field">
                        <span class="form__label">Nome</span>
                        <input type="text" name="full_name" maxlength="150" required value="<?= e($old['full_name'] ?? '') ?>">
                        <?= field_error($errors, 'full_name') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">E-mail</span>
                        <input type="email" name="email" maxlength="254" required value="<?= e($old['email'] ?? '') ?>">
                        <?= field_error($errors, 'email') ?>
                    </label>
                </div>
                <button type="submit" class="btn btn--primary">Enviar convite</button>
            </form>
        <?php endif; ?>
    </section>
</div>

<section class="panel panel--padded panel--danger">
    <?php if ($condominium['status'] === 'active'): ?>
        <h2 class="panel__title">Suspender condomínio</h2>
        <p>Os usuários deste condomínio perdem o acesso imediatamente (inclusive sessões abertas). Nenhum dado é apagado.</p>
        <form method="post" action="/platform/condominiums/<?= e($id) ?>/suspend" class="form" novalidate
              data-confirm="Suspender <?= e($condominium['name']) ?>? Todos os usuários dele perderão o acesso.">
            <?= csrf_field() ?>
            <label class="form__field">
                <span class="form__label">Motivo</span>
                <input type="text" name="suspension_reason" minlength="5" maxlength="255" required>
                <?= field_error($errors, 'suspension_reason') ?>
            </label>
            <button type="submit" class="btn btn--danger">Suspender</button>
        </form>
    <?php elseif ($condominium['status'] === 'suspended'): ?>
        <h2 class="panel__title">Reativar condomínio</h2>
        <form method="post" action="/platform/condominiums/<?= e($id) ?>/reactivate" class="form"
              data-confirm="Reativar <?= e($condominium['name']) ?>? Os usuários voltam a ter acesso.">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--primary">Reativar</button>
        </form>
    <?php else: ?>
        <p class="muted">Condomínio arquivado.</p>
    <?php endif; ?>
</section>
```


### 3.2 Property Manager: condominium setup

`AdminController` is the base of every `/admin` controller. It documents the tenant rule: `TenantContext::id()`, never a request value.

- **Units:** list, create, bulk create and edit/deactivate.
- **Common areas:** opening hours and maximum duration, enforced in `ReservationService`.
- **Notices:**
  - the Phase 2 create dialog gains an expiry field;
  - `/admin/notices` edits and deletes.

**`app/Controllers/Admin/AdminController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Pagination;
use App\Core\Response;
use App\Core\TenantContext;
use App\Services\BusinessRuleException;

/**
 * Base of the Property Manager's administration area (/admin, Phase 5).
 *
 * Every /admin route runs auth → tenant → role:manager (→ csrf on writes), and
 * every action repeats requireRole(self::MANAGERS) as defence in depth.
 *
 * TENANT ISOLATION: the condominium is always TenantContext::id(), which
 * TenantMiddleware resolved from $_SESSION['condominium_id'] and re-validated
 * against an ACTIVE membership in an ACTIVE condominium. No /admin action reads
 * a condominium id from the URL, form or JSON body. Record ids in URLs are only
 * lookup keys, resolved through tenant-scoped models (404 when not ours).
 */
abstract class AdminController extends Controller
{
    /** Only Property Managers administer a condominium; the Super Admin uses /platform. */
    public const MANAGERS = ['manager'];

    /** The session's condominium (never a request value). */
    protected function tenantId(): int
    {
        return TenantContext::id();
    }

    /**
     * Turns a service's BusinessRuleException into the right answer: 404 as a
     * real "not found", anything else as a form/JSON error.
     *
     * @param list<string> $keep
     */
    protected function ruleFailure(BusinessRuleException $e, string $redirectTo, array $keep = []): Response
    {
        if ($e->status() === 404) {
            throw new HttpException(404);
        }

        return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], $redirectTo, $e->status(), $keep);
    }

    /** Pagination of the current request. */
    protected function pagination(int $perPage = 25): Pagination
    {
        return Pagination::fromRequest($this->request, $perPage);
    }
}
```


**`app/Models/Unit.php`** (existing file: changes only)

```diff
@@ -4,6 +4,7 @@ declare(strict_types=1);
 
 namespace App\Models;
 
+use App\Core\Pagination;
 use App\Core\TenantModel;
 
 /**
@@ -14,8 +15,18 @@ final class Unit extends TenantModel
     /** "Bloco B 1203", or just "1203" for single-building condominiums. */
     public const LABEL_SQL = "CONCAT_WS(' ', NULLIF(u.building, ''), u.unit_number)";
 
+    public const TYPES = [
+        'apartment'  => 'Apartamento',
+        'house'      => 'Casa',
+        'commercial' => 'Comercial',
+        'other'      => 'Outro',
+    ];
+
     protected string $table = 'units';
 
+    // condominium_id is intentionally absent: TenantModel::insert() sets it.
+    protected array $fillable = ['building', 'unit_number', 'floor_number', 'unit_type', 'is_active'];
+
     /**
      * Active units of the current tenant, for <select> lists.
      *
@@ -48,4 +59,96 @@ final class Unit extends TenantModel
             $this->scoped(['id' => $id])
         );
     }
+
+    /**
+     * One page of units for the admin list.
+     *
+     * @param array{q?: string, building?: string, active?: bool} $filters Already validated.
+     * @return list<array<string, mixed>>
+     */
+    public function search(array $filters, Pagination $pagination): array
+    {
+        [$where, $params] = $this->filterSql($filters);
+
+        return $this->fetchAll(
+            "SELECT u.id, u.building, u.unit_number, u.floor_number, u.unit_type, u.is_active,
+                    " . self::LABEL_SQL . " AS label,
+                    (SELECT COUNT(*) FROM unit_residents ur
+                      WHERE ur.condominium_id = u.condominium_id AND ur.unit_id = u.id
+                        AND ur.move_out_date IS NULL) AS resident_count
+               FROM units u
+              WHERE {$where}
+              ORDER BY u.building, LENGTH(u.unit_number), u.unit_number
+              LIMIT :limit OFFSET :offset",
+            $params + ['limit' => $pagination->limit(), 'offset' => $pagination->offset()]
+        );
+    }
+
+    /** @param array{q?: string, building?: string, active?: bool} $filters */
+    public function count(array $filters): int
+    {
+        [$where, $params] = $this->filterSql($filters);
+        $row = $this->fetchOne("SELECT COUNT(*) AS total FROM units u WHERE {$where}", $params);
+
+        return (int) ($row['total'] ?? 0);
+    }
+
+    /**
+     * Distinct buildings/towers of the tenant, for the filter.
+     *
+     * @return list<string>
+     */
+    public function buildings(): array
+    {
+        $rows = $this->fetchAll(
+            "SELECT DISTINCT building FROM units WHERE condominium_id = :tenant AND building <> '' ORDER BY building",
+            $this->scoped()
+        );
+
+        return array_map(static fn (array $r): string => (string) $r['building'], $rows);
+    }
+
+    /**
+     * Inserts a unit unless (building, unit_number) already exists in this tenant.
+     *
+     * @return bool True when a row was inserted, false for an existing label.
+     */
+    public function insertIfMissing(string $building, string $unitNumber, ?int $floor, string $type): bool
+    {
+        return $this->execute(
+            'INSERT INTO units (condominium_id, building, unit_number, floor_number, unit_type)
+             VALUES (:tenant, :building, :unit_number, :floor_number, :unit_type)
+             ON DUPLICATE KEY UPDATE id = id',
+            $this->scoped([
+                'building'     => $building,
+                'unit_number'  => $unitNumber,
+                'floor_number' => $floor,
+                'unit_type'    => $type,
+            ])
+        ) === 1;
+    }
+
+    /**
+     * @param array<string, mixed> $filters
+     * @return array{0: string, 1: array<string, mixed>}
+     */
+    private function filterSql(array $filters): array
+    {
+        $conditions = ['u.condominium_id = :tenant'];   // tenant scope
+        $params = [];
+        if (isset($filters['q']) && $filters['q'] !== '') {
+            $conditions[] = 'u.unit_number LIKE :q';
+            $params['q'] = self::likeContains($filters['q']);
+        }
+        if (isset($filters['building'])) {
+            $conditions[] = 'u.building = :building';
+            $params['building'] = $filters['building'];
+        }
+        if (isset($filters['active'])) {
+            $conditions[] = 'u.is_active = :active';
+            $params['active'] = $filters['active'] ? 1 : 0;
+        }
+
+        return [implode(' AND ', $conditions), $this->scoped($params)];
+    }
 }
```


**`app/Services/UnitService.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Models\Unit;
use PDOException;
use Throwable;

/**
 * Unit registry of the current condominium (Phase 5). Units are never deleted:
 * invoices, visits and reservations reference them (FK RESTRICT), so a unit that
 * no longer exists is deactivated instead.
 */
final class UnitService
{
    /** Upper bound for one bulk run, so a typo (floors 1-1000) cannot create thousands of rows. */
    public const BULK_MAX = 1000;

    public function __construct(
        private readonly Request $request,
        private readonly Unit $units = new Unit()
    ) {
    }

    /**
     * @param array{building: string, unit_number: string, floor_number: ?int, unit_type: string} $data
     * @throws BusinessRuleException 409 when the label already exists in this condominium.
     */
    public function create(array $data): int
    {
        try {
            return Database::transaction(function () use ($data): int {
                $id = $this->units->insert($data + ['is_active' => 1]);
                (new AuditLogger($this->request))->tenant('unit.created', 'unit', $id, [
                    'building'    => $data['building'],
                    'unit_number' => $data['unit_number'],
                ]);

                return $id;
            });
        } catch (PDOException $e) {
            throw self::duplicateOr($e);
        }
    }

    /**
     * @param array{building: string, unit_number: string, floor_number: ?int, unit_type: string, is_active: int} $data
     * @throws BusinessRuleException
     */
    public function update(int $id, array $data): void
    {
        try {
            Database::transaction(function () use ($id, $data): void {
                $before = $this->units->find($id) ?? throw new BusinessRuleException('Unidade não encontrada.', 404);
                $this->units->update($id, $data);   // tenant-scoped UPDATE
                $changes = [];
                foreach ($data as $column => $value) {
                    if ((string) $before[$column] !== (string) $value) {
                        $changes[$column] = ['from' => $before[$column], 'to' => $value];
                    }
                }
                if ($changes !== []) {
                    (new AuditLogger($this->request))->tenant('unit.updated', 'unit', $id, $changes);
                }
            });
        } catch (PDOException $e) {
            throw self::duplicateOr($e);
        }
    }

    /**
     * Creates "floors × units per floor" units in one building, numbered
     * floor * 100 + n (floor 3, unit 2 → "302"); floor 0 gives "1", "2"...
     *
     * One transaction: either the whole batch is written or nothing is.
     * Labels that already exist are skipped (and counted), so running the same
     * batch twice is harmless.
     *
     * @return array{created: int, skipped: int}
     * @throws BusinessRuleException
     */
    public function bulkCreate(string $building, int $firstFloor, int $lastFloor, int $perFloor, string $type): array
    {
        $total = ($lastFloor - $firstFloor + 1) * $perFloor;
        if ($lastFloor < $firstFloor) {
            throw new BusinessRuleException('O andar final deve ser maior ou igual ao inicial.', 422, 'last_floor');
        }
        if ($total > self::BULK_MAX) {
            throw new BusinessRuleException('No máximo ' . self::BULK_MAX . " unidades por vez (pedido: {$total}).", 422, 'units_per_floor');
        }

        return Database::transaction(function () use ($building, $firstFloor, $lastFloor, $perFloor, $type): array {
            $created = 0;
            $skipped = 0;
            for ($floor = $firstFloor; $floor <= $lastFloor; $floor++) {
                for ($n = 1; $n <= $perFloor; $n++) {
                    $number = (string) ($floor * 100 + $n);
                    $this->units->insertIfMissing($building, $number, $floor, $type) ? $created++ : $skipped++;
                }
            }
            (new AuditLogger($this->request))->tenant('unit.bulk_created', 'unit', null, [
                'building'   => $building,
                'floors'     => [$firstFloor, $lastFloor],
                'per_floor'  => $perFloor,
                'created'    => $created,
                'skipped'    => $skipped,
            ]);

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    private static function duplicateOr(PDOException $e): Throwable
    {
        // 1062: uq_units_tenant_label (condominium_id, building, unit_number).
        if (($e->errorInfo[1] ?? null) === 1062) {
            return new BusinessRuleException('Já existe uma unidade com este bloco e número.', 409, 'unit_number');
        }

        return $e;
    }
}
```


**`app/Controllers/Admin/UnitController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Unit;
use App\Services\BusinessRuleException;
use App\Services\UnitService;

/**
 * Units of the current condominium (Property Manager, Phase 5): list, create,
 * bulk create, edit, activate/deactivate. All reads and writes go through the
 * tenant-scoped Unit model.
 */
final class UnitController extends AdminController
{
    private const KEEP = ['building', 'unit_number', 'floor_number', 'unit_type'];
    private const KEEP_BULK = ['bulk_building', 'first_floor', 'last_floor', 'units_per_floor', 'bulk_unit_type'];

    /** GET /admin/units?q=&building=&status=&page= */
    public function index(): Response
    {
        $this->requireRole(self::MANAGERS);

        $units = new Unit();
        $buildings = $units->buildings();
        $filters = [];
        $query = [];
        $q = mb_substr($this->request->queryString('q'), 0, 20);
        if ($q !== '') {
            $filters['q'] = $query['q'] = $q;
        }
        $building = $this->request->queryString('building');
        if (in_array($building, $buildings, true)) {   // allowlist: existing buildings only
            $filters['building'] = $query['building'] = $building;
        }
        $status = $this->request->queryString('status');
        if (in_array($status, ['active', 'inactive'], true)) {
            $filters['active'] = $status === 'active';
            $query['status'] = $status;
        }

        $pagination = $this->pagination(30)->withTotal($units->count($filters));

        return $this->view('admin/units/index', [
            'title'      => 'Unidades',
            'activeNav'  => 'admin-units',
            'units'      => $units->search($filters, $pagination),
            'buildings'  => $buildings,
            'types'      => Unit::TYPES,
            'query'      => $query,
            'pagination' => $pagination->toArray(),
            'bulkMax'    => UnitService::BULK_MAX,
        ]);
    }

    /** POST /admin/units */
    public function store(): Response
    {
        $this->requireRole(self::MANAGERS);
        [$data, $v] = $this->validated();
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/admin/units', 422, self::KEEP);
        }

        try {
            (new UnitService($this->request))->create($data);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, '/admin/units', self::KEEP);
        }

        return $this->done('Unidade cadastrada.', '/admin/units', [], 201);
    }

    /** POST /admin/units/bulk: e.g. Tower A, floors 1-10, 4 units per floor. */
    public function bulk(): Response
    {
        $this->requireRole(self::MANAGERS);

        $v = new Validator($this->request);
        $building = (string) $v->string('bulk_building', 'Bloco/Torre', 1, 30);
        $first = $v->integer('first_floor', 'Andar inicial', 0, 200);
        $last = $v->integer('last_floor', 'Andar final', 0, 200);
        $perFloor = $v->integer('units_per_floor', 'Unidades por andar', 1, 50);
        $type = $v->enum('bulk_unit_type', 'Tipo', array_keys(Unit::TYPES));
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/admin/units', 422, self::KEEP_BULK);
        }

        try {
            $result = (new UnitService($this->request))->bulkCreate($building, (int) $first, (int) $last, (int) $perFloor, (string) $type);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, '/admin/units', self::KEEP_BULK);
        }

        $message = "{$result['created']} unidade(s) criada(s)"
            . ($result['skipped'] > 0 ? "; {$result['skipped']} já existia(m) e foi(ram) mantida(s)." : '.');

        return $this->done($message, '/admin/units', $result, 201);
    }

    /** GET /admin/units/{id}/edit */
    public function edit(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $unit = (new Unit())->find((int) $id) ?? throw new HttpException(404);   // tenant-scoped find

        return $this->view('admin/units/edit', [
            'title'     => 'Editar unidade',
            'activeNav' => 'admin-units',
            'unit'      => $unit,
            'types'     => Unit::TYPES,
        ]);
    }

    /** POST /admin/units/{id} */
    public function update(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        (new Unit())->find((int) $id) ?? throw new HttpException(404);
        $back = "/admin/units/{$id}/edit";

        [$data, $v] = $this->validated();
        $data['is_active'] = $this->request->boolean('is_active') ? 1 : 0;
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back);
        }

        try {
            (new UnitService($this->request))->update((int) $id, $data);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, $back);
        }

        return $this->done('Unidade atualizada.', '/admin/units');
    }

    /** @return array{0: array{building: string, unit_number: string, floor_number: ?int, unit_type: string}, 1: Validator} */
    private function validated(): array
    {
        $v = new Validator($this->request);
        $building = $v->string('building', 'Bloco/Torre', 1, 30, required: false) ?? '';
        $number = $v->string('unit_number', 'Número', 1, 20);
        $floor = $v->filled('floor_number') ? $v->integer('floor_number', 'Andar', -5, 200) : null;
        $type = $v->enum('unit_type', 'Tipo', array_keys(Unit::TYPES));

        return [[
            'building'     => $building,
            'unit_number'  => (string) $number,
            'floor_number' => $floor,
            'unit_type'    => (string) $type,
        ], $v];
    }
}
```


**`app/Views/admin/units/index.php`**

```php
<?php
/**
 * Units: filters + list (server-rendered, paginated), single and bulk creation.
 *
 * @var list<array<string, mixed>>             $units
 * @var list<string>                           $buildings
 * @var array<string, string>                  $types
 * @var array<string, string>                  $query      Current filters.
 * @var array{page: int, per_page: int, total: int, pages: int} $pagination
 * @var int                                    $bulkMax
 * @var array<string, string>                  $errors
 * @var array<string, string>                  $old
 */
$selected = static fn (string $field, string $value): string
    => (string) ($old[$field] ?? '') === $value ? ' selected' : '';
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Unidades</h1>
        <p class="page-header__subtitle">Apartamentos, casas e salas do condomínio</p>
    </div>
</section>

<?= field_error($errors, 'general') ?>

<div class="columns">
    <div class="columns__side">
        <section class="panel panel--padded">
            <h2 class="panel__title">Nova unidade</h2>
            <form method="post" action="/admin/units" class="form" novalidate>
                <?= csrf_field() ?>
                <div class="form__row">
                    <label class="form__field">
                        <span class="form__label">Bloco/Torre</span>
                        <input type="text" name="building" maxlength="30" value="<?= e($old['building'] ?? '') ?>" placeholder="Ex.: A">
                        <?= field_error($errors, 'building') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">Número</span>
                        <input type="text" name="unit_number" maxlength="20" required value="<?= e($old['unit_number'] ?? '') ?>">
                        <?= field_error($errors, 'unit_number') ?>
                    </label>
                </div>
                <div class="form__row">
                    <label class="form__field">
                        <span class="form__label">Andar</span>
                        <input type="number" name="floor_number" min="-5" max="200" value="<?= e($old['floor_number'] ?? '') ?>">
                        <?= field_error($errors, 'floor_number') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">Tipo</span>
                        <select name="unit_type">
                            <?php foreach ($types as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $selected('unit_type', $value) ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <button type="submit" class="btn btn--primary">Cadastrar unidade</button>
            </form>
        </section>

        <section class="panel panel--padded">
            <h2 class="panel__title">Cadastro em lote</h2>
            <form method="post" action="/admin/units/bulk" class="form" novalidate>
                <?= csrf_field() ?>
                <label class="form__field">
                    <span class="form__label">Bloco/Torre</span>
                    <input type="text" name="bulk_building" maxlength="30" required value="<?= e($old['bulk_building'] ?? '') ?>" placeholder="Ex.: Torre A">
                    <?= field_error($errors, 'bulk_building') ?>
                </label>
                <div class="form__row">
                    <label class="form__field">
                        <span class="form__label">Do andar</span>
                        <input type="number" name="first_floor" min="0" max="200" required value="<?= e($old['first_floor'] ?? '1') ?>">
                        <?= field_error($errors, 'first_floor') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">Até o andar</span>
                        <input type="number" name="last_floor" min="0" max="200" required value="<?= e($old['last_floor'] ?? '10') ?>">
                        <?= field_error($errors, 'last_floor') ?>
                    </label>
                </div>
                <div class="form__row">
                    <label class="form__field">
                        <span class="form__label">Unidades por andar</span>
                        <input type="number" name="units_per_floor" min="1" max="50" required value="<?= e($old['units_per_floor'] ?? '4') ?>">
                        <?= field_error($errors, 'units_per_floor') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">Tipo</span>
                        <select name="bulk_unit_type">
                            <?php foreach ($types as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $selected('bulk_unit_type', $value) ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <p class="form__hint">Numeração: andar × 100 + posição (andar 3, unidade 2 = 302). Unidades já existentes são mantidas. Máximo de <?= e($bulkMax) ?> por vez.</p>
                <button type="submit" class="btn">Gerar unidades</button>
            </form>
        </section>
    </div>

    <div class="columns__main">
        <section class="panel">
            <form class="filters" method="get" action="/admin/units">
                <label class="filters__field filters__field--grow">
                    <span class="sr-only">Número</span>
                    <input type="search" name="q" placeholder="Buscar por número" maxlength="20" value="<?= e($query['q'] ?? '') ?>">
                </label>
                <label class="filters__field">
                    <span class="sr-only">Bloco</span>
                    <select name="building">
                        <option value="">Todos os blocos</option>
                        <?php foreach ($buildings as $building): ?>
                            <option value="<?= e($building) ?>"<?= ($query['building'] ?? '') === $building ? ' selected' : '' ?>><?= e($building) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="filters__field">
                    <span class="sr-only">Situação</span>
                    <select name="status">
                        <option value="">Ativas e inativas</option>
                        <option value="active"<?= ($query['status'] ?? '') === 'active' ? ' selected' : '' ?>>Ativas</option>
                        <option value="inactive"<?= ($query['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Inativas</option>
                    </select>
                </label>
                <button type="submit" class="btn">Filtrar</button>
            </form>

            <?php if ($units === []): ?>
                <p class="state">Nenhuma unidade encontrada.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr><th>Unidade</th><th>Andar</th><th>Tipo</th><th class="num">Moradores</th><th>Situação</th><th class="table__action"></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($units as $unit): ?>
                            <tr>
                                <td class="strong"><?= e($unit['label']) ?></td>
                                <td><?= e($unit['floor_number']) ?></td>
                                <td><?= e($types[$unit['unit_type']] ?? $unit['unit_type']) ?></td>
                                <td class="num"><?= e((int) $unit['resident_count']) ?></td>
                                <td><?= (int) $unit['is_active'] === 1 ? pill('active', 'Ativa') : pill('inactive', 'Inativa') ?></td>
                                <td class="table__action"><a class="btn btn--small" href="/admin/units/<?= e($unit['id']) ?>/edit">Editar</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?= partial('pagination', ['pagination' => $pagination, 'basePath' => '/admin/units', 'query' => $query]) ?>
        </section>
    </div>
</div>
```


**`app/Views/admin/units/edit.php`**

```php
<?php
/**
 * @var array<string, mixed>  $unit
 * @var array<string, string> $types
 * @var array<string, string> $errors
 */
?>
<section class="page-header">
    <div>
        <p class="page-header__eyebrow"><a href="/admin/units">← Unidades</a></p>
        <h1 class="page-header__title">Editar unidade</h1>
    </div>
</section>

<section class="panel panel--padded panel--narrow">
    <form method="post" action="/admin/units/<?= e($unit['id']) ?>" class="form" novalidate>
        <?= csrf_field() ?>
        <?= field_error($errors, 'general') ?>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Bloco/Torre</span>
                <input type="text" name="building" maxlength="30" value="<?= e($unit['building']) ?>">
                <?= field_error($errors, 'building') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Número</span>
                <input type="text" name="unit_number" maxlength="20" required value="<?= e($unit['unit_number']) ?>">
                <?= field_error($errors, 'unit_number') ?>
            </label>
        </div>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Andar</span>
                <input type="number" name="floor_number" min="-5" max="200" value="<?= e($unit['floor_number']) ?>">
                <?= field_error($errors, 'floor_number') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Tipo</span>
                <select name="unit_type">
                    <?php foreach ($types as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $unit['unit_type'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label class="form__check">
            <input type="checkbox" name="is_active" value="1"<?= (int) $unit['is_active'] === 1 ? ' checked' : '' ?>>
            Unidade ativa (inativas não aparecem em reservas, cobranças e portaria; o histórico é mantido)
        </label>
        <div class="form__actions">
            <a class="btn" href="/admin/units">Cancelar</a>
            <button type="submit" class="btn btn--primary">Salvar</button>
        </div>
    </form>
</section>
```


**`app/Models/CommonArea.php`** (existing file: changes only)

```diff
@@ -21,8 +21,34 @@ final class CommonArea extends TenantModel
         ['Academia', 'gym', 10, 8, 0, 1, 7],
     ];
 
+    public const TYPES = [
+        'bbq'        => 'Churrasqueira',
+        'party_room' => 'Salão de festas',
+        'gym'        => 'Academia',
+        'other'      => 'Outro',
+    ];
+
     protected string $table = 'common_areas';
 
+    // condominium_id is intentionally absent: TenantModel::insert() sets it.
+    protected array $fillable = [
+        'name',
+        'area_type',
+        'description',
+        'rules',
+        'max_people',
+        'bookings_per_slot',
+        'requires_approval',
+        'opens_at',
+        'closes_at',
+        'max_duration_minutes',
+        'min_advance_hours',
+        'max_advance_days',
+        'cancel_deadline_hours',
+        'max_active_per_unit',
+        'is_active',
+    ];
+
     /**
      * Active areas, for the booking form.
      *
@@ -32,7 +58,7 @@ final class CommonArea extends TenantModel
     {
         return $this->fetchAll(
             'SELECT id, name, area_type, max_people, bookings_per_slot, requires_approval,
-                    booking_fee, min_advance_hours, max_advance_days
+                    booking_fee, min_advance_hours, max_advance_days, opens_at, closes_at, max_duration_minutes
                FROM common_areas
               WHERE condominium_id = :tenant AND is_active = 1
               ORDER BY name',
@@ -54,7 +80,8 @@ final class CommonArea extends TenantModel
     {
         return $this->fetchOne(
             'SELECT id, name, is_active, max_people, bookings_per_slot, requires_approval,
-                    min_advance_hours, max_advance_days, max_active_per_unit, cancel_deadline_hours
+                    min_advance_hours, max_advance_days, max_active_per_unit, cancel_deadline_hours,
+                    opens_at, closes_at, max_duration_minutes
                FROM common_areas
               WHERE id = :id AND condominium_id = :tenant
               FOR UPDATE',
@@ -63,11 +90,17 @@ final class CommonArea extends TenantModel
     }
 
     /**
-     * Creates the default areas for this tenant if they are missing. Idempotent:
-     * the unique key (condominium_id, name) turns repeats into no-ops.
+     * Creates the default areas for a condominium that has NO area yet.
+     * Idempotent: the unique key (condominium_id, name) turns repeats into no-ops.
+     *
+     * Phase 5: once a manager has configured areas (even if every one is now
+     * inactive or renamed), the defaults are not re-created behind their back.
      */
     public function ensureDefaults(): void
     {
+        if ($this->fetchOne('SELECT 1 FROM common_areas WHERE condominium_id = :tenant LIMIT 1', $this->scoped()) !== null) {
+            return;
+        }
         foreach (self::DEFAULTS as [$name, $type, $maxPeople, $perSlot, $approval, $minHours, $maxDays]) {
             $this->execute(
                 'INSERT INTO common_areas
@@ -87,4 +120,24 @@ final class CommonArea extends TenantModel
             );
         }
     }
+
+    /**
+     * Every area of the tenant, active or not, for the admin list.
+     *
+     * @return list<array<string, mixed>>
+     */
+    public function all(): array
+    {
+        return $this->fetchAll(
+            'SELECT a.id, a.name, a.area_type, a.max_people, a.bookings_per_slot, a.requires_approval,
+                    a.opens_at, a.closes_at, a.max_duration_minutes, a.min_advance_hours, a.max_advance_days,
+                    a.is_active,
+                    (SELECT COUNT(*) FROM reservations r
+                      WHERE r.condominium_id = a.condominium_id AND r.common_area_id = a.id) AS reservation_count
+               FROM common_areas a
+              WHERE a.condominium_id = :tenant
+              ORDER BY a.is_active DESC, a.name',
+            $this->scoped()
+        );
+    }
 }
```


**`app/Services/ReservationService.php`** (existing file: changes only)

```diff
@@ -88,6 +88,28 @@ final class ReservationService
                     'reservation_date'
                 );
             }
+            // Phase 5: opening hours and maximum duration configured by the manager.
+            // Times are local wall-clock values, compared as "HH:MM:SS" strings.
+            if ($area['opens_at'] !== null && $area['closes_at'] !== null) {
+                $opens = (string) $area['opens_at'];
+                $closes = (string) $area['closes_at'];
+                if ($startsAt->format('H:i:s') < $opens || $endsAt->format('H:i:s') > $closes) {
+                    throw new BusinessRuleException(
+                        'Esta área funciona das ' . substr($opens, 0, 5) . ' às ' . substr($closes, 0, 5) . '.',
+                        422,
+                        'start_time'
+                    );
+                }
+            }
+            if ($area['max_duration_minutes'] !== null
+                && ($endsAt->getTimestamp() - $startsAt->getTimestamp()) > (int) $area['max_duration_minutes'] * 60
+            ) {
+                throw new BusinessRuleException(
+                    "Cada reserva desta área pode durar no máximo {$area['max_duration_minutes']} minutos.",
+                    422,
+                    'end_time'
+                );
+            }
             if ($area['max_people'] !== null && $guests > (int) $area['max_people']) {
                 throw new BusinessRuleException("Capacidade máxima: {$area['max_people']} pessoas.", 422, 'guest_count');
             }
```


**`app/Controllers/Admin/CommonAreaController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Validator;
use App\Models\CommonArea;
use App\Services\AuditLogger;
use PDOException;

/**
 * Common areas used by Reservations (Property Manager, Phase 5).
 *
 * Deactivating an area blocks NEW bookings (ReservationService checks
 * is_active under the area's row lock) but keeps every existing reservation:
 * nothing is deleted, the reservations' FK to the area is RESTRICT anyway.
 */
final class CommonAreaController extends AdminController
{
    private const KEEP = [
        'name', 'area_type', 'max_people', 'bookings_per_slot', 'opens_at', 'closes_at',
        'max_duration_minutes', 'min_advance_hours', 'max_advance_days', 'rules',
    ];

    /** GET /admin/common-areas */
    public function index(): Response
    {
        $this->requireRole(self::MANAGERS);

        return $this->view('admin/common-areas/index', [
            'title'     => 'Áreas comuns',
            'activeNav' => 'admin-areas',
            'areas'     => (new CommonArea())->all(),
            'types'     => CommonArea::TYPES,
        ]);
    }

    /** GET /admin/common-areas/new */
    public function create(): Response
    {
        $this->requireRole(self::MANAGERS);

        return $this->form(null);
    }

    /** POST /admin/common-areas */
    public function store(): Response
    {
        $this->requireRole(self::MANAGERS);
        [$data, $v] = $this->validated();
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/admin/common-areas/new', 422, self::KEEP);
        }

        try {
            Database::transaction(function () use ($data): void {
                $id = (new CommonArea())->insert($data + ['is_active' => 1]);
                (new AuditLogger($this->request))->tenant('common_area.created', 'common_area', $id, ['name' => $data['name']]);
            });
        } catch (PDOException $e) {
            return $this->duplicate($e, '/admin/common-areas/new');
        }

        return $this->done('Área comum cadastrada.', '/admin/common-areas', [], 201);
    }

    /** GET /admin/common-areas/{id}/edit */
    public function edit(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $area = (new CommonArea())->find((int) $id) ?? throw new HttpException(404);   // tenant-scoped

        return $this->form($area);
    }

    /** POST /admin/common-areas/{id} */
    public function update(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $areas = new CommonArea();
        $before = $areas->find((int) $id) ?? throw new HttpException(404);
        $back = "/admin/common-areas/{$id}/edit";

        [$data, $v] = $this->validated();
        $data['is_active'] = $this->request->boolean('is_active') ? 1 : 0;
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back);
        }

        try {
            Database::transaction(function () use ($areas, $id, $data, $before): void {
                $areas->update((int) $id, $data);   // tenant-scoped UPDATE
                $audit = new AuditLogger($this->request);
                $action = match (true) {
                    (int) $before['is_active'] === 1 && $data['is_active'] === 0 => 'common_area.deactivated',
                    (int) $before['is_active'] === 0 && $data['is_active'] === 1 => 'common_area.reactivated',
                    default                                                      => 'common_area.updated',
                };
                $audit->tenant($action, 'common_area', (int) $id, ['name' => $data['name']]);
            });
        } catch (PDOException $e) {
            return $this->duplicate($e, $back);
        }

        return $this->done('Área comum atualizada.', '/admin/common-areas');
    }

    /** @param array<string, mixed>|null $area */
    private function form(?array $area): Response
    {
        return $this->view('admin/common-areas/form', [
            'title'     => $area === null ? 'Nova área comum' : 'Editar área comum',
            'activeNav' => 'admin-areas',
            'area'      => $area,
            'types'     => CommonArea::TYPES,
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: Validator} */
    private function validated(): array
    {
        $v = new Validator($this->request);
        $data = [
            'name'                 => $v->string('name', 'Nome', 2, 80),
            'area_type'            => $v->enum('area_type', 'Tipo', array_keys(CommonArea::TYPES)),
            'max_people'           => $v->filled('max_people') ? $v->integer('max_people', 'Capacidade', 1, 2000) : null,
            'bookings_per_slot'    => $v->integer('bookings_per_slot', 'Reservas simultâneas', 1, 50, 1),
            'requires_approval'    => $this->request->boolean('requires_approval') ? 1 : 0,
            'opens_at'             => null,
            'closes_at'            => null,
            'max_duration_minutes' => $v->filled('max_duration_minutes')
                ? $v->integer('max_duration_minutes', 'Duração máxima', 30, 1440)
                : null,
            'min_advance_hours'    => $v->integer('min_advance_hours', 'Antecedência mínima', 0, 720, 24),
            'max_advance_days'     => $v->integer('max_advance_days', 'Antecedência máxima', 1, 365, 90),
            'rules'                => $v->string('rules', 'Regras', 1, 2000, required: false),
        ];

        // Opening hours: both or neither (schema CHECK ck_common_areas_hours).
        if ($v->filled('opens_at') || $v->filled('closes_at')) {
            $opens = $v->time('opens_at', 'Abertura');
            $closes = $v->time('closes_at', 'Fechamento');
            if ($opens !== null && $closes !== null) {
                if ($closes <= $opens) {
                    $v->addError('closes_at', 'O fechamento deve ser depois da abertura.');
                }
                $data['opens_at'] = $opens . ':00';
                $data['closes_at'] = $closes . ':00';
            }
        }

        return [$data, $v];
    }

    private function duplicate(PDOException $e, string $back): Response
    {
        if (($e->errorInfo[1] ?? null) === 1062) {   // uq_common_areas_name (condominium_id, name)
            return $this->invalid(['name' => 'Já existe uma área com este nome.'], $back, 409, self::KEEP);
        }
        throw $e;
    }
}
```


**`app/Views/admin/common-areas/index.php`**

```php
<?php
/**
 * @var list<array<string, mixed>> $areas
 * @var array<string, string>      $types
 */
$hours = static fn (array $a): string => $a['opens_at'] === null
    ? 'Dia todo'
    : substr((string) $a['opens_at'], 0, 5) . '–' . substr((string) $a['closes_at'], 0, 5);
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Áreas comuns</h1>
        <p class="page-header__subtitle">Espaços disponíveis para reserva</p>
    </div>
    <a class="btn btn--primary" href="/admin/common-areas/new">Nova área</a>
</section>

<section class="panel">
    <?php if ($areas === []): ?>
        <p class="state">Nenhuma área cadastrada.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Área</th><th>Tipo</th><th class="num">Capacidade</th><th>Horário</th>
                    <th>Duração máx.</th><th>Aprovação</th><th class="num">Reservas</th><th>Situação</th><th class="table__action"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($areas as $area): ?>
                    <tr>
                        <td class="strong"><?= e($area['name']) ?></td>
                        <td><?= e($types[$area['area_type']] ?? $area['area_type']) ?></td>
                        <td class="num"><?= e($area['max_people'] ?? '—') ?></td>
                        <td><?= e($hours($area)) ?></td>
                        <td><?= e($area['max_duration_minutes'] === null ? 'Sem limite' : $area['max_duration_minutes'] . ' min') ?></td>
                        <td><?= (int) $area['requires_approval'] === 1 ? 'Exige aprovação' : 'Automática' ?></td>
                        <td class="num"><?= e((int) $area['reservation_count']) ?></td>
                        <td><?= (int) $area['is_active'] === 1 ? pill('active', 'Ativa') : pill('inactive', 'Inativa') ?></td>
                        <td class="table__action"><a class="btn btn--small" href="/admin/common-areas/<?= e($area['id']) ?>/edit">Editar</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
```


**`app/Views/admin/common-areas/form.php`**

```php
<?php
/**
 * Create / edit a common area.
 *
 * @var array<string, mixed>|null $area   null when creating.
 * @var array<string, string>      $types
 * @var array<string, string>      $errors
 * @var array<string, string>      $old
 */
$value = static fn (string $field, mixed $default = ''): string
    => (string) ($old[$field] ?? ($area[$field] ?? $default));
$time = static fn (string $field): string => substr($value($field), 0, 5);
$action = $area === null ? '/admin/common-areas' : '/admin/common-areas/' . (int) $area['id'];
?>
<section class="page-header">
    <div>
        <p class="page-header__eyebrow"><a href="/admin/common-areas">← Áreas comuns</a></p>
        <h1 class="page-header__title"><?= $area === null ? 'Nova área comum' : e($area['name']) ?></h1>
    </div>
</section>

<section class="panel panel--padded panel--narrow">
    <form method="post" action="<?= e($action) ?>" class="form" novalidate>
        <?= csrf_field() ?>
        <?= field_error($errors, 'general') ?>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Nome</span>
                <input type="text" name="name" maxlength="80" required value="<?= e($value('name')) ?>">
                <?= field_error($errors, 'name') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Tipo</span>
                <select name="area_type">
                    <?php foreach ($types as $code => $label): ?>
                        <option value="<?= e($code) ?>"<?= $value('area_type', 'other') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Capacidade (pessoas)</span>
                <input type="number" name="max_people" min="1" max="2000" value="<?= e($value('max_people')) ?>">
                <?= field_error($errors, 'max_people') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Reservas simultâneas</span>
                <input type="number" name="bookings_per_slot" min="1" max="50" value="<?= e($value('bookings_per_slot', '1')) ?>">
                <span class="form__hint">1 = uso exclusivo (salão, churrasqueira).</span>
                <?= field_error($errors, 'bookings_per_slot') ?>
            </label>
        </div>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Abre às</span>
                <input type="time" name="opens_at" value="<?= e($time('opens_at')) ?>">
                <?= field_error($errors, 'opens_at') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Fecha às</span>
                <input type="time" name="closes_at" value="<?= e($time('closes_at')) ?>">
                <?= field_error($errors, 'closes_at') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Duração máxima (min)</span>
                <input type="number" name="max_duration_minutes" min="30" max="1440" value="<?= e($value('max_duration_minutes')) ?>">
                <?= field_error($errors, 'max_duration_minutes') ?>
            </label>
        </div>
        <p class="form__hint">Deixe os horários em branco para permitir reservas a qualquer hora do dia.</p>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Antecedência mínima (horas)</span>
                <input type="number" name="min_advance_hours" min="0" max="720" value="<?= e($value('min_advance_hours', '24')) ?>">
                <?= field_error($errors, 'min_advance_hours') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Antecedência máxima (dias)</span>
                <input type="number" name="max_advance_days" min="1" max="365" value="<?= e($value('max_advance_days', '90')) ?>">
                <?= field_error($errors, 'max_advance_days') ?>
            </label>
        </div>
        <label class="form__field">
            <span class="form__label">Regras de uso (opcional)</span>
            <textarea name="rules" rows="4" maxlength="2000"><?= e($value('rules')) ?></textarea>
            <?= field_error($errors, 'rules') ?>
        </label>
        <label class="form__check">
            <input type="checkbox" name="requires_approval" value="1"<?= (int) $value('requires_approval', '1') === 1 ? ' checked' : '' ?>>
            Reservas precisam de aprovação do síndico
        </label>
        <?php if ($area !== null): ?>
            <label class="form__check">
                <input type="checkbox" name="is_active" value="1"<?= (int) $area['is_active'] === 1 ? ' checked' : '' ?>>
                Área ativa (desativar impede novas reservas; as existentes são mantidas)
            </label>
        <?php endif; ?>
        <div class="form__actions">
            <a class="btn" href="/admin/common-areas">Cancelar</a>
            <button type="submit" class="btn btn--primary">Salvar</button>
        </div>
    </form>
</section>
```


**`app/Models/Notice.php`** (existing file: changes only)

```diff
@@ -4,6 +4,7 @@ declare(strict_types=1);
 
 namespace App\Models;
 
+use App\Core\Pagination;
 use App\Core\TenantModel;
 
 /**
@@ -67,4 +68,49 @@ final class Notice extends TenantModel
     {
         return $this->fetchOne(self::DISPLAY_SELECT . ' AND n.id = :id', $this->scoped(['id' => $id]));
     }
+
+    /**
+     * Every notice of the tenant for the management list (Phase 5), with a
+     * computed visibility state: scheduled | visible | expired | archived | draft.
+     *
+     * @return list<array<string, mixed>>
+     */
+    public function forAdmin(Pagination $pagination): array
+    {
+        return $this->fetchAll(
+            "SELECT n.id, n.title, n.priority, n.is_pinned, n.status, n.publish_at, n.expires_at,
+                    u.full_name AS author_name,
+                    CASE
+                      WHEN n.status <> 'published' THEN n.status
+                      WHEN n.publish_at > UTC_TIMESTAMP() THEN 'scheduled'
+                      WHEN n.expires_at IS NOT NULL AND n.expires_at <= UTC_TIMESTAMP() THEN 'expired'
+                      ELSE 'visible'
+                    END AS visibility
+               FROM notices n
+               LEFT JOIN users u ON u.id = COALESCE(n.author_user_id, n.author_super_admin_id)
+              WHERE n.condominium_id = :tenant
+              ORDER BY n.is_pinned DESC, n.publish_at DESC, n.id DESC
+              LIMIT :limit OFFSET :offset",
+            $this->scoped(['limit' => $pagination->limit(), 'offset' => $pagination->offset()])
+        );
+    }
+
+    public function countAll(): int
+    {
+        $row = $this->fetchOne('SELECT COUNT(*) AS total FROM notices WHERE condominium_id = :tenant', $this->scoped());
+
+        return (int) ($row['total'] ?? 0);
+    }
+
+    /**
+     * Deletes a notice of the current tenant (read receipts go with it through
+     * ON DELETE CASCADE). Returns false when the id is not in this tenant.
+     */
+    public function delete(int $id): bool
+    {
+        return $this->execute(
+            'DELETE FROM notices WHERE id = :id AND condominium_id = :tenant',
+            $this->scoped(['id' => $id])
+        ) === 1;
+    }
 }
```


**`app/Controllers/NoticeController.php`** (existing file: changes only)

```diff
@@ -8,6 +8,7 @@ use App\Core\Auth;
 use App\Core\Controller;
 use App\Core\Response;
 use App\Core\TenantContext;
+use App\Core\Validator;
 use App\Models\AuditLog;
 use App\Models\Notice;
 use DateTimeImmutable;
@@ -60,6 +61,19 @@ final class NoticeController extends Controller
         if (!in_array($priority, Notice::PRIORITIES, true)) {
             $errors['priority'] = 'Prioridade inválida.';
         }
+        // Phase 5: optional expiry, typed in the condominium's local time.
+        $expiresAt = null;
+        $v = new Validator($this->request);
+        if ($v->filled('expires_at')) {
+            $local = $v->dateTimeLocal('expires_at', 'Data de expiração');
+            if ($local !== null) {
+                $expiresAt = TenantContext::localToUtc($local);
+                if ($expiresAt <= gmdate('Y-m-d H:i:s')) {
+                    $v->addError('expires_at', 'A expiração deve ser no futuro.');
+                }
+            }
+            $errors += $v->errors();
+        }
         if ($errors !== []) {
             return $this->json(['errors' => $errors], 422);
         }
@@ -79,6 +93,7 @@ final class NoticeController extends Controller
             'is_pinned'             => $isPinned ? 1 : 0,
             'status'                => 'published',
             'publish_at'            => gmdate('Y-m-d H:i:s'),
+            'expires_at'            => $expiresAt,
         ]);
 
         (new AuditLog())->record('notice.published', $this->request, $userId, TenantContext::id(), 'notice', $id);
```


**`app/Controllers/Admin/NoticeController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\TenantContext;
use App\Core\Validator;
use App\Models\Notice;
use App\Services\AuditLogger;

/**
 * Notice Board management (Property Manager, Phase 5): list every notice
 * (visible, scheduled, expired), edit, pin, set expiry, delete.
 *
 * Creating a notice stays on the dashboard dialog (Phase 2, POST /api/notices),
 * which now also accepts an expiry date. Managers may edit or delete any notice
 * of their condominium, including those published by the concierge.
 */
final class NoticeController extends AdminController
{
    /** GET /admin/notices */
    public function index(): Response
    {
        $this->requireRole(self::MANAGERS);
        $notices = new Notice();
        $pagination = $this->pagination(25)->withTotal($notices->countAll());

        return $this->view('admin/notices/index', [
            'title'      => 'Avisos',
            'activeNav'  => 'admin-notices',
            'notices'    => $notices->forAdmin($pagination),
            'pagination' => $pagination->toArray(),
            'scripts'    => ['js/admin/confirm.js'],
        ]);
    }

    /** GET /admin/notices/{id}/edit */
    public function edit(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $notice = (new Notice())->find((int) $id) ?? throw new HttpException(404);   // tenant-scoped

        return $this->view('admin/notices/edit', [
            'title'     => 'Editar aviso',
            'activeNav' => 'admin-notices',
            'notice'    => $notice,
            'expiresLocal' => $notice['expires_at'] === null ? '' : TenantContext::utcToLocal((string) $notice['expires_at']),
        ]);
    }

    /** POST /admin/notices/{id} */
    public function update(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $notices = new Notice();
        $notice = $notices->find((int) $id) ?? throw new HttpException(404);
        $back = "/admin/notices/{$id}/edit";

        $v = new Validator($this->request);
        $title = $v->string('title', 'Título', 3, Notice::TITLE_MAX);
        $body = $v->string('body', 'Mensagem', 1, Notice::BODY_MAX);
        $priority = $v->enum('priority', 'Prioridade', Notice::PRIORITIES);
        $expiresAt = null;
        if ($v->filled('expires_at')) {
            $local = $v->dateTimeLocal('expires_at', 'Data de expiração');
            if ($local !== null) {
                $expiresAt = TenantContext::localToUtc($local);
                // Schema CHECK ck_notices_window: expires_at > publish_at.
                if ($notice['publish_at'] !== null && $expiresAt <= (string) $notice['publish_at']) {
                    $v->addError('expires_at', 'A expiração deve ser depois da publicação.');
                }
            }
        }
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back);
        }

        Database::transaction(function () use ($notices, $id, $title, $body, $priority, $expiresAt): void {
            $notices->update((int) $id, [
                'title'      => $title,
                'body'       => $body,
                'priority'   => $priority,
                'is_pinned'  => $this->request->boolean('is_pinned') ? 1 : 0,
                'expires_at' => $expiresAt,
            ]);
            (new AuditLogger($this->request))->tenant('notice.updated', 'notice', (int) $id);
        });

        return $this->done('Aviso atualizado.', '/admin/notices');
    }

    /**
     * POST /admin/notices/{id}/delete
     *
     * A real DELETE (read receipts cascade). The title is kept in the audit
     * entry, written in the same transaction, so the deletion stays traceable.
     */
    public function destroy(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $notices = new Notice();
        $notice = $notices->find((int) $id) ?? throw new HttpException(404);

        Database::transaction(function () use ($notices, $notice): void {
            (new AuditLogger($this->request))->tenant('notice.deleted', 'notice', (int) $notice['id'], [
                'title'      => $notice['title'],
                'publish_at' => $notice['publish_at'],
            ]);
            $notices->delete((int) $notice['id']);
        });

        return $this->done('Aviso excluído.', '/admin/notices');
    }
}
```


**`app/Controllers/DashboardController.php`** (existing file: changes only)

```diff
@@ -32,6 +32,7 @@ final class DashboardController extends Controller
             'title'           => 'Mural de avisos',
             'activeNav'       => 'notices',
             'canCreateNotice' => Auth::hasRole(NoticeController::CREATOR_ROLES),
+            'canManageNotices' => Auth::hasRole(Admin\AdminController::MANAGERS),
             'scripts'         => ['js/notices.js'],
         ]);
     }
```


**`app/Views/dashboard/index.php`** (existing file: changes only)

```diff
@@ -6,6 +6,7 @@
  *
  * @var string|null $tenantName
  * @var bool        $canCreateNotice UI only; POST /api/notices enforces the rule.
+ * @var bool        $canManageNotices UI only; the /admin/notices routes enforce the rule.
  */
 ?>
 <section class="page-header">
@@ -13,9 +14,14 @@
         <h1 class="page-header__title">Mural de avisos</h1>
         <p class="page-header__subtitle">Comunicados oficiais de <?= e($tenantName) ?></p>
     </div>
-    <?php if ($canCreateNotice): ?>
-        <button type="button" class="btn btn--primary" id="open-notice-form">Novo aviso</button>
-    <?php endif; ?>
+    <div class="page-header__actions">
+        <?php if ($canManageNotices): ?>
+            <a class="btn" href="/admin/notices">Gerenciar avisos</a>
+        <?php endif; ?>
+        <?php if ($canCreateNotice): ?>
+            <button type="button" class="btn btn--primary" id="open-notice-form">Novo aviso</button>
+        <?php endif; ?>
+    </div>
 </section>
 
 <section id="notice-board" class="panel" aria-live="polite" aria-busy="true">
@@ -77,11 +83,16 @@
                     </select>
                     <span class="form__error" data-error-for="priority"></span>
                 </label>
-                <label class="form__check">
-                    <input type="checkbox" name="is_pinned" value="1">
-                    Fixar no topo do mural
+                <label class="form__field">
+                    <span class="form__label">Expira em (opcional)</span>
+                    <input type="datetime-local" name="expires_at">
+                    <span class="form__error" data-error-for="expires_at"></span>
                 </label>
             </div>
+            <label class="form__check">
+                <input type="checkbox" name="is_pinned" value="1">
+                Fixar no topo do mural
+            </label>
 
             <div class="dialog__actions">
                 <button type="button" class="btn" data-close>Cancelar</button>
```


**`app/Views/admin/notices/index.php`**

```php
<?php
/**
 * Every notice of the condominium, with its visibility state.
 *
 * @var list<array<string, mixed>> $notices
 * @var array{page: int, per_page: int, total: int, pages: int} $pagination
 */
$visibility = [
    'visible'   => ['active', 'Visível'],
    'scheduled' => ['pending', 'Agendado'],
    'expired'   => ['inactive', 'Expirado'],
    'archived'  => ['inactive', 'Arquivado'],
    'draft'     => ['inactive', 'Rascunho'],
];
$priorities = ['normal' => 'Normal', 'important' => 'Importante', 'urgent' => 'Urgente'];
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Avisos</h1>
        <p class="page-header__subtitle">Edite, fixe, defina a expiração ou exclua avisos do mural</p>
    </div>
    <a class="btn btn--primary" href="/dashboard">Publicar novo aviso</a>
</section>

<section class="panel">
    <?php if ($notices === []): ?>
        <p class="state">Nenhum aviso publicado ainda.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Título</th><th>Prioridade</th><th>Publicado em</th><th>Expira em</th><th>Autor</th><th>Situação</th><th class="table__action">Ações</th></tr>
                </thead>
                <tbody>
                <?php foreach ($notices as $notice): ?>
                    <?php [$variant, $label] = $visibility[$notice['visibility']] ?? ['inactive', (string) $notice['visibility']]; ?>
                    <tr>
                        <td class="strong">
                            <?= e($notice['title']) ?>
                            <?php if ((int) $notice['is_pinned'] === 1): ?><span class="tag">Fixado</span><?php endif; ?>
                        </td>
                        <td><?= e($priorities[$notice['priority']] ?? $notice['priority']) ?></td>
                        <td><?= e(local_datetime($notice['publish_at'])) ?></td>
                        <td><?= e($notice['expires_at'] === null ? '—' : local_datetime((string) $notice['expires_at'])) ?></td>
                        <td><?= e($notice['author_name'] ?? 'Administração') ?></td>
                        <td><?= pill($variant, $label) ?></td>
                        <td class="table__action">
                            <span class="inline-form">
                                <a class="btn btn--small" href="/admin/notices/<?= e($notice['id']) ?>/edit">Editar</a>
                                <form method="post" action="/admin/notices/<?= e($notice['id']) ?>/delete"
                                      data-confirm="Excluir o aviso &quot;<?= e($notice['title']) ?>&quot;? Esta ação não pode ser desfeita.">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn--small btn--danger-ghost">Excluir</button>
                                </form>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= partial('pagination', ['pagination' => $pagination, 'basePath' => '/admin/notices', 'query' => []]) ?>
</section>
```


**`app/Views/admin/notices/edit.php`**

```php
<?php
/**
 * @var array<string, mixed>  $notice
 * @var string                $expiresLocal datetime-local value in the condominium's zone ('' = none).
 * @var array<string, string> $errors
 */
$priorities = ['normal' => 'Normal', 'important' => 'Importante', 'urgent' => 'Urgente'];
?>
<section class="page-header">
    <div>
        <p class="page-header__eyebrow"><a href="/admin/notices">← Avisos</a></p>
        <h1 class="page-header__title">Editar aviso</h1>
    </div>
</section>

<section class="panel panel--padded panel--narrow">
    <form method="post" action="/admin/notices/<?= e($notice['id']) ?>" class="form" novalidate>
        <?= csrf_field() ?>
        <label class="form__field">
            <span class="form__label">Título</span>
            <input type="text" name="title" maxlength="150" required value="<?= e($notice['title']) ?>">
            <?= field_error($errors, 'title') ?>
        </label>
        <label class="form__field">
            <span class="form__label">Mensagem</span>
            <textarea name="body" rows="10" maxlength="10000" required><?= e($notice['body']) ?></textarea>
            <?= field_error($errors, 'body') ?>
        </label>
        <div class="form__row">
            <label class="form__field">
                <span class="form__label">Prioridade</span>
                <select name="priority">
                    <?php foreach ($priorities as $code => $label): ?>
                        <option value="<?= e($code) ?>"<?= $notice['priority'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="form__field">
                <span class="form__label">Expira em (opcional)</span>
                <input type="datetime-local" name="expires_at" value="<?= e($expiresLocal) ?>">
                <?= field_error($errors, 'expires_at') ?>
            </label>
        </div>
        <label class="form__check">
            <input type="checkbox" name="is_pinned" value="1"<?= (int) $notice['is_pinned'] === 1 ? ' checked' : '' ?>>
            Fixar no topo do mural
        </label>
        <div class="form__actions">
            <a class="btn" href="/admin/notices">Cancelar</a>
            <button type="submit" class="btn btn--primary">Salvar</button>
        </div>
    </form>
</section>
```


### 3.3 Property Manager: user management

- **`Member`** (`TenantModel` over `condominium_users`) lists and edits one tenant's people.
- **`Membership`** (global) keeps deciding which tenant a user may enter.
- **`Invitation`** is a global model with an explicit condominium id, because both the Property Manager (session tenant) and the Super Admin (URL tenant, validated) invite, and the invitee accepts while logged out.
- **`MemberService`** holds the record-dependent privilege rules: role allowlist, no self-change, and the last active manager with rows locked.

**`app/Models/Member.php`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Pagination;
use App\Core\TenantModel;

/**
 * The current condominium's members, as seen by its Property Manager (Phase 5).
 *
 * Same table as Membership (`condominium_users`), different job:
 *  - Membership (global Model) decides WHICH tenant a user may enter, filtered by
 *    user_id, before a TenantContext exists.
 *  - Member (TenantModel) lists and edits the people of ONE tenant. Every query
 *    is "condominium_id = :tenant", so a user id from another condominium is
 *    simply not found and the controller answers 404.
 *
 * Global identity columns (users.full_name, users.email, password) are only
 * read here, never written: a user may belong to several condominiums, and a
 * manager of one must not be able to change an account that another uses.
 */
final class Member extends TenantModel
{
    public const STATUS_FILTERS = ['active', 'inactive', 'invited'];

    protected string $table = 'condominium_users';

    /**
     * One page of members matching the filters.
     *
     * @param array{q?: string, role?: string, unit_id?: int, status?: string} $filters Already validated.
     * @return list<array<string, mixed>>
     */
    public function search(array $filters, Pagination $pagination): array
    {
        [$where, $params] = $this->filterSql($filters);

        return $this->fetchAll(
            "SELECT cu.user_id, cu.status, cu.created_at, cu.approved_at, cu.deactivated_at,
                    r.code AS role_code, us.full_name, us.email, us.status AS user_status, us.last_login_at
               FROM condominium_users cu
               JOIN users us ON us.id = cu.user_id
               JOIN roles r  ON r.id = cu.role_id
              WHERE {$where}
              ORDER BY cu.status = 'inactive', us.full_name, cu.user_id
              LIMIT :limit OFFSET :offset",
            $params + ['limit' => $pagination->limit(), 'offset' => $pagination->offset()]
        );
    }

    /** @param array{q?: string, role?: string, unit_id?: int, status?: string} $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->filterSql($filters);
        $row = $this->fetchOne(
            "SELECT COUNT(*) AS total
               FROM condominium_users cu
               JOIN users us ON us.id = cu.user_id
               JOIN roles r  ON r.id = cu.role_id
              WHERE {$where}",
            $params
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * One member of the current condominium by USER id, or null (also for users
     * who belong only to other condominiums: same 404 as a missing id).
     *
     * @return array<string, mixed>|null
     */
    public function findByUser(int $userId): ?array
    {
        return $this->fetchOne(
            'SELECT cu.*, r.code AS role_code, us.full_name, us.email, us.status AS user_status
               FROM condominium_users cu
               JOIN users us ON us.id = cu.user_id
               JOIN roles r  ON r.id = cu.role_id
              WHERE cu.condominium_id = :tenant AND cu.user_id = :user_id',
            $this->scoped(['user_id' => $userId])
        );
    }

    /**
     * Locks the membership row (no joins, so the shared roles/users rows are not
     * locked) and returns it with its role code.
     *
     * @return array<string, mixed>|null
     */
    public function lockByUser(int $userId): ?array
    {
        $row = $this->fetchOne(
            'SELECT * FROM condominium_users WHERE condominium_id = :tenant AND user_id = :user_id FOR UPDATE',
            $this->scoped(['user_id' => $userId])
        );
        if ($row === null) {
            return null;
        }
        $row['role_code'] = (new Role())->codeById((int) $row['role_id']);

        return $row;
    }

    /**
     * Locks every ACTIVE manager membership of the tenant and returns how many
     * there are.
     *
     * The "last active Property Manager" rule is checked with these rows locked:
     * two managers deactivating each other at the same moment are serialised,
     * and the second one sees that only one manager is left.
     */
    public function lockActiveManagerCount(int $managerRoleId): int
    {
        $rows = $this->fetchAll(
            "SELECT id FROM condominium_users
              WHERE condominium_id = :tenant AND role_id = :role_id AND status = 'active'
              FOR UPDATE",
            $this->scoped(['role_id' => $managerRoleId])
        );

        return count($rows);
    }

    public function changeRole(int $userId, int $roleId): void
    {
        $this->execute(
            'UPDATE condominium_users SET role_id = :role_id WHERE condominium_id = :tenant AND user_id = :user_id',
            $this->scoped(['role_id' => $roleId, 'user_id' => $userId])
        );
    }

    /** Deactivation keeps the row (history: posts, invoices, occurrences reference it). */
    public function deactivate(int $userId, int $actorId): void
    {
        $this->execute(
            "UPDATE condominium_users
                SET status = 'inactive', deactivated_at = UTC_TIMESTAMP(), deactivated_by_user_id = :actor
              WHERE condominium_id = :tenant AND user_id = :user_id",
            $this->scoped(['actor' => $actorId, 'user_id' => $userId])
        );
    }

    /**
     * Reactivates a membership. An account that never accepted its invitation
     * (no verified e-mail yet) goes back to 'invited' instead of 'active', so it
     * still needs an invitation link to get in.
     */
    public function reactivate(int $userId, int $actorId, bool $accountReady): void
    {
        $this->execute(
            "UPDATE condominium_users
                SET status = IF(:ready = 1, 'active', 'invited'),
                    approved_at = COALESCE(approved_at, UTC_TIMESTAMP()),
                    approved_by_user_id = COALESCE(approved_by_user_id, :actor),
                    deactivated_at = NULL,
                    deactivated_by_user_id = NULL
              WHERE condominium_id = :tenant AND user_id = :user_id",
            $this->scoped(['ready' => $accountReady ? 1 : 0, 'actor' => $actorId, 'user_id' => $userId])
        );
    }

    /**
     * Active member counts by role for the current tenant (admin overview).
     *
     * @return array<string, int>
     */
    public function activeCountsByRole(): array
    {
        $rows = $this->fetchAll(
            "SELECT r.code, COUNT(*) AS total
               FROM condominium_users cu JOIN roles r ON r.id = cu.role_id
              WHERE cu.condominium_id = :tenant AND cu.status = 'active'
              GROUP BY r.code",
            $this->scoped()
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['code']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * WHERE clause from fixed fragments; values are bound.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filterSql(array $filters): array
    {
        $conditions = ['cu.condominium_id = :tenant'];   // tenant scope, always first
        $params = [];

        if (isset($filters['q']) && $filters['q'] !== '') {
            $conditions[] = '(us.full_name LIKE :q_name OR us.email LIKE :q_email)';
            $params['q_name'] = self::likeContains($filters['q']);
            $params['q_email'] = self::likeContains($filters['q']);
        }
        if (isset($filters['role'])) {
            $conditions[] = 'r.code = :role';
            $params['role'] = $filters['role'];
        }
        if (isset($filters['status'])) {
            $conditions[] = 'cu.status = :status';
            $params['status'] = $filters['status'];
        }
        if (isset($filters['unit_id'])) {
            // The unit filter is itself tenant-scoped: a unit id of another
            // condominium matches no unit_residents row here.
            $conditions[] = 'EXISTS (SELECT 1 FROM unit_residents ur
                                      WHERE ur.condominium_id = cu.condominium_id
                                        AND ur.user_id = cu.user_id
                                        AND ur.unit_id = :unit_id
                                        AND ur.move_out_date IS NULL)';
            $params['unit_id'] = $filters['unit_id'];
        }

        return [implode(' AND ', $conditions), $this->scoped($params)];
    }
}
```


**`app/Models/Invitation.php`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Invitation tokens `invitations` (migration 0003).
 *
 * This model extends the global Model, not TenantModel, on purpose: invitations
 * are created both by a Property Manager (tenant = the session's condominium)
 * and by the Super Admin (tenant = a condominium chosen on the platform screen,
 * validated to exist), and they are accepted by a logged-out invitee, when no
 * TenantContext exists. Every method therefore takes the condominium id
 * explicitly, and InvitationService documents where each caller's id comes from.
 */
final class Invitation extends Model
{
    protected string $table = 'invitations';

    /** Stores a new invitation token valid for $ttlHours. */
    public function create(int $condominiumId, int $userId, string $tokenHash, int $invitedBy, string $ip, int $ttlHours): int
    {
        $this->execute(
            'INSERT INTO invitations (condominium_id, user_id, token_hash, expires_at, invited_by_user_id, request_ip)
             VALUES (:condominium_id, :user_id, :token_hash, UTC_TIMESTAMP() + INTERVAL :ttl HOUR, :invited_by, INET6_ATON(:ip))',
            [
                'condominium_id' => $condominiumId,
                'user_id'        => $userId,
                'token_hash'     => $tokenHash,
                'ttl'            => $ttlHours,
                'invited_by'     => $invitedBy,
                'ip'             => $ip,
            ]
        );

        return (int) $this->db()->lastInsertId();
    }

    /**
     * Finds an invitation by token hash, with what the landing page needs
     * (condominium, invitee, membership state). Read-only.
     *
     * @return array<string, mixed>|null
     */
    public function findByHash(string $tokenHash): ?array
    {
        return $this->fetchOne(
            'SELECT i.*, (i.expires_at <= UTC_TIMESTAMP()) AS is_expired,
                    c.name AS condominium_name, c.status AS condominium_status,
                    u.email, u.full_name, u.status AS user_status, (u.password_hash IS NULL) AS needs_password,
                    cu.status AS membership_status, r.code AS role_code
               FROM invitations i
               JOIN condominiums c       ON c.id = i.condominium_id
               JOIN users u              ON u.id = i.user_id
               JOIN condominium_users cu ON cu.condominium_id = i.condominium_id AND cu.user_id = i.user_id
               JOIN roles r              ON r.id = cu.role_id
              WHERE i.token_hash = :token_hash',
            ['token_hash' => $tokenHash]
        );
    }

    /**
     * Locks ONLY the invitation row (no join, so no lock is taken on the shared
     * roles/condominiums rows) until the transaction ends. Two simultaneous
     * "accept" clicks are serialised here and the second sees consumed_at set.
     *
     * @return array<string, mixed>|null
     */
    public function lockByHash(string $tokenHash): ?array
    {
        return $this->fetchOne(
            'SELECT i.*, (i.expires_at <= UTC_TIMESTAMP()) AS is_expired
               FROM invitations i
              WHERE i.token_hash = :token_hash
              FOR UPDATE',
            ['token_hash' => $tokenHash]
        );
    }

    /**
     * Latest invitation of each of the given memberships (for the user list:
     * "pending" vs "expired" badge).
     *
     * @param list<int> $userIds
     * @return array<int, array{expires_at: string, is_expired: int}> keyed by user id
     */
    public function latestForUsers(int $condominiumId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        [$placeholders, $params] = $this->inList('user', $userIds);
        $rows = $this->fetchAll(
            "SELECT i.user_id, i.expires_at, (i.expires_at <= UTC_TIMESTAMP()) AS is_expired
               FROM invitations i
               JOIN (SELECT user_id, MAX(id) AS last_id
                       FROM invitations
                      WHERE condominium_id = :condominium_a AND user_id IN ({$placeholders})
                      GROUP BY user_id) latest ON latest.last_id = i.id
              WHERE i.condominium_id = :condominium_b",
            ['condominium_a' => $condominiumId, 'condominium_b' => $condominiumId] + $params
        );

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[(int) $row['user_id']] = ['expires_at' => (string) $row['expires_at'], 'is_expired' => (int) $row['is_expired']];
        }

        return $byUser;
    }

    /** Marks an invitation as accepted (single use). */
    public function markConsumed(int $id): void
    {
        $this->execute(
            'UPDATE invitations SET consumed_at = UTC_TIMESTAMP() WHERE id = :id AND consumed_at IS NULL',
            ['id' => $id]
        );
    }

    /** Revokes the still-usable invitations of one membership (resend, cancel, accept). */
    public function revokeOutstanding(int $condominiumId, int $userId): void
    {
        $this->execute(
            'UPDATE invitations SET revoked_at = UTC_TIMESTAMP()
              WHERE condominium_id = :condominium_id AND user_id = :user_id
                AND consumed_at IS NULL AND revoked_at IS NULL',
            ['condominium_id' => $condominiumId, 'user_id' => $userId]
        );
    }

    /** Seconds since the membership's latest invitation, or null. */
    public function secondsSinceLast(int $condominiumId, int $userId): ?int
    {
        $row = $this->fetchOne(
            'SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), UTC_TIMESTAMP()) AS seconds
               FROM invitations WHERE condominium_id = :condominium_id AND user_id = :user_id',
            ['condominium_id' => $condominiumId, 'user_id' => $userId]
        );

        return ($row === null || $row['seconds'] === null) ? null : (int) $row['seconds'];
    }

    /** Invitations e-mailed for this membership in the last $hours hours. */
    public function countSince(int $condominiumId, int $userId, int $hours): int
    {
        $row = $this->fetchOne(
            'SELECT COUNT(*) AS total FROM invitations
              WHERE condominium_id = :condominium_id AND user_id = :user_id
                AND created_at > UTC_TIMESTAMP() - INTERVAL :hours HOUR',
            ['condominium_id' => $condominiumId, 'user_id' => $userId, 'hours' => $hours]
        );

        return (int) ($row['total'] ?? 0);
    }
}
```


**`app/Models/Role.php`** (existing file: changes only)

```diff
@@ -20,4 +20,30 @@ final class Role extends Model
 
         return $row === null ? null : (int) $row['id'];
     }
+
+    public function codeById(int $id): ?string
+    {
+        $row = $this->fetchOne('SELECT code FROM roles WHERE id = :id', ['id' => $id]);
+
+        return $row === null ? null : (string) $row['code'];
+    }
+
+    /**
+     * Role ids keyed by code, limited to the given codes (an allowlist written
+     * in code). Used to turn a submitted role code into an id safely.
+     *
+     * @param list<string> $codes
+     * @return array<string, int>
+     */
+    public function idsByCodes(array $codes): array
+    {
+        $ids = [];
+        foreach ($this->fetchAll('SELECT id, code FROM roles ORDER BY id') as $row) {
+            if (in_array($row['code'], $codes, true)) {
+                $ids[(string) $row['code']] = (int) $row['id'];
+            }
+        }
+
+        return $ids;
+    }
 }
```


**`app/Models/UnitResident.php`** (existing file: changes only)

```diff
@@ -61,4 +61,81 @@ final class UnitResident extends TenantModel
             $this->scoped(['unit_id' => $unitId, 'today' => TenantContext::today()])
         );
     }
+
+    /**
+     * Makes $unitId the user's only current unit in this condominium (Phase 5,
+     * user management). Other current links get move_out_date = today (rows are
+     * kept for history); an earlier link to the same unit is re-opened instead
+     * of duplicated (UNIQUE condominium_id, unit_id, user_id). $unitId null
+     * just closes every current link.
+     *
+     * The unit must already have been checked to belong to the tenant
+     * (Unit::findActive); the composite FK would reject it otherwise anyway.
+     */
+    public function assign(int $userId, ?int $unitId, string $relationship): void
+    {
+        $today = TenantContext::today();
+        $this->execute(
+            'UPDATE unit_residents
+                SET move_out_date = :today
+              WHERE condominium_id = :tenant AND user_id = :user_id
+                AND move_out_date IS NULL
+                AND (:unit_id IS NULL OR unit_id <> :unit_id_b)',
+            $this->scoped(['today' => $today, 'user_id' => $userId, 'unit_id' => $unitId, 'unit_id_b' => $unitId])
+        );
+        if ($unitId === null) {
+            return;
+        }
+
+        $this->execute(
+            'INSERT INTO unit_residents (condominium_id, unit_id, user_id, relationship, is_billing_contact, move_in_date)
+             VALUES (:tenant, :unit_id, :user_id, :relationship, :billing, :today)
+             ON DUPLICATE KEY UPDATE relationship = VALUES(relationship),
+                                     is_billing_contact = VALUES(is_billing_contact),
+                                     move_out_date = NULL',
+            $this->scoped([
+                'unit_id'      => $unitId,
+                'user_id'      => $userId,
+                'relationship' => $relationship,
+                'billing'      => $relationship === 'dependent' ? 0 : 1,
+                'today'        => $today,
+            ])
+        );
+    }
+
+    /**
+     * Current unit and relationship of each given member (one unit shown per
+     * person in the admin list; the most recent link wins).
+     *
+     * @param list<int> $userIds
+     * @return array<int, array{unit_id: int, unit_label: string, relationship: string}>
+     */
+    public function currentUnits(array $userIds): array
+    {
+        if ($userIds === []) {
+            return [];
+        }
+        [$placeholders, $params] = $this->inList('user', $userIds);
+        $rows = $this->fetchAll(
+            "SELECT ur.user_id, ur.unit_id, ur.relationship, " . Unit::LABEL_SQL . " AS unit_label
+               FROM unit_residents ur
+               JOIN units u ON u.condominium_id = ur.condominium_id AND u.id = ur.unit_id
+              WHERE ur.condominium_id = :tenant
+                AND ur.user_id IN ({$placeholders})
+                AND (ur.move_out_date IS NULL OR ur.move_out_date >= :today)
+              ORDER BY ur.id",
+            $this->scoped(['today' => TenantContext::today()] + $params)
+        );
+
+        $byUser = [];
+        foreach ($rows as $row) {
+            $byUser[(int) $row['user_id']] = [
+                'unit_id'      => (int) $row['unit_id'],
+                'unit_label'   => (string) $row['unit_label'],
+                'relationship' => (string) $row['relationship'],
+            ];
+        }
+
+        return $byUser;
+    }
 }
```


**`app/Services/InvitationService.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\TenantContext;
use App\Mail\Mailer;
use App\Models\Condominium;
use App\Models\EmailVerificationToken;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Unit;
use App\Models\UnitResident;
use App\Models\User;
use LogicException;
use PDOException;

/**
 * Invitation lifecycle (Phase 5): invite, resend, accept.
 *
 * WHERE THE CONDOMINIUM ID COMES FROM. Every public method takes it explicitly:
 *  - Admin\UserController passes TenantContext::id(), i.e. the session's
 *    condominium re-validated by TenantMiddleware. Never a request value.
 *  - Platform\CondominiumController passes the id from the URL AFTER loading the
 *    condominium (Condominium::find) and checking it exists; that Super Admin
 *    action is audited with the condominium id.
 *  - accept() takes no id at all: it comes from the invitation row the token
 *    points to.
 *
 * PRIVILEGE: the role is a role CODE checked against ASSIGNABLE_ROLES here, and
 * the controllers narrow it further (a Property Manager's allowlist, the
 * platform's "manager only"). A Super Admin can never be created through an
 * invitation: that is users.is_super_admin, which no code path here writes.
 *
 * A new e-mail gets a users row in status 'pending_verification' with no
 * password. An e-mail that already has an account gets only the membership: the
 * invitation never touches the existing account's name or password.
 */
final class InvitationService
{
    /** Every role an invitation can grant. */
    public const ASSIGNABLE_ROLES = ['manager', 'concierge', 'resident'];

    public const RELATIONSHIPS = ['owner', 'tenant', 'dependent'];

    public const ACCEPT_VALID = 'valid';
    public const ACCEPT_EXPIRED = 'expired';
    public const ACCEPT_USED = 'already_used';
    public const ACCEPT_INVALID = 'invalid';
    public const ACCEPT_PASSWORD_REQUIRED = 'password_required';

    public function __construct(
        private readonly Request $request,
        private readonly User $users = new User(),
        private readonly Membership $memberships = new Membership(),
        private readonly Invitation $invitations = new Invitation(),
        private readonly Role $roles = new Role(),
        private readonly TokenService $tokenService = new TokenService(),
        private readonly RateLimiter $limiter = new RateLimiter(),
        private readonly Mailer $mailer = new Mailer()
    ) {
    }

    /**
     * Invites $email into a condominium with a role (and, for residents, a unit).
     *
     * One transaction: user (if new) + membership + unit link + invitation +
     * audit entry. The e-mail is sent after the commit.
     *
     * @param int|null    $unitId       Must be a unit of $condominiumId; only allowed in a
     *                                  tenant request (TenantContext::id() === $condominiumId).
     * @return array{user_id: int, email_sent: bool}
     * @throws BusinessRuleException 409 when the e-mail already belongs to this condominium.
     */
    public function invite(
        int $condominiumId,
        string $email,
        string $fullName,
        string $roleCode,
        ?int $unitId,
        ?string $relationship,
        int $actorId
    ): array {
        if (!in_array($roleCode, self::ASSIGNABLE_ROLES, true)) {
            throw new LogicException('Role is not assignable through an invitation.');
        }
        if ($unitId !== null && (!TenantContext::has() || TenantContext::id() !== $condominiumId)) {
            // Unit links use the tenant-scoped UnitResident model; refuse rather than
            // write a link under a tenant the request is not operating on.
            throw new LogicException('A unit can only be linked inside its own tenant context.');
        }
        $roleId = $this->roles->idByCode($roleCode) ?? throw new LogicException("Role {$roleCode} missing.");
        $condominium = (new Condominium())->find($condominiumId) ?? throw new BusinessRuleException('Condomínio não encontrado.', 404);
        $email = User::normalizeEmail($email);
        ['raw' => $raw, 'hash' => $hash] = $this->tokenService->generate();

        try {
            $user = Database::transaction(function () use (
                $condominiumId, $email, $fullName, $roleId, $roleCode, $unitId, $relationship, $actorId, $hash
            ): array {
                $user = $this->users->findByEmail($email);
                $isNew = $user === null;
                if ($isNew) {
                    $userId = $this->users->insert([
                        'full_name'     => $fullName,
                        'email'         => $email,
                        'password_hash' => null,
                        'status'        => 'pending_verification',
                    ]);
                    $user = (array) $this->users->find($userId);
                } elseif (!in_array($user['status'], ['active', 'pending_verification'], true)) {
                    // Blocked/deleted accounts cannot be revived by an invitation.
                    throw new BusinessRuleException('Não foi possível convidar este e-mail.', 409, 'email');
                }
                $userId = (int) $user['id'];

                if ($this->memberships->exists($userId, $condominiumId)) {
                    throw new BusinessRuleException(
                        'Este e-mail já está vinculado a este condomínio. Reative ou reenvie o convite pela lista de usuários.',
                        409,
                        'email'
                    );
                }

                $this->memberships->createInvited($condominiumId, $userId, $roleId);
                if ($unitId !== null) {
                    (new UnitResident())->assign($userId, $unitId, $relationship ?? 'owner');
                }
                $this->invitations->create(
                    $condominiumId,
                    $userId,
                    $hash,
                    $actorId,
                    $this->request->ip(),
                    (int) Config::get('security.invitation_ttl_hours', 72)
                );

                (new AuditLogger($this->request))->record('invitation.created', $actorId, $condominiumId, 'user', $userId, [
                    'role'        => $roleCode,
                    'unit_id'     => $unitId,
                    'new_account' => $isNew,
                ]);

                return $user;
            });
        } catch (PDOException $e) {
            // 1062: the same e-mail was invited by a concurrent request.
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new BusinessRuleException('Este e-mail acabou de ser convidado. Atualize a lista.', 409, 'email');
            }
            throw $e;
        }

        $sent = $this->sendEmail($user, (string) $condominium['name'], $roleCode, $raw);

        return ['user_id' => (int) $user['id'], 'email_sent' => $sent];
    }

    /**
     * E-mails a fresh invitation link (the previous one stops working).
     *
     * Throttled three ways: per actor and per IP (database rate limiter) and per
     * invitation (cooldown + daily cap), so a manager's account cannot be used
     * to flood someone's inbox.
     *
     * @throws BusinessRuleException 404 not a member, 409 not pending, 429 throttled.
     */
    public function resend(int $condominiumId, int $userId, int $actorId): bool
    {
        if (!$this->limiter->attempt('invitation_resend', ['ip' => $this->request->ip(), 'account' => (string) $actorId])) {
            throw new BusinessRuleException('Muitos reenvios em pouco tempo. Aguarde alguns minutos.', 429);
        }
        $cooldown = (int) Config::get('security.invitation_resend_cooldown', 60);
        $maxPerDay = (int) Config::get('security.invitation_max_per_day', 5);
        $sinceLast = $this->invitations->secondsSinceLast($condominiumId, $userId);
        if (($sinceLast !== null && $sinceLast < $cooldown) || $this->invitations->countSince($condominiumId, $userId, 24) >= $maxPerDay) {
            throw new BusinessRuleException('Este convite foi enviado há pouco tempo ou atingiu o limite diário de envios.', 429);
        }

        $condominium = (new Condominium())->find($condominiumId) ?? throw new BusinessRuleException('Condomínio não encontrado.', 404);
        ['raw' => $raw, 'hash' => $hash] = $this->tokenService->generate();

        [$user, $roleCode] = Database::transaction(function () use ($condominiumId, $userId, $actorId, $hash): array {
            $membership = $this->memberships->lockFor($condominiumId, $userId)
                ?? throw new BusinessRuleException('Usuário não encontrado.', 404);
            if ($membership['status'] !== 'invited') {
                throw new BusinessRuleException('Este usuário não tem convite pendente.', 409);
            }

            $this->invitations->revokeOutstanding($condominiumId, $userId);
            $this->invitations->create(
                $condominiumId,
                $userId,
                $hash,
                $actorId,
                $this->request->ip(),
                (int) Config::get('security.invitation_ttl_hours', 72)
            );
            (new AuditLogger($this->request))->record('invitation.resent', $actorId, $condominiumId, 'user', $userId);

            return [(array) $this->users->find($userId), (string) $this->roles->codeById((int) $membership['role_id'])];
        });

        return $this->sendEmail($user, (string) $condominium['name'], $roleCode, $raw);
    }

    /**
     * Read-only view of a token for the GET landing page.
     *
     * @return array{state: string, invitation: array<string, mixed>|null}
     */
    public function inspect(string $rawToken): array
    {
        if (!$this->tokenService->isWellFormed($rawToken)) {
            return ['state' => self::ACCEPT_INVALID, 'invitation' => null];
        }
        $invitation = $this->invitations->findByHash($this->tokenService->hash($rawToken));

        return ['state' => $this->evaluate($invitation, $rawToken), 'invitation' => $invitation];
    }

    /**
     * Accepts an invitation.
     *
     * For a NEW account the invitee chooses a password here, and the e-mail is
     * marked verified through the Phase 2 logic (User::markEmailVerified, the
     * same call EmailVerificationService::verify() makes): receiving the token
     * proves control of the address. An EXISTING account keeps its password;
     * $passwordHash is ignored for it, so this page can never reset a password.
     *
     * @param string|null $passwordHash Already validated and hashed; required for new accounts.
     * @return string One of the ACCEPT_* constants.
     */
    public function accept(string $rawToken, ?string $passwordHash): string
    {
        if (!$this->tokenService->isWellFormed($rawToken)) {
            return self::ACCEPT_INVALID;
        }
        if (!$this->limiter->attempt('invitation_accept', ['ip' => $this->request->ip()])) {
            return self::ACCEPT_INVALID;
        }

        return Database::transaction(function () use ($rawToken, $passwordHash): string {
            // Lock order: invitation → membership → user (always the same order,
            // so concurrent accepts cannot deadlock).
            $invitation = $this->invitations->lockByHash($this->tokenService->hash($rawToken));
            if ($invitation === null || !$this->tokenService->matches($rawToken, (string) $invitation['token_hash'])) {
                return self::ACCEPT_INVALID;
            }
            $condominiumId = (int) $invitation['condominium_id'];
            $userId = (int) $invitation['user_id'];
            $membership = $this->memberships->lockFor($condominiumId, $userId);
            $user = $this->users->findForUpdate($userId);
            $condominium = (new Condominium())->find($condominiumId);

            $context = $invitation + [
                'membership_status'  => $membership['status'] ?? null,
                'user_status'        => $user['status'] ?? null,
                'condominium_status' => $condominium['status'] ?? null,
            ];
            $state = $this->evaluate($context, $rawToken);
            if ($state !== self::ACCEPT_VALID) {
                return $state;
            }

            $isNewAccount = $user['password_hash'] === null;
            if ($isNewAccount && $passwordHash === null) {
                return self::ACCEPT_PASSWORD_REQUIRED;
            }

            $this->invitations->markConsumed((int) $invitation['id']);
            $this->invitations->revokeOutstanding($condominiumId, $userId);
            $this->memberships->activateInvited($condominiumId, $userId, (int) $invitation['invited_by_user_id']);

            if ($user['email_verified_at'] === null || $user['status'] !== 'active') {
                // Phase 2 verification logic, reused: verified + active (+ password
                // for a new account; COALESCE keeps an existing one).
                $this->users->markEmailVerified($userId, $isNewAccount ? $passwordHash : null);
                (new EmailVerificationToken())->revokeOutstanding($userId);
            }

            (new AuditLogger($this->request))->record('invitation.accepted', $userId, $condominiumId, 'user', $userId, [
                'new_account' => $isNewAccount,
            ]);

            return self::ACCEPT_VALID;
        });
    }

    /** @param array<string, mixed>|null $invitation */
    private function evaluate(?array $invitation, string $rawToken): string
    {
        if ($invitation === null || !$this->tokenService->matches($rawToken, (string) $invitation['token_hash'])) {
            return self::ACCEPT_INVALID;
        }
        if ($invitation['consumed_at'] !== null) {
            return self::ACCEPT_USED;
        }
        if ($invitation['revoked_at'] !== null
            || $invitation['membership_status'] !== 'invited'
            || !in_array($invitation['user_status'], ['active', 'pending_verification'], true)
            || $invitation['condominium_status'] !== 'active'
        ) {
            return self::ACCEPT_INVALID;
        }

        return (int) $invitation['is_expired'] === 1 ? self::ACCEPT_EXPIRED : self::ACCEPT_VALID;
    }

    /** @param array<string, mixed> $user */
    private function sendEmail(array $user, string $condominiumName, string $roleCode, string $rawToken): bool
    {
        $inviter = Auth::user();

        return $this->mailer->sendInvitation(
            (string) $user['email'],
            (string) $user['full_name'],
            $condominiumName,
            Auth::labelFor($roleCode),
            (string) ($inviter['full_name'] ?? 'A administração'),
            $rawToken,
            $user['password_hash'] === null
        );
    }
}
```


**`app/Services/MemberService.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Models\Invitation;
use App\Models\Member;
use App\Models\Role;
use App\Models\UnitResident;
use LogicException;

/**
 * A Property Manager's changes to the members of THEIR condominium (Phase 5):
 * role, unit, deactivation and reactivation.
 *
 * Every model used here is tenant-scoped (TenantModel) except Invitation, which
 * receives TenantContext::id() through $condominiumId. The caller has already
 * checked the "manager" role; this class enforces the privilege rules that
 * depend on the target record:
 *
 *  - The new role must be in MANAGEABLE_ROLES (an allowlist in code, compared
 *    as role CODES). A Super Admin is not a role and can never be granted.
 *  - Nobody changes their own role or deactivates themselves (a manager could
 *    otherwise demote the last manager by accident, or escape the audit trail).
 *  - The last active Property Manager can be neither demoted nor deactivated:
 *    the condominium would be left without anyone able to manage it. This is
 *    checked with the managers' rows locked (Member::lockActiveManagerCount).
 */
final class MemberService
{
    /** Roles a Property Manager may grant (other managers of the same condominium included). */
    public const MANAGEABLE_ROLES = ['manager', 'concierge', 'resident'];

    public function __construct(
        private readonly Request $request,
        private readonly int $condominiumId,
        private readonly Member $members = new Member(),
        private readonly Role $roles = new Role(),
        private readonly UnitResident $unitResidents = new UnitResident(),
        private readonly Invitation $invitations = new Invitation()
    ) {
    }

    /**
     * Changes a member's role and/or unit.
     *
     * @param int|null $unitId Already validated by the caller to be an active unit of this tenant.
     * @throws BusinessRuleException
     */
    public function update(int $userId, string $roleCode, ?int $unitId, string $relationship, int $actorId): void
    {
        if (!in_array($roleCode, self::MANAGEABLE_ROLES, true)) {
            throw new BusinessRuleException('Perfil inválido.', 422, 'role');
        }
        if ($roleCode === 'resident' && $unitId === null) {
            throw new BusinessRuleException('Moradores precisam de uma unidade.', 422, 'unit_id');
        }
        $roleIds = $this->roles->idsByCodes(self::MANAGEABLE_ROLES);

        Database::transaction(function () use ($userId, $roleCode, $unitId, $relationship, $actorId, $roleIds): void {
            $member = $this->members->lockByUser($userId) ?? throw new BusinessRuleException('Usuário não encontrado.', 404);
            $audit = new AuditLogger($this->request);

            if ($member['role_code'] !== $roleCode) {
                if ($userId === $actorId) {
                    throw new BusinessRuleException('Você não pode alterar o seu próprio perfil.', 403, 'role');
                }
                if (!in_array($member['role_code'], self::MANAGEABLE_ROLES, true)) {
                    throw new LogicException('Unexpected role on a membership.');
                }
                if ($member['role_code'] === 'manager' && $member['status'] === 'active') {
                    $this->guardLastManager($roleIds['manager']);
                }
                $this->members->changeRole($userId, $roleIds[$roleCode]);
                $audit->tenant('member.role_changed', 'user', $userId, ['from' => $member['role_code'], 'to' => $roleCode]);
            }

            $current = $this->unitResidents->currentUnits([$userId])[$userId] ?? null;
            if (($current['unit_id'] ?? null) !== $unitId || ($unitId !== null && ($current['relationship'] ?? null) !== $relationship)) {
                $this->unitResidents->assign($userId, $unitId, $relationship);
                $audit->tenant('member.unit_changed', 'user', $userId, [
                    'from_unit_id' => $current['unit_id'] ?? null,
                    'to_unit_id'   => $unitId,
                    'relationship' => $unitId === null ? null : $relationship,
                ]);
            }
        });
    }

    /**
     * Deactivates a membership (or cancels a pending invitation). History is
     * kept; the person's session ends on their next request (Auth/TenantMiddleware).
     *
     * @throws BusinessRuleException
     */
    public function deactivate(int $userId, int $actorId): void
    {
        if ($userId === $actorId) {
            throw new BusinessRuleException('Você não pode desativar a sua própria conta.', 403);
        }
        $managerRoleId = $this->roles->idByCode('manager') ?? throw new LogicException('Role manager missing.');

        Database::transaction(function () use ($userId, $actorId, $managerRoleId): void {
            $member = $this->members->lockByUser($userId) ?? throw new BusinessRuleException('Usuário não encontrado.', 404);
            if ($member['status'] === 'inactive') {
                throw new BusinessRuleException('Este usuário já está desativado.', 409);
            }
            if (!in_array($member['status'], ['active', 'invited'], true)) {
                throw new BusinessRuleException('Este cadastro não pode ser desativado por aqui.', 409);
            }
            if ($member['role_code'] === 'manager' && $member['status'] === 'active') {
                $this->guardLastManager($managerRoleId);
            }

            $this->members->deactivate($userId, $actorId);
            // A pending invitation link must stop working too.
            $this->invitations->revokeOutstanding($this->condominiumId, $userId);
            (new AuditLogger($this->request))->tenant('member.deactivated', 'user', $userId, [
                'role'            => $member['role_code'],
                'previous_status' => $member['status'],
            ]);
        });
    }

    /**
     * Reactivates a membership. Returns the new status ('active', or 'invited'
     * when the account was never activated and needs a new invitation).
     *
     * @throws BusinessRuleException
     */
    public function reactivate(int $userId, int $actorId): string
    {
        return Database::transaction(function () use ($userId, $actorId): string {
            $member = $this->members->lockByUser($userId) ?? throw new BusinessRuleException('Usuário não encontrado.', 404);
            if ($member['status'] !== 'inactive') {
                throw new BusinessRuleException('Este usuário não está desativado.', 409);
            }
            $account = $this->members->findByUser($userId);
            // A globally blocked/deleted account stays out, whatever this tenant decides.
            if (!in_array($account['user_status'] ?? null, ['active', 'pending_verification'], true)) {
                throw new BusinessRuleException('Esta conta está bloqueada na plataforma. Fale com o suporte.', 409);
            }
            $ready = $account['user_status'] === 'active';

            $this->members->reactivate($userId, $actorId, $ready);
            (new AuditLogger($this->request))->tenant('member.reactivated', 'user', $userId, ['account_ready' => $ready]);

            return $ready ? 'active' : 'invited';
        });
    }

    /** @throws BusinessRuleException 409 when only one active manager is left. */
    private function guardLastManager(int $managerRoleId): void
    {
        if ($this->members->lockActiveManagerCount($managerRoleId) <= 1) {
            throw new BusinessRuleException(
                'Este é o último síndico ativo do condomínio. Convide ou promova outro síndico antes.',
                409
            );
        }
    }
}
```


**`app/Controllers/Admin/UserController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Invitation;
use App\Models\Member;
use App\Models\Unit;
use App\Models\UnitResident;
use App\Services\BusinessRuleException;
use App\Services\InvitationService;
use App\Services\MemberService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * User management of the current condominium (Property Manager, Phase 5).
 *
 * {id} in these routes is a USER id. It is resolved with Member::findByUser(),
 * which is tenant-scoped: the id of someone who belongs only to another
 * condominium answers 404, exactly like an id that does not exist.
 */
final class UserController extends AdminController
{
    public const RELATIONSHIP_LABELS = ['owner' => 'Proprietário', 'tenant' => 'Inquilino', 'dependent' => 'Dependente'];

    private const KEEP = ['full_name', 'email', 'role', 'unit_id', 'relationship'];

    /** GET /admin/users: the list is filled by users.js from GET /admin/users/search. */
    public function index(): Response
    {
        $this->requireRole(self::MANAGERS);

        return $this->view('admin/users/index', [
            'title'         => 'Usuários',
            'activeNav'     => 'admin-users',
            'units'         => (new Unit())->active(),
            'roles'         => self::roleOptions(),
            'relationships' => self::RELATIONSHIP_LABELS,
            'scripts'       => ['js/admin/users.js'],
        ]);
    }

    /**
     * GET /admin/users/search?q=&role=&unit_id=&status=&page= (JSON).
     * Every filter is validated against an allowlist; anything else is ignored.
     */
    public function search(): Response
    {
        $this->requireRole(self::MANAGERS);

        $filters = [];
        $q = mb_substr($this->request->queryString('q'), 0, 100);
        if ($q !== '') {
            $filters['q'] = $q;
        }
        $role = $this->request->queryString('role');
        if (in_array($role, MemberService::MANAGEABLE_ROLES, true)) {
            $filters['role'] = $role;
        }
        $status = $this->request->queryString('status');
        if (in_array($status, Member::STATUS_FILTERS, true)) {
            $filters['status'] = $status;
        }
        $unitId = $this->request->queryString('unit_id');
        if (preg_match('/^[1-9]\d{0,9}$/', $unitId) === 1) {
            $filters['unit_id'] = (int) $unitId;   // tenant-scoped inside the query
        }

        $members = new Member();
        $pagination = $this->pagination(20)->withTotal($members->count($filters));
        $rows = $members->search($filters, $pagination);

        $userIds = array_map(static fn (array $r): int => (int) $r['user_id'], $rows);
        $units = (new UnitResident())->currentUnits($userIds);
        $invitations = (new Invitation())->latestForUsers($this->tenantId(), $userIds);

        return $this->success([
            'users'      => array_map(fn (array $r): array => $this->present($r, $units, $invitations), $rows),
            'pagination' => $pagination->toArray(),
        ]);
    }

    /**
     * POST /admin/users/invitations: invites a resident, concierge or manager.
     *
     * Order: CSRF (middleware) → role → validation (role allowlist, unit belongs
     * to THIS condominium) → service (transaction + audit) → e-mail.
     */
    public function invite(): Response
    {
        $this->requireRole(self::MANAGERS);

        $v = new Validator($this->request);
        $name = $v->string('full_name', 'Nome', 3, 150);
        $email = $v->email('email');
        // Allowlist of role CODES: "super_admin" or any unknown value fails here.
        $role = $v->enum('role', 'Perfil', MemberService::MANAGEABLE_ROLES);
        $unitId = $v->filled('unit_id') ? $v->id('unit_id', 'a unidade') : null;
        $relationship = $v->filled('relationship')
            ? $v->enum('relationship', 'Vínculo', InvitationService::RELATIONSHIPS)
            : 'owner';

        if ($role === 'resident' && $unitId === null && !isset($v->errors()['unit_id'])) {
            $v->addError('unit_id', 'Selecione a unidade do morador.');
        }
        // Ownership: the unit must be an active unit of the session's condominium.
        if ($unitId !== null && (new Unit())->findActive($unitId) === null) {
            $v->addError('unit_id', 'Unidade inválida.');
        }
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/admin/users', 422, self::KEEP);
        }

        try {
            $result = (new InvitationService($this->request))->invite(
                $this->tenantId(),            // session tenant, never a request value
                (string) $email,
                (string) $name,
                (string) $role,
                $unitId,
                $unitId === null ? null : $relationship,
                (int) Auth::id()
            );
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, '/admin/users', self::KEEP);
        }

        $message = $result['email_sent']
            ? 'Convite enviado para ' . $email . '.'
            : 'Usuário criado, mas o e-mail não pôde ser enviado agora. Use "Reenviar convite" em instantes.';

        return $this->done($message, '/admin/users', ['user_id' => $result['user_id']], 201);
    }

    /** GET /admin/users/{id}/edit */
    public function edit(string $id): Response
    {
        $this->requireRole(self::MANAGERS);

        $member = (new Member())->findByUser((int) $id) ?? throw new HttpException(404);
        $unit = (new UnitResident())->currentUnits([(int) $id])[(int) $id] ?? null;

        return $this->view('admin/users/edit', [
            'title'         => 'Editar usuário',
            'activeNav'     => 'admin-users',
            'member'        => $member,
            'currentUnit'   => $unit,
            'units'         => (new Unit())->active(),
            'roles'         => self::roleOptions(),
            'relationships' => self::RELATIONSHIP_LABELS,
            'isSelf'        => (int) $id === (int) Auth::id(),
            'scripts'       => ['js/admin/confirm.js'],
        ]);
    }

    /**
     * POST /admin/users/{id}: role and unit. Name and e-mail are NOT editable by
     * a manager: they belong to the person's global account (they may live in
     * other condominiums too) and are changed only by the person (/account).
     */
    public function update(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $userId = (int) $id;
        (new Member())->findByUser($userId) ?? throw new HttpException(404);
        $back = "/admin/users/{$userId}/edit";

        $v = new Validator($this->request);
        $role = $v->enum('role', 'Perfil', MemberService::MANAGEABLE_ROLES);
        $unitId = $v->filled('unit_id') ? $v->id('unit_id', 'a unidade') : null;
        $relationship = $v->filled('relationship') ? $v->enum('relationship', 'Vínculo', InvitationService::RELATIONSHIPS) : 'owner';
        if ($unitId !== null && (new Unit())->findActive($unitId) === null) {
            $v->addError('unit_id', 'Unidade inválida.');
        }
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back);
        }

        try {
            (new MemberService($this->request, $this->tenantId()))
                ->update($userId, (string) $role, $unitId, (string) $relationship, (int) Auth::id());
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, $back);
        }

        return $this->done('Usuário atualizado.', $back);
    }

    /** POST /admin/users/{id}/deactivate (form or fetch) */
    public function deactivate(string $id): Response
    {
        $this->requireRole(self::MANAGERS);

        try {
            (new MemberService($this->request, $this->tenantId()))->deactivate((int) $id, (int) Auth::id());
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, "/admin/users/{$id}/edit");
        }

        return $this->done('Usuário desativado. O acesso dele termina na próxima ação.', "/admin/users/{$id}/edit", [
            'user' => ['user_id' => (int) $id, 'status' => 'inactive'],
        ]);
    }

    /** POST /admin/users/{id}/reactivate (form or fetch) */
    public function reactivate(string $id): Response
    {
        $this->requireRole(self::MANAGERS);

        try {
            $status = (new MemberService($this->request, $this->tenantId()))->reactivate((int) $id, (int) Auth::id());
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, "/admin/users/{$id}/edit");
        }

        $message = $status === 'active'
            ? 'Usuário reativado.'
            : 'Cadastro reativado. A pessoa ainda não aceitou o convite: reenvie-o.';

        return $this->done($message, "/admin/users/{$id}/edit", ['user' => ['user_id' => (int) $id, 'status' => $status]]);
    }

    /** POST /admin/users/{id}/invitation/resend (form or fetch) */
    public function resendInvitation(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        (new Member())->findByUser((int) $id) ?? throw new HttpException(404);

        try {
            $sent = (new InvitationService($this->request))->resend($this->tenantId(), (int) $id, (int) Auth::id());
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, "/admin/users/{$id}/edit");
        }

        return $this->done(
            $sent ? 'Convite reenviado.' : 'Novo convite gerado, mas o e-mail não pôde ser enviado agora.',
            "/admin/users/{$id}/edit",
            ['user' => ['user_id' => (int) $id, 'status' => 'invited']]
        );
    }

    /** @return array<string, string> code => label, for <select> */
    private static function roleOptions(): array
    {
        $options = [];
        foreach (MemberService::MANAGEABLE_ROLES as $code) {
            $options[$code] = Auth::labelFor($code);
        }

        return $options;
    }

    /**
     * Shapes one member for JSON: raw text only (the browser inserts it with
     * textContent), no internal columns.
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $units
     * @param array<int, array<string, mixed>> $invitations
     * @return array<string, mixed>
     */
    private function present(array $row, array $units, array $invitations): array
    {
        $userId = (int) $row['user_id'];
        $status = (string) $row['status'];
        if ($status === 'invited' && (($invitations[$userId]['is_expired'] ?? 1) === 1)) {
            $status = 'invitation_expired';
        }

        return [
            'user_id'       => $userId,
            'full_name'     => (string) $row['full_name'],
            'email'         => (string) $row['email'],
            'role'          => (string) $row['role_code'],
            'role_label'    => Auth::labelFor((string) $row['role_code']),
            'status'        => $status,
            'unit_label'    => $units[$userId]['unit_label'] ?? null,
            'last_login_at' => $row['last_login_at'] === null
                ? null
                : (new DateTimeImmutable((string) $row['last_login_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'is_self'       => $userId === (int) Auth::id(),
        ];
    }
}
```


**`app/Controllers/InvitationController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\InvitationService;

/**
 * Invitation landing page and acceptance (Phase 5). Works logged out.
 */
final class InvitationController extends Controller
{
    /** GET /account/invitation?token=... Read-only (mail scanners open links). */
    public function show(): Response
    {
        $token = $this->request->query('token', '');
        $token = is_string($token) ? $token : '';
        $result = (new InvitationService($this->request))->inspect($token);

        return $this->page($token, $result['state'], $result['invitation'], []);
    }

    /** POST /account/invitation */
    public function accept(): Response
    {
        $token = $this->request->string('token');
        $service = new InvitationService($this->request);
        $inspection = $service->inspect($token);
        if ($inspection['state'] !== InvitationService::ACCEPT_VALID) {
            return $this->page($token, $inspection['state'], $inspection['invitation'], []);
        }

        $passwordHash = null;
        if ((int) $inspection['invitation']['needs_password'] === 1) {
            $v = new Validator($this->request);
            $password = $v->newPassword();
            if ($v->fails()) {
                return $this->page($token, $inspection['state'], $inspection['invitation'], $v->errors(), 422);
            }
            $passwordHash = password_hash(
                (string) $password,
                Config::get('security.password_algo', PASSWORD_DEFAULT),
                Config::get('security.password_options', [])
            );
        }

        $state = $service->accept($token, $passwordHash);
        if ($state !== InvitationService::ACCEPT_VALID) {
            return $this->page($token, $state, $inspection['invitation'], []);
        }

        // Whoever was logged in in this browser is logged out: the invitee
        // should start a clean session as themself.
        if (Auth::check()) {
            Auth::logout();
        }
        Session::flash('success', 'Convite aceito! Entre com seu e-mail e senha para acessar o condomínio.');

        return $this->redirect('/login');
    }

    /**
     * @param array<string, mixed>|null $invitation
     * @param array<string, string>     $errors
     */
    private function page(string $token, string $state, ?array $invitation, array $errors, int $status = 200): Response
    {
        $usable = in_array($state, [InvitationService::ACCEPT_VALID, InvitationService::ACCEPT_PASSWORD_REQUIRED], true);

        return $this->view('account/invitation', [
            'title'           => 'Aceitar convite',
            'state'           => $state,
            'token'           => $token,
            // Details are shown only for a usable token: an expired or revoked
            // link reveals nothing about the account or the condominium.
            'condominiumName' => $usable ? (string) $invitation['condominium_name'] : null,
            'roleLabel'       => $usable ? Auth::labelFor((string) $invitation['role_code']) : null,
            'email'           => $usable ? (string) $invitation['email'] : null,
            'needsPassword'   => $usable && (int) $invitation['needs_password'] === 1,
            'passwordMin'     => (int) Config::get('security.password_min', 10),
            'errors'          => $errors,
        ], $status, 'layouts/auth')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
```


**`app/Views/admin/users/index.php`**

```php
<?php
/**
 * User management. The table is filled by public/assets/js/admin/users.js from
 * GET /admin/users/search, by cloning <template id="user-row"> and writing
 * every value with textContent. The invite form is a normal POST.
 *
 * @var list<array{id: int, label: string}> $units
 * @var array<string, string>               $roles         code => label
 * @var array<string, string>               $relationships code => label
 * @var array<string, string>               $errors
 * @var array<string, string>               $old
 */
$selected = static fn (string $field, string|int $value): string
    => (string) ($old[$field] ?? '') === (string) $value ? ' selected' : '';
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Usuários</h1>
        <p class="page-header__subtitle">Moradores, portaria e síndicos deste condomínio</p>
    </div>
</section>

<div class="columns">
    <div class="columns__side">
        <section class="panel panel--padded">
            <h2 class="panel__title">Convidar usuário</h2>
            <form method="post" action="/admin/users/invitations" class="form" novalidate>
                <?= csrf_field() ?>
                <?= field_error($errors, 'general') ?>
                <label class="form__field">
                    <span class="form__label">Nome completo</span>
                    <input type="text" name="full_name" maxlength="150" required value="<?= e($old['full_name'] ?? '') ?>">
                    <?= field_error($errors, 'full_name') ?>
                </label>
                <label class="form__field">
                    <span class="form__label">E-mail</span>
                    <input type="email" name="email" maxlength="254" required value="<?= e($old['email'] ?? '') ?>">
                    <?= field_error($errors, 'email') ?>
                </label>
                <label class="form__field">
                    <span class="form__label">Perfil</span>
                    <select name="role" required>
                        <?php foreach ($roles as $code => $label): ?>
                            <option value="<?= e($code) ?>"<?= $selected('role', $code) ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error($errors, 'role') ?>
                </label>
                <div class="form__row">
                    <label class="form__field">
                        <span class="form__label">Unidade</span>
                        <select name="unit_id">
                            <option value="">Nenhuma</option>
                            <?php foreach ($units as $unit): ?>
                                <option value="<?= e($unit['id']) ?>"<?= $selected('unit_id', $unit['id']) ?>><?= e($unit['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= field_error($errors, 'unit_id') ?>
                    </label>
                    <label class="form__field">
                        <span class="form__label">Vínculo</span>
                        <select name="relationship">
                            <?php foreach ($relationships as $code => $label): ?>
                                <option value="<?= e($code) ?>"<?= $selected('relationship', $code) ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <p class="form__hint">A pessoa recebe um link de uso único, válido por 72 horas, para aceitar o convite.</p>
                <button type="submit" class="btn btn--primary">Enviar convite</button>
            </form>
        </section>
    </div>

    <div class="columns__main">
        <section class="panel">
            <form class="filters" id="user-filters" role="search" novalidate>
                <label class="filters__field filters__field--grow">
                    <span class="sr-only">Buscar</span>
                    <input type="search" name="q" placeholder="Buscar por nome ou e-mail" maxlength="100" autocomplete="off">
                </label>
                <label class="filters__field">
                    <span class="sr-only">Perfil</span>
                    <select name="role">
                        <option value="">Todos os perfis</option>
                        <?php foreach ($roles as $code => $label): ?>
                            <option value="<?= e($code) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="filters__field">
                    <span class="sr-only">Unidade</span>
                    <select name="unit_id">
                        <option value="">Todas as unidades</option>
                        <?php foreach ($units as $unit): ?>
                            <option value="<?= e($unit['id']) ?>"><?= e($unit['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="filters__field">
                    <span class="sr-only">Situação</span>
                    <select name="status">
                        <option value="">Todas as situações</option>
                        <option value="active">Ativos</option>
                        <option value="invited">Convite pendente</option>
                        <option value="inactive">Desativados</option>
                    </select>
                </label>
            </form>

            <div id="user-list" aria-live="polite" aria-busy="true">
                <div class="state" data-state="loading"><span class="spinner" aria-hidden="true"></span> Carregando usuários…</div>
                <div class="state" data-state="empty" hidden>Nenhum usuário encontrado com estes filtros.</div>
                <div class="state state--error" data-state="error" hidden>
                    <p>Não foi possível carregar os usuários.</p>
                    <button type="button" class="btn" data-retry>Tentar novamente</button>
                </div>
                <div class="table-wrap" data-state="list" hidden>
                    <table class="table">
                        <thead>
                        <tr><th>Nome</th><th>Perfil</th><th>Unidade</th><th>Situação</th><th class="table__action">Ações</th></tr>
                        </thead>
                        <tbody id="user-rows"></tbody>
                    </table>
                </div>
                <nav class="pager" data-pager hidden aria-label="Paginação">
                    <span class="pager__info muted" data-pager-info></span>
                    <button type="button" class="btn btn--small" data-page="prev">Anterior</button>
                    <button type="button" class="btn btn--small" data-page="next">Próxima</button>
                </nav>
            </div>
        </section>
    </div>
</div>

<template id="user-row">
    <tr data-row>
        <td>
            <a class="strong" data-field="name"></a>
            <span class="tag" data-field="self" hidden>Você</span>
            <div class="small muted" data-field="email"></div>
        </td>
        <td data-field="role"></td>
        <td data-field="unit"></td>
        <td><span class="pill" data-field="status"></span></td>
        <td class="table__action">
            <span class="inline-form">
                <button type="button" class="btn btn--small" data-action="resend" hidden>Reenviar convite</button>
                <button type="button" class="btn btn--small btn--danger-ghost" data-action="deactivate" hidden>Desativar</button>
                <button type="button" class="btn btn--small" data-action="reactivate" hidden>Reativar</button>
                <span class="feedback" data-feedback role="status"></span>
            </span>
        </td>
    </tr>
</template>
```


**`app/Views/admin/users/edit.php`**

```php
<?php
/**
 * Edit one member: role, unit and status. Name and e-mail are shown read-only:
 * they belong to the person's global account.
 *
 * @var array<string, mixed>                $member      Member::findByUser() row.
 * @var array<string, mixed>|null           $currentUnit
 * @var list<array{id: int, label: string}> $units
 * @var array<string, string>               $roles
 * @var array<string, string>               $relationships
 * @var bool                                $isSelf
 * @var array<string, string>               $errors
 */
$statusLabels = ['active' => 'Ativo', 'inactive' => 'Desativado', 'invited' => 'Convite pendente'];
$unitId = (int) ($currentUnit['unit_id'] ?? 0);
$relationship = (string) ($currentUnit['relationship'] ?? 'owner');
$userId = (int) $member['user_id'];
?>
<section class="page-header">
    <div>
        <p class="page-header__eyebrow"><a href="/admin/users">← Usuários</a></p>
        <h1 class="page-header__title"><?= e($member['full_name']) ?></h1>
        <p class="page-header__subtitle">
            <?= e($member['email']) ?> ·
            <?= pill((string) $member['status'], $statusLabels[$member['status']] ?? (string) $member['status']) ?>
        </p>
    </div>
</section>

<?= field_error($errors, 'general') ?>

<div class="grid-2">
    <section class="panel panel--padded">
        <h2 class="panel__title">Perfil e unidade</h2>
        <form method="post" action="/admin/users/<?= e($userId) ?>" class="form" novalidate>
            <?= csrf_field() ?>
            <label class="form__field">
                <span class="form__label">Perfil</span>
                <select name="role" <?= $isSelf ? 'disabled' : '' ?>>
                    <?php foreach ($roles as $code => $label): ?>
                        <option value="<?= e($code) ?>"<?= $member['role_code'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($isSelf): ?>
                    <!-- A disabled select is not submitted: send the current role so only the unit changes. -->
                    <input type="hidden" name="role" value="<?= e($member['role_code']) ?>">
                    <span class="form__hint">Você não pode alterar o seu próprio perfil.</span>
                <?php endif; ?>
                <?= field_error($errors, 'role') ?>
            </label>
            <div class="form__row">
                <label class="form__field">
                    <span class="form__label">Unidade</span>
                    <select name="unit_id">
                        <option value="">Nenhuma</option>
                        <?php foreach ($units as $unit): ?>
                            <option value="<?= e($unit['id']) ?>"<?= $unitId === (int) $unit['id'] ? ' selected' : '' ?>><?= e($unit['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error($errors, 'unit_id') ?>
                </label>
                <label class="form__field">
                    <span class="form__label">Vínculo</span>
                    <select name="relationship">
                        <?php foreach ($relationships as $code => $label): ?>
                            <option value="<?= e($code) ?>"<?= $relationship === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="form__actions">
                <button type="submit" class="btn btn--primary">Salvar alterações</button>
            </div>
        </form>
    </section>

    <section class="panel panel--padded">
        <h2 class="panel__title">Acesso</h2>
        <?php if ($member['status'] === 'invited'): ?>
            <p>O convite ainda não foi aceito.</p>
            <form method="post" action="/admin/users/<?= e($userId) ?>/invitation/resend" class="form">
                <?= csrf_field() ?>
                <button type="submit" class="btn">Reenviar convite</button>
            </form>
        <?php endif; ?>

        <?php if ($isSelf): ?>
            <p class="muted">Você não pode desativar a sua própria conta.</p>
        <?php elseif ($member['status'] === 'inactive'): ?>
            <p>Usuário desativado<?= $member['deactivated_at'] !== null ? ' em ' . e(local_datetime((string) $member['deactivated_at'])) : '' ?>. O histórico dele foi mantido.</p>
            <form method="post" action="/admin/users/<?= e($userId) ?>/reactivate" class="form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--primary">Reativar usuário</button>
            </form>
        <?php else: ?>
            <p>Ao desativar, a pessoa perde o acesso a este condomínio na próxima ação. Publicações, cobranças e ocorrências dela são mantidas.</p>
            <form method="post" action="/admin/users/<?= e($userId) ?>/deactivate" class="form"
                  data-confirm="Desativar <?= e($member['full_name']) ?>? A pessoa perderá o acesso a este condomínio.">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--danger">Desativar usuário</button>
            </form>
        <?php endif; ?>
    </section>
</div>
```


**`app/Views/account/invitation.php`**

```php
<?php
/**
 * Invitation landing page (InvitationService::ACCEPT_* states). Condominium,
 * role and e-mail are passed only for a usable token.
 *
 * @var string                $state
 * @var string                $token
 * @var string|null           $condominiumName
 * @var string|null           $roleLabel
 * @var string|null           $email
 * @var bool                  $needsPassword
 * @var int                   $passwordMin
 * @var array<string, string> $errors
 */

use App\Services\InvitationService;
?>
<h1 class="auth__title">Aceitar convite</h1>

<?php if ($state === InvitationService::ACCEPT_VALID || $state === InvitationService::ACCEPT_PASSWORD_REQUIRED): ?>
    <p>
        Você foi convidado para acessar <strong><?= e($condominiumName) ?></strong>
        como <strong><?= e($roleLabel) ?></strong>.
    </p>
    <p class="muted">Conta: <?= e($email) ?></p>

    <form method="post" action="/account/invitation" class="form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <?php if ($needsPassword): ?>
            <label class="form__field">
                <span class="form__label">Crie sua senha (mínimo <?= e($passwordMin) ?> caracteres)</span>
                <input type="password" name="password" autocomplete="new-password" required minlength="<?= e($passwordMin) ?>">
                <?= field_error($errors, 'password') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Repita a senha</span>
                <input type="password" name="password_confirmation" autocomplete="new-password" required>
                <?= field_error($errors, 'password_confirmation') ?>
            </label>
        <?php else: ?>
            <p class="form__hint">Você já tem uma conta no Koinon: depois de aceitar, entre com sua senha de sempre.</p>
        <?php endif; ?>

        <button type="submit" class="btn btn--primary btn--block">Aceitar convite</button>
    </form>

<?php elseif ($state === InvitationService::ACCEPT_EXPIRED): ?>
    <div class="alert alert--warning">Este convite expirou. Peça à administração do condomínio que o reenvie.</div>

<?php elseif ($state === InvitationService::ACCEPT_USED): ?>
    <div class="alert alert--success">Este convite já foi aceito. Você já pode entrar.</div>
    <a class="btn btn--primary btn--block" href="/login">Ir para o login</a>

<?php else: ?>
    <div class="alert alert--error">Convite inválido ou cancelado. Confira se copiou o endereço completo ou fale com a administração.</div>
<?php endif; ?>
```


### 3.4 Financial reports

Every amount is a MySQL `SUM()` over `DECIMAL` columns and stays a string ("1234.50") all the way to the screen and the CSV.

- **Payment and cancellation** lock the invoice row and are audited in the same transaction.
- **Invoices are never deleted.**

**`app/Models/Invoice.php`** (existing file: changes only)

```diff
@@ -4,6 +4,7 @@ declare(strict_types=1);
 
 namespace App\Models;
 
+use App\Core\Pagination;
 use App\Core\TenantModel;
 
 /**
@@ -29,6 +30,19 @@ final class Invoice extends TenantModel
         'cancelled' => 'Cancelada',
     ];
 
+    /** Manual payment methods (payments.payment_method ENUM). */
+    public const PAYMENT_METHODS = [
+        'pix'           => 'PIX',
+        'bank_slip'     => 'Boleto',
+        'bank_transfer' => 'Transferência',
+        'cash'          => 'Dinheiro',
+        'card'          => 'Cartão',
+        'other'         => 'Outro',
+    ];
+
+    /** Filters of the admin invoice list ("overdue" is derived, never stored). */
+    public const LIST_FILTERS = ['open', 'overdue', 'paid', 'cancelled'];
+
     protected string $table = 'invoices';
 
     protected array $fillable = [
@@ -102,4 +116,243 @@ final class Invoice extends TenantModel
             $this->scoped(['today_a' => $today, 'today_b' => $today])
         );
     }
+
+    // ------------------------------------------------------------------
+    // Phase 5: reports, list and state changes. Every amount is summed by
+    // MySQL on DECIMAL columns and returned as a string; PHP never does
+    // arithmetic on money.
+    // ------------------------------------------------------------------
+
+    /**
+     * Totals of one period, by due date (accrual view) plus cash received by
+     * payment date. "Pending" = open and not yet due; "overdue" = open and past
+     * due. Each placeholder is used once (native prepared statements).
+     *
+     * @param string $from      First local date, Y-m-d.
+     * @param string $to        Last local date, Y-m-d (inclusive).
+     * @param string $fromUtc   Local midnight of $from, in UTC (payments.paid_at is UTC).
+     * @param string $toUtcExcl Local midnight of the day after $to, in UTC.
+     * @return array<string, string|int>
+     */
+    public function periodTotals(string $from, string $to, string $fromUtc, string $toUtcExcl, string $today): array
+    {
+        $totals = $this->fetchOne(
+            "SELECT COUNT(*) AS invoice_count,
+                    COALESCE(SUM(i.total_amount), 0) AS billed,
+                    COALESCE(SUM(CASE WHEN i.status = 'paid' THEN i.total_amount END), 0) AS billed_paid,
+                    COALESCE(SUM(CASE WHEN i.status = 'open' AND i.due_date >= :today_a THEN i.total_amount END), 0) AS pending,
+                    COALESCE(SUM(CASE WHEN i.status = 'open' AND i.due_date < :today_b THEN i.total_amount END), 0) AS overdue,
+                    COALESCE(SUM(i.status = 'open' AND i.due_date < :today_c), 0) AS overdue_count
+               FROM invoices i
+              WHERE i.condominium_id = :tenant
+                AND i.status IN ('open', 'paid')
+                AND i.due_date BETWEEN :from AND :to",
+            $this->scoped(['today_a' => $today, 'today_b' => $today, 'today_c' => $today, 'from' => $from, 'to' => $to])
+        ) ?? [];
+
+        $received = $this->fetchOne(
+            "SELECT COUNT(*) AS payment_count, COALESCE(SUM(p.amount), 0) AS received
+               FROM payments p
+              WHERE p.condominium_id = :tenant
+                AND p.status = 'confirmed'
+                AND p.paid_at >= :from_utc AND p.paid_at < :to_utc",
+            $this->scoped(['from_utc' => $fromUtc, 'to_utc' => $toUtcExcl])
+        ) ?? [];
+
+        return [
+            'invoice_count' => (int) ($totals['invoice_count'] ?? 0),
+            'billed'        => (string) ($totals['billed'] ?? '0.00'),
+            'billed_paid'   => (string) ($totals['billed_paid'] ?? '0.00'),
+            'pending'       => (string) ($totals['pending'] ?? '0.00'),
+            'overdue'       => (string) ($totals['overdue'] ?? '0.00'),
+            'overdue_count' => (int) ($totals['overdue_count'] ?? 0),
+            'payment_count' => (int) ($received['payment_count'] ?? 0),
+            'received'      => (string) ($received['received'] ?? '0.00'),
+        ];
+    }
+
+    /**
+     * Invoices due in the period (detail lines of the period CSV).
+     *
+     * @return list<array<string, mixed>>
+     */
+    public function periodLines(string $from, string $to, string $today): array
+    {
+        return $this->fetchAll(
+            "SELECT i.invoice_number, i.invoice_type, i.reference_month, i.due_date, i.total_amount, i.status,
+                    i.paid_at, " . Unit::LABEL_SQL . " AS unit_label,
+                    (i.status = 'open' AND i.due_date < :today) AS is_overdue
+               FROM invoices i
+               JOIN units u ON u.condominium_id = i.condominium_id AND u.id = i.unit_id
+              WHERE i.condominium_id = :tenant
+                AND i.status IN ('open', 'paid')
+                AND i.due_date BETWEEN :from AND :to
+              ORDER BY i.due_date, i.invoice_number
+              LIMIT 20000",
+            $this->scoped(['today' => $today, 'from' => $from, 'to' => $to])
+        );
+    }
+
+    /**
+     * Units with overdue invoices, most overdue first.
+     *
+     * @return list<array<string, mixed>>
+     */
+    public function delinquency(string $today): array
+    {
+        return $this->fetchAll(
+            "SELECT u.id AS unit_id, " . Unit::LABEL_SQL . " AS unit_label,
+                    COUNT(*) AS overdue_count,
+                    SUM(i.total_amount) AS amount_owed,
+                    MIN(i.due_date) AS oldest_due_date,
+                    DATEDIFF(:today_a, MIN(i.due_date)) AS days_overdue
+               FROM invoices i
+               JOIN units u ON u.condominium_id = i.condominium_id AND u.id = i.unit_id
+              WHERE i.condominium_id = :tenant
+                AND i.status = 'open'
+                AND i.due_date < :today_b
+              GROUP BY u.id, u.building, u.unit_number
+              ORDER BY days_overdue DESC, amount_owed DESC",
+            $this->scoped(['today_a' => $today, 'today_b' => $today])
+        );
+    }
+
+    /** Sum of every overdue amount (footer of the delinquency report). */
+    public function delinquencyTotal(string $today): string
+    {
+        $row = $this->fetchOne(
+            "SELECT COALESCE(SUM(total_amount), 0) AS total FROM invoices
+              WHERE condominium_id = :tenant AND status = 'open' AND due_date < :today",
+            $this->scoped(['today' => $today])
+        );
+
+        return (string) ($row['total'] ?? '0.00');
+    }
+
+    /**
+     * Admin invoice list.
+     *
+     * @param array{status?: string, unit_id?: int, from?: string, to?: string} $filters Already validated.
+     * @return list<array<string, mixed>>
+     */
+    public function searchForAdmin(array $filters, string $today, Pagination $pagination): array
+    {
+        [$where, $params] = $this->adminFilterSql($filters, $today);
+
+        return $this->fetchAll(
+            "SELECT i.id, i.invoice_number, i.invoice_type, i.reference_month, i.due_date, i.total_amount,
+                    i.status, i.paid_at, i.cancelled_at, " . Unit::LABEL_SQL . " AS unit_label,
+                    (i.status = 'open' AND i.due_date < :today_row) AS is_overdue
+               FROM invoices i
+               JOIN units u ON u.condominium_id = i.condominium_id AND u.id = i.unit_id
+              WHERE {$where}
+              ORDER BY i.due_date DESC, i.invoice_number DESC
+              LIMIT :limit OFFSET :offset",
+            $params + ['today_row' => $today, 'limit' => $pagination->limit(), 'offset' => $pagination->offset()]
+        );
+    }
+
+    /** @param array{status?: string, unit_id?: int, from?: string, to?: string} $filters */
+    public function countForAdmin(array $filters, string $today): int
+    {
+        [$where, $params] = $this->adminFilterSql($filters, $today);
+        $row = $this->fetchOne("SELECT COUNT(*) AS total FROM invoices i WHERE {$where}", $params);
+
+        return (int) ($row['total'] ?? 0);
+    }
+
+    /**
+     * One invoice of the tenant with its unit label and payments, for the detail page.
+     *
+     * @return array<string, mixed>|null
+     */
+    public function findDetailed(int $id, string $today): ?array
+    {
+        $invoice = $this->fetchOne(
+            "SELECT i.*, " . Unit::LABEL_SQL . " AS unit_label,
+                    (i.status = 'open' AND i.due_date < :today) AS is_overdue
+               FROM invoices i
+               JOIN units u ON u.condominium_id = i.condominium_id AND u.id = i.unit_id
+              WHERE i.condominium_id = :tenant AND i.id = :id",
+            $this->scoped(['today' => $today, 'id' => $id])
+        );
+        if ($invoice === null) {
+            return null;
+        }
+        $invoice['payments'] = $this->fetchAll(
+            'SELECT p.amount, p.paid_at, p.payment_method, p.status, p.notes, us.full_name AS recorded_by
+               FROM payments p
+               LEFT JOIN users us ON us.id = p.recorded_by_user_id
+              WHERE p.condominium_id = :tenant AND p.invoice_id = :id
+              ORDER BY p.paid_at',
+            $this->scoped(['id' => $id])
+        );
+
+        return $invoice;
+    }
+
+    /**
+     * Locks an invoice row of the tenant for a state change.
+     *
+     * @return array<string, mixed>|null
+     */
+    public function lockForUpdate(int $id): ?array
+    {
+        return $this->fetchOne(
+            'SELECT * FROM invoices WHERE id = :id AND condominium_id = :tenant FOR UPDATE',
+            $this->scoped(['id' => $id])
+        );
+    }
+
+    public function markPaid(int $id, string $paidAtUtc): void
+    {
+        $this->execute(
+            "UPDATE invoices SET status = 'paid', paid_at = :paid_at
+              WHERE id = :id AND condominium_id = :tenant AND status = 'open'",
+            $this->scoped(['paid_at' => $paidAtUtc, 'id' => $id])
+        );
+    }
+
+    public function cancel(int $id, string $reason): void
+    {
+        $this->execute(
+            "UPDATE invoices SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(), cancellation_reason = :reason
+              WHERE id = :id AND condominium_id = :tenant AND status = 'open'",
+            $this->scoped(['reason' => $reason, 'id' => $id])
+        );
+    }
+
+    /**
+     * @param array<string, mixed> $filters
+     * @return array{0: string, 1: array<string, mixed>}
+     */
+    private function adminFilterSql(array $filters, string $today): array
+    {
+        $conditions = ['i.condominium_id = :tenant'];   // tenant scope
+        $params = [];
+        $status = $filters['status'] ?? null;
+        if ($status === 'overdue') {
+            $conditions[] = "i.status = 'open' AND i.due_date < :today";
+            $params['today'] = $today;
+        } elseif ($status !== null) {
+            $conditions[] = 'i.status = :status';
+            $params['status'] = $status;
+        } else {
+            $conditions[] = "i.status <> 'draft'";
+        }
+        if (isset($filters['unit_id'])) {
+            $conditions[] = 'i.unit_id = :unit_id';
+            $params['unit_id'] = $filters['unit_id'];
+        }
+        if (isset($filters['from'])) {
+            $conditions[] = 'i.due_date >= :from';
+            $params['from'] = $filters['from'];
+        }
+        if (isset($filters['to'])) {
+            $conditions[] = 'i.due_date <= :to';
+            $params['to'] = $filters['to'];
+        }
+
+        return [implode(' AND ', $conditions), $this->scoped($params)];
+    }
 }
```


**`app/Models/Payment.php`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Money received against an invoice (`payments`, Phase 1 table). Recorded
 * manually by a Property Manager in Phase 5; rows are never edited or deleted
 * (a mistake is corrected by reversing, which is outside this phase).
 */
final class Payment extends TenantModel
{
    protected string $table = 'payments';

    // condominium_id is intentionally absent: TenantModel::insert() sets it.
    protected array $fillable = [
        'invoice_id',
        'amount',
        'paid_at',
        'payment_method',
        'external_reference',
        'recorded_by_user_id',
        'notes',
    ];
}
```


**`app/Services/InvoiceService.php`** (existing file: changes only)

```diff
@@ -5,16 +5,20 @@ declare(strict_types=1);
 namespace App\Services;
 
 use App\Core\Database;
+use App\Core\Request;
 use App\Core\TenantContext;
 use App\Models\FinancialCategory;
 use App\Models\Invoice;
 use App\Models\InvoiceItem;
+use App\Models\Payment;
 use App\Models\TenantCounter;
 use App\Models\Unit;
 use PDOException;
 
 /**
- * Creating charges (invoices) for units.
+ * Creating charges (invoices) for units, and (Phase 5) recording a manual
+ * payment or cancelling an invoice. Invoices are never hard-deleted: a
+ * cancelled invoice stays in the table with its reason.
  */
 final class InvoiceService
 {
@@ -84,4 +88,71 @@ final class InvoiceService
             throw $e;
         }
     }
+
+    /**
+     * Records a full manual payment of an open invoice.
+     *
+     * One transaction: lock the invoice (tenant-scoped), check it is still
+     * open, insert the payment with the invoice's own DECIMAL total (a string
+     * read from MySQL, never recomputed in PHP), mark the invoice paid, audit.
+     * The row lock makes a double click (or two managers) record one payment only.
+     *
+     * @param string $paidOn Local date Y-m-d (not in the future; checked by the caller).
+     * @throws BusinessRuleException 404 not in this condominium, 409 not open.
+     */
+    public function recordPayment(int $invoiceId, string $paidOn, string $method, ?string $notes, int $managerUserId, Request $request): void
+    {
+        Database::transaction(function () use ($invoiceId, $paidOn, $method, $notes, $managerUserId, $request): void {
+            $invoice = $this->invoices->lockForUpdate($invoiceId)
+                ?? throw new BusinessRuleException('Cobrança não encontrada.', 404);
+            if ($invoice['status'] !== 'open') {
+                throw new BusinessRuleException('Só é possível registrar pagamento de cobranças em aberto.', 409);
+            }
+            if ($paidOn < (string) $invoice['issue_date']) {
+                throw new BusinessRuleException('A data do pagamento não pode ser anterior à emissão.', 422, 'paid_on');
+            }
+
+            // Local midnight of the payment date, stored in UTC like every DATETIME.
+            $paidAtUtc = TenantContext::localToUtc($paidOn);
+            (new Payment())->insert([
+                'invoice_id'          => $invoiceId,
+                'amount'              => (string) $invoice['total_amount'],
+                'paid_at'             => $paidAtUtc,
+                'payment_method'      => $method,
+                'recorded_by_user_id' => $managerUserId,
+                'notes'               => $notes,
+            ]);
+            $this->invoices->markPaid($invoiceId, $paidAtUtc);
+
+            (new AuditLogger($request))->tenant('invoice.paid', 'invoice', $invoiceId, [
+                'invoice_number' => (int) $invoice['invoice_number'],
+                'amount'         => (string) $invoice['total_amount'],
+                'paid_on'        => $paidOn,
+                'method'         => $method,
+            ]);
+        });
+    }
+
+    /**
+     * Cancels an open invoice (kept, with its reason; never deleted).
+     *
+     * @throws BusinessRuleException 404 not in this condominium, 409 not open.
+     */
+    public function cancel(int $invoiceId, string $reason, Request $request): void
+    {
+        Database::transaction(function () use ($invoiceId, $reason, $request): void {
+            $invoice = $this->invoices->lockForUpdate($invoiceId)
+                ?? throw new BusinessRuleException('Cobrança não encontrada.', 404);
+            if ($invoice['status'] !== 'open') {
+                throw new BusinessRuleException('Só é possível cancelar cobranças em aberto.', 409);
+            }
+
+            $this->invoices->cancel($invoiceId, $reason);
+            (new AuditLogger($request))->tenant('invoice.cancelled', 'invoice', $invoiceId, [
+                'invoice_number' => (int) $invoice['invoice_number'],
+                'amount'         => (string) $invoice['total_amount'],
+                'reason'         => $reason,
+            ]);
+        });
+    }
 }
```


**`app/Services/CsvExporter.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Builds CSV files for download (financial reports, Phase 5).
 *
 *  - fputcsv() does the quoting, so commas, quotes and line breaks inside a
 *    value cannot break the column layout.
 *  - UTF-8 with a BOM, and ";" as the separator: that is what Excel expects in a
 *    pt-BR locale, so accents and columns open correctly with a double click.
 *  - CSV/formula injection: a cell that starts with =, +, -, @ (or a tab / CR,
 *    which some spreadsheet apps strip before evaluating) would run as a formula
 *    when opened, e.g. a unit named "=HYPERLINK(...)". Such cells are prefixed
 *    with a single quote, which makes the spreadsheet treat them as text.
 */
final class CsvExporter
{
    private const BOM = "\u{FEFF}";
    private const DANGEROUS_FIRST_CHARS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param list<list<string|int|null>> $rows The first row is usually the header.
     */
    public function build(array $rows): string
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream for the CSV.');
        }

        fwrite($handle, self::BOM);
        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'sanitize'], $row), ';', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** "1234.50" → "1234,50": DECIMAL strings in the pt-BR format Excel parses as numbers. */
    public static function decimal(string $amount): string
    {
        return str_replace('.', ',', $amount);
    }

    /** Neutralises spreadsheet formulas (see class comment). */
    public static function sanitize(string|int|null $value): string
    {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], self::DANGEROUS_FIRST_CHARS, true)) {
            return "'" . $value;
        }

        return $value;
    }
}
```


**`app/Controllers/Admin/FinanceController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\TenantContext;
use App\Core\Validator;
use App\Models\Invoice;
use App\Models\Unit;
use App\Services\BusinessRuleException;
use App\Services\CsvExporter;
use App\Services\InvoiceService;
use DateTimeImmutable;

/**
 * Financial reports, the invoice list, manual payment and cancellation
 * (Property Manager, Phase 5).
 *
 * MONEY: every total is computed by MySQL on DECIMAL columns and travels as a
 * string ("1234.50"). PHP only formats it (money_br / CsvExporter::decimal);
 * there is no float arithmetic anywhere in this controller.
 */
final class FinanceController extends AdminController
{
    /** Longest period a report may cover, to keep the queries bounded. */
    private const MAX_PERIOD_DAYS = 366;

    /** GET /admin/finance/reports: page with the period form; totals load through reports.js. */
    public function reports(): Response
    {
        $this->requireRole(self::MANAGERS);
        $today = TenantContext::today();
        $invoices = new Invoice();

        return $this->view('admin/finance/reports', [
            'title'            => 'Relatórios financeiros',
            'activeNav'        => 'admin-finance',
            'defaultFrom'      => substr($today, 0, 8) . '01',
            'defaultTo'        => (new DateTimeImmutable($today))->modify('last day of this month')->format('Y-m-d'),
            'delinquency'      => $invoices->delinquency($today),
            'delinquencyTotal' => $invoices->delinquencyTotal($today),
            'today'            => $today,
            'scripts'          => ['js/admin/reports.js'],
        ]);
    }

    /** GET /admin/finance/reports/period?from=Y-m-d&to=Y-m-d (JSON) */
    public function period(): Response
    {
        $this->requireRole(self::MANAGERS);
        [$from, $to, $errors] = $this->periodFromQuery();
        if ($errors !== []) {
            return $this->failure('Período inválido.', 422, $errors);
        }

        return $this->success(['from' => $from, 'to' => $to] + $this->periodTotals($from, $to));
    }

    /** GET /admin/finance/reports/period.csv?from=&to= */
    public function periodCsv(): Response
    {
        $this->requireRole(self::MANAGERS);
        [$from, $to, $errors] = $this->periodFromQuery();
        if ($errors !== []) {
            throw new HttpException(422, (string) reset($errors));
        }

        $today = TenantContext::today();
        $totals = $this->periodTotals($from, $to);
        $rows = [
            ['Relatório do período', date_br($from) . ' a ' . date_br($to)],
            ['Condomínio', (string) TenantContext::name()],
            ['Total faturado', CsvExporter::decimal($totals['billed'])],
            ['Total recebido', CsvExporter::decimal($totals['received'])],
            ['Total a vencer', CsvExporter::decimal($totals['pending'])],
            ['Total vencido', CsvExporter::decimal($totals['overdue'])],
            [],
            ['Nº', 'Unidade', 'Tipo', 'Referência', 'Vencimento', 'Valor', 'Situação', 'Pago em'],
        ];
        foreach ((new Invoice())->periodLines($from, $to, $today) as $line) {
            $rows[] = [
                (int) $line['invoice_number'],
                (string) $line['unit_label'],
                Invoice::TYPES[$line['invoice_type']] ?? (string) $line['invoice_type'],
                substr((string) $line['reference_month'], 0, 7),
                date_br((string) $line['due_date']),
                CsvExporter::decimal((string) $line['total_amount']),
                (int) $line['is_overdue'] === 1 ? 'Vencida' : (Invoice::STATUS_LABELS[$line['status']] ?? (string) $line['status']),
                $line['paid_at'] === null ? '' : local_datetime((string) $line['paid_at'], 'd/m/Y'),
            ];
        }

        return Response::file((new CsvExporter())->build($rows), 'text/csv; charset=UTF-8', "relatorio-{$from}-a-{$to}.csv");
    }

    /** GET /admin/finance/reports/delinquency.csv */
    public function delinquencyCsv(): Response
    {
        $this->requireRole(self::MANAGERS);
        $today = TenantContext::today();
        $invoices = new Invoice();

        $rows = [['Unidade', 'Cobranças vencidas', 'Valor devido', 'Vencimento mais antigo', 'Dias em atraso']];
        foreach ($invoices->delinquency($today) as $row) {
            $rows[] = [
                (string) $row['unit_label'],
                (int) $row['overdue_count'],
                CsvExporter::decimal((string) $row['amount_owed']),
                date_br((string) $row['oldest_due_date']),
                (int) $row['days_overdue'],
            ];
        }
        $rows[] = ['Total', '', CsvExporter::decimal($invoices->delinquencyTotal($today)), '', ''];

        return Response::file((new CsvExporter())->build($rows), 'text/csv; charset=UTF-8', "inadimplencia-{$today}.csv");
    }

    /** GET /admin/finance/invoices?status=&unit_id=&from=&to=&page= */
    public function invoices(): Response
    {
        $this->requireRole(self::MANAGERS);
        $today = TenantContext::today();
        $units = (new Unit())->active();

        $filters = [];
        $query = [];
        $status = $this->request->queryString('status');
        if (in_array($status, Invoice::LIST_FILTERS, true)) {
            $filters['status'] = $query['status'] = $status;
        }
        $unitId = (int) $this->request->queryString('unit_id');
        if (in_array($unitId, array_map(static fn (array $u): int => (int) $u['id'], $units), true)) {
            $filters['unit_id'] = $unitId;   // allowlist: this tenant's units only
            $query['unit_id'] = (string) $unitId;
        }
        foreach (['from', 'to'] as $key) {
            $value = $this->request->queryString($key);
            if (self::isDate($value)) {
                $filters[$key] = $query[$key] = $value;
            }
        }

        $invoices = new Invoice();
        $pagination = $this->pagination(30)->withTotal($invoices->countForAdmin($filters, $today));

        return $this->view('admin/finance/invoices', [
            'title'        => 'Cobranças',
            'activeNav'    => 'admin-invoices',
            'invoices'     => $invoices->searchForAdmin($filters, $today, $pagination),
            'units'        => $units,
            'query'        => $query,
            'pagination'   => $pagination->toArray(),
            'types'        => Invoice::TYPES,
            'statusLabels' => Invoice::STATUS_LABELS,
        ]);
    }

    /** GET /admin/finance/invoices/{id} */
    public function show(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $today = TenantContext::today();
        $invoice = (new Invoice())->findDetailed((int) $id, $today) ?? throw new HttpException(404);   // tenant-scoped

        return $this->view('admin/finance/invoice', [
            'title'          => 'Cobrança nº ' . $invoice['invoice_number'],
            'activeNav'      => 'admin-invoices',
            'invoice'        => $invoice,
            'types'          => Invoice::TYPES,
            'statusLabels'   => Invoice::STATUS_LABELS,
            'paymentMethods' => Invoice::PAYMENT_METHODS,
            'today'          => $today,
            'scripts'        => ['js/admin/confirm.js'],
        ]);
    }

    /** POST /admin/finance/invoices/{id}/payment */
    public function pay(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $back = "/admin/finance/invoices/{$id}";

        $v = new Validator($this->request);
        $paidOn = $v->date('paid_on', 'Data do pagamento');
        $method = $v->enum('payment_method', 'Forma de pagamento', array_keys(Invoice::PAYMENT_METHODS));
        $notes = $v->string('notes', 'Observações', 1, 500, required: false);
        if ($paidOn !== null && $paidOn > TenantContext::today()) {
            $v->addError('paid_on', 'A data do pagamento não pode estar no futuro.');
        }
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back, 422, ['paid_on', 'payment_method', 'notes']);
        }

        try {
            (new InvoiceService())->recordPayment((int) $id, (string) $paidOn, (string) $method, $notes, (int) Auth::id(), $this->request);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, $back);
        }

        return $this->done('Pagamento registrado.', $back);
    }

    /** POST /admin/finance/invoices/{id}/cancel */
    public function cancel(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $back = "/admin/finance/invoices/{$id}";

        $v = new Validator($this->request);
        $reason = $v->string('cancellation_reason', 'Motivo', 5, 255);
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back);
        }

        try {
            (new InvoiceService())->cancel((int) $id, (string) $reason, $this->request);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, $back);
        }

        return $this->done('Cobrança cancelada.', $back);
    }

    /**
     * Reads and checks ?from=&to= (local dates, inclusive range).
     *
     * @return array{0: string, 1: string, 2: array<string, string>}
     */
    private function periodFromQuery(): array
    {
        $from = $this->request->queryString('from');
        $to = $this->request->queryString('to');
        $errors = [];
        if (!self::isDate($from)) {
            $errors['from'] = 'Data inicial inválida.';
        }
        if (!self::isDate($to)) {
            $errors['to'] = 'Data final inválida.';
        }
        if ($errors === []) {
            $days = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');
            if ($days < 0) {
                $errors['to'] = 'A data final deve ser igual ou posterior à inicial.';
            } elseif ($days >= self::MAX_PERIOD_DAYS) {
                $errors['to'] = 'O período pode ter no máximo ' . self::MAX_PERIOD_DAYS . ' dias.';
            }
        }

        return [$from, $to, $errors];
    }

    /** @return array<string, string|int> */
    private function periodTotals(string $from, string $to): array
    {
        // Payments are UTC DATETIMEs: the local days [from, to] become the UTC
        // interval [local midnight of from, local midnight of the day after to).
        $fromUtc = TenantContext::localToUtc($from);
        $toUtcExcl = TenantContext::localToUtc((new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d'));

        return (new Invoice())->periodTotals($from, $to, $fromUtc, $toUtcExcl, TenantContext::today());
    }

    private static function isDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
```


**`app/Views/admin/finance/reports.php`**

```php
<?php
/**
 * Financial reports. The period totals are loaded by public/assets/js/admin/reports.js
 * from GET /admin/finance/reports/period and written with textContent; the
 * delinquency table is rendered here. Both CSV exports are plain GET downloads.
 *
 * @var string                     $defaultFrom
 * @var string                     $defaultTo
 * @var list<array<string, mixed>> $delinquency
 * @var string                     $delinquencyTotal DECIMAL string.
 * @var string                     $today
 */
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Relatórios financeiros</h1>
        <p class="page-header__subtitle">Valores calculados pelo banco de dados, em reais</p>
    </div>
</section>

<section class="panel panel--padded">
    <h2 class="panel__title">Relatório do período</h2>
    <form class="filters filters--flush" id="period-form" action="/admin/finance/reports/period" novalidate>
        <label class="filters__field">
            <span class="form__label">Mês</span>
            <input type="month" name="month" value="<?= e(substr($defaultFrom, 0, 7)) ?>">
        </label>
        <span class="filters__or muted">ou</span>
        <label class="filters__field">
            <span class="form__label">De</span>
            <input type="date" name="from" required value="<?= e($defaultFrom) ?>">
        </label>
        <label class="filters__field">
            <span class="form__label">Até</span>
            <input type="date" name="to" required value="<?= e($defaultTo) ?>">
        </label>
        <button type="submit" class="btn btn--primary">Gerar relatório</button>
        <a class="btn" id="period-csv" href="/admin/finance/reports/period.csv?from=<?= e($defaultFrom) ?>&amp;to=<?= e($defaultTo) ?>" download>Exportar CSV</a>
        <span class="feedback" id="period-feedback" role="status"></span>
    </form>

    <div class="kpis" id="period-totals" aria-live="polite" aria-busy="true">
        <div class="kpi">
            <span class="kpi__label">Total faturado</span>
            <strong class="kpi__value" data-total="billed">—</strong>
            <span class="kpi__hint" data-total="invoice_count"></span>
        </div>
        <div class="kpi kpi--success">
            <span class="kpi__label">Total recebido</span>
            <strong class="kpi__value" data-total="received">—</strong>
            <span class="kpi__hint" data-total="payment_count"></span>
        </div>
        <div class="kpi">
            <span class="kpi__label">A vencer</span>
            <strong class="kpi__value" data-total="pending">—</strong>
        </div>
        <div class="kpi kpi--danger">
            <span class="kpi__label">Vencido</span>
            <strong class="kpi__value" data-total="overdue">—</strong>
            <span class="kpi__hint" data-total="overdue_count"></span>
        </div>
    </div>
    <p class="form__hint">Faturado, a vencer e vencido consideram as cobranças com vencimento no período; recebido considera os pagamentos registrados no período.</p>
</section>

<section class="panel">
    <h2 class="panel__title panel__title--bar">
        Inadimplência em <?= e(date_br($today)) ?>
        <a class="btn btn--small panel__title-action" href="/admin/finance/reports/delinquency.csv" download>Exportar CSV</a>
    </h2>
    <?php if ($delinquency === []): ?>
        <p class="state">Nenhuma unidade com cobranças vencidas.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Unidade</th><th class="num">Cobranças vencidas</th><th class="num">Valor devido</th><th>Vencimento mais antigo</th><th class="num">Dias em atraso</th><th class="table__action"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($delinquency as $row): ?>
                    <tr class="row--overdue">
                        <td class="strong"><?= e($row['unit_label']) ?></td>
                        <td class="num"><?= e((int) $row['overdue_count']) ?></td>
                        <td class="num"><?= e(money_br((string) $row['amount_owed'])) ?></td>
                        <td><?= e(date_br((string) $row['oldest_due_date'])) ?></td>
                        <td class="num"><?= e((int) $row['days_overdue']) ?></td>
                        <td class="table__action">
                            <a class="btn btn--small" href="/admin/finance/invoices?status=overdue&amp;unit_id=<?= e($row['unit_id']) ?>">Ver cobranças</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr><th>Total</th><th></th><th class="num"><?= e(money_br($delinquencyTotal)) ?></th><th colspan="3"></th></tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</section>
```


**`app/Views/admin/finance/invoices.php`**

```php
<?php
/**
 * Invoice list with filters; each row links to its detail page, where payment
 * and cancellation are recorded.
 *
 * @var list<array<string, mixed>>          $invoices
 * @var list<array{id: int, label: string}> $units
 * @var array<string, string>               $query
 * @var array{page: int, per_page: int, total: int, pages: int} $pagination
 * @var array<string, string>               $types
 * @var array<string, string>               $statusLabels
 */
$statusFilters = ['open' => 'Em aberto', 'overdue' => 'Vencidas', 'paid' => 'Pagas', 'cancelled' => 'Canceladas'];
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Cobranças</h1>
        <p class="page-header__subtitle">Registre pagamentos manuais ou cancele cobranças em aberto</p>
    </div>
    <a class="btn" href="/finance">Lançar cobrança</a>
</section>

<section class="panel">
    <form class="filters" method="get" action="/admin/finance/invoices">
        <label class="filters__field">
            <span class="sr-only">Situação</span>
            <select name="status">
                <option value="">Todas as situações</option>
                <?php foreach ($statusFilters as $code => $label): ?>
                    <option value="<?= e($code) ?>"<?= ($query['status'] ?? '') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filters__field">
            <span class="sr-only">Unidade</span>
            <select name="unit_id">
                <option value="">Todas as unidades</option>
                <?php foreach ($units as $unit): ?>
                    <option value="<?= e($unit['id']) ?>"<?= ($query['unit_id'] ?? '') === (string) $unit['id'] ? ' selected' : '' ?>><?= e($unit['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filters__field">
            <span class="form__label">Vencimento de</span>
            <input type="date" name="from" value="<?= e($query['from'] ?? '') ?>">
        </label>
        <label class="filters__field">
            <span class="form__label">até</span>
            <input type="date" name="to" value="<?= e($query['to'] ?? '') ?>">
        </label>
        <button type="submit" class="btn">Filtrar</button>
    </form>

    <?php if ($invoices === []): ?>
        <p class="state">Nenhuma cobrança encontrada.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Nº</th><th>Unidade</th><th>Tipo</th><th>Referência</th><th>Vencimento</th><th class="num">Valor</th><th>Situação</th><th class="table__action"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($invoices as $invoice): ?>
                    <?php $overdue = (int) $invoice['is_overdue'] === 1; ?>
                    <tr class="<?= $overdue ? 'row--overdue' : '' ?>">
                        <td class="mono"><?= e($invoice['invoice_number']) ?></td>
                        <td><?= e($invoice['unit_label']) ?></td>
                        <td><?= e($types[$invoice['invoice_type']] ?? $invoice['invoice_type']) ?></td>
                        <td><?= e(substr((string) $invoice['reference_month'], 5, 2) . '/' . substr((string) $invoice['reference_month'], 0, 4)) ?></td>
                        <td><?= e(date_br((string) $invoice['due_date'])) ?></td>
                        <td class="num"><?= e(money_br((string) $invoice['total_amount'])) ?></td>
                        <td><?= $overdue ? pill('overdue', 'Vencida') : pill((string) $invoice['status'], $statusLabels[$invoice['status']] ?? (string) $invoice['status']) ?></td>
                        <td class="table__action"><a class="btn btn--small" href="/admin/finance/invoices/<?= e($invoice['id']) ?>">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= partial('pagination', ['pagination' => $pagination, 'basePath' => '/admin/finance/invoices', 'query' => $query]) ?>
</section>
```


**`app/Views/admin/finance/invoice.php`**

```php
<?php
/**
 * One invoice: details, payments, and (when open) the manual payment and
 * cancellation forms. Invoices are never deleted.
 *
 * @var array<string, mixed>  $invoice        Invoice::findDetailed() row (+ payments).
 * @var array<string, string> $types
 * @var array<string, string> $statusLabels
 * @var array<string, string> $paymentMethods
 * @var string                $today
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$overdue = (int) $invoice['is_overdue'] === 1;
$id = (int) $invoice['id'];
?>
<section class="page-header">
    <div>
        <p class="page-header__eyebrow"><a href="/admin/finance/invoices">← Cobranças</a></p>
        <h1 class="page-header__title">Cobrança nº <?= e($invoice['invoice_number']) ?></h1>
        <p class="page-header__subtitle">
            <?= e($invoice['unit_label']) ?> ·
            <?= $overdue ? pill('overdue', 'Vencida') : pill((string) $invoice['status'], $statusLabels[$invoice['status']] ?? (string) $invoice['status']) ?>
        </p>
    </div>
</section>

<?= field_error($errors, 'general') ?>

<div class="grid-2">
    <section class="panel panel--padded">
        <h2 class="panel__title">Dados</h2>
        <dl class="details">
            <dt>Tipo</dt><dd><?= e($types[$invoice['invoice_type']] ?? $invoice['invoice_type']) ?></dd>
            <dt>Referência</dt><dd><?= e(substr((string) $invoice['reference_month'], 5, 2) . '/' . substr((string) $invoice['reference_month'], 0, 4)) ?></dd>
            <dt>Emissão</dt><dd><?= e(date_br((string) $invoice['issue_date'])) ?></dd>
            <dt>Vencimento</dt><dd><?= e(date_br((string) $invoice['due_date'])) ?></dd>
            <dt>Valor</dt><dd class="strong"><?= e(money_br((string) $invoice['total_amount'])) ?></dd>
            <?php if ($invoice['paid_at'] !== null): ?>
                <dt>Pago em</dt><dd><?= e(local_datetime((string) $invoice['paid_at'], 'd/m/Y')) ?></dd>
            <?php endif; ?>
            <?php if ($invoice['status'] === 'cancelled'): ?>
                <dt>Cancelada em</dt><dd><?= e(local_datetime((string) $invoice['cancelled_at'])) ?></dd>
                <dt>Motivo</dt><dd class="prewrap"><?= e($invoice['cancellation_reason']) ?></dd>
            <?php endif; ?>
            <?php if ($invoice['notes'] !== null): ?>
                <dt>Observações</dt><dd class="prewrap"><?= e($invoice['notes']) ?></dd>
            <?php endif; ?>
        </dl>

        <?php if ($invoice['payments'] !== []): ?>
            <h3 class="panel__subtitle">Pagamentos</h3>
            <table class="table table--compact">
                <thead><tr><th>Data</th><th>Forma</th><th class="num">Valor</th><th>Registrado por</th></tr></thead>
                <tbody>
                <?php foreach ($invoice['payments'] as $payment): ?>
                    <tr>
                        <td><?= e(local_datetime((string) $payment['paid_at'], 'd/m/Y')) ?></td>
                        <td><?= e($paymentMethods[$payment['payment_method']] ?? $payment['payment_method']) ?></td>
                        <td class="num"><?= e(money_br((string) $payment['amount'])) ?></td>
                        <td><?= e($payment['recorded_by'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <?php if ($invoice['status'] === 'open'): ?>
        <div class="stack">
            <section class="panel panel--padded">
                <h2 class="panel__title">Registrar pagamento</h2>
                <form method="post" action="/admin/finance/invoices/<?= e($id) ?>/payment" class="form" novalidate
                      data-confirm="Registrar o pagamento integral de <?= e(money_br((string) $invoice['total_amount'])) ?>?">
                    <?= csrf_field() ?>
                    <div class="form__row">
                        <label class="form__field">
                            <span class="form__label">Data do pagamento</span>
                            <input type="date" name="paid_on" required max="<?= e($today) ?>" value="<?= e($old['paid_on'] ?? $today) ?>">
                            <?= field_error($errors, 'paid_on') ?>
                        </label>
                        <label class="form__field">
                            <span class="form__label">Forma</span>
                            <select name="payment_method">
                                <?php foreach ($paymentMethods as $code => $label): ?>
                                    <option value="<?= e($code) ?>"<?= ($old['payment_method'] ?? 'pix') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= field_error($errors, 'payment_method') ?>
                        </label>
                    </div>
                    <label class="form__field">
                        <span class="form__label">Observações (opcional)</span>
                        <textarea name="notes" rows="2" maxlength="500"><?= e($old['notes'] ?? '') ?></textarea>
                        <?= field_error($errors, 'notes') ?>
                    </label>
                    <p class="form__hint">O valor registrado é o total da cobrança: <?= e(money_br((string) $invoice['total_amount'])) ?>.</p>
                    <button type="submit" class="btn btn--primary">Marcar como paga</button>
                </form>
            </section>

            <section class="panel panel--padded">
                <h2 class="panel__title">Cancelar cobrança</h2>
                <form method="post" action="/admin/finance/invoices/<?= e($id) ?>/cancel" class="form" novalidate
                      data-confirm="Cancelar a cobrança nº <?= e($invoice['invoice_number']) ?>? Ela continuará no histórico como cancelada.">
                    <?= csrf_field() ?>
                    <label class="form__field">
                        <span class="form__label">Motivo</span>
                        <input type="text" name="cancellation_reason" minlength="5" maxlength="255" required>
                        <?= field_error($errors, 'cancellation_reason') ?>
                    </label>
                    <button type="submit" class="btn btn--danger">Cancelar cobrança</button>
                </form>
            </section>
        </div>
    <?php endif; ?>
</div>
```


### 3.5 Account self-service (all roles)

No user id appears in any `/account` URL or form: every action works on `Auth::id()`.

- **Login** gains the database rate limit.
- **The login page** gains "Esqueci minha senha".

**`app/Models/User.php`** (existing file: changes only)

```diff
@@ -106,4 +106,58 @@ final class User extends Model
             ['password_hash' => $passwordHash, 'id' => $id]
         );
     }
+
+    /**
+     * Stores a new password hash and increments session_version, which ends
+     * every existing session of the user on their next request (Auth::user()).
+     * The lockout counter is cleared: whoever proved ownership of the account
+     * (current password or e-mailed token) should not stay locked out.
+     */
+    public function updatePassword(int $id, string $passwordHash): void
+    {
+        $this->execute(
+            'UPDATE users
+                SET password_hash = :password_hash,
+                    session_version = session_version + 1,
+                    failed_login_count = 0,
+                    locked_until = NULL
+              WHERE id = :id',
+            ['password_hash' => $passwordHash, 'id' => $id]
+        );
+    }
+
+    /** Name and phone, edited by the user themself (never by a manager: users is global). */
+    public function updateProfile(int $id, string $fullName, ?string $phone): void
+    {
+        $this->execute(
+            'UPDATE users SET full_name = :full_name, phone = :phone WHERE id = :id',
+            ['full_name' => $fullName, 'phone' => $phone, 'id' => $id]
+        );
+    }
+
+    /** Relative path under storage/uploads, or null to remove the avatar. */
+    public function updateAvatar(int $id, ?string $avatarPath): void
+    {
+        $this->execute(
+            'UPDATE users SET avatar_path = :avatar_path WHERE id = :id',
+            ['avatar_path' => $avatarPath, 'id' => $id]
+        );
+    }
+
+    /**
+     * Moves the account to a confirmed new address. The new address was proven
+     * by the e-mailed token, so it is verified now; other sessions end because
+     * the login identifier changed.
+     */
+    public function changeEmail(int $id, string $newEmail): void
+    {
+        $this->execute(
+            'UPDATE users
+                SET email = :email,
+                    email_verified_at = UTC_TIMESTAMP(),
+                    session_version = session_version + 1
+              WHERE id = :id',
+            ['email' => self::normalizeEmail($newEmail), 'id' => $id]
+        );
+    }
 }
```


**`app/Services/AccountService.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Mail\Mailer;
use App\Models\EmailChangeToken;
use App\Models\PasswordResetToken;
use App\Models\User;
use finfo;
use PDOException;

/**
 * Self-service for every role (Phase 5): profile, avatar, password and e-mail.
 *
 * OWNERSHIP: every method takes the user id from the caller, and every caller
 * passes Auth::id() (the session's user, re-validated against the database on
 * each request). No method accepts a user id from the request, so one user can
 * never edit another's profile. Role and condominium are not editable here at
 * all: they live in condominium_users and are changed only by a manager.
 */
final class AccountService
{
    public const EMAIL_VALID = 'valid';
    public const EMAIL_EXPIRED = 'expired';
    public const EMAIL_TAKEN = 'taken';
    public const EMAIL_INVALID = 'invalid';

    /** Allowed avatar types, detected from the file CONTENT (finfo), not from the name. */
    private const AVATAR_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly Request $request,
        private readonly User $users = new User(),
        private readonly EmailChangeToken $emailTokens = new EmailChangeToken(),
        private readonly TokenService $tokenService = new TokenService(),
        private readonly RateLimiter $limiter = new RateLimiter(),
        private readonly Mailer $mailer = new Mailer()
    ) {
    }

    /** Name and phone. */
    public function updateProfile(int $userId, string $fullName, ?string $phone): void
    {
        $this->users->updateProfile($userId, $fullName, $phone);
        (new AuditLogger($this->request))->record('account.profile_updated', $userId, null, 'user', $userId);
    }

    /**
     * Replaces the avatar with an uploaded image.
     *
     * The upload is checked by content (finfo + getimagesize), stored under a
     * random name OUTSIDE the web root (storage/uploads/avatars) and only ever
     * served by AccountController::avatar() with a fixed Content-Type. The
     * browser-supplied file name and type are ignored.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @throws BusinessRuleException
     */
    public function replaceAvatar(int $userId, array $file): void
    {
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new BusinessRuleException('Não foi possível receber a imagem. Tente novamente.', 422, 'avatar');
        }
        $maxBytes = (int) Config::get('security.avatar_max_bytes', 1_048_576);
        if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
            throw new BusinessRuleException('A imagem deve ter no máximo ' . intdiv($maxBytes, 1024) . ' KB.', 422, 'avatar');
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $size = @getimagesize($file['tmp_name']);
        if (!isset(self::AVATAR_TYPES[$mime]) || $size === false || $size[0] > 4000 || $size[1] > 4000) {
            throw new BusinessRuleException('Envie uma imagem JPG, PNG ou WebP de até 4000×4000 pixels.', 422, 'avatar');
        }

        $relative = 'avatars/' . bin2hex(random_bytes(16)) . '.' . self::AVATAR_TYPES[$mime];
        $directory = self::uploadsPath() . '/avatars';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new BusinessRuleException('Não foi possível salvar a imagem agora.', 500, 'avatar');
        }
        if (!move_uploaded_file($file['tmp_name'], self::uploadsPath() . '/' . $relative)) {
            throw new BusinessRuleException('Não foi possível salvar a imagem agora.', 500, 'avatar');
        }

        $previous = $this->users->find($userId)['avatar_path'] ?? null;
        $this->users->updateAvatar($userId, $relative);
        self::deleteUpload(is_string($previous) ? $previous : null);
        (new AuditLogger($this->request))->record('account.avatar_updated', $userId, null, 'user', $userId);
    }

    /** Removes the avatar (file and column). */
    public function removeAvatar(int $userId): void
    {
        $previous = $this->users->find($userId)['avatar_path'] ?? null;
        $this->users->updateAvatar($userId, null);
        self::deleteUpload(is_string($previous) ? $previous : null);
    }

    /**
     * Absolute path of a user's avatar file, or null. The stored path is
     * re-validated against the exact format this class writes, so a tampered
     * database value cannot point the file server at "../../.env".
     */
    public static function avatarFile(?string $relative): ?string
    {
        if ($relative === null || preg_match('#^avatars/[a-f0-9]{32}\.(jpg|png|webp)$#', $relative) !== 1) {
            return null;
        }
        $path = self::uploadsPath() . '/' . $relative;

        return is_file($path) ? $path : null;
    }

    /**
     * Changes the password after checking the current one.
     *
     * session_version is incremented (all OTHER sessions end); the caller then
     * calls Auth::refreshAfterCredentialChange() so the current session keeps
     * working under a new session id.
     *
     * @throws BusinessRuleException 422 when the current password is wrong.
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->users->find($userId) ?? throw new BusinessRuleException('Conta não encontrada.', 404);
        if ($user['password_hash'] === null || !password_verify($currentPassword, (string) $user['password_hash'])) {
            (new AuditLogger($this->request))->record('account.password_change_failed', $userId, null, 'user', $userId);
            throw new BusinessRuleException('A senha atual está incorreta.', 422, 'current_password');
        }
        if (password_verify($newPassword, (string) $user['password_hash'])) {
            throw new BusinessRuleException('A nova senha deve ser diferente da atual.', 422, 'password');
        }

        $hash = password_hash(
            $newPassword,
            Config::get('security.password_algo', PASSWORD_DEFAULT),
            Config::get('security.password_options', [])
        );
        Database::transaction(function () use ($userId, $hash): void {
            $this->users->updatePassword($userId, $hash);
            // Any reset link e-mailed earlier must not be usable to undo this change.
            (new PasswordResetToken())->revokeOutstanding($userId);
            (new AuditLogger($this->request))->record('auth.password_changed', $userId, null, 'user', $userId);
        });

        $this->mailer->sendSecurityNotice(
            (string) $user['email'],
            (string) $user['full_name'],
            'A senha da sua conta no Koinon foi alterada.'
        );
    }

    /**
     * Starts an e-mail change: checks the password, then e-mails a confirmation
     * link to the NEW address. users.email changes only when that link is used.
     *
     * Whether the new address already belongs to another account is NOT
     * revealed here (that would let any user probe which e-mails are
     * registered); it is checked again when the link is confirmed.
     *
     * @throws BusinessRuleException
     */
    public function requestEmailChange(int $userId, string $currentPassword, string $newEmail): void
    {
        $user = $this->users->find($userId) ?? throw new BusinessRuleException('Conta não encontrada.', 404);
        if ($user['password_hash'] === null || !password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new BusinessRuleException('A senha atual está incorreta.', 422, 'email_password');
        }
        if ($newEmail === $user['email']) {
            throw new BusinessRuleException('Este já é o seu e-mail atual.', 422, 'new_email');
        }
        if (!$this->limiter->attempt('email_change', ['account' => (string) $userId])) {
            throw new BusinessRuleException('Muitas solicitações. Tente novamente mais tarde.', 429, 'new_email');
        }

        ['raw' => $raw, 'hash' => $hash] = $this->tokenService->generate();
        $ttl = (int) Config::get('security.email_change_ttl_hours', 24) * 60;
        Database::transaction(function () use ($userId, $newEmail, $hash, $ttl): void {
            $this->emailTokens->revokeOutstanding($userId);
            $this->emailTokens->create($userId, $newEmail, $hash, $this->request->ip(), $ttl);
        });
        (new AuditLogger($this->request))->record('account.email_change_requested', $userId, null, 'user', $userId);

        $this->mailer->sendEmailChange($newEmail, (string) $user['full_name'], $raw);
    }

    /** Read-only state of an e-mail change link (GET page). */
    public function inspectEmailChange(string $rawToken): string
    {
        if (!$this->tokenService->isWellFormed($rawToken)) {
            return self::EMAIL_INVALID;
        }

        return $this->evaluateEmailToken($this->emailTokens->findByHash($this->tokenService->hash($rawToken)), $rawToken);
    }

    /**
     * Consumes the link and moves the account to the new address.
     *
     * @return string One of the EMAIL_* constants.
     */
    public function confirmEmailChange(string $rawToken): string
    {
        if (!$this->tokenService->isWellFormed($rawToken)) {
            return self::EMAIL_INVALID;
        }

        try {
            $result = $this->applyEmailChange($rawToken);
        } catch (PDOException $e) {
            // 1062: someone registered the address between the check and the UPDATE.
            if (($e->errorInfo[1] ?? null) === 1062) {
                return self::EMAIL_TAKEN;
            }
            throw $e;
        }

        [$state, $user] = $result;
        if ($state === self::EMAIL_VALID && is_array($user)) {
            $this->mailer->sendSecurityNotice(
                (string) $user['email'],
                (string) $user['full_name'],
                'O e-mail de acesso da sua conta no Koinon foi alterado. Este endereço não será mais usado para entrar.'
            );
        }

        return $state;
    }

    /**
     * The transactional part of confirmEmailChange().
     *
     * @return array{0: string, 1: array<string, mixed>|null} state and the user row before the change
     */
    private function applyEmailChange(string $rawToken): array
    {
        return Database::transaction(function () use ($rawToken): array {
            $token = $this->emailTokens->findByHash($this->tokenService->hash($rawToken), true);
            $state = $this->evaluateEmailToken($token, $rawToken);
            if ($state !== self::EMAIL_VALID) {
                return [$state, null];
            }

            $userId = (int) $token['user_id'];
            $user = $this->users->findForUpdate($userId);
            if ($user === null || $user['status'] !== 'active') {
                return [self::EMAIL_INVALID, null];
            }
            // The address may have been registered by someone else since the request.
            $owner = $this->users->findByEmail((string) $token['new_email']);
            if ($owner !== null && (int) $owner['id'] !== $userId) {
                $this->emailTokens->revokeOutstanding($userId);

                return [self::EMAIL_TAKEN, null];
            }

            $this->emailTokens->markConsumed((int) $token['id']);
            $this->users->changeEmail($userId, (string) $token['new_email']);
            $this->emailTokens->revokeOutstanding($userId);
            (new AuditLogger($this->request))->record('account.email_changed', $userId, null, 'user', $userId);

            return [self::EMAIL_VALID, $user];
        });
    }

    /** @param array<string, mixed>|null $token */
    private function evaluateEmailToken(?array $token, string $rawToken): string
    {
        if ($token === null || !$this->tokenService->matches($rawToken, (string) $token['token_hash'])) {
            return self::EMAIL_INVALID;
        }
        if ($token['consumed_at'] !== null || $token['revoked_at'] !== null) {
            return self::EMAIL_INVALID;
        }

        return (int) $token['is_expired'] === 1 ? self::EMAIL_EXPIRED : self::EMAIL_VALID;
    }

    private static function uploadsPath(): string
    {
        return BASE_PATH . '/storage/uploads';
    }

    private static function deleteUpload(?string $relative): void
    {
        $path = self::avatarFile($relative);
        if ($path !== null && !@unlink($path)) {
            Logger::warning('Could not delete old avatar', ['path' => $relative]);
        }
    }
}
```


**`app/Services/PasswordResetService.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Mail\Mailer;
use App\Models\PasswordResetToken;
use App\Models\User;

/**
 * "Forgot password" and "reset password" (Phase 5).
 *
 *  - request() is SILENT: the controller shows the same message whether the
 *    e-mail exists, is inactive, or was rate-limited, so the form cannot be used
 *    to discover which e-mails have accounts (account enumeration).
 *  - inspect() is read-only (GET landing page): mail scanners that open links
 *    must not burn the single-use token.
 *  - reset() locks the token row, re-checks it, stores the new password,
 *    increments session_version (every session of the account ends) and revokes
 *    every other outstanding reset token, all in one transaction.
 */
final class PasswordResetService
{
    public const VALID = 'valid';
    public const EXPIRED = 'expired';
    public const INVALID = 'invalid';

    public function __construct(
        private readonly Request $request,
        private readonly PasswordResetToken $tokens = new PasswordResetToken(),
        private readonly User $users = new User(),
        private readonly TokenService $tokenService = new TokenService(),
        private readonly RateLimiter $limiter = new RateLimiter(),
        private readonly Mailer $mailer = new Mailer()
    ) {
    }

    /** Sends a reset link when the account exists and is active. Never reveals the outcome. */
    public function request(string $email): void
    {
        $audit = new AuditLogger($this->request);
        $email = User::normalizeEmail($email);

        // Counted for every submitted address, existing or not, so the limiter
        // itself does not behave differently for real accounts.
        if (!$this->limiter->attempt('password_forgot', ['ip' => $this->request->ip(), 'account' => $email])) {
            $audit->record('auth.password_reset_throttled', null);

            return;
        }

        $user = $email === '' ? null : $this->users->findByEmail($email);
        // Only active accounts can reset. Invited users finish through their
        // invitation link; blocked or deleted accounts must not be revived here.
        if ($user === null || $user['status'] !== 'active') {
            return;
        }

        $userId = (int) $user['id'];
        ['raw' => $raw, 'hash' => $hash] = $this->tokenService->generate();
        Database::transaction(function () use ($userId, $hash): void {
            // One usable link at a time: an older e-mail stops working.
            $this->tokens->revokeOutstanding($userId);
            $this->tokens->create($userId, $hash, $this->request->ip(), (int) Config::get('security.password_reset_ttl_minutes', 60));
        });
        $audit->record('auth.password_reset_requested', $userId, null, 'user', $userId);

        // Sent after the commit: a slow SMTP server must never hold database locks.
        $this->mailer->sendPasswordReset((string) $user['email'], (string) $user['full_name'], $raw);
    }

    /** State of a raw token for the GET page (valid | expired | invalid). */
    public function inspect(string $rawToken): string
    {
        if (!$this->tokenService->isWellFormed($rawToken)) {
            return self::INVALID;
        }

        return $this->evaluate($this->tokens->findByHash($this->tokenService->hash($rawToken)), $rawToken);
    }

    /**
     * Consumes the token and sets the new password.
     *
     * @return string self::VALID on success, otherwise the reason it failed.
     */
    public function reset(string $rawToken, string $newPassword): string
    {
        if (!$this->tokenService->isWellFormed($rawToken)) {
            return self::INVALID;
        }

        $passwordHash = password_hash(
            $newPassword,
            Config::get('security.password_algo', PASSWORD_DEFAULT),
            Config::get('security.password_options', [])
        );

        return Database::transaction(function () use ($rawToken, $passwordHash): string {
            $token = $this->tokens->findByHash($this->tokenService->hash($rawToken), true);
            $state = $this->evaluate($token, $rawToken);
            if ($state !== self::VALID) {
                return $state;
            }

            $userId = (int) $token['user_id'];
            $user = $this->users->findForUpdate($userId);
            if ($user === null || $user['status'] !== 'active') {
                return self::INVALID;
            }

            $this->tokens->markConsumed((int) $token['id']);
            // updatePassword() increments session_version: every session of this
            // account, including one an attacker may hold, ends on its next request.
            $this->users->updatePassword($userId, $passwordHash);
            // Brief rule: a successful reset invalidates the user's other reset links.
            $this->tokens->revokeOutstanding($userId);
            (new AuditLogger($this->request))->record('auth.password_reset', $userId, null, 'user', $userId);

            return self::VALID;
        });
    }

    /** @param array<string, mixed>|null $token */
    private function evaluate(?array $token, string $rawToken): string
    {
        // hash_equals() as a second, constant-time check after the indexed lookup.
        if ($token === null || !$this->tokenService->matches($rawToken, (string) $token['token_hash'])) {
            return self::INVALID;
        }
        if ($token['consumed_at'] !== null || $token['revoked_at'] !== null) {
            return self::INVALID;
        }

        return (int) $token['is_expired'] === 1 ? self::EXPIRED : self::VALID;
    }
}
```


**`app/Controllers/AccountController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\AccountService;
use App\Services\BusinessRuleException;

/**
 * "Minha conta" for every role (Phase 5): profile, avatar, password, e-mail.
 *
 * OWNERSHIP: every action works on Auth::id(), the session's user re-validated
 * against the database on each request. There is no user id in these URLs or
 * forms, so a user can only ever change their own account. Role and
 * condominium are not editable here (they belong to the manager's screens).
 */
final class AccountController extends Controller
{
    /** GET /account */
    public function show(): Response
    {
        $user = $this->user();

        return $this->view('account/index', [
            'title'       => 'Minha conta',
            'activeNav'   => 'account',
            'account'     => $user,
            'hasAvatar'   => AccountService::avatarFile($user['avatar_path']) !== null,
            'passwordMin' => (int) Config::get('security.password_min', 10),
        ]);
    }

    /** POST /account/profile (multipart: the avatar is optional) */
    public function updateProfile(): Response
    {
        $userId = (int) Auth::id();
        $v = new Validator($this->request);
        $name = $v->string('full_name', 'Nome', 3, 150);
        $phone = $v->phone('phone');
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/account', 422, ['full_name', 'phone']);
        }

        $service = new AccountService($this->request);
        $service->updateProfile($userId, (string) $name, $phone);

        try {
            $avatar = $this->request->file('avatar');
            if ($avatar !== null) {
                $service->replaceAvatar($userId, $avatar);
            } elseif ($this->request->boolean('remove_avatar')) {
                $service->removeAvatar($userId);
            }
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'avatar' => $e->getMessage()], '/account', $e->status());
        }

        return $this->done('Perfil atualizado.', '/account');
    }

    /**
     * GET /account/avatar: the current user's own avatar.
     *
     * Served from storage/ (outside the web root) with the Content-Type of the
     * stored extension, nosniff (set globally) and a restrictive CSP, so even a
     * crafted image cannot be interpreted as HTML or script.
     */
    public function avatar(): Response
    {
        $path = AccountService::avatarFile($this->user()['avatar_path']) ?? throw new HttpException(404);
        $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

        return Response::file((string) file_get_contents($path), $types[pathinfo($path, PATHINFO_EXTENSION)])
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withHeader('Cache-Control', 'private, max-age=300');
    }

    /** POST /account/password */
    public function changePassword(): Response
    {
        $userId = (int) Auth::id();
        $v = new Validator($this->request);
        $current = $this->request->string('current_password');
        if ($current === '') {
            $v->addError('current_password', 'Informe a senha atual.');
        }
        $new = $v->newPassword();
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/account');
        }

        try {
            (new AccountService($this->request))->changePassword($userId, $current, (string) $new);
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], '/account', $e->status());
        }

        // session_version was incremented: every OTHER session of this account is
        // now dead. This one continues under a new session id and CSRF token.
        Auth::refreshAfterCredentialChange($userId);

        return $this->done('Senha alterada. As outras sessões abertas da sua conta foram encerradas.', '/account');
    }

    /** POST /account/email: e-mails a confirmation link to the new address. */
    public function requestEmailChange(): Response
    {
        $v = new Validator($this->request);
        $email = $v->email('new_email', 'Novo e-mail');
        $password = $this->request->string('email_password');
        if ($password === '') {
            $v->addError('email_password', 'Informe sua senha.');
        }
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/account', 422, ['new_email']);
        }

        try {
            (new AccountService($this->request))->requestEmailChange((int) Auth::id(), $password, (string) $email);
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], '/account', $e->status(), ['new_email']);
        }

        return $this->done("Enviamos um link de confirmação para {$email}. O e-mail só muda depois da confirmação.", '/account');
    }

    /**
     * GET /account/email/confirm?token=... (works logged out: the link may be
     * opened on another device). Read-only: shows a confirmation button.
     */
    public function showEmailConfirmation(): Response
    {
        $token = $this->request->query('token', '');
        $token = is_string($token) ? $token : '';
        $state = (new AccountService($this->request))->inspectEmailChange($token);

        return $this->emailConfirmationPage($token, $state);
    }

    /** POST /account/email/confirm */
    public function confirmEmail(): Response
    {
        $token = $this->request->string('token');
        $state = (new AccountService($this->request))->confirmEmailChange($token);
        if ($state !== AccountService::EMAIL_VALID) {
            return $this->emailConfirmationPage($token, $state);
        }

        // The login identifier changed and session_version was incremented, so
        // every session (including this browser's, if any) ends.
        Auth::logout();
        Session::flash('success', 'E-mail alterado. Entre novamente usando o novo endereço.');

        return $this->redirect('/login');
    }

    private function emailConfirmationPage(string $token, string $state): Response
    {
        return $this->view('account/email-confirm', [
            'title' => 'Confirmar novo e-mail',
            'state' => $state,
            'token' => $token,
        ], layout: 'layouts/auth')
            // The token is in the URL: never leak it to other sites via Referer.
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
```


**`app/Controllers/PasswordResetController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\PasswordResetService;
use App\Services\RateLimiter;

/**
 * Forgot password / reset password (Phase 5). Works logged out.
 */
final class PasswordResetController extends Controller
{
    /** The only answer "forgot password" ever gives (no account enumeration). */
    private const SENT_MESSAGE = 'Se houver uma conta ativa com este e-mail, enviamos um link para redefinir a senha. Verifique sua caixa de entrada.';

    /** GET /account/forgot-password */
    public function showForgot(): Response
    {
        return $this->view('account/forgot-password', ['title' => 'Esqueci minha senha'], layout: 'layouts/auth');
    }

    /**
     * POST /account/forgot-password
     *
     * Same message and same redirect whatever happened: unknown e-mail,
     * inactive account, rate-limited, or link sent. A malformed address is not
     * even reported as invalid, for the same reason.
     */
    public function sendLink(): Response
    {
        (new PasswordResetService($this->request))->request($this->request->string('email'));
        Session::flash('success', self::SENT_MESSAGE);

        return $this->redirect('/login');
    }

    /** GET /account/reset-password?token=... Read-only: never consumes the token. */
    public function showReset(): Response
    {
        $token = $this->request->query('token', '');
        $token = is_string($token) ? $token : '';

        return $this->resetPage($token, (new PasswordResetService($this->request))->inspect($token), []);
    }

    /** POST /account/reset-password */
    public function reset(): Response
    {
        $token = $this->request->string('token');
        if (!(new RateLimiter())->attempt('password_reset', ['ip' => $this->request->ip()])) {
            Session::flash('error', 'Muitas tentativas. Aguarde alguns minutos e tente novamente.');

            return $this->resetPage($token, PasswordResetService::VALID, [], 429);
        }

        $service = new PasswordResetService($this->request);
        $state = $service->inspect($token);
        if ($state !== PasswordResetService::VALID) {
            return $this->resetPage($token, $state, []);
        }

        $v = new Validator($this->request);
        $password = $v->newPassword();
        if ($v->fails()) {
            return $this->resetPage($token, $state, $v->errors(), 422);
        }

        $state = $service->reset($token, (string) $password);
        if ($state !== PasswordResetService::VALID) {
            return $this->resetPage($token, $state, []);
        }

        Session::flash('success', 'Senha redefinida. Entre com a nova senha; as sessões abertas da sua conta foram encerradas.');

        return $this->redirect('/login');
    }

    /** @param array<string, string> $errors */
    private function resetPage(string $token, string $state, array $errors, int $status = 200): Response
    {
        return $this->view('account/reset-password', [
            'title'  => 'Redefinir senha',
            'state'  => $state,
            'token'  => $token,
            'errors' => $errors,
        ], $status, 'layouts/auth')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
```


**`app/Controllers/AuthController.php`** (existing file: changes only)

```diff
@@ -9,8 +9,11 @@ use App\Core\Controller;
 use App\Core\Response;
 use App\Core\Session;
 use App\Models\Membership;
+use App\Models\User;
+use App\Services\AuditLogger;
 use App\Services\AuthService;
 use App\Services\LoginResult;
+use App\Services\RateLimiter;
 
 /**
  * Login and logout.
@@ -34,6 +37,29 @@ final class AuthController extends Controller
     public function login(): Response
     {
         $email = $this->request->string('email');
+
+        // Phase 5: database rate limit per IP and per submitted e-mail, checked
+        // before the password. Counted for unknown e-mails too, so the answer
+        // (429) does not reveal whether an account exists. The per-account
+        // lockout (5 wrong passwords) still applies on top of this.
+        $limited = !(new RateLimiter())->attempt('login', [
+            'ip'      => $this->request->ip(),
+            'account' => User::normalizeEmail($email),
+        ]);
+        if ($limited) {
+            (new AuditLogger($this->request))->record('auth.login_rate_limited', null, null, null, null, [
+                'email' => User::normalizeEmail($email),
+            ]);
+            Session::flash('error', 'Muitas tentativas de login. Aguarde alguns minutos e tente novamente.');
+
+            // Rendered directly (not redirected) so the response carries 429.
+            return $this->view('auth/login', [
+                'title'           => 'Entrar',
+                'email'           => $email,
+                'unverifiedEmail' => null,
+            ], 429, 'layouts/auth');
+        }
+
         $result = (new AuthService())->attempt($email, $this->request->string('password'), $this->request);
 
         if ($result->status === LoginResult::INVALID) {
```


**`app/Views/account/index.php`**

```php
<?php
/**
 * "Minha conta": profile (+ avatar), password and e-mail change.
 * There is no user id anywhere in these forms: the server always acts on the
 * logged-in user.
 *
 * @var array<string, mixed>  $account     The logged-in user's row.
 * @var bool                  $hasAvatar
 * @var int                   $passwordMin
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title">Minha conta</h1>
        <p class="page-header__subtitle"><?= e($account['email']) ?></p>
    </div>
</section>

<?= field_error($errors, 'general') ?>

<div class="grid-2">
    <section class="panel panel--padded">
        <h2 class="panel__title">Perfil</h2>
        <form method="post" action="/account/profile" class="form" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <div class="avatar-row">
                <?php if ($hasAvatar): ?>
                    <img class="avatar" src="/account/avatar" alt="Sua foto" width="64" height="64">
                <?php else: ?>
                    <span class="avatar avatar--empty" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $account['full_name'], 0, 1))) ?></span>
                <?php endif; ?>
                <label class="form__field">
                    <span class="form__label">Foto (JPG, PNG ou WebP, até 1 MB)</span>
                    <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp">
                    <?= field_error($errors, 'avatar') ?>
                </label>
            </div>
            <?php if ($hasAvatar): ?>
                <label class="form__check">
                    <input type="checkbox" name="remove_avatar" value="1"> Remover foto atual
                </label>
            <?php endif; ?>
            <label class="form__field">
                <span class="form__label">Nome completo</span>
                <input type="text" name="full_name" maxlength="150" required autocomplete="name"
                       value="<?= e($old['full_name'] ?? $account['full_name']) ?>">
                <?= field_error($errors, 'full_name') ?>
            </label>
            <label class="form__field">
                <span class="form__label">Telefone (opcional)</span>
                <input type="tel" name="phone" maxlength="30" autocomplete="tel"
                       value="<?= e($old['phone'] ?? $account['phone']) ?>">
                <?= field_error($errors, 'phone') ?>
            </label>
            <div class="form__actions">
                <button type="submit" class="btn btn--primary">Salvar perfil</button>
            </div>
        </form>
    </section>

    <div class="stack">
        <section class="panel panel--padded">
            <h2 class="panel__title">Alterar senha</h2>
            <form method="post" action="/account/password" class="form" novalidate>
                <?= csrf_field() ?>
                <label class="form__field">
                    <span class="form__label">Senha atual</span>
                    <input type="password" name="current_password" autocomplete="current-password" required>
                    <?= field_error($errors, 'current_password') ?>
                </label>
                <label class="form__field">
                    <span class="form__label">Nova senha (mínimo <?= e($passwordMin) ?> caracteres)</span>
                    <input type="password" name="password" autocomplete="new-password" required minlength="<?= e($passwordMin) ?>">
                    <?= field_error($errors, 'password') ?>
                </label>
                <label class="form__field">
                    <span class="form__label">Repita a nova senha</span>
                    <input type="password" name="password_confirmation" autocomplete="new-password" required>
                    <?= field_error($errors, 'password_confirmation') ?>
                </label>
                <p class="form__hint">Ao trocar a senha, as outras sessões abertas da sua conta são encerradas.</p>
                <div class="form__actions">
                    <button type="submit" class="btn btn--primary">Alterar senha</button>
                </div>
            </form>
        </section>

        <section class="panel panel--padded">
            <h2 class="panel__title">Alterar e-mail</h2>
            <form method="post" action="/account/email" class="form" novalidate>
                <?= csrf_field() ?>
                <label class="form__field">
                    <span class="form__label">Novo e-mail</span>
                    <input type="email" name="new_email" maxlength="254" required autocomplete="email"
                           value="<?= e($old['new_email'] ?? '') ?>">
                    <?= field_error($errors, 'new_email') ?>
                </label>
                <label class="form__field">
                    <span class="form__label">Sua senha</span>
                    <input type="password" name="email_password" autocomplete="current-password" required>
                    <?= field_error($errors, 'email_password') ?>
                </label>
                <p class="form__hint">Enviaremos um link para o novo endereço. O e-mail só muda depois da confirmação.</p>
                <div class="form__actions">
                    <button type="submit" class="btn">Enviar confirmação</button>
                </div>
            </form>
        </section>
    </div>
</div>
```


**`app/Views/account/forgot-password.php`**

```php
<?php
/**
 * "Esqueci minha senha". The answer is always the same message (see
 * PasswordResetController::SENT_MESSAGE), whatever e-mail is typed.
 */
?>
<h1 class="auth__title">Esqueci minha senha</h1>
<p>Informe o e-mail da sua conta. Se ela existir e estiver ativa, enviaremos um link para criar uma nova senha.</p>

<form method="post" action="/account/forgot-password" class="form" novalidate>
    <?= csrf_field() ?>
    <label class="form__field">
        <span class="form__label">E-mail</span>
        <input type="email" name="email" autocomplete="email" required autofocus>
    </label>
    <button type="submit" class="btn btn--primary btn--block">Enviar link</button>
</form>

<p class="auth__links"><a href="/login">Voltar ao login</a></p>
```


**`app/Views/account/reset-password.php`**

```php
<?php
/**
 * Landing page of the reset link: valid | expired | invalid
 * (PasswordResetService constants).
 *
 * @var string                $state
 * @var string                $token
 * @var array<string, string> $errors
 */

use App\Services\PasswordResetService;
?>
<h1 class="auth__title">Redefinir senha</h1>

<?php if ($state === PasswordResetService::VALID): ?>
    <form method="post" action="/account/reset-password" class="form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label class="form__field">
            <span class="form__label">Nova senha</span>
            <input type="password" name="password" autocomplete="new-password" required autofocus>
            <?= field_error($errors, 'password') ?>
        </label>
        <label class="form__field">
            <span class="form__label">Repita a nova senha</span>
            <input type="password" name="password_confirmation" autocomplete="new-password" required>
            <?= field_error($errors, 'password_confirmation') ?>
        </label>
        <p class="form__hint">Depois da troca, todas as sessões abertas da sua conta serão encerradas.</p>
        <button type="submit" class="btn btn--primary btn--block">Salvar nova senha</button>
    </form>

<?php elseif ($state === PasswordResetService::EXPIRED): ?>
    <div class="alert alert--warning">Este link expirou. Peça um novo.</div>
    <a class="btn btn--primary btn--block" href="/account/forgot-password">Pedir novo link</a>

<?php else: ?>
    <div class="alert alert--error">Link inválido ou já utilizado. Peça um novo link se ainda precisar trocar a senha.</div>
    <a class="btn btn--block" href="/account/forgot-password">Pedir novo link</a>
<?php endif; ?>
```


**`app/Views/account/email-confirm.php`**

```php
<?php
/**
 * Landing page of the e-mail change link (AccountService::EMAIL_* states).
 *
 * @var string $state
 * @var string $token
 */

use App\Services\AccountService;
?>
<h1 class="auth__title">Confirmar novo e-mail</h1>

<?php if ($state === AccountService::EMAIL_VALID): ?>
    <p>Clique no botão para confirmar que este endereço é seu. Depois disso, use-o para entrar.</p>
    <form method="post" action="/account/email/confirm" class="form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <button type="submit" class="btn btn--primary btn--block">Confirmar novo e-mail</button>
    </form>

<?php elseif ($state === AccountService::EMAIL_EXPIRED): ?>
    <div class="alert alert--warning">Este link expirou. Faça a alteração de novo em "Minha conta".</div>

<?php elseif ($state === AccountService::EMAIL_TAKEN): ?>
    <div class="alert alert--error">Este e-mail já está em uso por outra conta. A alteração foi cancelada.</div>

<?php else: ?>
    <div class="alert alert--error">Link inválido ou já utilizado.</div>
<?php endif; ?>

<p class="auth__links"><a href="/login">Ir para o login</a></p>
```


**`app/Views/auth/login.php`** (existing file: changes only)

```diff
@@ -34,5 +34,7 @@
 </form>
 
 <p class="auth__links">
+    <a href="/account/forgot-password">Esqueci minha senha</a>
+    <span aria-hidden="true">·</span>
     <a href="/verify-email/resend">Não recebeu o e-mail de ativação?</a>
 </p>
```


### 3.6 Audit log

One view serves both audiences:

- the Property Manager's version passes `TenantContext::id()` as a mandatory condition;
- the Super Admin's version passes `null` and may narrow it to one condominium (allowlisted ids).

Phase 4 moderation deletions were already audited (`community.post_deleted`) and appear here under "Comunidade".

**`app/Controllers/Concerns/ReadsAuditFilters.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use App\Core\Request;
use App\Models\AuditLog;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Query-string filters of the two audit log views (Property Manager and Super
 * Admin). Every value is validated: the module against AuditLog::MODULES, dates
 * strictly as Y-m-d, the search text cut to 100 characters (and bound as a
 * LIKE parameter by the model).
 */
trait ReadsAuditFilters
{
    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>} [model filters, echo for the form/pager]
     */
    private function auditFilters(Request $request, DateTimeZone $timezone): array
    {
        $filters = [];
        $query = [];

        $module = $request->queryString('module');
        if (array_key_exists($module, AuditLog::MODULES)) {
            $filters['module'] = $query['module'] = $module;
        }
        $q = mb_substr($request->queryString('q'), 0, 100);
        if ($q !== '') {
            $filters['q'] = $query['q'] = $q;
        }
        // Dates are local days of the viewer's zone, turned into UTC bounds
        // (created_at is UTC): [from 00:00, to + 1 day 00:00).
        foreach (['from' => 'from_utc', 'to' => 'to_utc'] as $key => $bound) {
            $value = $request->queryString($key);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
            if ($date !== false && $date->format('Y-m-d') === $value) {
                $query[$key] = $value;
                $edge = $key === 'to' ? $date->modify('+1 day') : $date;
                $filters[$bound] = $edge->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }

        return [$filters, $query];
    }
}
```


**`app/Controllers/Admin/AuditController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Concerns\ReadsAuditFilters;
use App\Core\Response;
use App\Core\TenantContext;
use App\Models\AuditLog;

/**
 * Audit log of the current condominium (Property Manager, Phase 5).
 *
 * TENANT ISOLATION: AuditLog::search() receives TenantContext::id() as a
 * mandatory condition, so only this condominium's entries are listed.
 * Platform-level entries (logins, password resets: condominium_id NULL) and
 * other tenants' entries are never shown here.
 */
final class AuditController extends AdminController
{
    use ReadsAuditFilters;

    /** GET /admin/audit?module=&q=&from=&to=&page= */
    public function index(): Response
    {
        $this->requireRole(self::MANAGERS);
        [$filters, $query] = $this->auditFilters($this->request, TenantContext::timezone());

        $log = new AuditLog();
        $pagination = $this->pagination(50)->withTotal($log->count($this->tenantId(), $filters));

        return $this->view('admin/audit/index', [
            'title'      => 'Auditoria',
            'activeNav'  => 'admin-audit',
            'entries'    => $log->search($this->tenantId(), $filters, $pagination),
            'modules'    => AuditLog::MODULES,
            'query'      => $query,
            'pagination' => $pagination->toArray(),
            'basePath'   => '/admin/audit',
            'showTenant' => false,
            'condominiums' => [],
        ]);
    }
}
```


**`app/Controllers/Platform/AuditController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Controllers\Concerns\ReadsAuditFilters;
use App\Core\Controller;
use App\Core\Pagination;
use App\Core\Response;
use App\Models\AuditLog;
use App\Models\Condominium;
use DateTimeZone;

/**
 * Platform-wide audit log (Super Admin, Phase 5): every entry, including
 * platform-level ones (condominium_id NULL), optionally narrowed to one
 * condominium. Times are shown in UTC because entries span every tenant's zone.
 */
final class AuditController extends Controller
{
    use ReadsAuditFilters;

    /** GET /platform/audit?condominium_id=&module=&q=&from=&to=&page= */
    public function index(): Response
    {
        [$filters, $query] = $this->auditFilters($this->request, new DateTimeZone('UTC'));

        $condominiums = (new Condominium())->options();
        $condominiumId = (int) $this->request->queryString('condominium_id');
        if (isset($condominiums[$condominiumId])) {   // allowlist: existing condominiums only
            $filters['condominium_id'] = $condominiumId;
            $query['condominium_id'] = (string) $condominiumId;
        }

        $log = new AuditLog();
        $pagination = Pagination::fromRequest($this->request, 50)->withTotal($log->count(null, $filters));

        return $this->view('admin/audit/index', [
            'title'        => 'Auditoria da plataforma',
            'activeNav'    => 'platform-audit',
            'entries'      => $log->search(null, $filters, $pagination),
            'modules'      => AuditLog::MODULES,
            'query'        => $query,
            'pagination'   => $pagination->toArray(),
            'basePath'     => '/platform/audit',
            'showTenant'   => true,
            'condominiums' => $condominiums,
        ]);
    }
}
```


**`app/Views/admin/audit/index.php`**

```php
<?php
/**
 * Audit log, shared by the Property Manager view (/admin/audit, this
 * condominium only) and the Super Admin view (/platform/audit, every entry).
 * Details are JSON from the database: printed as text through e(), never parsed as HTML.
 *
 * @var list<array<string, mixed>> $entries
 * @var array<string, string>      $modules
 * @var array<string, string>      $query
 * @var array{page: int, per_page: int, total: int, pages: int} $pagination
 * @var string                     $basePath
 * @var bool                       $showTenant   Super Admin view.
 * @var array<int, string>         $condominiums id => name (Super Admin view only)
 */
$format = $showTenant
    ? static fn (string $utc): string => date_br($utc, true) . ' UTC'
    : static fn (string $utc): string => local_datetime($utc);
?>
<section class="page-header">
    <div>
        <h1 class="page-header__title"><?= $showTenant ? 'Auditoria da plataforma' : 'Auditoria' ?></h1>
        <p class="page-header__subtitle">
            <?= $showTenant ? 'Todos os eventos registrados, de todos os condomínios' : 'Ações administrativas e de segurança deste condomínio' ?>
        </p>
    </div>
</section>

<section class="panel">
    <form class="filters" method="get" action="<?= e($basePath) ?>">
        <?php if ($showTenant): ?>
            <label class="filters__field">
                <span class="sr-only">Condomínio</span>
                <select name="condominium_id">
                    <option value="">Todos os condomínios</option>
                    <?php foreach ($condominiums as $id => $name): ?>
                        <option value="<?= e($id) ?>"<?= ($query['condominium_id'] ?? '') === (string) $id ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <label class="filters__field">
            <span class="sr-only">Módulo</span>
            <select name="module">
                <option value="">Todos os módulos</option>
                <?php foreach ($modules as $code => $label): ?>
                    <option value="<?= e($code) ?>"<?= ($query['module'] ?? '') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filters__field filters__field--grow">
            <span class="sr-only">Autor</span>
            <input type="search" name="q" maxlength="100" placeholder="Autor (nome ou e-mail)" value="<?= e($query['q'] ?? '') ?>">
        </label>
        <label class="filters__field">
            <span class="sr-only">De</span>
            <input type="date" name="from" value="<?= e($query['from'] ?? '') ?>">
        </label>
        <label class="filters__field">
            <span class="sr-only">Até</span>
            <input type="date" name="to" value="<?= e($query['to'] ?? '') ?>">
        </label>
        <button type="submit" class="btn">Filtrar</button>
    </form>

    <?php if ($entries === []): ?>
        <p class="state">Nenhum evento encontrado.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--compact">
                <thead>
                <tr>
                    <th>Quando</th><th>Ação</th>
                    <?php if ($showTenant): ?><th>Condomínio</th><?php endif; ?>
                    <th>Autor</th><th>Alvo</th><th>Detalhes</th><th>IP</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td class="nowrap"><?= e($format((string) $entry['created_at'])) ?></td>
                        <td><code><?= e($entry['action_code']) ?></code></td>
                        <?php if ($showTenant): ?>
                            <td><?= e($entry['condominium_name'] ?? '— plataforma') ?></td>
                        <?php endif; ?>
                        <td>
                            <?= e($entry['actor_name'] ?? 'Sistema / anônimo') ?>
                            <?php if ($entry['actor_email'] !== null): ?><div class="small muted"><?= e($entry['actor_email']) ?></div><?php endif; ?>
                        </td>
                        <td><?= e($entry['entity_type'] === null ? '' : $entry['entity_type'] . ' #' . $entry['entity_id']) ?></td>
                        <td class="audit__details"><?= e($entry['details'] ?? '') ?></td>
                        <td class="mono small"><?= e($entry['ip'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= partial('pagination', ['pagination' => $pagination, 'basePath' => $basePath, 'query' => $query]) ?>
</section>
```


### 3.7 Navigation and layout

The sidebar gains labelled sections: Plataforma (Super Admin), Administração (Property Manager) and Conta (everyone). Links are built from the same role constants the routes use.

**`app/Core/Navigation.php`** (existing file: changes only)

```diff
@@ -4,6 +4,7 @@ declare(strict_types=1);
 
 namespace App\Core;
 
+use App\Controllers\Admin\AdminController;
 use App\Controllers\Community\CommunityController;
 use App\Controllers\ConciergeController;
 use App\Controllers\FinanceController;
@@ -19,10 +20,24 @@ use App\Controllers\ReservationController;
  */
 final class Navigation
 {
-    /** @return list<array{key: string, label: string, href: string}> */
+    /**
+     * Entries in display order. "section" (optional) starts a labelled group in
+     * the sidebar (Phase 5: Plataforma, Administração).
+     *
+     * @return list<array{key: string, label: string, href: string, section?: string}>
+     */
     public static function items(): array
     {
-        $items = [['key' => 'notices', 'label' => 'Mural de avisos', 'href' => '/dashboard']];
+        $items = [];
+
+        // Phase 5: the Super Admin's platform area comes first.
+        if (Auth::isSuperAdmin()) {
+            $items[] = ['key' => 'platform', 'label' => 'Visão geral', 'href' => '/platform', 'section' => 'Plataforma'];
+            $items[] = ['key' => 'platform-condominiums', 'label' => 'Condomínios', 'href' => '/platform/condominiums'];
+            $items[] = ['key' => 'platform-audit', 'label' => 'Auditoria', 'href' => '/platform/audit'];
+        }
+
+        $items[] = ['key' => 'notices', 'label' => 'Mural de avisos', 'href' => '/dashboard', 'section' => 'Condomínio'];
 
         $modules = [
             ['concierge', 'Portaria', '/concierge', ConciergeController::VIEWERS],
@@ -42,6 +57,24 @@ final class Navigation
             $items[] = ['key' => 'community', 'label' => 'Moderação', 'href' => '/community/moderation'];
         }
 
+        // Phase 5: the Property Manager's administration area.
+        if (Auth::hasRole(AdminController::MANAGERS)) {
+            $admin = [
+                ['admin-users', 'Usuários', '/admin/users'],
+                ['admin-units', 'Unidades', '/admin/units'],
+                ['admin-areas', 'Áreas comuns', '/admin/common-areas'],
+                ['admin-notices', 'Avisos', '/admin/notices'],
+                ['admin-invoices', 'Cobranças', '/admin/finance/invoices'],
+                ['admin-finance', 'Relatórios financeiros', '/admin/finance/reports'],
+                ['admin-audit', 'Auditoria', '/admin/audit'],
+            ];
+            foreach ($admin as $i => [$key, $label, $href]) {
+                $items[] = ['key' => $key, 'label' => $label, 'href' => $href] + ($i === 0 ? ['section' => 'Administração'] : []);
+            }
+        }
+
+        $items[] = ['key' => 'account', 'label' => 'Minha conta', 'href' => '/account', 'section' => 'Conta'];
+
         return $items;
     }
 }
```


**`app/Views/layouts/app.php`** (existing file: changes only)

```diff
@@ -11,7 +11,7 @@
  * @var bool                  $isSuperAdmin
  * @var string|null           $tenantName
  * @var array<string, string> $flashes
- * @var list<array{key: string, label: string, href: string}> $navigation Entries allowed for the current role.
+ * @var list<array{key: string, label: string, href: string, section?: string}> $navigation Entries allowed for the current role.
  */
 ?>
 <!doctype html>
@@ -33,13 +33,14 @@
         <?php endif; ?>
         <?php if ($isSuperAdmin): ?>
             <span class="tag tag--admin">Modo Super Admin</span>
+            <a class="topbar__switch" href="/platform">Plataforma</a>
         <?php endif; ?>
         <a class="topbar__switch" href="/select-condominium">Trocar condomínio</a>
     </div>
 
     <div class="topbar__user">
         <div class="topbar__identity">
-            <span class="topbar__name"><?= e($currentUserName) ?></span>
+            <a class="topbar__name" href="/account"><?= e($currentUserName) ?></a>
             <span class="topbar__role"><?= e($currentRole) ?></span>
         </div>
         <form method="post" action="/logout">
@@ -53,6 +54,9 @@
     <nav class="sidebar" aria-label="Módulos">
         <ul class="sidebar__list">
             <?php foreach ($navigation as $item): ?>
+                <?php if (isset($item['section'])): ?>
+                    <li class="sidebar__section"><?= e($item['section']) ?></li>
+                <?php endif; ?>
                 <li>
                     <a class="sidebar__link<?= $activeNav === $item['key'] ? ' is-active' : '' ?>"
                        href="<?= e($item['href']) ?>"
```


## 4. Email templates

Every e-mail is sent through the existing `Mailer` (PHPMailer), with an HTML body and a plain-text alternative.

- **Links** are built from `APP_URL` (`config/app.php`), never from the `Host` header. A forged `Host` would otherwise turn the e-mail into a link that hands the token to another site.
- **Every dynamic value** in the HTML is escaped with `e()`.
- **Shared shell:** the new templates use `layouts/email`.

**`app/Mail/Mailer.php`** (existing file: changes only)

```diff
@@ -83,6 +83,102 @@ final class Mailer
         return $this->send($toEmail, $toName, 'Confirme seu e-mail - Koinon', $html, $text);
     }
 
+    /**
+     * Invitation to join a condominium (Phase 5). The link is built from APP_URL
+     * (config app.url), never from the request's Host header: a forged Host
+     * would otherwise turn the e-mail into a link to an attacker's site that
+     * receives the token.
+     */
+    public function sendInvitation(
+        string $toEmail,
+        string $toName,
+        string $condominiumName,
+        string $roleLabel,
+        string $inviterName,
+        string $rawToken,
+        bool $needsPassword
+    ): bool {
+        $link = Config::get('app.url') . '/account/invitation?token=' . $rawToken;
+        $hours = (int) Config::get('security.invitation_ttl_hours', 72);
+        $data = [
+            'name'            => $toName,
+            'condominiumName' => $condominiumName,
+            'roleLabel'       => $roleLabel,
+            'inviterName'     => $inviterName,
+            'link'            => $link,
+            'hours'           => $hours,
+            'needsPassword'   => $needsPassword,
+        ];
+
+        $html = View::render('emails/invitation', $data, 'layouts/email');
+        $text = "Olá, {$toName}!\n\n"
+            . "{$inviterName} convidou você para acessar o condomínio {$condominiumName} no Koinon como {$roleLabel}.\n\n"
+            . ($needsPassword
+                ? "Aceite o convite e crie sua senha:\n"
+                : "Aceite o convite (depois, entre com o e-mail e a senha que você já usa no Koinon):\n")
+            . "{$link}\n\n"
+            . "O convite vale por {$hours} horas e só pode ser usado uma vez.\n"
+            . "Se você não esperava este convite, ignore este e-mail.\n";
+
+        return $this->send($toEmail, $toName, "Convite para {$condominiumName} - Koinon", $html, $text);
+    }
+
+    /** Password reset link (Phase 5). Same APP_URL rule as sendInvitation(). */
+    public function sendPasswordReset(string $toEmail, string $toName, string $rawToken): bool
+    {
+        $link = Config::get('app.url') . '/account/reset-password?token=' . $rawToken;
+        $minutes = (int) Config::get('security.password_reset_ttl_minutes', 60);
+
+        $html = View::render('emails/password-reset', ['name' => $toName, 'link' => $link, 'minutes' => $minutes], 'layouts/email');
+        $text = "Olá, {$toName}!\n\n"
+            . "Recebemos um pedido para redefinir a senha da sua conta no Koinon:\n{$link}\n\n"
+            . "O link vale por {$minutes} minutos e só pode ser usado uma vez.\n"
+            . "Se você não pediu a redefinição, ignore este e-mail: sua senha continua a mesma.\n";
+
+        return $this->send($toEmail, $toName, 'Redefinição de senha - Koinon', $html, $text);
+    }
+
+    /** Confirmation link sent to a NEW e-mail address (Phase 5). */
+    public function sendEmailChange(string $toEmail, string $toName, string $rawToken): bool
+    {
+        $link = Config::get('app.url') . '/account/email/confirm?token=' . $rawToken;
+        $hours = (int) Config::get('security.email_change_ttl_hours', 24);
+
+        $html = View::render('emails/email-change', [
+            'name'     => $toName,
+            'newEmail' => $toEmail,
+            'link'     => $link,
+            'hours'    => $hours,
+        ], 'layouts/email');
+        $text = "Olá, {$toName}!\n\n"
+            . "Foi solicitado que o e-mail de acesso da sua conta no Koinon passe a ser {$toEmail}.\n"
+            . "Confirme que este endereço é seu:\n{$link}\n\n"
+            . "O link vale por {$hours} horas e só pode ser usado uma vez. Até a confirmação, o e-mail antigo continua valendo.\n"
+            . "Se você não pediu esta alteração, ignore este e-mail.\n";
+
+        return $this->send($toEmail, $toName, 'Confirme seu novo e-mail - Koinon', $html, $text);
+    }
+
+    /**
+     * Security notice to the OLD address after an e-mail change or a password
+     * change, so the owner notices if someone else did it.
+     */
+    public function sendSecurityNotice(string $toEmail, string $toName, string $what): bool
+    {
+        $text = "Olá, {$toName}!\n\n"
+            . "{$what}\n\n"
+            . "Se foi você, nada mais é necessário. Se não foi, redefina sua senha imediatamente em "
+            . Config::get('app.url') . "/account/forgot-password e avise a administração do condomínio.\n";
+        $html = '<p>Olá, ' . e($toName) . '!</p>'
+            . '<p>' . e($what) . '</p>'
+            . '<p style="font-size:13px;color:#5f6b7a;">Se foi você, nada mais é necessário. Se não foi, '
+            . 'redefina sua senha imediatamente em <span style="word-break:break-all;">'
+            . e(Config::get('app.url') . '/account/forgot-password')
+            . '</span> e avise a administração do condomínio.</p>';
+
+        return $this->send($toEmail, $toName, 'Alteração na sua conta - Koinon', View::render('emails/raw', ['html' => $html], 'layouts/email'), $text);
+    }
+
     /**
      * Tells a resident that a package is waiting at the desk, with the pickup code.
      * All values are escaped with e(): carrier and description are typed by staff
```


**`app/Views/layouts/email.php`**

```php
<?php
/**
 * Shared shell of the Phase 5 HTML e-mails (same look as emails/verify.php).
 * E-mail clients ignore external CSS, so styles are inline here; the web CSP
 * does not apply to e-mails.
 *
 * @var string $content Rendered e-mail body (already escaped by its template).
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
                <?= $content /* already-escaped e-mail body */ ?>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
```


**`app/Views/emails/invitation.php`**

```php
<?php
/**
 * Invitation to join a condominium. Every value is escaped: the inviter's and
 * the condominium's names were typed by people and may contain HTML.
 *
 * @var string $name             Invitee name.
 * @var string $condominiumName
 * @var string $roleLabel        e.g. "Morador"
 * @var string $inviterName
 * @var string $link             Built from APP_URL, never from the request's Host header.
 * @var int    $hours            Validity.
 * @var bool   $needsPassword    New account: the invitee will choose a password.
 */
?>
<p>Olá, <?= e($name) ?>!</p>
<p>
    <?= e($inviterName) ?> convidou você para acessar o condomínio
    <strong><?= e($condominiumName) ?></strong> no Koinon como <strong><?= e($roleLabel) ?></strong>.
</p>
<p>
    <?= $needsPassword
        ? 'Clique no botão abaixo para aceitar o convite e criar sua senha.'
        : 'Clique no botão abaixo para aceitar o convite. Depois, entre com o e-mail e a senha que você já usa no Koinon.' ?>
</p>
<p style="margin:32px 0;">
    <a href="<?= e($link) ?>" style="background:#1d3c6e;color:#ffffff;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:bold;">Aceitar convite</a>
</p>
<p style="font-size:13px;color:#5f6b7a;">
    O convite vale por <?= e($hours) ?> horas e só pode ser usado uma vez.<br>
    Se o botão não funcionar, copie este endereço no navegador:<br>
    <span style="word-break:break-all;"><?= e($link) ?></span>
</p>
<p style="font-size:13px;color:#5f6b7a;">Se você não esperava este convite, ignore este e-mail.</p>
```


**`app/Views/emails/password-reset.php`**

```php
<?php
/**
 * Password reset link.
 *
 * @var string $name
 * @var string $link    Built from APP_URL, never from the request's Host header.
 * @var int    $minutes Validity.
 */
?>
<p>Olá, <?= e($name) ?>!</p>
<p>Recebemos um pedido para redefinir a senha da sua conta no Koinon.</p>
<p style="margin:32px 0;">
    <a href="<?= e($link) ?>" style="background:#1d3c6e;color:#ffffff;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:bold;">Redefinir senha</a>
</p>
<p style="font-size:13px;color:#5f6b7a;">
    O link vale por <?= e($minutes) ?> minutos e só pode ser usado uma vez.<br>
    Se o botão não funcionar, copie este endereço no navegador:<br>
    <span style="word-break:break-all;"><?= e($link) ?></span>
</p>
<p style="font-size:13px;color:#5f6b7a;">
    Se você não pediu a redefinição, ignore este e-mail: sua senha continua a mesma.
</p>
```


**`app/Views/emails/email-change.php`**

```php
<?php
/**
 * Confirmation of a new e-mail address, sent TO the new address: clicking the
 * link proves the requester controls it.
 *
 * @var string $name
 * @var string $newEmail
 * @var string $link  Built from APP_URL, never from the request's Host header.
 * @var int    $hours Validity.
 */
?>
<p>Olá, <?= e($name) ?>!</p>
<p>
    Foi solicitado que o e-mail de acesso da sua conta no Koinon passe a ser
    <strong><?= e($newEmail) ?></strong>. Confirme que este endereço é seu:
</p>
<p style="margin:32px 0;">
    <a href="<?= e($link) ?>" style="background:#1d3c6e;color:#ffffff;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:bold;">Confirmar novo e-mail</a>
</p>
<p style="font-size:13px;color:#5f6b7a;">
    O link vale por <?= e($hours) ?> horas e só pode ser usado uma vez. Até a confirmação,
    o e-mail antigo continua valendo para entrar.<br>
    Se o botão não funcionar, copie este endereço no navegador:<br>
    <span style="word-break:break-all;"><?= e($link) ?></span>
</p>
<p style="font-size:13px;color:#5f6b7a;">Se você não pediu esta alteração, ignore este e-mail.</p>
```


**`app/Views/emails/raw.php`**

```php
<?php
/**
 * Body built by the Mailer itself for short notices; every dynamic value in it
 * was already escaped with e() by the Mailer.
 *
 * @var string $html
 */
?>
<?= $html /* already escaped by App\Mail\Mailer */ ?>
```


## 5. Vanilla JavaScript

The new modules reuse `core/http.js`, which adds the CSRF header, and `core/async-form.js` (`messageFor`).

- **Server data is written with `textContent` only.**
- **`confirm.js`:** the shared confirmation `<dialog>` for every form with `data-confirm`.
- **`users.js`:** debounced search and filters (out-of-order responses dropped), inline deactivate/reactivate with confirmation, and invitation resend.
- **`reports.js`:**
  - month shortcut and date range;
  - JSON totals, formatted as strings;
  - the CSV link kept in sync.

**`public/assets/js/admin/confirm.js`**

```javascript
/**
 * Confirmation dialog for destructive or high-impact actions.
 *
 *   <form method="post" action="..." data-confirm="Excluir este aviso?"> ... </form>
 *
 * Any form with data-confirm is intercepted: a native <dialog> asks first and
 * the form is submitted only after "Confirmar". The message comes from the
 * (server-escaped) attribute and is inserted with textContent, never innerHTML.
 * The server enforces every rule anyway; this only prevents accidental clicks.
 *
 * confirmAction() is exported for fetch-based actions (users.js).
 */

let dialog = null;

function buildDialog() {
    const element = document.createElement('dialog');
    element.className = 'dialog dialog--confirm';
    element.setAttribute('aria-labelledby', 'confirm-dialog-title');

    const form = document.createElement('form');
    form.method = 'dialog';
    form.className = 'form';

    const title = document.createElement('h2');
    title.className = 'dialog__title';
    title.id = 'confirm-dialog-title';
    title.textContent = 'Confirmar ação';

    const message = document.createElement('p');
    message.dataset.message = '';

    const actions = document.createElement('div');
    actions.className = 'dialog__actions';
    const cancel = document.createElement('button');
    cancel.type = 'submit';
    cancel.value = 'cancel';
    cancel.className = 'btn';
    cancel.textContent = 'Cancelar';
    const confirm = document.createElement('button');
    confirm.type = 'submit';
    confirm.value = 'confirm';
    confirm.className = 'btn btn--danger';
    confirm.textContent = 'Confirmar';
    actions.append(cancel, confirm);

    form.append(title, message, actions);
    element.append(form);
    document.body.append(element);

    return element;
}

/**
 * Asks the user to confirm. Resolves true only for "Confirmar"
 * (Esc, "Cancelar" and closing the dialog all resolve false).
 * @param {string} text
 * @returns {Promise<boolean>}
 */
export function confirmAction(text) {
    dialog ??= buildDialog();
    dialog.querySelector('[data-message]').textContent = text;
    dialog.returnValue = '';

    return new Promise((resolve) => {
        dialog.addEventListener('close', () => resolve(dialog.returnValue === 'confirm'), { once: true });
        dialog.showModal();
        dialog.querySelector('button[value="cancel"]').focus(); // safe default
    });
}

document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmed === '1') {
        return;
    }
    event.preventDefault();
    if (await confirmAction(form.dataset.confirm)) {
        form.dataset.confirmed = '1';
        form.querySelector('[type="submit"]')?.setAttribute('disabled', ''); // no double submit
        form.submit();
    }
});
```


**`public/assets/js/admin/users.js`**

```javascript
/**
 * User management list (Property Manager).
 *
 *  - Search box and filters, debounced (300 ms), call GET /admin/users/search.
 *    Responses that arrive out of order are ignored (only the latest request
 *    is rendered).
 *  - Rows are cloned from <template id="user-row"> and filled with textContent:
 *    names and e-mails are user input and are never parsed as HTML.
 *  - Inline deactivate / reactivate (with confirmation) and invitation resend,
 *    via the shared fetch helper (CSRF header added by http.js).
 *
 * The server re-checks everything: role, tenant, "not yourself", last manager.
 */
import { getJson, postJson, HttpError } from '../core/http.js';
import { messageFor } from '../core/async-form.js';
import { confirmAction } from './confirm.js';

const STATUS = {
    active: ['active', 'Ativo'],
    inactive: ['inactive', 'Desativado'],
    invited: ['invited', 'Convite pendente'],
    invitation_expired: ['expired', 'Convite expirado'],
};
const DEBOUNCE_MS = 300;

const filters = document.getElementById('user-filters');
const container = document.getElementById('user-list');
const rows = document.getElementById('user-rows');
const template = document.getElementById('user-row');
const pager = container.querySelector('[data-pager]');

let page = 1;
let pages = 1;
let requestSeq = 0;
let debounceTimer = null;

/** @param {'loading'|'empty'|'error'|'list'} name */
function showState(name) {
    container.querySelectorAll('[data-state]').forEach((element) => {
        element.hidden = element.dataset.state !== name;
    });
    container.setAttribute('aria-busy', String(name === 'loading'));
}

function currentQuery() {
    const params = new URLSearchParams();
    for (const [key, value] of new FormData(filters)) {
        const text = String(value).trim();
        if (text !== '') {
            params.set(key, text);
        }
    }
    params.set('page', String(page));
    return params;
}

/**
 * Applies a status to a row: badge text/class and which buttons are visible.
 * @param {HTMLTableRowElement} row
 * @param {string} status
 * @param {boolean} isSelf
 */
function applyStatus(row, status, isSelf) {
    const [variant, label] = STATUS[status] ?? ['inactive', status];
    const badge = row.querySelector('[data-field="status"]');
    badge.className = `pill pill--${variant}`;
    badge.textContent = label;

    const pending = status === 'invited' || status === 'invitation_expired';
    row.querySelector('[data-action="resend"]').hidden = !pending;
    row.querySelector('[data-action="deactivate"]').hidden = isSelf || status === 'inactive';
    row.querySelector('[data-action="reactivate"]').hidden = status !== 'inactive';
}

function renderRow(user) {
    const row = template.content.firstElementChild.cloneNode(true);
    const field = (name) => row.querySelector(`[data-field="${name}"]`);

    const link = field('name');
    link.textContent = user.full_name;
    link.href = `/admin/users/${encodeURIComponent(user.user_id)}/edit`;
    field('email').textContent = user.email;
    field('role').textContent = user.role_label;
    field('unit').textContent = user.unit_label ?? '—';
    field('self').hidden = !user.is_self;

    row.dataset.userId = String(user.user_id);
    row.dataset.name = user.full_name;
    row.dataset.self = user.is_self ? '1' : '0';
    applyStatus(row, user.status, user.is_self);
    return row;
}

async function load() {
    const seq = ++requestSeq;
    showState('loading');
    try {
        const { data } = await getJson(`/admin/users/search?${currentQuery()}`);
        if (seq !== requestSeq) {
            return; // a newer search started meanwhile
        }
        rows.replaceChildren(...data.users.map(renderRow));
        ({ page, pages } = data.pagination);
        pager.hidden = data.pagination.total === 0;
        pager.querySelector('[data-pager-info]').textContent =
            `${data.pagination.total} usuário(s) · página ${page} de ${pages}`;
        pager.querySelector('[data-page="prev"]').disabled = page <= 1;
        pager.querySelector('[data-page="next"]').disabled = page >= pages;
        showState(data.users.length > 0 ? 'list' : 'empty');
    } catch (error) {
        if (seq === requestSeq) {
            console.error('Failed to load users', error);
            showState('error');
        }
    }
}

function scheduleLoad() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        page = 1;
        load();
    }, DEBOUNCE_MS);
}

const ACTIONS = {
    deactivate: {
        confirm: (name) => `Desativar ${name}? A pessoa perderá o acesso a este condomínio na próxima ação.`,
        url: (id) => `/admin/users/${id}/deactivate`,
    },
    reactivate: {
        confirm: (name) => `Reativar ${name}?`,
        url: (id) => `/admin/users/${id}/reactivate`,
    },
    resend: {
        confirm: null,
        url: (id) => `/admin/users/${id}/invitation/resend`,
    },
};

rows.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-action]');
    if (!button || button.disabled) {
        return;
    }
    const row = button.closest('[data-row]');
    const action = ACTIONS[button.dataset.action];
    const feedback = row.querySelector('[data-feedback]');
    const id = encodeURIComponent(row.dataset.userId);

    if (action.confirm && !(await confirmAction(action.confirm(row.dataset.name)))) {
        return;
    }

    button.disabled = true;
    feedback.dataset.kind = 'info';
    feedback.textContent = 'Enviando…';
    try {
        const payload = await postJson(action.url(id), {});
        feedback.dataset.kind = 'success';
        feedback.textContent = payload?.message ?? 'Concluído.';
        if (payload?.user?.status) {
            applyStatus(row, payload.user.status, row.dataset.self === '1');
        }
    } catch (error) {
        feedback.dataset.kind = 'error';
        feedback.textContent = messageFor(error);
        if (!(error instanceof HttpError)) {
            console.error(error);
        }
    } finally {
        button.disabled = false;
    }
});

filters.addEventListener('input', scheduleLoad);
filters.addEventListener('submit', (event) => {
    event.preventDefault();
    scheduleLoad();
});
pager.addEventListener('click', (event) => {
    const button = event.target.closest('[data-page]');
    if (!button) {
        return;
    }
    page = Math.min(pages, Math.max(1, page + (button.dataset.page === 'next' ? 1 : -1)));
    load();
});
container.querySelector('[data-retry]').addEventListener('click', load);

load();
```


**`public/assets/js/admin/reports.js`**

```javascript
/**
 * Period report form (Property Manager).
 *
 *  - Picking a month fills "De"/"Até" with its first and last day.
 *  - Submitting fetches GET /admin/finance/reports/period?from=&to= and writes
 *    the totals with textContent (amounts arrive as DECIMAL strings, e.g.
 *    "1234.50", and are only formatted here, as strings, never added up).
 *  - The "Exportar CSV" link always points at the same period: it is a plain
 *    GET download, so the browser saves the file the server builds.
 */
import { getJson } from '../core/http.js';
import { messageFor } from '../core/async-form.js';

const form = document.getElementById('period-form');
const totals = document.getElementById('period-totals');
const feedback = document.getElementById('period-feedback');
const csvLink = document.getElementById('period-csv');

/**
 * "1234567.5" → "R$ 1.234.567,50", with string operations only (the same
 * rule as money_br() in PHP): money is never turned into a float.
 * @param {string} decimal
 */
function formatMoney(decimal) {
    const text = String(decimal);
    const negative = text.startsWith('-');
    const [whole, fraction = ''] = text.replace('-', '').split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return `${negative ? '-' : ''}R$ ${grouped},${fraction.padEnd(2, '0').slice(0, 2)}`;
}

const isDate = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value);

function period() {
    return {
        from: String(form.elements.namedItem('from').value),
        to: String(form.elements.namedItem('to').value),
    };
}

function updateCsvLink() {
    const { from, to } = period();
    csvLink.href = `/admin/finance/reports/period.csv?${new URLSearchParams({ from, to })}`;
}

function setTotal(name, text) {
    const element = totals.querySelector(`[data-total="${name}"]`);
    if (element) {
        element.textContent = text;
    }
}

async function loadReport() {
    const { from, to } = period();
    if (!isDate(from) || !isDate(to) || to < from) {
        feedback.dataset.kind = 'error';
        feedback.textContent = 'Informe um período válido (a data final não pode ser anterior à inicial).';
        return;
    }

    totals.setAttribute('aria-busy', 'true');
    feedback.dataset.kind = 'info';
    feedback.textContent = 'Calculando…';
    try {
        const { data } = await getJson(`${form.getAttribute('action')}?${new URLSearchParams({ from, to })}`);
        setTotal('billed', formatMoney(data.billed));
        setTotal('received', formatMoney(data.received));
        setTotal('pending', formatMoney(data.pending));
        setTotal('overdue', formatMoney(data.overdue));
        setTotal('invoice_count', `${data.invoice_count} cobrança(s)`);
        setTotal('payment_count', `${data.payment_count} pagamento(s)`);
        setTotal('overdue_count', `${data.overdue_count} cobrança(s) vencida(s)`);
        feedback.textContent = '';
    } catch (error) {
        feedback.dataset.kind = 'error';
        feedback.textContent = messageFor(error);
    } finally {
        totals.setAttribute('aria-busy', 'false');
    }
}

form.elements.namedItem('month').addEventListener('change', (event) => {
    const value = String(event.target.value); // "YYYY-MM"
    if (!/^\d{4}-\d{2}$/.test(value)) {
        return;
    }
    const [year, month] = value.split('-').map(Number);
    const lastDay = new Date(year, month, 0).getDate(); // day 0 of next month
    form.elements.namedItem('from').value = `${value}-01`;
    form.elements.namedItem('to').value = `${value}-${String(lastDay).padStart(2, '0')}`;
    updateCsvLink();
    loadReport();
});

form.addEventListener('input', updateCsvLink);
form.addEventListener('submit', (event) => {
    event.preventDefault();
    updateCsvLink();
    loadReport();
});

updateCsvLink();
loadReport();
```


**`public/assets/js/notices.js`** (existing file: changes only)

```diff
@@ -116,6 +116,7 @@ function setupNoticeForm() {
                 body: String(formData.get('body') ?? ''),
                 priority: String(formData.get('priority') ?? 'normal'),
                 is_pinned: formData.get('is_pinned') === '1',
+                expires_at: String(formData.get('expires_at') ?? ''),
             });
             dialog.close();
             await loadNotices();
```


**`public/assets/css/app.css`** (existing file: changes only)

```diff
@@ -947,3 +947,359 @@ input[type="number"] {
 }
 
 /* End of Phase 3 styles */
+
+/* =====================================================================
+ * Phase 5 - administration (platform, condominium setup, users, reports)
+ * ===================================================================== */
+
+/* ---------- Shell additions ---------- */
+
+.sidebar__section {
+    padding: 16px 24px 6px;
+    font-size: 11px;
+    font-weight: 700;
+    letter-spacing: 0.06em;
+    text-transform: uppercase;
+    color: var(--color-muted);
+}
+
+.sidebar__list > .sidebar__section:first-child {
+    padding-top: 4px;
+}
+
+a.topbar__name {
+    color: #ffffff;
+    text-decoration: none;
+}
+
+a.topbar__name:hover {
+    text-decoration: underline;
+}
+
+.page-header__actions {
+    display: flex;
+    gap: 12px;
+}
+
+.grid-2 {
+    display: grid;
+    grid-template-columns: repeat(2, minmax(0, 1fr));
+    gap: 24px;
+    align-items: start;
+    margin-bottom: 24px;
+}
+
+.stack {
+    display: flex;
+    flex-direction: column;
+    gap: 24px;
+    min-width: 0;
+}
+
+.panel + .panel,
+.grid-2 + .panel,
+.kpis + .grid-2 {
+    margin-top: 24px;
+}
+
+.panel__subtitle {
+    margin: 20px 0 10px;
+    font-size: 14px;
+}
+
+.panel__title-action {
+    margin-left: auto;
+}
+
+.panel--danger {
+    border-color: #fecdca;
+}
+
+.strong {
+    font-weight: 600;
+}
+
+.nowrap {
+    white-space: nowrap;
+}
+
+/* Visually hidden but read by screen readers (labels of compact filters). */
+.sr-only {
+    position: absolute;
+    width: 1px;
+    height: 1px;
+    padding: 0;
+    margin: -1px;
+    overflow: hidden;
+    clip: rect(0, 0, 0, 0);
+    white-space: nowrap;
+    border: 0;
+}
+
+/* ---------- Buttons ---------- */
+
+.btn--danger {
+    color: #ffffff;
+    background: var(--color-danger);
+    border-color: var(--color-danger);
+}
+
+.btn--danger:hover {
+    background: #912018;
+}
+
+/* ---------- Filters toolbar & pager ---------- */
+
+.filters {
+    display: flex;
+    flex-wrap: wrap;
+    align-items: flex-end;
+    gap: 12px;
+    padding: 16px 20px;
+    border-bottom: 1px solid var(--color-border);
+}
+
+.filters--flush {
+    padding: 0 0 20px;
+    border-bottom: 0;
+}
+
+.filters__field {
+    display: flex;
+    flex-direction: column;
+    gap: 4px;
+    min-width: 160px;
+}
+
+.filters__field--grow {
+    flex: 1 1 240px;
+}
+
+.filters__or {
+    padding-bottom: 10px;
+    font-size: 13px;
+}
+
+.filters input,
+.filters select {
+    width: 100%;
+    padding: 8px 10px;
+    font: inherit;
+    font-size: 14px;
+    color: var(--color-text);
+    background: var(--color-surface);
+    border: 1px solid var(--color-border);
+    border-radius: var(--radius);
+}
+
+.filters .feedback {
+    flex-basis: auto;
+    align-self: center;
+}
+
+.pager {
+    display: flex;
+    align-items: center;
+    justify-content: flex-end;
+    gap: 8px;
+    padding: 12px 20px;
+    border-top: 1px solid var(--color-border);
+}
+
+.pager__info {
+    margin-right: auto;
+    font-size: 13px;
+}
+
+/* ---------- Status pills (Phase 5 states) ---------- */
+
+.pill--active {
+    background: var(--color-success-soft);
+    color: var(--color-success);
+}
+
+.pill--invited {
+    background: var(--color-primary-soft);
+    color: var(--color-primary);
+}
+
+.pill--expired,
+.pill--suspended {
+    background: var(--color-warning-soft);
+    color: var(--color-warning);
+}
+
+.pill--inactive,
+.pill--cancelled,
+.pill--default {
+    background: #eef0f3;
+    color: var(--color-muted);
+}
+
+/* ---------- KPI tiles (platform overview, period report) ---------- */
+
+.kpis {
+    display: grid;
+    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
+    gap: 16px;
+    margin-bottom: 16px;
+}
+
+.kpi {
+    display: flex;
+    flex-direction: column;
+    gap: 4px;
+    padding: 16px 20px;
+    background: var(--color-surface);
+    border: 1px solid var(--color-border);
+    border-left: 4px solid var(--color-primary);
+    border-radius: var(--radius);
+    color: var(--color-text);
+    text-decoration: none;
+}
+
+.kpi--success {
+    border-left-color: var(--color-success);
+}
+
+.kpi--danger {
+    border-left-color: var(--color-danger);
+}
+
+.kpi--link:hover {
+    background: var(--color-primary-soft);
+}
+
+.kpi__label {
+    font-size: 13px;
+    color: var(--color-muted);
+}
+
+.kpi__value {
+    font-size: 24px;
+    font-variant-numeric: tabular-nums;
+}
+
+.kpi__hint {
+    font-size: 12px;
+    color: var(--color-muted);
+}
+
+.kpis[aria-busy="true"] .kpi__value {
+    opacity: 0.5;
+}
+
+/* ---------- Detail lists ---------- */
+
+.details {
+    display: grid;
+    grid-template-columns: 180px minmax(0, 1fr);
+    gap: 8px 16px;
+    margin: 0;
+    font-size: 14px;
+}
+
+.details dt {
+    color: var(--color-muted);
+}
+
+.details dd {
+    margin: 0;
+    overflow-wrap: anywhere;
+}
+
+.person-list,
+.event-list {
+    list-style: none;
+    margin: 0;
+    padding: 0;
+}
+
+.person-list__item {
+    display: flex;
+    align-items: center;
+    gap: 12px;
+    padding: 10px 0;
+    border-bottom: 1px solid var(--color-border);
+}
+
+.person-list__item > div {
+    flex: 1;
+    min-width: 0;
+}
+
+.event-list__item {
+    display: flex;
+    flex-direction: column;
+    gap: 2px;
+    padding: 10px 20px;
+    border-bottom: 1px solid var(--color-border);
+}
+
+.event-list__item:last-child {
+    border-bottom: 0;
+}
+
+.audit__details {
+    max-width: 360px;
+    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
+    font-size: 12px;
+    color: var(--color-muted);
+    overflow-wrap: anywhere;
+}
+
+/* ---------- Account: avatar ---------- */
+
+.avatar-row {
+    display: flex;
+    align-items: center;
+    gap: 16px;
+}
+
+.avatar {
+    flex: none;
+    width: 64px;
+    height: 64px;
+    border-radius: 50%;
+    object-fit: cover;
+    border: 1px solid var(--color-border);
+}
+
+.avatar--empty {
+    display: inline-flex;
+    align-items: center;
+    justify-content: center;
+    font-size: 24px;
+    font-weight: 700;
+    color: var(--color-primary);
+    background: var(--color-primary-soft);
+}
+
+input[type="file"],
+input[type="datetime-local"] {
+    font: inherit;
+    font-size: 14px;
+}
+
+input[type="datetime-local"],
+input[type="tel"],
+input[type="search"] {
+    width: 100%;
+    padding: 9px 12px;
+    color: var(--color-text);
+    background: var(--color-surface);
+    border: 1px solid var(--color-border);
+    border-radius: var(--radius);
+}
+
+/* ---------- Confirmation dialog ---------- */
+
+.dialog--confirm {
+    max-width: 440px;
+}
+
+.dialog--confirm p {
+    margin: 0;
+}
+
+/* End of Phase 5 styles */
```


**`.gitignore`** (existing file: changes only)

```diff
@@ -4,3 +4,5 @@
 !/storage/logs/.gitkeep
 /storage/sessions/*
 !/storage/sessions/.gitkeep
+/storage/uploads/*
+!/storage/uploads/.gitkeep
```


## 6. Routes

Grouped by `/account` (all roles), `/admin` (Property Manager: `auth → tenant → role:manager → csrf`) and `/platform` (Super Admin: `auth → platform → csrf`). Ids are `\d+` lookup keys only.

**`routes/web.php`** (existing file: changes only)

```diff
@@ -8,11 +8,20 @@
  *   auth    - only for logged-in users
  *   tenant  - resolves the condominium (must come after auth)
  *   role:.. - requireRole([...]) (must come after tenant, which refreshes the role)
+ *   platform - Super Admin only (Phase 5; must come after auth; no tenant)
  *   csrf    - required on every POST
  */
 
 declare(strict_types=1);
 
+use App\Controllers\AccountController;
+use App\Controllers\Admin\AdminController;
+use App\Controllers\Admin\AuditController as AdminAuditController;
+use App\Controllers\Admin\CommonAreaController as AdminCommonAreaController;
+use App\Controllers\Admin\FinanceController as AdminFinanceController;
+use App\Controllers\Admin\NoticeController as AdminNoticeController;
+use App\Controllers\Admin\UnitController as AdminUnitController;
+use App\Controllers\Admin\UserController as AdminUserController;
 use App\Controllers\AuthController;
 use App\Controllers\Community\CommentController;
 use App\Controllers\Community\CommunityController;
@@ -23,8 +32,13 @@ use App\Controllers\Community\ReportController;
 use App\Controllers\ConciergeController;
 use App\Controllers\DashboardController;
 use App\Controllers\FinanceController;
+use App\Controllers\InvitationController;
 use App\Controllers\NoticeController;
 use App\Controllers\OccurrenceController;
+use App\Controllers\PasswordResetController;
+use App\Controllers\Platform\AuditController as PlatformAuditController;
+use App\Controllers\Platform\CondominiumController as PlatformCondominiumController;
+use App\Controllers\Platform\DashboardController as PlatformDashboardController;
 use App\Controllers\ReservationController;
 use App\Controllers\TenantController;
 use App\Controllers\VerificationController;
@@ -33,6 +47,7 @@ use App\Middleware\AuthMiddleware;
 use App\Middleware\CsrfMiddleware;
 use App\Middleware\GuestMiddleware;
 use App\Middleware\RoleMiddleware;
+use App\Middleware\SuperAdminMiddleware;
 use App\Middleware\TenantMiddleware;
 
 $router = new Router();
@@ -42,6 +57,7 @@ $router->alias('auth', AuthMiddleware::class);
 $router->alias('tenant', TenantMiddleware::class);
 $router->alias('role', RoleMiddleware::class);
 $router->alias('csrf', CsrfMiddleware::class);
+$router->alias('platform', SuperAdminMiddleware::class);
 
 // --- Authentication ---------------------------------------------------------
 $router->get('/', [DashboardController::class, 'home']);
@@ -181,4 +197,96 @@ $router->post('/api/community/moderation/posts/{id:\d+}/dismiss', [ModerationCon
     'auth', 'tenant', $moderators, 'csrf',
 ]);
 
+// =============================================================================
+// Phase 5 - administration, account self-service and recovery
+// =============================================================================
+
+// --- /account: every role ----------------------------------------------------------
+// "auth" only (no tenant): a Super Admin has no condominium, and a member with
+// several condominiums manages ONE global account. Every action works on
+// Auth::id(); there is no user id in these URLs.
+$router->get('/account', [AccountController::class, 'show'], ['auth']);
+$router->post('/account/profile', [AccountController::class, 'updateProfile'], ['auth', 'csrf']);
+$router->get('/account/avatar', [AccountController::class, 'avatar'], ['auth']);
+$router->post('/account/password', [AccountController::class, 'changePassword'], ['auth', 'csrf']);
+$router->post('/account/email', [AccountController::class, 'requestEmailChange'], ['auth', 'csrf']);
+
+// Token links: work logged out (the e-mail may be opened on another device).
+// GET only displays a confirmation form; the token is consumed by the POST.
+$router->get('/account/forgot-password', [PasswordResetController::class, 'showForgot'], ['guest']);
+$router->post('/account/forgot-password', [PasswordResetController::class, 'sendLink'], ['guest', 'csrf']);
+$router->get('/account/reset-password', [PasswordResetController::class, 'showReset']);
+$router->post('/account/reset-password', [PasswordResetController::class, 'reset'], ['csrf']);
+$router->get('/account/invitation', [InvitationController::class, 'show']);
+$router->post('/account/invitation', [InvitationController::class, 'accept'], ['csrf']);
+$router->get('/account/email/confirm', [AccountController::class, 'showEmailConfirmation']);
+$router->post('/account/email/confirm', [AccountController::class, 'confirmEmail'], ['csrf']);
+
+// --- /admin: Property Manager of the session's condominium --------------------------
+// auth → tenant (condominium from $_SESSION, re-validated) → role:manager → csrf.
+// {id} is only a lookup key into tenant-scoped models (another tenant's id = 404).
+$admin = ['auth', 'tenant', $viewers(AdminController::MANAGERS)];
+$adminWrite = [...$admin, 'csrf'];
+
+$router->get('/admin/users', [AdminUserController::class, 'index'], $admin);
+$router->get('/admin/users/search', [AdminUserController::class, 'search'], $admin);
+$router->post('/admin/users/invitations', [AdminUserController::class, 'invite'], $adminWrite);
+$router->get('/admin/users/{id:\d+}/edit', [AdminUserController::class, 'edit'], $admin);
+$router->post('/admin/users/{id:\d+}', [AdminUserController::class, 'update'], $adminWrite);
+$router->post('/admin/users/{id:\d+}/deactivate', [AdminUserController::class, 'deactivate'], $adminWrite);
+$router->post('/admin/users/{id:\d+}/reactivate', [AdminUserController::class, 'reactivate'], $adminWrite);
+$router->post('/admin/users/{id:\d+}/invitation/resend', [AdminUserController::class, 'resendInvitation'], $adminWrite);
+
+$router->get('/admin/units', [AdminUnitController::class, 'index'], $admin);
+$router->post('/admin/units', [AdminUnitController::class, 'store'], $adminWrite);
+$router->post('/admin/units/bulk', [AdminUnitController::class, 'bulk'], $adminWrite);
+$router->get('/admin/units/{id:\d+}/edit', [AdminUnitController::class, 'edit'], $admin);
+$router->post('/admin/units/{id:\d+}', [AdminUnitController::class, 'update'], $adminWrite);
+
+$router->get('/admin/common-areas', [AdminCommonAreaController::class, 'index'], $admin);
+$router->get('/admin/common-areas/new', [AdminCommonAreaController::class, 'create'], $admin);
+$router->post('/admin/common-areas', [AdminCommonAreaController::class, 'store'], $adminWrite);
+$router->get('/admin/common-areas/{id:\d+}/edit', [AdminCommonAreaController::class, 'edit'], $admin);
+$router->post('/admin/common-areas/{id:\d+}', [AdminCommonAreaController::class, 'update'], $adminWrite);
+
+$router->get('/admin/notices', [AdminNoticeController::class, 'index'], $admin);
+$router->get('/admin/notices/{id:\d+}/edit', [AdminNoticeController::class, 'edit'], $admin);
+$router->post('/admin/notices/{id:\d+}', [AdminNoticeController::class, 'update'], $adminWrite);
+$router->post('/admin/notices/{id:\d+}/delete', [AdminNoticeController::class, 'destroy'], $adminWrite);
+
+$router->get('/admin/finance/reports', [AdminFinanceController::class, 'reports'], $admin);
+$router->get('/admin/finance/reports/period', [AdminFinanceController::class, 'period'], $admin);
+$router->get('/admin/finance/reports/period.csv', [AdminFinanceController::class, 'periodCsv'], $admin);
+$router->get('/admin/finance/reports/delinquency.csv', [AdminFinanceController::class, 'delinquencyCsv'], $admin);
+$router->get('/admin/finance/invoices', [AdminFinanceController::class, 'invoices'], $admin);
+$router->get('/admin/finance/invoices/{id:\d+}', [AdminFinanceController::class, 'show'], $admin);
+$router->post('/admin/finance/invoices/{id:\d+}/payment', [AdminFinanceController::class, 'pay'], $adminWrite);
+$router->post('/admin/finance/invoices/{id:\d+}/cancel', [AdminFinanceController::class, 'cancel'], $adminWrite);
+
+$router->get('/admin/audit', [AdminAuditController::class, 'index'], $admin);
+
+// --- /platform: Super Admin --------------------------------------------------------
+// auth → platform (users.is_super_admin from the database) → csrf. No "tenant":
+// the target condominium is the {id} in the URL, loaded (404 if missing) and
+// written to the audit log by every action.
+$platform = ['auth', 'platform'];
+$platformWrite = [...$platform, 'csrf'];
+
+$router->get('/platform', [PlatformDashboardController::class, 'index'], $platform);
+$router->get('/platform/condominiums', [PlatformCondominiumController::class, 'index'], $platform);
+$router->get('/platform/condominiums/new', [PlatformCondominiumController::class, 'create'], $platform);
+$router->post('/platform/condominiums', [PlatformCondominiumController::class, 'store'], $platformWrite);
+$router->get('/platform/condominiums/{id:\d+}', [PlatformCondominiumController::class, 'show'], $platform);
+$router->get('/platform/condominiums/{id:\d+}/edit', [PlatformCondominiumController::class, 'edit'], $platform);
+$router->post('/platform/condominiums/{id:\d+}', [PlatformCondominiumController::class, 'update'], $platformWrite);
+$router->post('/platform/condominiums/{id:\d+}/suspend', [PlatformCondominiumController::class, 'suspend'], $platformWrite);
+$router->post('/platform/condominiums/{id:\d+}/reactivate', [PlatformCondominiumController::class, 'reactivate'], $platformWrite);
+$router->post('/platform/condominiums/{id:\d+}/managers', [PlatformCondominiumController::class, 'inviteManager'], $platformWrite);
+$router->post(
+    '/platform/condominiums/{id:\d+}/managers/{userId:\d+}/resend',
+    [PlatformCondominiumController::class, 'resendManagerInvitation'],
+    $platformWrite
+);
+$router->get('/platform/audit', [PlatformAuditController::class, 'index'], $platform);
+
 return $router;
```



---

## Setup (in addition to Phases 2–4)

```
mysql -u root -p < database/migrations/0003_phase5_administration.sql
```

- Run it with the migration user. Creating the two triggers needs the `TRIGGER` privilege, and with binary logging on it also needs `SUPER` or `log_bin_trust_function_creators = 1`.
- If the application user does not already have DML on `koinon.*`, grant it what the header of the migration lists. For `audit_logs`, grant only `INSERT, SELECT`.
- `storage/uploads/` must be writable by PHP. The avatars directory is created on the first upload.
- `APP_URL` must be the public HTTPS address: every e-mail link is built from it, never from the request's `Host` header.

First run: `php bin/create-user.php --super-admin ...` (Phase 2). Then log in, open **Plataforma → Novo condomínio**, and invite the first síndico from the condominium page. Units, areas, users and notices no longer need SQL.

---

## Manual test checklist (privilege boundaries first)

Use two condominiums, A and B, each with a Property Manager (PM-A, PM-B), plus a resident and a concierge in A.

**Tenant isolation (always 404, never 403, so nothing leaks):**

- [ ] PM-A opens `/admin/users/{id of a B-only user}/edit` → 404; `POST …/deactivate`, `…/reactivate`, `…/invitation/resend` with that id → 404.
- [ ] PM-A opens `/admin/units/{unit of B}/edit`, `/admin/common-areas/{area of B}/edit`, `/admin/notices/{notice of B}/edit` → 404; `POST /admin/notices/{B}/delete` → 404 and the notice still exists.
- [ ] PM-A opens `/admin/finance/invoices/{invoice of B}` → 404; `POST …/payment` and `…/cancel` → 404 and the invoice is unchanged.
- [ ] PM-A invites with `unit_id` of a B unit → 422 "Unidade inválida."; `/admin/users/search?unit_id={B unit}` → empty list.
- [ ] `/admin/users/search?q=<name of a B user>` never returns them; `/admin/audit` shows no entry of B and no platform-level login rows.
- [ ] Adding `condominium_id=B` to any `/admin` form or query string changes nothing (the session tenant is used).

**Privilege escalation:**

- [ ] PM-A invites with `role=super_admin` (edit the form) → 422 "Perfil inválido."; `role=admin` / unknown → 422.
- [ ] PM-A changes their own role → 403 "Você não pode alterar o seu próprio perfil."; deactivates themself → 403.
- [ ] With PM-A as the only active manager, any attempt to deactivate or demote PM-A → 409 "último síndico ativo".
- [ ] Resident or concierge opens any `/admin/*` page or JSON → 403; any `/platform/*` → 403.
- [ ] PM-A opens `/platform`, `/platform/audit`, `POST /platform/condominiums/{A}/suspend` → 403.
- [ ] Super Admin who entered condominium A through the selector opens `/admin/users` → 403 (platform actions only).
- [ ] Logged out: every `/admin/*`, `/platform/*` and `/account` page → redirect to `/login`; JSON endpoints → 401.
- [ ] Any POST without `_csrf` / `X-CSRF-Token`, or with a wrong one → 419.

**Sessions and account lifecycle:**

- [ ] PM-A deactivates the resident → the resident's next click lands on `/login`; their login says there is no active access; history (invoices, posts) is still visible to PM-A.
- [ ] Super Admin suspends A → PM-A and every A user are logged out on their next request and cannot log in; reactivating restores access.
- [ ] A user changes their password in browser 1 → browser 2 is logged out on its next request, browser 1 keeps working, and a security e-mail arrives.
- [ ] Forgot password with an unknown e-mail and with a real one → identical message and redirect; a second request makes the first link invalid; the reset link works once, then shows "inválido"; every open session of that account ends.
- [ ] Changing the e-mail requires the current password; the account keeps the old e-mail until the link sent to the new one is confirmed; all sessions then end.
- [ ] Eleven wrong logins for one e-mail (or 21 from one IP) within 15 min → 429 page, `auth.login_rate_limited` in the audit log.

**Invitations:**

- [ ] Invitation link: GET shows the condominium and role and does not consume the token; POST with a short password → 422; accepting → login works; reusing the link → "já foi aceito".
- [ ] Resend immediately → 429; after the invitation expires, resend → new link, and the old link says "inválido".
- [ ] Deactivating an invited member makes the link invalid; reactivating puts them back in "Convite pendente".

**Finance:**

- [ ] Register a payment twice quickly (double click / two tabs) → one payment row, the second answer is 409.
- [ ] Payment date in the future → 422; cancel without a reason → 422; cancelled and paid invoices show no action forms.
- [ ] Period report totals equal a manual `SUM()` in SQL; the period CSV and the delinquency CSV open in Excel with accents and numbers right; a unit named `=HYPERLINK("x")` appears as text (`'=HYPERLINK…`).

**Setup screens:**

- [ ] Bulk units "Torre A, floors 1–10, 4 per floor" → 40 units (101–1004); running it again creates 0 and reports 40 kept; more than 1000 → 422.
- [ ] Area with 08:00–22:00 and 120 min: booking 06:00–07:00 → refused; 09:00–12:00 → refused; 09:00–10:30 → accepted; deactivating the area blocks new bookings and keeps the existing one.
- [ ] Notice with an expiry leaves the board after that time and is listed as "Expirado"; deleting it writes `notice.deleted` with the title.
