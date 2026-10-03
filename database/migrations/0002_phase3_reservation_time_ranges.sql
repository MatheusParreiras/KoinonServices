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
