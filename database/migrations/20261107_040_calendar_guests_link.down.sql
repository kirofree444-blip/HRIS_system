-- Select the intended database first. Refused while any guest row or meeting link exists (HR-entered data).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_040_down$$
CREATE PROCEDURE stageb_040_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='calendar_event_guests') THEN
  IF (SELECT COUNT(*) FROM calendar_event_guests)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Calendar guests exist; rollback would delete HR-entered data';
  END IF;
 END IF;
 IF EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='calendar_events' AND column_name='meeting_url') THEN
  IF (SELECT COUNT(*) FROM calendar_events WHERE meeting_url IS NOT NULL)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Meeting links exist; rollback would delete HR-entered data';
  END IF;
  ALTER TABLE calendar_events DROP COLUMN meeting_url;
 END IF;
 DROP TABLE IF EXISTS calendar_event_guests;
END$$
DELIMITER ;
CALL stageb_040_down();
DROP PROCEDURE stageb_040_down;
