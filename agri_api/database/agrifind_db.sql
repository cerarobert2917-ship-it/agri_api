-- Agri Price & Stock Locator - Mamburao, Occidental Mindoro
-- I-import sa phpMyAdmin (Import tab). Gagawa ito ng database na agri_db.

CREATE DATABASE IF NOT EXISTS agri_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE agri_db;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  phone VARCHAR(15),
  role ENUM('customer','store_owner','admin') NOT NULL DEFAULT 'customer',
  avatar_url VARCHAR(255),
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  failed_logins INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE refresh_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  INDEX (token_hash),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE stores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  owner_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  description TEXT,
  address VARCHAR(255),
  barangay VARCHAR(80),
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  contact_number VARCHAR(20),
  opening_hours VARCHAR(120),
  photo_url VARCHAR(255),
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  rejection_reason VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (latitude, longitude),
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  icon VARCHAR(40)
) ENGINE=InnoDB;

CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  store_id INT NOT NULL,
  category_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  brand VARCHAR(80),
  description TEXT,
  unit ENUM('kg','sack','liter','piece') NOT NULL DEFAULT 'piece',
  price DECIMAL(10,2) NOT NULL,
  stock_quantity INT NOT NULL DEFAULT 0,
  low_stock_threshold INT NOT NULL DEFAULT 5,
  image_url VARCHAR(255),
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  price_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  stock_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (store_id), INDEX (category_id),
  FULLTEXT INDEX ft_products (name, brand),
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

CREATE TABLE price_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  old_price DECIMAL(10,2),
  new_price DECIMAL(10,2),
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE favorites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  product_id INT NULL,
  store_id INT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE notification_subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  product_id INT NOT NULL,
  notify_on_stock TINYINT(1) NOT NULL DEFAULT 1,
  notify_on_price_change TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE (user_id, product_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(120),
  body VARCHAR(255),
  type VARCHAR(30),
  reference_id INT,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE chat_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  message TEXT,
  response TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE search_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  query VARCHAR(200),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE device_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  fcm_token VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- TRIGGERS
DELIMITER $$
CREATE TRIGGER products_before_update BEFORE UPDATE ON products
FOR EACH ROW
BEGIN
  IF NEW.price <> OLD.price THEN SET NEW.price_updated_at = NOW(); END IF;
  IF NEW.stock_quantity <> OLD.stock_quantity THEN SET NEW.stock_updated_at = NOW(); END IF;
END$$

CREATE TRIGGER products_after_update AFTER UPDATE ON products
FOR EACH ROW
BEGIN
  IF NEW.price <> OLD.price THEN
    INSERT INTO price_history (product_id, old_price, new_price) VALUES (NEW.id, OLD.price, NEW.price);
    INSERT INTO notifications (user_id, title, body, type, reference_id)
      SELECT user_id, 'Nagbago ang presyo', CONCAT(NEW.name, ': ₱', OLD.price, ' → ₱', NEW.price), 'price', NEW.id
      FROM notification_subscriptions WHERE product_id = NEW.id AND notify_on_price_change = 1;
  END IF;
  IF OLD.stock_quantity = 0 AND NEW.stock_quantity > 0 THEN
    INSERT INTO notifications (user_id, title, body, type, reference_id)
      SELECT user_id, 'May stock na!', CONCAT(NEW.name, ' ay available na ulit.'), 'stock', NEW.id
      FROM notification_subscriptions WHERE product_id = NEW.id AND notify_on_stock = 1;
  END IF;
END$$
DELIMITER ;

-- SEED DATA (password ng lahat ng demo account: Admin1234)
INSERT INTO categories (name, icon) VALUES
('Fertilizer','grass'),('Pesticide','bug_report'),('Seeds','eco'),('Animal Feeds','pets'),
('Farm Tools','handyman'),('Irrigation','water_drop'),('Veterinary Supplies','medical_services');

INSERT INTO users (full_name, email, password_hash, phone, role) VALUES
('System Admin','admin@agri.local','$2b$12$bAQGen2R7YqkEDgBXbc90.KRWkv4uzHWUKbZtGFiprGlQLEwMjTAO','09170000001','admin'),
('Juan Dela Cruz','owner1@agri.local','$2b$12$bAQGen2R7YqkEDgBXbc90.KRWkv4uzHWUKbZtGFiprGlQLEwMjTAO','09170000002','store_owner'),
('Maria Santos','owner2@agri.local','$2b$12$bAQGen2R7YqkEDgBXbc90.KRWkv4uzHWUKbZtGFiprGlQLEwMjTAO','09170000003','store_owner'),
('Pedro Reyes','customer@agri.local','$2b$12$bAQGen2R7YqkEDgBXbc90.KRWkv4uzHWUKbZtGFiprGlQLEwMjTAO','09170000004','customer');

-- Sample na tindahan (palitan ng totoong tindahan at coordinates sa Mamburao)
INSERT INTO stores (owner_id, name, description, address, barangay, latitude, longitude, contact_number, opening_hours, status) VALUES
(2,'Dela Cruz Agri Supply','Pataba, binhi at gamit sa bukid','Poblacion, Mamburao','Poblacion 1',13.2230000,120.5960000,'09170000002','Mon-Sat 7:00AM-6:00PM','approved'),
(3,'Santos Farm Store','Pakain sa hayop at pesticide','National Hwy, Mamburao','Payompon',13.2190000,120.5900000,'09170000003','Mon-Sun 7:00AM-5:00PM','approved');

INSERT INTO products (store_id, category_id, name, brand, unit, price, stock_quantity, low_stock_threshold) VALUES
(1,1,'Urea 46-0-0','Atlas','sack',1850.00,25,5),
(1,1,'Complete 14-14-14','Atlas','sack',1950.00,3,5),
(1,3,'Hybrid Rice Seeds','SL-8H','kg',185.00,40,10),
(2,1,'Urea 46-0-0','Yara','sack',1790.00,0,5),
(2,4,'Hog Grower Feeds','Pilmico','sack',1450.00,12,5),
(2,2,'Cypermethrin 5EC','Generic','liter',420.00,15,5);
