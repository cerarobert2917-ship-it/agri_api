-- Patakbuhin sa phpMyAdmin (database: agri_db > SQL tab). Ligtas patakbuhin nang ilang beses.

-- Ang store ay ginagawa ng admin kaya puwedeng walang owner pa
ALTER TABLE stores MODIFY owner_id INT NULL;

CREATE TABLE IF NOT EXISTS store_claims (
  id INT AUTO_INCREMENT PRIMARY KEY,
  store_id INT NOT NULL,
  owner_id INT NOT NULL,
  permit_image VARCHAR(255) NOT NULL,
  storefront_image VARCHAR(255) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  rejection_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  reviewed_by INT NULL,
  INDEX idx_claim_store_status (store_id, status),
  INDEX idx_claim_owner (owner_id),
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
