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
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  email VARCHAR(150) NOT NULL,
  phone VARCHAR(30),
  profile_image VARCHAR(255),
  address VARCHAR(255),
  nic VARCHAR(30),
  date_of_birth DATE,
  membership ENUM('none','silver','gold','platinum') DEFAULT 'none',
  reward_points INT DEFAULT 0,                  -- Spendable balance; detailed earn/redeem entries are in loyalty_transactions.
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customers_user_id (user_id),
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
  UNIQUE KEY uq_employees_user_id (user_id),
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
  current_km INT NULL,
  next_service_date DATE NULL,
  next_service_km INT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
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
  loyalty_points_used INT UNSIGNED NOT NULL DEFAULT 0,
  loyalty_discount DECIMAL(10,2) NOT NULL DEFAULT 0,
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

CREATE TABLE loyalty_transactions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  booking_id INT NULL,
  transaction_type ENUM('earned','redeemed') NOT NULL,
  points INT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_loyalty_booking_type (booking_id, transaction_type), -- Prevent duplicate earn/redeem entries per booking.
  KEY idx_loyalty_customer_created (customer_id, created_at),
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE vehicle_service_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  booking_id INT NULL,
  service_date DATE NOT NULL,
  odometer_km INT NULL,
  service_summary VARCHAR(255) NOT NULL,
  issues VARCHAR(255),
  recommendations VARCHAR(255),
  next_service_date DATE NULL,
  next_service_km INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL
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
-- SEED DATA — complete demo dataset for the website and dashboard
-- ============================================================

-- Customer and staff accounts. Admin credentials: admin@rapid.lk / admin123.
INSERT INTO users (id, first_name, last_name, email, phone, password, role) VALUES
(1,'Kasun','Perera','kasun.perera@example.com','0771112233','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llCkQpVZ0LZ8p3DkT8y6a','customer'),
(2,'Nadeesha','Fernando','nadeesha.fernando@example.com','0772223344','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llCkQpVZ0LZ8p3DkT8y6a','customer'),
(3,'Dilan','Silva','dilan.silva@rapid.lk','0773334455','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llCkQpVZ0LZ8p3DkT8y6a','mechanic'),
(4,'Rapid','Admin','admin@rapid.lk',NULL,'$2y$12$NOosxghSetUOZWIWXkkabONqksyfXDpHKx//I7Ij/nE17jcCAchIG','super_admin');

INSERT INTO customers (id, user_id, first_name, last_name, email, phone, address, nic, date_of_birth, membership, reward_points) VALUES
(1,1,'Kasun','Perera','kasun.perera@example.com','0771112233','45 Lake Road, Colombo 03','199012345678','1990-05-14','gold',860),
(2,2,'Nadeesha','Fernando','nadeesha.fernando@example.com','0772223344','18 Temple Lane, Dehiwala','199245678901','1992-11-02','silver',340);

INSERT INTO employees (id, user_id, name, position, phone, email, salary, availability, skills) VALUES
(1,3,'Dilan Silva','Senior Mechanic','0773334455','dilan.silva@rapid.lk',85000,'available','Engine diagnostics, brake service, oil changes'),
(2,NULL,'Tharindu Jayasinghe','Car Wash Technician','0774445566','tharindu.j@rapid.lk',65000,'busy','Exterior wash, vacuum, ceramic coating'),
(3,NULL,'Madhavi Kumari','Receptionist','0775556677','madhavi.k@rapid.lk',60000,'available','Bookings, customer support, payments');

INSERT INTO vehicles (id, customer_id, vehicle_type, brand, model, year, color, registration_number, engine_number, chassis_number, fuel_type, image) VALUES
(1,1,'Car','Toyota','Aqua',2018,'White','CAB-4521','1NZ-7A4521','NHP10-4521','Hybrid','images/35b87f_db036e6c09604e2aa90e687cc85b1d4c~mv2.avif'),
(2,2,'Van (Small)','Suzuki','Every',2020,'Silver','NC-7890','K14B-7890','DA17V-7890','Petrol','images/uv rain.avif'),
(3,NULL,'Motorcycle','Honda','Hornet',2022,'Black','BKE-2468','CBR-2468','MC-2468','Petrol','images/motor.jpg');

