-- Run once on an existing database to enable vehicle service reminders.
ALTER TABLE vehicles
  ADD COLUMN current_km INT NULL,
  ADD COLUMN next_service_date DATE NULL,
  ADD COLUMN next_service_km INT NULL,
  ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1;