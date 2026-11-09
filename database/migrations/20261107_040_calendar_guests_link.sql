-- Select the intended database first (never run blind on pmbsi_hris: dump first).
-- dashboard-calendar v5 (John 2026-11-07): meeting link on an event, and invited guests so an event (e.g. an announcement)
-- can be shown only to the people HR added. Re-runnable. Additive: no existing row changes.
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_040_up$$
CREATE PROCEDURE stageb_040_up()
BEGIN
 IF NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='calendar_events' AND column_name='meeting_url') THEN
  ALTER TABLE calendar_events ADD COLUMN meeting_url VARCHAR(500) NULL AFTER notes;
 END IF;
END$$
DELIMITER ;
CALL stageb_040_up();
DROP PROCEDURE stageb_040_up;

CREATE TABLE IF NOT EXISTS calendar_event_guests(
 event_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 added_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(event_id,user_id),
 KEY idx_ceg_user(user_id),
 CONSTRAINT fk_ceg_event FOREIGN KEY(event_id) REFERENCES calendar_events(id),
 CONSTRAINT fk_ceg_user FOREIGN KEY(user_id) REFERENCES users(id),
 CONSTRAINT fk_ceg_added_by FOREIGN KEY(added_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
