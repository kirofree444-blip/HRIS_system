-- Select the intended database first. Refused while any calendar event exists (including soft-deleted ones: they are HR-entered history).
DELIMITER $$
DROP PROCEDURE IF EXISTS stageb_039_down$$
CREATE PROCEDURE stageb_039_down()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='calendar_events') THEN
  IF (SELECT COUNT(*) FROM calendar_events)>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Calendar events exist; rollback would delete HR-entered data';
  END IF;
  DROP TABLE calendar_events;
 END IF;
END$$
DELIMITER ;
CALL stageb_039_down();
DROP PROCEDURE stageb_039_down;
