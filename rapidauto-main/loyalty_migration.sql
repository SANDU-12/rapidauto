-- Run once on an existing Rapid Auto database to enable loyalty rewards.
ALTER TABLE bookings
  ADD COLUMN loyalty_points_used INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN loyalty_discount DECIMAL(10,2) NOT NULL DEFAULT 0;

CREATE TABLE loyalty_transactions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  booking_id INT NULL,
  transaction_type ENUM('earned','redeemed') NOT NULL,
  points INT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_loyalty_booking_type (booking_id, transaction_type),
  KEY idx_loyalty_customer_created (customer_id, created_at),
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL
) ENGINE=InnoDB;