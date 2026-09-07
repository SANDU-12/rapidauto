-- ============================================================
-- Rapid Auto Service Center — Database Schema (MySQL / WampServer)
-- Import this in phpMyAdmin, or run: mysql -u root -p < schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS rapidauto CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rapidauto;

-- ---------------- USERS (customers + staff login) ----------------
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(80),
  last_name VARCHAR(80),
  email VARCHAR(150) NOT NULL UNIQUE,
  phone VARCHAR(30),
  password VARCHAR(255) NULL,               -- bcrypt hash; NULL for Google-only accounts
  google_id VARCHAR(100) NULL,
  role ENUM('customer','super_admin','manager','cashier','receptionist','mechanic','car_wash_staff','inventory_manager') DEFAULT 'customer',
  status ENUM('active','suspended') DEFAULT 'active',
  profile_image VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------- CUSTOMERS (profile info) ----------------
CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  address VARCHAR(255),
  nic VARCHAR(30),
  date_of_birth DATE,
  membership ENUM('none','silver','gold','platinum') DEFAULT 'none',
  reward_points INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------- EMPLOYEES ----------------
CREATE TABLE employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,                          -- linked login account (users.role should be a staff role)
  name VARCHAR(120),
  position VARCHAR(80),
  phone VARCHAR(30),
  email VARCHAR(150),
  salary DECIMAL(10,2),
  availability ENUM('available','busy','off') DEFAULT 'available',
  skills VARCHAR(255),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------- VEHICLES ----------------
CREATE TABLE vehicles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NULL,
  vehicle_type ENUM('Motorcycle','Car','Jeep/Vessel','Three Wheeler','Van (Small)','Van (Large)','Lorry') NOT NULL,
  brand VARCHAR(80),
  model VARCHAR(80),
  year INT,
  color VARCHAR(40),
  registration_number VARCHAR(30),
  engine_number VARCHAR(60),
  chassis_number VARCHAR(60),
  fuel_type VARCHAR(30),
  image VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------- SERVICES (the price list) ----------------
CREATE TABLE services (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(60) NOT NULL,
  service_name VARCHAR(150) NOT NULL,
  description VARCHAR(255),
  price DECIMAL(10,2) NOT NULL,
  duration INT DEFAULT 30,                 -- minutes
  image VARCHAR(255),
  status ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

-- ---------------- PACKAGES (bundled services) ----------------
CREATE TABLE packages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150),
  description VARCHAR(255),
  discount DECIMAL(5,2) DEFAULT 0,
  price DECIMAL(10,2),
  availability BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB;

CREATE TABLE package_services (
  package_id INT NOT NULL,
  service_id INT NOT NULL,
  PRIMARY KEY (package_id, service_id),
  FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
  FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------- BOOKINGS ----------------
CREATE TABLE bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_code VARCHAR(20) NOT NULL UNIQUE,   -- e.g. RA-1001
  customer_id INT NULL,
  vehicle_id INT NULL,
  employee_id INT NULL,
  guest_name VARCHAR(120),
  guest_phone VARCHAR(30),
  guest_email VARCHAR(150),
  booking_date DATE NOT NULL,
  time_slot VARCHAR(20) NOT NULL,
  notes VARCHAR(255),
  status ENUM('pending','confirmed','in_progress','completed','cancelled') DEFAULT 'confirmed',
  total_amount DECIMAL(10,2) DEFAULT 0,
  payment_status ENUM('unpaid','paid') DEFAULT 'unpaid',
  tracking_stage_index INT DEFAULT 0,        -- 0-9, see stages list in app
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE booking_services (
  booking_id INT NOT NULL,
  service_id INT NOT NULL,
  price_at_booking DECIMAL(10,2),
  PRIMARY KEY (booking_id, service_id),
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------- PAYMENTS ----------------
CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  amount DECIMAL(10,2),
  tax DECIMAL(10,2) DEFAULT 0,
  discount DECIMAL(10,2) DEFAULT 0,
  total DECIMAL(10,2),
  payment_method ENUM('PayPal','Card','Cash','BankTransfer') NOT NULL,
  paypal_email VARCHAR(150),                 -- for PayPal method
  card_last4 VARCHAR(4),                     -- for Card method (never store full PAN)
  paypal_transaction_id VARCHAR(100),
  card_transaction_id VARCHAR(100),
  status ENUM('pending','success','failed','refunded') DEFAULT 'pending',
  receipt_url VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------- INVENTORY ----------------
CREATE TABLE suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company VARCHAR(150),
  contact_person VARCHAR(100),
  phone VARCHAR(30),
  email VARCHAR(150),
  address VARCHAR(255)
) ENGINE=InnoDB;

CREATE TABLE inventory (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120),
  category VARCHAR(60),
  quantity INT DEFAULT 0,
  minimum_stock INT DEFAULT 5,
  supplier_id INT NULL,
  purchase_price DECIMAL(10,2),
  selling_price DECIMAL(10,2),
  location VARCHAR(100),
  status ENUM('available','unavailable','low_stock') DEFAULT 'available',
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------- PASSWORD RESET TOKENS ----------------
CREATE TABLE password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token VARCHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------- PURCHASE RECORDS (what the shop bought in) ----------------
CREATE TABLE purchase_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  purchase_code VARCHAR(20) NOT NULL UNIQUE,     -- e.g. PR-1001
  item_name VARCHAR(150) NOT NULL,
  quantity INT NOT NULL,
  cost DECIMAL(10,2) NOT NULL,
  purchase_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------- SUPPLIER ORDERS (what's on order from suppliers) ----------------
CREATE TABLE supplier_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_code VARCHAR(20) NOT NULL UNIQUE,        -- e.g. SO-1001
  supplier_id INT NULL,
  item_name VARCHAR(150) NOT NULL,
  quantity INT NOT NULL,
  cost DECIMAL(10,2) NOT NULL,
  order_date DATE NOT NULL,
  status ENUM('ordered','received','returned') DEFAULT 'ordered',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE purchase_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT NULL,
  amount DECIMAL(10,2),
  invoice VARCHAR(100),
  order_date DATE,
  status ENUM('pending','received','cancelled') DEFAULT 'pending',
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------- REVIEWS ----------------
CREATE TABLE reviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NULL,
  booking_id INT NULL,
  guest_name VARCHAR(120),
  rating TINYINT CHECK (rating BETWEEN 1 AND 5),
  comment TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------- NOTIFICATIONS ----------------
CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(150),
  message VARCHAR(255),
  type VARCHAR(40),
  status ENUM('unread','read') DEFAULT 'unread',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------- COUPONS ----------------
CREATE TABLE coupons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) UNIQUE,
  discount DECIMAL(5,2),
  expiry_date DATE,
  minimum_amount DECIMAL(10,2),
  usage_limit INT
) ENGINE=InnoDB;

