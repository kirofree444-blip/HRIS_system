-- Select the intended database first (never run blind on pmbsi_hris: dump first).
-- dashboard-calendar (John 2026-11-07): manual calendar events HR types in (payroll cutoff, pay date, holiday, company event, meeting, other).
-- Display only: these rows never drive payroll day types or cutoffs (payroll_cutoffs stays the payroll source). Re-runnable. Additive: no existing row changes.
-- event_type is a VARCHAR checked in PHP (CalendarEvents::TYPES) so the list can change without a migration. Soft delete keeps history.
CREATE TABLE IF NOT EXISTS calendar_events(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(120) NOT NULL,
 event_type VARCHAR(30) NOT NULL,
 start_date DATE NOT NULL,
 end_date DATE NOT NULL,
 start_time TIME NULL,
 end_time TIME NULL,
 notes VARCHAR(500) NULL,
 visible_to_employees TINYINT(1) NOT NULL DEFAULT 0,
 created_by BIGINT UNSIGNED NULL,
 updated_by BIGINT UNSIGNED NULL,
 deleted_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
 deleted_at DATETIME NULL,
 KEY idx_ce_range(start_date,end_date),
 CONSTRAINT fk_ce_created_by FOREIGN KEY(created_by) REFERENCES users(id),
 CONSTRAINT fk_ce_updated_by FOREIGN KEY(updated_by) REFERENCES users(id),
 CONSTRAINT fk_ce_deleted_by FOREIGN KEY(deleted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
