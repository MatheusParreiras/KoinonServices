-- =====================================================================================
-- Koinon - Condominium Management SaaS
-- Phase 1 schema  |  MySQL 8.0+  |  InnoDB  |  utf8mb4 / utf8mb4_unicode_ci
--
-- Conventions
--   * Every tenant-scoped table carries `condominium_id` and declares
--     UNIQUE (condominium_id, id) when another table points at it.
--   * Every reference between tenant-scoped rows is a COMPOSITE foreign key
--     (condominium_id, x_id) -> parent (condominium_id, id). A row in tenant A can
--     therefore never point at a row in tenant B: the database rejects it.
--   * References to a person inside a tenant point at the membership
--     (condominium_id, user_id) -> condominium_users, not at users directly. This
--     proves the person belongs to that condominium.
--   * All DATETIME values are UTC (the app sets time_zone = '+00:00' per connection).
--     DATE/TIME values for reservations are the local wall-clock time of the condominium.
--   * ON DELETE policy:
--       - Tenant -> condominiums: RESTRICT everywhere. A tenant is never removed by a
--         cascade. Offboarding = status 'archived', then an explicit, audited purge job.
--       - Junction / pure child rows (likes, read receipts, images, slots, invoice items,
--         tokens): CASCADE from their parent, because they mean nothing alone.
--       - Authored or historical records (invoices, payments, occurrence history,
--         visits, reservations, audit log): RESTRICT, because they must survive.
--       - Users and memberships are never hard-deleted (status / anonymisation instead),
--         so references to them use RESTRICT.
--   * MySQL forbids CHECK constraints on columns used by FKs with referential actions
--     (error 3823), so no CHECK below touches a foreign-key column.
--   * Indexes needed by a foreign key that are not declared explicitly are created
--     implicitly by InnoDB (named after the constraint).
-- =====================================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Uncomment to rebuild a development database from scratch (DESTROYS ALL DATA):
-- DROP DATABASE IF EXISTS koinon;

CREATE DATABASE koinon
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE koinon;

-- =====================================================================================
-- 1. GLOBAL TABLES (no condominium_id): tenant registry, identity, RBAC catalogue
-- =====================================================================================

