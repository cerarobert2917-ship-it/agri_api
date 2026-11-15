-- Run in phpMyAdmin: agri_db > SQL tab. Safe to run more than once.
-- Feed ng pagbabago ng presyo/stock na makikita ng mga viewer (farmer).

CREATE TABLE IF NOT EXISTS product_changes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  store_id INT NOT NULL,
  old_price DECIMAL(10,2) NULL,   -- NULL kung hindi nagbago ang presyo
  new_price DECIMAL(10,2) NULL,
  old_stock INT NULL,             -- NULL kung hindi nagbago ang stock
  new_stock INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_changes_created (created_at),
  INDEX idx_changes_product (product_id, created_at),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Hanggang saang update na ang nakita ng bawat viewer (para sa bilang sa badge)
CREATE TABLE IF NOT EXISTS user_notification_state (
  user_id INT PRIMARY KEY,
  last_seen_id INT NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
