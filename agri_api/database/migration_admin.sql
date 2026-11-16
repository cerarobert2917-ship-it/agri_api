-- Run in phpMyAdmin: agri_db > SQL tab. Safe to run more than once (MariaDB / XAMPP).
-- Run this BEFORE using the new Admin Panel and the updated controllers.

-- 1) Stores: admins can activate / deactivate a store
ALTER TABLE stores ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1;

-- 2) Products: admins can hide inappropriate listings (owners still manage their own products)
ALTER TABLE products ADD COLUMN IF NOT EXISTS is_hidden TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN IF NOT EXISTS moderation_reason VARCHAR(255) NULL;

-- 3) Claims: separate permit / storefront verification statuses
ALTER TABLE store_claims ADD COLUMN IF NOT EXISTS permit_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending';
ALTER TABLE store_claims ADD COLUMN IF NOT EXISTS storefront_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending';

-- 4) Admin activity log
CREATE TABLE IF NOT EXISTS admin_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  action VARCHAR(60) NOT NULL,
  target_type VARCHAR(30) NULL,
  target_id INT NULL,
  details VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_logs_created (created_at),
  FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