INSERT INTO services (id, category, service_name, description, price, duration, image) VALUES
(1,'Motorcycle','Motorcycle Wash Only','Exterior body wash for motorbikes & scooters.',500,30,'images/motor.jpg'),
(2,'Motorcycle','Motorcycle Wash & Oil','Body wash plus a quick engine oil top-up.',600,45,'images/eng oil.webp'),
(3,'Motorcycle','Motorcycle Wash & Wax','Body wash with a protective wax finish.',700,60,'images/wax.png'),
(4,'Car','Car Body Wash Only','Full exterior hand wash for sedans & hatchbacks.',800,30,'images/foam.jpg'),
(5,'Car','Car Body Wash & Vacuum','Exterior wash plus full interior vacuuming.',1000,45,'images/vacuum-cleaner.jpg'),
(6,'Car','Car/Van Vacuum Only','Interior vacuum service only, no exterior wash.',600,30,'images/vacuum-cleaner.jpg'),
(7,'Jeep/Vessel','Vessel/Jeep Body Wash','Exterior wash suited for jeeps & higher-clearance vehicles.',1000,45,'images/foam.jpg'),
(8,'Jeep/Vessel','Vessel/Jeep Body Wash & Vacuum','Exterior wash plus interior vacuum for jeeps.',1300,60,'images/vacuum-cleaner.jpg'),
(9,'Three Wheeler','Three Wheel Body Wash','Complete body wash for three-wheelers.',1100,45,'images/foam.jpg'),
(10,'Three Wheeler','Three Wheel Fully Wash & Oil','Full wash plus engine oil service for three-wheelers.',1800,75,'images/eng oil.webp'),
(11,'Van (Small)','Van (Small) Body Wash','Exterior wash for small vans.',1000,45,'images/foam.jpg'),
(12,'Van (Small)','Van (Small) Body Wash & Vacuum','Exterior wash plus interior vacuum, small vans.',1200,60,'images/vacuum-cleaner.jpg'),
(13,'Van (Large)','Van (Large) Body Wash','Exterior wash for large vans.',1500,60,'images/foam.jpg'),
(14,'Van (Large)','Van (Large) Body Wash & Vacuum','Exterior wash plus interior vacuum, large vans.',2000,90,'images/vacuum-cleaner.jpg'),
(15,'Lorry','Lorry Body Wash (Dimo & other small lorry)','Exterior wash for Dimo & similar small lorries.',1600,75,'images/foam.jpg'),
(16,'Lorry','Lorry Body Wash & Vacuum','Full exterior wash plus cabin vacuum for lorries.',2000,100,'images/vacuum-cleaner.jpg'),
(17,'Additional Service','Engine Oil Change','Replace engine oil and inspect fluid levels.',3500,45,'images/eng oil.webp'),
(18,'Additional Service','Transmission Oil','Transmission oil replacement service.',4500,60,'images/eng oil.webp'),
(19,'Additional Service','Brake Service','Brake inspection, cleaning and adjustment.',2500,60,'images/brake pads.jpg'),
(20,'Additional Service','Battery Replacement','Battery testing and replacement service.',1000,30,'images/battery.webp'),
(21,'Additional Service','Wheel Alignment','Four-wheel alignment and steering check.',3500,60,'images/whel.png'),
(22,'Additional Service','Wheel Balancing','Dynamic wheel balancing service.',2500,45,'images/wheel tyre detailing.avif'),
(23,'Additional Service','Tyre Replacement','Tyre fitting and pressure adjustment.',1500,45,'images/wheel tyre detailing.avif'),
(24,'Additional Service','AC Service','Vehicle air-conditioning inspection and service.',5000,90,'images/uv rain.avif'),
(25,'Additional Service','Engine Diagnostics','Computer diagnostic scan and report.',3000,45,'images/mechanic dig.png'),
(26,'Additional Service','Interior Detailing','Deep clean for seats, carpets and dashboard.',6500,150,'images/detailing.png'),
(27,'Additional Service','Exterior Detailing','Paint decontamination and exterior detail.',7500,180,'images/finished car.png'),
(28,'Additional Service','Wax Polishing','Machine polish and protective wax finish.',4500,120,'images/wax.png'),
(29,'Additional Service','Ceramic Coating','Long-lasting ceramic paint protection.',25000,300,'images/finished car.png'),
(30,'Additional Service','Engine Cleaning','Engine bay cleaning and dressing.',3500,90,'images/detailing.png'),
(31,'Additional Service','Pressure Wash','High-pressure cleaning for exterior surfaces.',1200,30,'images/foam.jpg'),
(32,'Additional Service','Underbody Wash','Underbody rinse and mud removal.',1800,45,'images/uv rain.avif'),
(33,'Additional Service','Steam Cleaning','Steam sanitation for interior surfaces.',4000,120,'images/detailing.png');

