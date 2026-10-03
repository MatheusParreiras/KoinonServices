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
