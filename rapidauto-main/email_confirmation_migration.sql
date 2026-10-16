-- Prevents duplicate confirmation emails (e.g. when PayHere retries its notify callback).
ALTER TABLE bookings ADD COLUMN confirmation_email_sent_at DATETIME NULL;
ALTER TABLE orders   ADD COLUMN confirmation_email_sent_at DATETIME NULL;