INSERT INTO packages (id, name, description, discount, price, availability) VALUES
(1,'Aqua Care Package','Car body wash, vacuum and wax polish.',10,1800,TRUE),
(2,'Motorcycle Complete Care','Motorcycle wash, oil service and wax finish.',5,1200,TRUE),
(3,'Van Family Package','Large van wash, vacuum and interior detailing.',12,7500,TRUE);

INSERT INTO package_services (package_id, service_id) VALUES
(1,5),(1,28),(2,2),(2,3),(3,14),(3,26);

INSERT INTO suppliers (id, company, contact_person, phone, email, address) VALUES
(1,'Colombo Auto Chemicals','S. Fernando','0771234567','sales@cac.lk','125 High Level Road, Colombo 05'),
(2,'Lanka Motor Parts','R. Wijesinghe','0719876543','orders@lmp.lk','22 Main Street, Nugegoda');

-- Product images intentionally use the local files shipped in the images directory.
INSERT INTO products (id, name, category, price, image, stock) VALUES
(1,'Car Engine Oil 5W30 (4L)','Fluids',4200,'images/eng oil.webp',40),
(2,'Front Brake Pads (Set)','Brakes',6500,'images/brake pads.jpg',25),
(3,'12V Car Battery 45Ah','Electrical',18500,'images/battery.webp',10),
(4,'Air Filter (Universal)','Filters',1800,'images/air.webp',60),
(5,'Wiper Blade Set','Exterior',2400,'images/wiper.webp',35),
(6,'Spark Plug (Set of 4)','Engine',3200,'images/spark.webp',30),
(7,'Car Wax Polish (500ml)','Detailing',1500,'images/polish.webp',50),
(8,'Microfiber Cloth Pack (5)','Detailing',900,'images/micro.webp',80),
(9,'Headlight Bulb (H4, pair)','Electrical',2100,'images/head.webp',45),
(10,'Tyre Shine Spray (400ml)','Detailing',1200,'images/tcleaner.webp',55),
(11,'Cabin AC Filter','Filters',1600,'images/cabin.webp',40),
(12,'Car Floor Mats (Set of 4)','Interior',5200,'images/mats.webp',20);

INSERT INTO inventory (id, name, category, quantity, minimum_stock, supplier_id, purchase_price, selling_price, location, status) VALUES
(1,'Car Shampoo','Chemicals',12,10,1,800,1200,'Wash bay shelf A','low_stock'),
(2,'Microfiber Cloths','Supplies',40,15,1,150,250,'Detailing shelf B','available'),
(3,'Wax Polish','Chemicals',5,8,1,1200,1500,'Detailing shelf A','low_stock'),
(4,'Engine Oil 5W30','Fluids',22,10,2,2500,4200,'Parts store rack 1','available'),
(5,'Brake Cleaner','Chemicals',0,5,2,900,1400,'Workshop cabinet','unavailable');

