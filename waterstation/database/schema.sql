-- Water Station Record Management System - schema (MySQL 5.7+/MariaDB 10.3+)
-- Import into an EMPTY database that already exists (utf8mb4).
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  contact_number VARCHAR(30) NULL,
  address VARCHAR(255) NULL,
  type ENUM('Walk-in','Residential','Office','Business') NOT NULL DEFAULT 'Residential',
  registered_at DATE NOT NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cust_name (name),
  INDEX idx_cust_deleted (is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE inventory_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  category ENUM('Full gallons','Empty gallons','Bottles','Caps and seals','Labels','Packaging','Filters','Maintenance supplies') NOT NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
  quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
  min_level DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_inv_qty CHECK (quantity >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  track_stock TINYINT(1) NOT NULL DEFAULT 0,
  inventory_item_id INT UNSIGNED NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_prod_status (status),
  CONSTRAINT fk_prod_inv FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(25) NOT NULL UNIQUE,
  receipt_number VARCHAR(25) NOT NULL UNIQUE,   -- one receipt per order => no duplicate receipts
  customer_id INT UNSIGNED NULL,                -- NULL = anonymous walk-in
  order_date DATETIME NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_method ENUM('Cash','GCash','Bank Transfer') NOT NULL DEFAULT 'Cash',
  status ENUM('Pending','Processing','Ready for Pickup','Out for Delivery','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  stock_deducted TINYINT(1) NOT NULL DEFAULT 0,
  processed_by INT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  cancelled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ord_date (order_date),
  INDEX idx_ord_status (status),
  INDEX idx_ord_customer (customer_id),
  CONSTRAINT fk_ord_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ord_user FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  product_name VARCHAR(120) NOT NULL,           -- snapshot
  unit_price DECIMAL(10,2) NOT NULL,            -- snapshot: later price changes never alter past orders
  quantity INT UNSIGNED NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  INDEX idx_oi_order (order_id),
  INDEX idx_oi_product (product_id),
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_oi_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE riders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  contact_number VARCHAR(30) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE deliveries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL UNIQUE,
  address VARCHAR(255) NOT NULL,
  contact_number VARCHAR(30) NULL,
  rider_id INT UNSIGNED NULL,
  rider_name VARCHAR(100) NULL,
  scheduled_at DATETIME NOT NULL,
  delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  remarks VARCHAR(255) NULL,
  status ENUM('Scheduled','Preparing','Out for Delivery','Delivered','Failed') NOT NULL DEFAULT 'Scheduled',
  received_by VARCHAR(100) NULL,
  confirmation_notes VARCHAR(255) NULL,
  photo_path VARCHAR(255) NULL,
  delivered_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_del_sched (scheduled_at),
  INDEX idx_del_status (status),
  CONSTRAINT fk_del_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_del_rider FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE inventory_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  type ENUM('in','out','order','adjustment') NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,              -- always positive; direction comes from type
  balance_after DECIMAL(12,2) NOT NULL,
  txn_date DATE NOT NULL,
  remarks VARCHAR(255) NULL,
  order_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_it_item (item_id),
  INDEX idx_it_date (txn_date),
  CONSTRAINT fk_it_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_it_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_it_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category ENUM('Electricity','Water bill','Rent','Supplies','Salaries','Maintenance','Fuel','Other') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  expense_date DATE NOT NULL,
  description VARCHAR(255) NULL,
  receipt_path VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_exp_date (expense_date),
  INDEX idx_exp_cat (category),
  CONSTRAINT chk_exp_amt CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NOT NULL,
  entity_id VARCHAR(40) NULL,
  details VARCHAR(500) NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_al_created (created_at),
  INDEX idx_al_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE system_settings (
  setting_key VARCHAR(50) PRIMARY KEY,
  setting_value VARCHAR(500) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- Required default settings (not demo data). The admin account is created
-- automatically on first visit to login.php (see README).
INSERT INTO system_settings (setting_key, setting_value) VALUES
('station_name','My Water Refilling Station'),
('station_address','Your street, Barangay, City, Province'),
('station_contact','0900-000-0000'),
('receipt_footer','Thank you for your purchase! Please come again.');