-- ---------------- PRODUCTS (spare parts / accessories shop) ----------------
CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  category VARCHAR(60),
  price DECIMAL(10,2) NOT NULL,
  image VARCHAR(255),
  stock INT DEFAULT 50,
  status ENUM('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB;

-- ---------------- ORDERS (spare parts checkout) ----------------
CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_code VARCHAR(20) NOT NULL UNIQUE,   -- e.g. ORD-1001
  customer_name VARCHAR(120),
  phone VARCHAR(30),
  email VARCHAR(150),
  address VARCHAR(255),
  subtotal DECIMAL(10,2),
  delivery_fee DECIMAL(10,2) DEFAULT 350,
  total DECIMAL(10,2),
  payment_method ENUM('PayPal','Card','Cash') NOT NULL,
  paypal_email VARCHAR(150),
  card_last4 VARCHAR(4),
  status ENUM('pending','confirmed','shipped','delivered','cancelled') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NOT NULL,
  qty INT NOT NULL,
  price_at_order DECIMAL(10,2),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- SEED DATA — price list from the shop's actual sign
-- ============================================================
INSERT INTO services (category, service_name, description, price) VALUES
('Motorcycle','Motorcycle Wash Only','Exterior body wash for motorbikes & scooters.',500),
('Motorcycle','Motorcycle Wash & Oil','Body wash plus a quick engine oil top-up.',600),
('Motorcycle','Motorcycle Wash & Wax','Body wash with a protective wax finish.',700),
('Car','Car Body Wash Only','Full exterior hand wash for sedans & hatchbacks.',800),
('Car','Car Body Wash & Vacuum','Exterior wash plus full interior vacuuming.',1000),
('Car','Car/Van Vacuum Only','Interior vacuum service only, no exterior wash.',600),
('Jeep/Vessel','Vessel/Jeep Body Wash','Exterior wash suited for jeeps & higher-clearance vehicles.',1000),
('Jeep/Vessel','Vessel/Jeep Body Wash & Vacuum','Exterior wash plus interior vacuum for jeeps.',1300),
('Three Wheeler','Three Wheel Body Wash','Complete body wash for three-wheelers.',1100),
('Three Wheeler','Three Wheel Fully Wash & Oil','Full wash plus engine oil service for three-wheelers.',1800),
('Van (Small)','Van (Small) Body Wash','Exterior wash for small vans.',1000),
('Van (Small)','Van (Small) Body Wash & Vacuum','Exterior wash plus interior vacuum, small vans.',1200),
('Van (Large)','Van (Large) Body Wash','Exterior wash for large vans.',1500),
('Van (Large)','Van (Large) Body Wash & Vacuum','Exterior wash plus interior vacuum, large vans.',2000),
('Lorry','Lorry Body Wash (Dimo & other small lorry)','Exterior wash for Dimo & similar small lorries.',1600),
('Lorry','Lorry Body Wash & Vacuum','Full exterior wash plus cabin vacuum for lorries.',2000),
('Additional Service','Engine Oil Change',NULL,0),
('Additional Service','Transmission Oil',NULL,0),
('Additional Service','Brake Service',NULL,0),
('Additional Service','Battery Replacement',NULL,0),
('Additional Service','Wheel Alignment',NULL,0),
('Additional Service','Wheel Balancing',NULL,0),
('Additional Service','Tyre Replacement',NULL,0),
('Additional Service','AC Service',NULL,0),
('Additional Service','Engine Diagnostics',NULL,0),
('Additional Service','Interior Detailing',NULL,0),
('Additional Service','Exterior Detailing',NULL,0),
('Additional Service','Wax Polishing',NULL,0),
('Additional Service','Ceramic Coating',NULL,0),
('Additional Service','Engine Cleaning',NULL,0),
('Additional Service','Pressure Wash',NULL,0),
('Additional Service','Underbody Wash',NULL,0),
('Additional Service','Steam Cleaning',NULL,0);

-- Sample inventory + supplier
INSERT INTO suppliers (company, contact_person, phone, email, address) VALUES
('Colombo Auto Chemicals','S. Fernando','0771234567','sales@cac.lk','Colombo');

-- Spare parts / accessories shop catalog
INSERT INTO products (name, category, price, image, stock) VALUES
('Car Engine Oil 5W30 (4L)','Fluids',4200,'https://images.unsplash.com/photo-1635784063388-1ff609e42ac2?w=400&h=300&fit=crop',40),
('Front Brake Pads (Set)','Brakes',6500,'https://images.unsplash.com/photo-1615906655593-ad0386982a0f?w=400&h=300&fit=crop',25),
('12V Car Battery 45Ah','Electrical',18500,'https://images.unsplash.com/photo-1620891549027-942fdc95d3f5?w=400&h=300&fit=crop',10),
('Air Filter (Universal)','Filters',1800,'https://images.unsplash.com/photo-1635784357262-04c8a1a5f5b8?w=400&h=300&fit=crop',60),
('Wiper Blade Set','Exterior',2400,'https://images.unsplash.com/photo-1617886322168-72b886573c5f?w=400&h=300&fit=crop',35),
('Spark Plug (Set of 4)','Engine',3200,'https://images.unsplash.com/photo-1607860108855-64acf2078ed9?w=400&h=300&fit=crop',30),
('Car Wax Polish (500ml)','Detailing',1500,'https://images.unsplash.com/photo-1601362840469-51e4d8d58785?w=400&h=300&fit=crop',50),
('Microfiber Cloth Pack (5)','Detailing',900,'https://images.unsplash.com/photo-1600861195091-690c92f1d2cd?w=400&h=300&fit=crop',80),
('Headlight Bulb (H4, pair)','Electrical',2100,'https://images.unsplash.com/photo-1621007947382-bb3c3994e3fb?w=400&h=300&fit=crop',45),
('Tyre Shine Spray (400ml)','Detailing',1200,'https://images.unsplash.com/photo-1520340356584-f9917d1eea6f?w=400&h=300&fit=crop',55),
('Cabin AC Filter','Filters',1600,'https://images.unsplash.com/photo-1635784357262-04c8a1a5f5b8?w=400&h=300&fit=crop',40),
('Car Floor Mats (Set of 4)','Interior',5200,'https://images.unsplash.com/photo-1592840331700-6a4b2b604c4d?w=400&h=300&fit=crop',20);

INSERT INTO inventory (name, category, quantity, minimum_stock, supplier_id, purchase_price, status) VALUES
('Car Shampoo','Chemicals',12,10,1,800,'low_stock'),
('Microfiber Cloths','Supplies',40,15,1,150,'available'),
('Wax Polish','Chemicals',5,8,1,1200,'low_stock'),
('Engine Oil 5W30','Fluids',22,10,1,2500,'available');

-- Sample purchase records
INSERT INTO purchase_records (purchase_code, item_name, quantity, cost, purchase_date) VALUES
('PR-1001','Car Shampoo (5L drums)',10,7500,'2026-07-02'),
('PR-1002','Microfiber Cloths (bulk pack)',100,14000,'2026-07-10'),
('PR-1003','Engine Oil 5W30 (case of 12)',5,12000,'2026-07-18');

-- Sample supplier orders
INSERT INTO supplier_orders (order_code, supplier_id, item_name, quantity, cost, order_date, status) VALUES
('SO-1001', 1, 'Wax Polish (bottles)', 20, 22000, '2026-08-01', 'ordered'),
('SO-1002', 1, 'Tyre Shine Spray', 15, 9000, '2026-07-25', 'received'),
('SO-1003', 1, 'Car Battery 45Ah', 5, 80000, '2026-07-15', 'returned');

-- Default admin login is created by seed_admin.php (run once after import).
-- We don't hardcode a password hash here because bcrypt hashes are salted per-install;
-- seed_admin.php uses PHP's password_hash() so it's guaranteed to match.