-- -------------------------------------------------------------------------------------
-- condominiums: the tenants. Managed only by the Super Admin.
-- -------------------------------------------------------------------------------------
CREATE TABLE condominiums (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name             VARCHAR(150)  NOT NULL,
  slug             VARCHAR(80)   NOT NULL COMMENT 'URL-safe identifier, e.g. "residencial-aurora"',
  legal_id         VARCHAR(20)   NULL     COMMENT 'Company registry number (CNPJ); NULL if not informed',
  signup_code      CHAR(8)       CHARACTER SET ascii COLLATE ascii_bin NOT NULL
                                 COMMENT 'Code residents type to self-register into this condominium',
  email            VARCHAR(254)  NULL,
  phone            VARCHAR(30)   NULL,
  address_line     VARCHAR(200)  NOT NULL,
  city             VARCHAR(100)  NOT NULL,
  state_province   VARCHAR(50)   NOT NULL,
  postal_code      VARCHAR(20)   NOT NULL,
  country_code     CHAR(2)       NOT NULL DEFAULT 'BR',
  timezone         VARCHAR(64)   NOT NULL DEFAULT 'America/Sao_Paulo',
  billing_due_day  TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT 'Default due day for monthly fees',
  status           ENUM('active','suspended','archived') NOT NULL DEFAULT 'active',
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_condominiums_slug (slug),
  UNIQUE KEY uq_condominiums_legal_id (legal_id),
  UNIQUE KEY uq_condominiums_signup_code (signup_code),
  KEY ix_condominiums_status_name (status, name),
  CONSTRAINT ck_condominiums_due_day CHECK (billing_due_day BETWEEN 1 AND 28)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- users: one global identity per e-mail address. Tenant membership lives in
-- condominium_users, so one person can belong to several condominiums.
-- -------------------------------------------------------------------------------------
CREATE TABLE users (
  id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  full_name           VARCHAR(150)  NOT NULL,
  email               VARCHAR(254)  NOT NULL COMMENT 'Stored trimmed and lower-cased; collation is case-insensitive anyway',
  password_hash       VARCHAR(255)  NULL     COMMENT 'password_hash() output; NULL only for invited users not yet activated',
  phone               VARCHAR(30)   NULL,
  avatar_path         VARCHAR(255)  NULL     COMMENT 'Relative path under storage/uploads (outside the web root)',
  is_super_admin      BOOLEAN       NOT NULL DEFAULT FALSE COMMENT 'Global SaaS owner; never set through the UI',
  status              ENUM('pending_verification','active','blocked','deleted') NOT NULL DEFAULT 'pending_verification',
  email_verified_at   DATETIME      NULL,
  failed_login_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until        DATETIME      NULL,
  session_version     INT UNSIGNED  NOT NULL DEFAULT 1 COMMENT 'Incremented on password change/reset to invalidate every other session',
  last_login_at       DATETIME      NULL,
  created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_status_created (status, created_at),
  -- An active account must have both a verified e-mail and a password.
  CONSTRAINT ck_users_active_is_complete
    CHECK (status <> 'active' OR (email_verified_at IS NOT NULL AND password_hash IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- roles: tenant roles (manager, concierge, resident). Global catalogue shared by all
-- tenants. Super Admin is NOT a role row; it is users.is_super_admin.
-- -------------------------------------------------------------------------------------
CREATE TABLE roles (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  code         VARCHAR(30)   NOT NULL,
  name         VARCHAR(60)   NOT NULL,
  description  VARCHAR(255)  NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- permissions: fine-grained capabilities checked by the PermissionMiddleware.
-- -------------------------------------------------------------------------------------
CREATE TABLE permissions (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  code         VARCHAR(60)   NOT NULL COMMENT 'e.g. reservations.manage',
  module       VARCHAR(30)   NOT NULL,
  description  VARCHAR(255)  NOT NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code),
  KEY ix_permissions_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- role_permissions: junction. CASCADE on both sides: a grant means nothing without
-- either the role or the permission.
-- -------------------------------------------------------------------------------------
CREATE TABLE role_permissions (
  role_id        INT UNSIGNED NOT NULL,
  permission_id  INT UNSIGNED NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id, permission_id),
  KEY ix_role_permissions_permission (permission_id),
  CONSTRAINT fk_role_permissions_role
    FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission
    FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- email_verification_tokens: account activation. Only the SHA-256 hash of the token is
-- stored; the raw token exists only inside the e-mail link. Single use is recorded by
-- consumed_at; a resend revokes older tokens through revoked_at.
-- CASCADE from users: a token has no meaning without its user.
-- -------------------------------------------------------------------------------------
CREATE TABLE email_verification_tokens (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED  NOT NULL,
  token_hash   CHAR(64)      CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'hex(SHA-256(raw token))',
  expires_at   DATETIME      NOT NULL COMMENT 'created_at + 24 hours',
  consumed_at  DATETIME      NULL,
  revoked_at   DATETIME      NULL,
  request_ip   VARBINARY(16) NULL     COMMENT 'INET6_ATON() of the requesting address',
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evt_token_hash (token_hash),
  KEY ix_evt_user_created (user_id, created_at),   -- resend throttling + FK
  KEY ix_evt_expires (expires_at),                 -- housekeeping purge
  CONSTRAINT fk_evt_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT ck_evt_expiry_after_creation CHECK (expires_at > created_at),
  CONSTRAINT ck_evt_single_outcome CHECK (consumed_at IS NULL OR revoked_at IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- password_reset_tokens: same design as verification tokens, 60-minute lifetime.
-- -------------------------------------------------------------------------------------
CREATE TABLE password_reset_tokens (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED  NOT NULL,
  token_hash   CHAR(64)      CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at   DATETIME      NOT NULL COMMENT 'created_at + 60 minutes',
  consumed_at  DATETIME      NULL,
  revoked_at   DATETIME      NULL,
  request_ip   VARBINARY(16) NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prt_token_hash (token_hash),
  KEY ix_prt_user_created (user_id, created_at),
  KEY ix_prt_expires (expires_at),
  CONSTRAINT fk_prt_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT ck_prt_expiry_after_creation CHECK (expires_at > created_at),
  CONSTRAINT ck_prt_single_outcome CHECK (consumed_at IS NULL OR revoked_at IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================
-- 2. TENANCY: memberships, counters, units
-- =====================================================================================

-- -------------------------------------------------------------------------------------
-- condominium_users: membership of a user in a tenant with exactly one role.
-- UNIQUE (condominium_id, user_id) is the target of every "person inside a tenant"
-- foreign key in the schema. Memberships are deactivated, never deleted, so RESTRICT.
-- -------------------------------------------------------------------------------------
CREATE TABLE condominium_users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  condominium_id       INT UNSIGNED NOT NULL,
  user_id              INT UNSIGNED NOT NULL,
  role_id              INT UNSIGNED NOT NULL,
  status               ENUM('pending_approval','active','inactive','rejected') NOT NULL DEFAULT 'pending_approval',
  requested_unit       VARCHAR(60)  NULL COMMENT 'Free text typed at self-registration, e.g. "Tower B 1203"',
  approved_by_user_id  INT UNSIGNED NULL COMMENT 'Manager or Super Admin who approved/invited',
  approved_at          DATETIME     NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cu_tenant_user (condominium_id, user_id),
  KEY ix_cu_user_status (user_id, status),                 -- "which condominiums can I enter?"
  KEY ix_cu_tenant_status_role (condominium_id, status, role_id),  -- member lists, approval queue
  KEY ix_cu_role (role_id),
  KEY ix_cu_approved_by (approved_by_user_id),
  CONSTRAINT fk_cu_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_cu_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_cu_role
    FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT,
  CONSTRAINT fk_cu_approved_by
    FOREIGN KEY (approved_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT ck_cu_active_is_approved CHECK (status <> 'active' OR approved_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- tenant_counters: gap-free per-tenant numbering (invoice numbers, occurrence protocols).
-- Read with SELECT ... FOR UPDATE inside the inserting transaction.
-- -------------------------------------------------------------------------------------
CREATE TABLE tenant_counters (
  condominium_id  INT UNSIGNED NOT NULL,
  counter_name    ENUM('invoice','occurrence') NOT NULL,
  next_value      INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (condominium_id, counter_name),
  CONSTRAINT fk_tc_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT ck_tc_positive CHECK (next_value >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- units: apartments/houses. UNIQUE (condominium_id, id) is redundant for uniqueness
-- (id is the PK) but required as the target of composite tenant-safe foreign keys.
-- -------------------------------------------------------------------------------------
CREATE TABLE units (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  condominium_id  INT UNSIGNED  NOT NULL,
  building        VARCHAR(30)   NOT NULL DEFAULT '' COMMENT 'Tower/block; empty string for single-building condominiums',
  unit_number     VARCHAR(20)   NOT NULL,
  floor_number    SMALLINT      NULL,
  unit_type       ENUM('apartment','house','commercial','other') NOT NULL DEFAULT 'apartment',
  area_m2         DECIMAL(8,2)  NULL,
  ideal_fraction  DECIMAL(9,8)  NULL COMMENT 'Share of common expenses, 0 < x <= 1; used to split monthly fees',
  is_active       BOOLEAN       NOT NULL DEFAULT TRUE,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_units_tenant_id (condominium_id, id),
  UNIQUE KEY uq_units_tenant_label (condominium_id, building, unit_number),
  CONSTRAINT fk_units_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT ck_units_area CHECK (area_m2 IS NULL OR area_m2 > 0),
  CONSTRAINT ck_units_fraction CHECK (ideal_fraction IS NULL OR (ideal_fraction > 0 AND ideal_fraction <= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- unit_residents: N:M link between memberships and units. Junction row -> CASCADE.
-- -------------------------------------------------------------------------------------
CREATE TABLE unit_residents (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  condominium_id      INT UNSIGNED NOT NULL,
  unit_id             INT UNSIGNED NOT NULL,
  user_id             INT UNSIGNED NOT NULL,
  relationship        ENUM('owner','tenant','dependent') NOT NULL,
  is_billing_contact  BOOLEAN      NOT NULL DEFAULT FALSE COMMENT 'Receives invoice e-mails for the unit',
  move_in_date        DATE         NULL,
  move_out_date       DATE         NULL COMMENT 'Set when the person leaves; row kept for history',
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ur_unit_user (condominium_id, unit_id, user_id),
  KEY ix_ur_tenant_user (condominium_id, user_id),   -- "which units am I linked to?"
  CONSTRAINT fk_ur_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_ur_unit
    FOREIGN KEY (condominium_id, unit_id) REFERENCES units (condominium_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_member
    FOREIGN KEY (condominium_id, user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE CASCADE,
  CONSTRAINT ck_ur_dates
    CHECK (move_out_date IS NULL OR move_in_date IS NULL OR move_out_date >= move_in_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================
-- 3. NOTICE BOARD
-- =====================================================================================

CREATE TABLE notices (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  condominium_id  INT UNSIGNED  NOT NULL,
  author_user_id  INT UNSIGNED  NOT NULL,
  title           VARCHAR(150)  NOT NULL,
  body            TEXT          NOT NULL,
  priority        ENUM('normal','important','urgent') NOT NULL DEFAULT 'normal',
  is_pinned       BOOLEAN       NOT NULL DEFAULT FALSE,
  status          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  publish_at      DATETIME      NULL COMMENT 'Visible from this moment (supports scheduling)',
  expires_at      DATETIME      NULL COMMENT 'Hidden from the homepage after this moment',
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notices_tenant_id (condominium_id, id),
  -- Homepage: WHERE condominium_id=? AND status='published' AND publish_at<=NOW()
  --           ORDER BY is_pinned DESC, publish_at DESC
  KEY ix_notices_homepage (condominium_id, status, is_pinned, publish_at),
  KEY ix_notices_author (condominium_id, author_user_id),
  CONSTRAINT fk_notices_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_notices_author
    FOREIGN KEY (condominium_id, author_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT ck_notices_published_has_date CHECK (status <> 'published' OR publish_at IS NOT NULL),
  CONSTRAINT ck_notices_window CHECK (expires_at IS NULL OR publish_at IS NULL OR expires_at > publish_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- notice_reads: read receipts. Junction -> CASCADE from both notice and membership.
CREATE TABLE notice_reads (
  condominium_id  INT UNSIGNED NOT NULL,
  notice_id       INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  read_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (condominium_id, notice_id, user_id),
  KEY ix_notice_reads_user (condominium_id, user_id),
  CONSTRAINT fk_notice_reads_notice
    FOREIGN KEY (condominium_id, notice_id) REFERENCES notices (condominium_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_notice_reads_member
    FOREIGN KEY (condominium_id, user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================
-- 4. CONCIERGE: visitors, visits, packages
-- =====================================================================================

-- visitors: per-tenant registry of people who have entered (identified by document).
CREATE TABLE visitors (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  condominium_id      INT UNSIGNED NOT NULL,
  full_name           VARCHAR(150) NOT NULL,
  document_type       ENUM('national_id','tax_id','passport','driver_license','other') NOT NULL,
  document_number     VARCHAR(30)  NOT NULL,
  phone               VARCHAR(30)  NULL,
  photo_path          VARCHAR(255) NULL,
  is_blocked          BOOLEAN      NOT NULL DEFAULT FALSE,
  block_reason        VARCHAR(255) NULL,
  created_by_user_id  INT UNSIGNED NOT NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_visitors_tenant_id (condominium_id, id),
  UNIQUE KEY uq_visitors_document (condominium_id, document_type, document_number),
  KEY ix_visitors_name (condominium_id, full_name),
  CONSTRAINT fk_visitors_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_visitors_created_by
    FOREIGN KEY (condominium_id, created_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT ck_visitors_block_reason CHECK (is_blocked = FALSE OR block_reason IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- visits: one access event (or resident pre-authorisation). Access history is a
-- security record, so every reference is RESTRICT.
CREATE TABLE visits (
  id                           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  condominium_id               INT UNSIGNED NOT NULL,
  visitor_id                   INT UNSIGNED NULL COMMENT 'NULL while a pre-authorisation waits for the visitor to arrive',
  expected_visitor_name        VARCHAR(150) NULL COMMENT 'Name typed by the resident when pre-authorising',
  unit_id                      INT UNSIGNED NOT NULL COMMENT 'Destination unit',
  visit_type                   ENUM('guest','service_provider','delivery','other') NOT NULL DEFAULT 'guest',
  vehicle_plate                VARCHAR(10)  NULL,
  status                       ENUM('expected','inside','exited','denied','cancelled') NOT NULL,
  expected_at                  DATETIME     NULL,
  valid_until                  DATETIME     NULL COMMENT 'End of the pre-authorisation window',
  entry_at                     DATETIME     NULL,
  exit_at                      DATETIME     NULL,
  created_by_user_id           INT UNSIGNED NOT NULL COMMENT 'Resident (pre-authorisation) or concierge (walk-in)',
  entry_registered_by_user_id  INT UNSIGNED NULL,
  exit_registered_by_user_id   INT UNSIGNED NULL,
  notes                        VARCHAR(500) NULL,
  created_at                   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_visits_gate (condominium_id, status, entry_at),        -- "who is inside now"
  KEY ix_visits_expected (condominium_id, status, expected_at), -- pre-authorisations for today
  KEY ix_visits_unit (condominium_id, unit_id, created_at),     -- visit history of a unit
  KEY ix_visits_visitor (condominium_id, visitor_id, entry_at), -- history of one visitor
  CONSTRAINT fk_visits_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_visits_visitor
    FOREIGN KEY (condominium_id, visitor_id) REFERENCES visitors (condominium_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_visits_unit
    FOREIGN KEY (condominium_id, unit_id) REFERENCES units (condominium_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_visits_created_by
    FOREIGN KEY (condominium_id, created_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_visits_entry_by
    FOREIGN KEY (condominium_id, entry_registered_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_visits_exit_by
    FOREIGN KEY (condominium_id, exit_registered_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT ck_visits_exit_after_entry
    CHECK (exit_at IS NULL OR (entry_at IS NOT NULL AND exit_at >= entry_at)),
  CONSTRAINT ck_visits_entered_has_time
    CHECK (status NOT IN ('inside','exited') OR entry_at IS NOT NULL),
  CONSTRAINT ck_visits_exited_has_time
    CHECK (status <> 'exited' OR exit_at IS NOT NULL),
  CONSTRAINT ck_visits_window
    CHECK (valid_until IS NULL OR expected_at IS NULL OR valid_until >= expected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- packages: deliveries held at the concierge desk.
CREATE TABLE packages (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  condominium_id           INT UNSIGNED NOT NULL,
  unit_id                  INT UNSIGNED NOT NULL,
  carrier                  VARCHAR(80)  NULL,
  tracking_code            VARCHAR(60)  NULL,
  description              VARCHAR(255) NULL,
  package_size             ENUM('envelope','small','medium','large') NOT NULL DEFAULT 'small',
  storage_location         VARCHAR(60)  NULL COMMENT 'Shelf/locker where the package is stored',
  pickup_code              CHAR(6)      CHARACTER SET ascii COLLATE ascii_bin NOT NULL
                                        COMMENT '6 random digits shown to the resident; checked at pickup',
  status                   ENUM('awaiting_pickup','picked_up','returned') NOT NULL DEFAULT 'awaiting_pickup',
  received_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_by_user_id      INT UNSIGNED NOT NULL,
  resident_notified_at     DATETIME     NULL,
  picked_up_at             DATETIME     NULL,
  picked_up_by_name        VARCHAR(150) NULL,
  handed_over_by_user_id   INT UNSIGNED NULL,
  created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_packages_desk (condominium_id, status, received_at),  -- pending list at the desk
  KEY ix_packages_unit (condominium_id, unit_id, status),      -- "my packages"
  CONSTRAINT fk_packages_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_packages_unit
    FOREIGN KEY (condominium_id, unit_id) REFERENCES units (condominium_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_packages_received_by
    FOREIGN KEY (condominium_id, received_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_packages_handed_over_by
    FOREIGN KEY (condominium_id, handed_over_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT ck_packages_pickup_after_receipt
    CHECK (picked_up_at IS NULL OR picked_up_at >= received_at),
  CONSTRAINT ck_packages_picked_up_complete
    CHECK (status <> 'picked_up' OR (picked_up_at IS NOT NULL AND picked_up_by_name IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================
-- 5. RESERVATIONS: common areas, fixed time slots, bookings
-- =====================================================================================

CREATE TABLE common_areas (
  id                     INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  condominium_id         INT UNSIGNED      NOT NULL,
  name                   VARCHAR(80)       NOT NULL,
  area_type              ENUM('bbq','party_room','gym','other') NOT NULL,
  description            TEXT              NULL,
  rules                  TEXT              NULL,
  max_people             SMALLINT UNSIGNED NULL COMMENT 'Physical capacity (people), informative',
  bookings_per_slot      TINYINT UNSIGNED  NOT NULL DEFAULT 1
                                           COMMENT '1 = exclusive use (BBQ, party room); >1 = shared (gym)',
  requires_approval      BOOLEAN           NOT NULL DEFAULT TRUE,
  booking_fee            DECIMAL(10,2)     NOT NULL DEFAULT 0.00,
  min_advance_hours      SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  max_advance_days       SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  cancel_deadline_hours  SMALLINT UNSIGNED NOT NULL DEFAULT 48 COMMENT 'Residents cannot cancel later than this before the slot',
  max_active_per_unit    TINYINT UNSIGNED  NOT NULL DEFAULT 2  COMMENT 'Future pending/approved bookings allowed per unit',
  is_active              BOOLEAN           NOT NULL DEFAULT TRUE,
  created_at             DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_common_areas_tenant_id (condominium_id, id),
  UNIQUE KEY uq_common_areas_name (condominium_id, name),
  KEY ix_common_areas_active (condominium_id, is_active),
  CONSTRAINT fk_common_areas_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT ck_common_areas_slot_capacity CHECK (bookings_per_slot >= 1),
  CONSTRAINT ck_common_areas_fee CHECK (booking_fee >= 0),
  CONSTRAINT ck_common_areas_advance CHECK (max_advance_days >= 1),
  CONSTRAINT ck_common_areas_per_unit CHECK (max_active_per_unit >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- common_area_slots: the bookable time windows of an area (same every day, local time).
-- Pure child of the area -> CASCADE (but reservations RESTRICT the delete of a used slot,
-- so in practice slots are deactivated, not deleted).
CREATE TABLE common_area_slots (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  condominium_id  INT UNSIGNED NOT NULL,
  common_area_id  INT UNSIGNED NOT NULL,
  label           VARCHAR(40)  NOT NULL COMMENT 'e.g. "Lunch 11:00-16:00"',
  start_time      TIME         NOT NULL,
  end_time        TIME         NOT NULL,
  is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slots_tenant_area_id (condominium_id, common_area_id, id),  -- composite FK target
  UNIQUE KEY uq_slots_area_window (condominium_id, common_area_id, start_time, end_time),
  CONSTRAINT fk_slots_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_slots_area
    FOREIGN KEY (condominium_id, common_area_id) REFERENCES common_areas (condominium_id, id) ON DELETE CASCADE,
  CONSTRAINT ck_slots_window CHECK (end_time > start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- reservations
-- DOUBLE-BOOKING PROTECTION
--   occupies_slot is 1 while a booking holds its slot (pending/approved/completed) and
--   NULL once it is rejected or cancelled. A UNIQUE index ignores rows containing NULL,
--   so uq_reservations_no_double_booking allows only ONE live booking per
--   (slot, date, seat). Exclusive areas (bookings_per_slot = 1) always use seat 1, so a
--   second booking of the same slot/date fails with a duplicate-key error even under
--   concurrent requests. Shared areas (gym) use seats 1..bookings_per_slot; the app
--   picks the lowest free seat and retries on a duplicate-key error.
--   The composite FK to common_area_slots guarantees the slot belongs to the same area
--   and tenant as the reservation.
CREATE TABLE reservations (
  id                    INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  condominium_id        INT UNSIGNED      NOT NULL,
  common_area_id        INT UNSIGNED      NOT NULL,
  slot_id               INT UNSIGNED      NOT NULL,
  reservation_date      DATE              NOT NULL COMMENT 'Local date of the condominium',
  seat_number           TINYINT UNSIGNED  NOT NULL DEFAULT 1,
  unit_id               INT UNSIGNED      NOT NULL,
  requested_by_user_id  INT UNSIGNED      NOT NULL,
  guest_count           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status                ENUM('pending','approved','rejected','cancelled','completed') NOT NULL DEFAULT 'pending',
  decided_by_user_id    INT UNSIGNED      NULL,
  decided_at            DATETIME          NULL,
  decision_note         VARCHAR(255)      NULL,
  cancelled_by_user_id  INT UNSIGNED      NULL,
  cancelled_at          DATETIME          NULL,
  cancellation_reason   VARCHAR(255)      NULL,
  notes                 VARCHAR(500)      NULL,
  occupies_slot         TINYINT UNSIGNED  GENERATED ALWAYS AS (
                          CASE WHEN status IN ('pending','approved','completed') THEN 1 ELSE NULL END
                        ) STORED,
  created_at            DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reservations_tenant_id (condominium_id, id),
  UNIQUE KEY uq_reservations_no_double_booking (slot_id, reservation_date, seat_number, occupies_slot),
  UNIQUE KEY uq_reservations_one_seat_per_unit (slot_id, reservation_date, unit_id, occupies_slot),
  KEY ix_reservations_slot_fk (condominium_id, common_area_id, slot_id),
  KEY ix_reservations_calendar (condominium_id, common_area_id, reservation_date, status),
  KEY ix_reservations_queue (condominium_id, status, reservation_date),   -- approval queue / day list
  KEY ix_reservations_unit (condominium_id, unit_id, reservation_date),
  CONSTRAINT fk_reservations_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservations_area
    FOREIGN KEY (condominium_id, common_area_id) REFERENCES common_areas (condominium_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservations_slot
    FOREIGN KEY (condominium_id, common_area_id, slot_id)
    REFERENCES common_area_slots (condominium_id, common_area_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservations_unit
    FOREIGN KEY (condominium_id, unit_id) REFERENCES units (condominium_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservations_requested_by
    FOREIGN KEY (condominium_id, requested_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservations_decided_by
    FOREIGN KEY (condominium_id, decided_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservations_cancelled_by
    FOREIGN KEY (condominium_id, cancelled_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT ck_reservations_seat CHECK (seat_number >= 1),
  CONSTRAINT ck_reservations_decided CHECK (status NOT IN ('approved','rejected') OR decided_at IS NOT NULL),
  CONSTRAINT ck_reservations_cancelled CHECK (status <> 'cancelled' OR cancelled_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================
-- 6. FINANCIAL
-- Financial records are legal records: invoices and payments are never cascaded away.
-- =====================================================================================

CREATE TABLE financial_categories (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  condominium_id  INT UNSIGNED NOT NULL,
  name            VARCHAR(80)  NOT NULL,
  kind            ENUM('income','expense') NOT NULL,
  is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fin_categories_tenant_id (condominium_id, id),
  UNIQUE KEY uq_fin_categories_name (condominium_id, kind, name),
  CONSTRAINT fk_fin_categories_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- invoices (bills) issued to a unit.
-- RESTRICT towards condominium and unit: deleting a tenant or a unit must never erase
-- billing history. "Overdue" is not stored: it is status = 'open' AND due_date < today.
-- monthly_fee_key enforces at most one non-cancelled monthly fee per unit per month
-- (protects against running the monthly batch twice).
CREATE TABLE invoices (
  id                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  condominium_id       INT UNSIGNED  NOT NULL,
  unit_id              INT UNSIGNED  NOT NULL,
  invoice_number       INT UNSIGNED  NOT NULL COMMENT 'Sequential per tenant (tenant_counters.invoice)',
  invoice_type         ENUM('monthly_fee','extraordinary','reservation_fee','fine','other') NOT NULL,
  reference_month      DATE          NOT NULL COMMENT 'First day of the competence month',
  issue_date           DATE          NOT NULL,
  due_date             DATE          NOT NULL,
  total_amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of invoice_items, maintained in the same transaction',
  status               ENUM('draft','open','paid','cancelled') NOT NULL DEFAULT 'draft',
  paid_at              DATETIME      NULL,
  cancelled_at         DATETIME      NULL,
  cancellation_reason  VARCHAR(255)  NULL,
  notes                VARCHAR(500)  NULL,
  created_by_user_id   INT UNSIGNED  NOT NULL,
  monthly_fee_key      DATE GENERATED ALWAYS AS (
                         CASE WHEN invoice_type = 'monthly_fee' AND status <> 'cancelled'
                              THEN reference_month ELSE NULL END
                       ) STORED,
  created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoices_tenant_id (condominium_id, id),
  UNIQUE KEY uq_invoices_number (condominium_id, invoice_number),
  UNIQUE KEY uq_invoices_one_monthly_fee (condominium_id, unit_id, monthly_fee_key),
  KEY ix_invoices_unit_due (condominium_id, unit_id, due_date),          -- resident statement
  KEY ix_invoices_status_due (condominium_id, status, due_date),         -- delinquency report
  KEY ix_invoices_month (condominium_id, reference_month, status),       -- receivables by month
  CONSTRAINT fk_invoices_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_invoices_unit
    FOREIGN KEY (condominium_id, unit_id) REFERENCES units (condominium_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_invoices_created_by
    FOREIGN KEY (condominium_id, created_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT ck_invoices_amount CHECK (total_amount >= 0),
  CONSTRAINT ck_invoices_dates CHECK (due_date >= issue_date),
  CONSTRAINT ck_invoices_month_first_day CHECK (DAYOFMONTH(reference_month) = 1),
  CONSTRAINT ck_invoices_paid CHECK (status <> 'paid' OR paid_at IS NOT NULL),
  CONSTRAINT ck_invoices_cancelled CHECK (status <> 'cancelled' OR cancelled_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- invoice_items: lines of an invoice. CASCADE from the invoice because a line is
-- meaningless alone; the invoice itself is protected (payments RESTRICT its deletion,
-- and the application only ever deletes invoices still in 'draft').
-- uq_items_reservation guarantees a reservation fee is billed at most once.
CREATE TABLE invoice_items (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  condominium_id  INT UNSIGNED  NOT NULL,
  invoice_id      INT UNSIGNED  NOT NULL,
  category_id     INT UNSIGNED  NOT NULL,
  description     VARCHAR(200)  NOT NULL,
  amount          DECIMAL(12,2) NOT NULL COMMENT 'Negative values are discounts',
  reservation_id  INT UNSIGNED  NULL COMMENT 'Set when the line bills a reservation fee',
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_items_invoice (condominium_id, invoice_id),
  KEY ix_items_category (condominium_id, category_id),
  UNIQUE KEY uq_items_reservation (condominium_id, reservation_id),
  CONSTRAINT fk_items_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_items_invoice
    FOREIGN KEY (condominium_id, invoice_id) REFERENCES invoices (condominium_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_items_category
    FOREIGN KEY (condominium_id, category_id) REFERENCES financial_categories (condominium_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_items_reservation
    FOREIGN KEY (condominium_id, reservation_id) REFERENCES reservations (condominium_id, id) ON DELETE RESTRICT,
  CONSTRAINT ck_items_amount CHECK (amount <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- payments: money received against an invoice (manual registration in Phase 1).
-- RESTRICT towards the invoice: a paid invoice can never be deleted. Payments are
-- immutable; a mistake is corrected by marking the payment 'reversed'.
CREATE TABLE payments (
  id                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  condominium_id       INT UNSIGNED  NOT NULL,
  invoice_id           INT UNSIGNED  NOT NULL,
  amount               DECIMAL(12,2) NOT NULL,
  paid_at              DATETIME      NOT NULL,
  payment_method       ENUM('bank_slip','pix','bank_transfer','cash','card','other') NOT NULL,
  external_reference   VARCHAR(100)  NULL COMMENT 'Bank/PIX transaction id',
  status               ENUM('confirmed','reversed') NOT NULL DEFAULT 'confirmed',
  reversed_at          DATETIME      NULL,
  reversal_reason      VARCHAR(255)  NULL,
  recorded_by_user_id  INT UNSIGNED  NOT NULL,
  notes                VARCHAR(500)  NULL,
  created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_payments_invoice (condominium_id, invoice_id, status),
  KEY ix_payments_cash_flow (condominium_id, paid_at),
  CONSTRAINT fk_payments_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_invoice
    FOREIGN KEY (condominium_id, invoice_id) REFERENCES invoices (condominium_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_recorded_by
    FOREIGN KEY (condominium_id, recorded_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT ck_payments_amount CHECK (amount > 0),
  CONSTRAINT ck_payments_reversed CHECK (status <> 'reversed' OR (reversed_at IS NOT NULL AND reversal_reason IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- expenses: money paid out by the condominium (feeds the cash-flow report).
CREATE TABLE expenses (
  id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  condominium_id      INT UNSIGNED  NOT NULL,
  category_id         INT UNSIGNED  NOT NULL,
  description         VARCHAR(200)  NOT NULL,
  supplier_name       VARCHAR(150)  NULL,
  amount              DECIMAL(12,2) NOT NULL,
  expense_date        DATE          NOT NULL COMMENT 'Date the money left the account',
  document_path       VARCHAR(255)  NULL COMMENT 'Receipt/invoice scan under storage/uploads',
  created_by_user_id  INT UNSIGNED  NOT NULL,
  created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_expenses_date (condominium_id, expense_date),
  KEY ix_expenses_category_date (condominium_id, category_id, expense_date),
  CONSTRAINT fk_expenses_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_expenses_category
    FOREIGN KEY (condominium_id, category_id) REFERENCES financial_categories (condominium_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_expenses_created_by
    FOREIGN KEY (condominium_id, created_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT ck_expenses_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================
-- 7. OCCURRENCES (digital incident book)
-- =====================================================================================

CREATE TABLE occurrences (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  condominium_id       INT UNSIGNED NOT NULL,
  protocol_number      INT UNSIGNED NOT NULL COMMENT 'Sequential per tenant (tenant_counters.occurrence)',
  reported_by_user_id  INT UNSIGNED NOT NULL,
  unit_id              INT UNSIGNED NULL COMMENT 'Reporter''s unit, when applicable',
  occurrence_type      ENUM('incident','complaint','maintenance','suggestion') NOT NULL,
  category             ENUM('noise','security','maintenance','cleaning','parking','pets','neighbor_conduct','other') NOT NULL,
  title                VARCHAR(150) NOT NULL,
  description          TEXT         NOT NULL,
  location             VARCHAR(120) NULL,
  occurred_at          DATETIME     NULL,
  priority             ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  status               ENUM('open','in_progress','resolved','closed','rejected') NOT NULL DEFAULT 'open',
  assigned_to_user_id  INT UNSIGNED NULL,
  resolved_at          DATETIME     NULL,
  closed_at            DATETIME     NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_occurrences_tenant_id (condominium_id, id),
  UNIQUE KEY uq_occurrences_protocol (condominium_id, protocol_number),
  KEY ix_occurrences_queue (condominium_id, status, priority, created_at),
  KEY ix_occurrences_reporter (condominium_id, reported_by_user_id, created_at),
  KEY ix_occurrences_assignee (condominium_id, assigned_to_user_id, status),
  CONSTRAINT fk_occurrences_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_occurrences_reporter
    FOREIGN KEY (condominium_id, reported_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_occurrences_unit
    FOREIGN KEY (condominium_id, unit_id) REFERENCES units (condominium_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_occurrences_assignee
    FOREIGN KEY (condominium_id, assigned_to_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT ck_occurrences_resolved CHECK (status <> 'resolved' OR resolved_at IS NOT NULL),
  CONSTRAINT ck_occurrences_closed CHECK (status <> 'closed' OR closed_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- occurrence_updates: append-only timeline (comments + status changes). RESTRICT, not
-- CASCADE: the incident book is a legal record, so once an occurrence has history the
-- database itself refuses to delete it. No updated_at because rows are never edited.
CREATE TABLE occurrence_updates (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  condominium_id  INT UNSIGNED NOT NULL,
  occurrence_id   INT UNSIGNED NOT NULL,
  author_user_id  INT UNSIGNED NOT NULL,
  message         TEXT         NULL,
  status_from     ENUM('open','in_progress','resolved','closed','rejected') NULL,
  status_to       ENUM('open','in_progress','resolved','closed','rejected') NULL,
  is_internal     BOOLEAN      NOT NULL DEFAULT FALSE COMMENT 'Staff-only note, hidden from the reporter',
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_occ_updates_timeline (condominium_id, occurrence_id, created_at),
  CONSTRAINT fk_occ_updates_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_occ_updates_occurrence
    FOREIGN KEY (condominium_id, occurrence_id) REFERENCES occurrences (condominium_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_occ_updates_author
    FOREIGN KEY (condominium_id, author_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT ck_occ_updates_not_empty CHECK (message IS NOT NULL OR status_to IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- occurrence_attachments: photos/documents. Pure child -> CASCADE.
CREATE TABLE occurrence_attachments (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  condominium_id       INT UNSIGNED NOT NULL,
  occurrence_id        INT UNSIGNED NOT NULL,
  uploaded_by_user_id  INT UNSIGNED NOT NULL,
  file_path            VARCHAR(255) NOT NULL,
  original_name        VARCHAR(255) NOT NULL,
  mime_type            VARCHAR(100) NOT NULL,
  size_bytes           INT UNSIGNED NOT NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_occ_attachments_occurrence (condominium_id, occurrence_id),
  CONSTRAINT fk_occ_attachments_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_occ_attachments_occurrence
    FOREIGN KEY (condominium_id, occurrence_id) REFERENCES occurrences (condominium_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_occ_attachments_uploader
    FOREIGN KEY (condominium_id, uploaded_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT ck_occ_attachments_size CHECK (size_bytes > 0 AND size_bytes <= 5242880)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================
-- 8. SOCIAL NETWORK
-- =====================================================================================

-- social_categories: intentionally GLOBAL. The four categories are a product decision,
-- identical for every tenant, so they are seeded once below.
CREATE TABLE social_categories (
  id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  code        VARCHAR(30)      NOT NULL,
  name        VARCHAR(60)      NOT NULL,
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_active   BOOLEAN          NOT NULL DEFAULT TRUE,
  created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_social_categories_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- posts. Statuses: published | hidden (auto-hidden after 3 pending reports, awaiting
-- review) | removed (by a moderator) | deleted (by the author). Rows are soft-deleted.
-- like_count / comment_count / report_count are denormalised counters updated in the
-- same transaction as the like/comment/report so the feed never runs COUNT(*).
CREATE TABLE posts (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  condominium_id        INT UNSIGNED NOT NULL,
  author_user_id        INT UNSIGNED NOT NULL,
  category_id           INT UNSIGNED NOT NULL,
  title                 VARCHAR(150) NULL,
  body                  TEXT         NOT NULL,
  status                ENUM('published','hidden','removed','deleted') NOT NULL DEFAULT 'published',
  like_count            INT UNSIGNED NOT NULL DEFAULT 0,
  comment_count         INT UNSIGNED NOT NULL DEFAULT 0,
  report_count          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Pending reports only',
  moderated_by_user_id  INT UNSIGNED NULL,
  moderated_at          DATETIME     NULL,
  moderation_note       VARCHAR(255) NULL,
  edited_at             DATETIME     NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_posts_tenant_id (condominium_id, id),
  -- Feed with keyset pagination: WHERE condominium_id=? AND status='published' AND id<? ORDER BY id DESC
  KEY ix_posts_feed (condominium_id, status, id),
  KEY ix_posts_category_feed (condominium_id, category_id, status, id),
  KEY ix_posts_author (condominium_id, author_user_id, id),
  KEY ix_posts_category (category_id),
  CONSTRAINT fk_posts_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_posts_author
    FOREIGN KEY (condominium_id, author_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_posts_category
    FOREIGN KEY (category_id) REFERENCES social_categories (id) ON DELETE RESTRICT,
  CONSTRAINT fk_posts_moderated_by
    FOREIGN KEY (condominium_id, moderated_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT ck_posts_moderated CHECK (status NOT IN ('removed') OR moderated_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- post_images: up to 4 per post. Pure child -> CASCADE.
CREATE TABLE post_images (
  id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  condominium_id  INT UNSIGNED     NOT NULL,
  post_id         INT UNSIGNED     NOT NULL,
  file_path       VARCHAR(255)     NOT NULL,
  sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_post_images_order (condominium_id, post_id, sort_order),
  CONSTRAINT fk_post_images_post
    FOREIGN KEY (condominium_id, post_id) REFERENCES posts (condominium_id, id) ON DELETE CASCADE,
  CONSTRAINT ck_post_images_order CHECK (sort_order BETWEEN 1 AND 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- post_likes: junction; the PK makes a second like by the same person impossible.
CREATE TABLE post_likes (
  condominium_id  INT UNSIGNED NOT NULL,
  post_id         INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (condominium_id, post_id, user_id),
  KEY ix_post_likes_user (condominium_id, user_id),
  CONSTRAINT fk_post_likes_post
    FOREIGN KEY (condominium_id, post_id) REFERENCES posts (condominium_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_post_likes_member
    FOREIGN KEY (condominium_id, user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- post_comments: CASCADE from the post (a comment has no meaning without it).
CREATE TABLE post_comments (
  id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  condominium_id        INT UNSIGNED  NOT NULL,
  post_id               INT UNSIGNED  NOT NULL,
  author_user_id        INT UNSIGNED  NOT NULL,
  body                  VARCHAR(1000) NOT NULL,
  status                ENUM('visible','removed','deleted') NOT NULL DEFAULT 'visible',
  moderated_by_user_id  INT UNSIGNED  NULL,
  moderated_at          DATETIME      NULL,
  created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_post_comments_thread (condominium_id, post_id, status, id),
  KEY ix_post_comments_author (condominium_id, author_user_id),
  CONSTRAINT fk_post_comments_post
    FOREIGN KEY (condominium_id, post_id) REFERENCES posts (condominium_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_post_comments_author
    FOREIGN KEY (condominium_id, author_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_post_comments_moderated_by
    FOREIGN KEY (condominium_id, moderated_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT ck_post_comments_moderated CHECK (status <> 'removed' OR moderated_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- post_reports: flags raised by residents. One report per person per post.
-- CASCADE from the post (posts are soft-deleted, so this only fires on a tenant purge).
CREATE TABLE post_reports (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  condominium_id       INT UNSIGNED NOT NULL,
  post_id              INT UNSIGNED NOT NULL,
  reporter_user_id     INT UNSIGNED NOT NULL,
  reason               ENUM('spam','offensive','harassment','scam','other') NOT NULL,
  details              VARCHAR(500) NULL,
  status               ENUM('pending','upheld','dismissed') NOT NULL DEFAULT 'pending',
  reviewed_by_user_id  INT UNSIGNED NULL,
  reviewed_at          DATETIME     NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_post_reports_once (condominium_id, post_id, reporter_user_id),
  KEY ix_post_reports_queue (condominium_id, status, created_at),
  CONSTRAINT fk_post_reports_post
    FOREIGN KEY (condominium_id, post_id) REFERENCES posts (condominium_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_post_reports_reporter
    FOREIGN KEY (condominium_id, reporter_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_post_reports_reviewer
    FOREIGN KEY (condominium_id, reviewed_by_user_id) REFERENCES condominium_users (condominium_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT ck_post_reports_reviewed CHECK (status = 'pending' OR reviewed_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================
-- 9. CROSS-CUTTING (mixed scope: condominium_id is NULLable for platform-level rows)
-- =====================================================================================

-- email_outbox: notification e-mails queued for the PHPMailer worker (bin/send-mail.php).
-- Never stores verification or password-reset links: those are sent synchronously so
-- that no usable raw token is ever written to the database.
-- CASCADE: queued mail for a purged tenant is worthless.
CREATE TABLE email_outbox (
  id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  condominium_id   INT UNSIGNED     NULL,
  recipient_email  VARCHAR(254)     NOT NULL,
  recipient_name   VARCHAR(150)     NULL,
  subject          VARCHAR(200)     NOT NULL,
  body_html        MEDIUMTEXT       NOT NULL,
  body_text        MEDIUMTEXT       NULL,
  status           ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error       VARCHAR(500)     NULL,
  available_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Next attempt not before (exponential back-off)',
  sent_at          DATETIME         NULL,
  created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_outbox_dispatch (status, available_at),
  KEY ix_outbox_tenant (condominium_id, created_at),
  CONSTRAINT fk_outbox_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE CASCADE,
  CONSTRAINT ck_outbox_attempts CHECK (attempts <= 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- audit_logs: append-only security trail (logins, role changes, financial actions,
-- moderation, Super Admin support access). RESTRICT on both FKs: audit evidence must
-- outlive whatever it describes. The application DB user gets only INSERT/SELECT here.
CREATE TABLE audit_logs (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  condominium_id  INT UNSIGNED    NULL COMMENT 'NULL for platform-level events',
  actor_user_id   INT UNSIGNED    NULL COMMENT 'NULL for system jobs and anonymous events (failed login)',
  action_code     VARCHAR(60)     NOT NULL COMMENT 'e.g. auth.login_failed, invoice.cancelled',
  entity_type     VARCHAR(40)     NULL,
  entity_id       INT UNSIGNED    NULL,
  details         JSON            NULL,
  ip_address      VARBINARY(16)   NULL,
  user_agent      VARCHAR(255)    NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_audit_tenant_time (condominium_id, created_at),
  KEY ix_audit_actor_time (actor_user_id, created_at),
  KEY ix_audit_entity (entity_type, entity_id),
  CONSTRAINT fk_audit_condominium
    FOREIGN KEY (condominium_id) REFERENCES condominiums (id) ON DELETE RESTRICT,
  CONSTRAINT fk_audit_actor
    FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================
-- 10. SEED DATA (global catalogues only)
-- The first Super Admin is created with `php bin/create-super-admin.php`, because the
-- password hash must come from the PHP password_hash() function.
-- =====================================================================================

INSERT INTO roles (code, name, description) VALUES
  ('manager',   'Property Manager', 'Full access to one condominium'),
  ('concierge', 'Concierge / Security', 'Visitors, packages and the security incident book'),
  ('resident',  'Resident', 'Reservations, bills, occurrences and the social network');

INSERT INTO permissions (code, module, description) VALUES
  ('tenant.settings.manage',  'tenant',       'Edit condominium settings and signup code'),
  ('members.manage',          'tenant',       'Approve, invite, change role of and deactivate members'),
  ('units.manage',            'tenant',       'Create and edit units and link residents'),
  ('audit.view',              'tenant',       'View the condominium audit log'),
  ('notices.view',            'notices',      'Read published notices'),
  ('notices.manage',          'notices',      'Create, publish, pin and archive notices'),
  ('visits.view_own',         'concierge',    'View visits to own units'),
  ('visits.preauthorize',     'concierge',    'Pre-authorise visitors for own units'),
  ('visits.view_all',         'concierge',    'View all visits of the condominium'),
  ('visits.manage',           'concierge',    'Register visitors, entries and exits'),
  ('visitors.block',          'concierge',    'Block and unblock visitors'),
  ('packages.view_own',       'concierge',    'View packages of own units'),
  ('packages.manage',         'concierge',    'Register packages and pickups'),
  ('reservations.view_own',   'reservations', 'View own reservations'),
  ('reservations.create',     'reservations', 'Book common areas for own units'),
  ('reservations.view_all',   'reservations', 'View the reservation calendar of all units'),
  ('reservations.manage',     'reservations', 'Approve, reject and cancel any reservation'),
  ('common_areas.manage',     'reservations', 'Configure common areas, slots and rules'),
  ('occurrences.create',      'occurrences',  'Open occurrences'),
  ('occurrences.view_own',    'occurrences',  'View and follow own occurrences'),
  ('occurrences.manage',      'occurrences',  'View, assign and resolve all occurrences'),
  ('finance.view_own',        'financial',    'View invoices of own units'),
  ('finance.manage',          'financial',    'Issue invoices, record payments and expenses'),
  ('finance.reports',         'financial',    'View financial reports'),
  ('social.use',              'social',       'Read, post, like, comment and report'),
  ('social.moderate',         'social',       'Review reports and remove posts/comments');

-- Manager: every permission.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.code = 'manager';

-- Concierge: operational desk permissions; no finance, no social network.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.code = 'concierge'
  AND p.code IN ('notices.view', 'visits.view_all', 'visits.manage', 'packages.manage',
                 'reservations.view_all', 'occurrences.create', 'occurrences.view_own');

-- Resident: self-service permissions scoped to own units.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.code = 'resident'
  AND p.code IN ('notices.view', 'visits.view_own', 'visits.preauthorize', 'packages.view_own',
                 'reservations.view_own', 'reservations.create', 'occurrences.create',
                 'occurrences.view_own', 'finance.view_own', 'social.use');

INSERT INTO social_categories (code, name, sort_order) VALUES
  ('classifieds',       'Classifieds',        1),
  ('lost_found',        'Lost & Found',       2),
  ('neighborhood_tips', 'Neighborhood Tips',  3),
  ('pets',              'Pets',               4);

-- End of script