INSERT INTO bookings (id, booking_code, customer_id, vehicle_id, employee_id, guest_name, guest_phone, guest_email, booking_date, time_slot, notes, status, total_amount, payment_status, tracking_stage_index) VALUES
(1,'RA-1001',1,1,2,'Kasun Perera','0771112233','kasun.perera@example.com','2026-08-18','09:00 AM','Please check the front brakes.','completed',7500,'paid',9),
(2,'RA-1002',2,2,2,'Nadeesha Fernando','0772223344','nadeesha.fernando@example.com','2026-08-26','11:00 AM','Regular family van wash.','in_progress',1200,'unpaid',3),
(3,'RA-1003',NULL,3,1,'Ruwan Senanayake','0776667788','ruwan.s@example.com','2026-09-08','02:00 PM','First visit.','confirmed',700,'unpaid',0);

INSERT INTO booking_services (booking_id, service_id, price_at_booking) VALUES
(1,5,1000),(1,28,4500),(1,19,2000),(2,12,1200),(3,3,700);

INSERT INTO payments (id, booking_id, amount, tax, discount, total, payment_method, card_last4, status, receipt_url) VALUES
(1,1,7500,0,0,7500,'Card','4242','success','receipts/RA-1001.pdf'),
(2,2,1200,0,0,1200,'Cash',NULL,'pending',NULL),
(3,3,700,0,0,700,'PayPal',NULL,'pending',NULL);

INSERT INTO purchase_records (purchase_code, item_name, quantity, cost, purchase_date) VALUES
('PR-1001','Car Shampoo (5L drums)',10,7500,'2026-07-02'),
('PR-1002','Microfiber Cloths (bulk pack)',100,14000,'2026-07-10'),
('PR-1003','Engine Oil 5W30 (case of 12)',5,12000,'2026-07-18');

INSERT INTO supplier_orders (supplier_id, order_code, item_name, quantity, cost, order_date, status) VALUES
(1,'SO-1001','Wax Polish (bottles)',20,22000,'2026-08-01','ordered'),
(1,'SO-1002','Tyre Shine Spray',15,9000,'2026-07-25','received'),
(2,'SO-1003','Car Battery 45Ah',5,80000,'2026-07-15','returned');

INSERT INTO purchase_orders (supplier_id, amount, invoice, order_date, status) VALUES
(1,22000,'INV-CAC-2026-081','2026-08-01','pending'),
(2,80000,'INV-LMP-2026-072','2026-07-15','cancelled');

INSERT INTO reviews (id, customer_id, booking_id, guest_name, rating, comment) VALUES
(1,1,1,'Kasun Perera',5,'The car looked brand new and the brake check was very helpful.'),
(2,2,2,'Nadeesha Fernando',5,'Fast booking and an excellent wash for our van.'),
(3,NULL,NULL,'Chathura P.',4,'Friendly staff and a thorough motorcycle wash.');

INSERT INTO notifications (user_id, title, message, type, status) VALUES
(1,'Booking completed','Your booking RA-1001 is complete. Thank you for choosing Rapid Auto.','booking','read'),
(2,'Booking in progress','Your vehicle is currently being washed.','booking','unread'),
(3,'Low stock alert','Brake Cleaner is out of stock.','inventory','unread');

INSERT INTO coupons (code, discount, expiry_date, minimum_amount, usage_limit) VALUES
('RAPID10',10,'2026-12-31',2000,100),
('WELCOME500',500,'2026-10-31',5000,25);

INSERT INTO password_resets (user_id, token, expires_at, used) VALUES
(1,'demo-reset-token-kasun','2026-12-31 23:59:59',0);

INSERT INTO orders (id, order_code, customer_name, phone, email, address, subtotal, delivery_fee, total, payment_method, card_last4, status) VALUES
(1,'ORD-1001','Kasun Perera','0771112233','kasun.perera@example.com','45 Lake Road, Colombo 03',5700,350,6050,'Card','4242','delivered'),
(2,'ORD-1002','Nadeesha Fernando','0772223344','nadeesha.fernando@example.com','18 Temple Lane, Dehiwala',900,350,1250,'Cash',NULL,'confirmed');

INSERT INTO order_items (order_id, product_id, qty, price_at_order) VALUES
(1,1,1,4200),(1,7,1,1500),(2,8,1,900);

-- Default admin login is created by seed_admin.php (run once after import).

