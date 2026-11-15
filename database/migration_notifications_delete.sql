-- Run in phpMyAdmin: agri_db > SQL tab (paste the CONTENTS of this file, then Go).
-- Run AFTER migration_notifications.sql. Safe to run more than once.
-- Lets each viewer delete notifications for themselves (other viewers still see them).

CREATE TABLE IF NOT EXISTS user_notification_hidden (
  user_id INT NOT NULL,
  change_id INT NOT NULL,
  PRIMARY KEY (user_id, change_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (change_id) REFERENCES product_changes(id) ON DELETE CASCADE
) ENGINE=InnoDB;
