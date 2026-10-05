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
