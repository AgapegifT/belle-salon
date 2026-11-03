-- =====================================================================
--  Salon Appointment and Management System  -  database  (MySQL / MariaDB)
--
--  HOW TO USE (XAMPP):
--    1. Start Apache and MySQL in the XAMPP Control Panel.
--    2. Open http://localhost/phpmyadmin  ->  "Import"  ->  choose this file  ->  "Go".
--    3. That's it. The database "salon_db" is created and filled with demo data.
--
--  Importing it again wipes everything and starts fresh.
--  Tables follow the ERD of the design:
--    users, clients, staff, admins, services, staff_schedule,
--    appointments, payments, notifications, reviews
-- =====================================================================

CREATE DATABASE IF NOT EXISTS salon_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE salon_db;

-- Remove old tables first (children before parents) so the import can be repeated.
DROP TABLE IF EXISTS reviews;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS appointments;
DROP TABLE IF EXISTS staff_schedule;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS admins;
DROP TABLE IF EXISTS staff;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS users;

-- ---------------------------------------------------------------------
-- USER and its three roles (generalisation in the class diagram)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  user_id       INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(150) NOT NULL UNIQUE,
  phone         VARCHAR(30)  NOT NULL DEFAULT '',
  password_hash VARCHAR(255) NOT NULL,          -- never the real password
  role          VARCHAR(10)  NOT NULL,          -- client | staff | admin
  created_at    DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clients (
  client_id      INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL UNIQUE,
  loyalty_points INT NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE staff (
  staff_id       INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL UNIQUE,
  specialization VARCHAR(50) NOT NULL,          -- matches services.category, e.g. Hair
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admins (
  admin_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id  INT NOT NULL UNIQUE,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- What the salon sells, and when each stylist works
-- ---------------------------------------------------------------------
CREATE TABLE services (
  service_id       INT AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(100) NOT NULL,
  category         VARCHAR(50)  NOT NULL,
  price            DECIMAL(10,2) NOT NULL,
  duration_minutes INT NOT NULL,
  is_active        TINYINT NOT NULL DEFAULT 1   -- 0 = hidden from clients
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE staff_schedule (
  schedule_id INT AUTO_INCREMENT PRIMARY KEY,
  staff_id    INT NOT NULL,
  admin_id    INT NULL,                         -- the admin who set the shift
  work_date   DATE NOT NULL,
  start_time  TIME NOT NULL,
  end_time    TIME NOT NULL,
  FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
  FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- The heart of the system: an appointment and what hangs off it
-- status: requested | confirmed | in_progress | completed | cancelled | rejected
-- ---------------------------------------------------------------------
CREATE TABLE appointments (
  appointment_id INT AUTO_INCREMENT PRIMARY KEY,
  client_id      INT NOT NULL,
  staff_id       INT NOT NULL,
  service_id     INT NOT NULL,
  start_at       DATETIME NOT NULL,
  end_at         DATETIME NOT NULL,
  status         VARCHAR(15) NOT NULL DEFAULT 'requested',
  reason         VARCHAR(255) NOT NULL DEFAULT '',   -- why it was cancelled / rejected
  created_at     DATETIME NOT NULL,
  FOREIGN KEY (client_id)  REFERENCES clients(client_id),
  FOREIGN KEY (staff_id)   REFERENCES staff(staff_id),
  FOREIGN KEY (service_id) REFERENCES services(service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payments (
  payment_id     INT AUTO_INCREMENT PRIMARY KEY,
  appointment_id INT NOT NULL UNIQUE,           -- one payment per appointment
  amount         DECIMAL(10,2) NOT NULL,
  method         VARCHAR(20) NULL,              -- cash | card | mobile_money
  status         VARCHAR(10) NOT NULL DEFAULT 'pending',   -- pending | paid
  promo_percent  INT NOT NULL DEFAULT 0,
  paid_at        DATETIME NULL,
  FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
  notification_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,
  appointment_id  INT NULL,
  message         VARCHAR(255) NOT NULL,
  is_read         TINYINT NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE reviews (
  review_id      INT AUTO_INCREMENT PRIMARY KEY,
  appointment_id INT NOT NULL UNIQUE,           -- one review per appointment
  rating         INT NOT NULL,                  -- 1 to 5
  review_text    VARCHAR(500) NOT NULL DEFAULT '',
  created_at     DATETIME NOT NULL,
  FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Speeds up the availability check ("is this stylist free then?")
CREATE INDEX idx_appt_staff_time ON appointments (staff_id, start_at);
CREATE INDEX idx_notif_user ON notifications (user_id, is_read);

-- =====================================================================
--  DEMO DATA   (every demo account uses the password:  Salon@123)
-- =====================================================================
INSERT INTO users (user_id, name, email, phone, password_hash, role, created_at) VALUES
 (1, 'Diane Mukeshimana', 'admin@salon.rw', '0788000001', '$2y$10$pi9UUH5/xX8iAWqgifzpnek/lzwsX7PW09O2xJ91RB4b/Gq83gZDW', 'admin',  '2026-01-01 08:00:00'),
 (2, 'Grace Uwera',       'grace@salon.rw', '0788000002', '$2y$10$pi9UUH5/xX8iAWqgifzpnek/lzwsX7PW09O2xJ91RB4b/Gq83gZDW', 'staff',  '2026-01-01 08:00:00'),
 (3, 'Olive Ingabire',    'olive@salon.rw', '0788000003', '$2y$10$pi9UUH5/xX8iAWqgifzpnek/lzwsX7PW09O2xJ91RB4b/Gq83gZDW', 'staff',  '2026-01-01 08:00:00'),
 (4, 'Peter Niyonzima',   'peter@salon.rw', '0788000004', '$2y$10$pi9UUH5/xX8iAWqgifzpnek/lzwsX7PW09O2xJ91RB4b/Gq83gZDW', 'staff',  '2026-01-01 08:00:00'),
 (5, 'Claire Mukamana',   'claire@mail.rw', '0788100001', '$2y$10$pi9UUH5/xX8iAWqgifzpnek/lzwsX7PW09O2xJ91RB4b/Gq83gZDW', 'client', '2026-01-01 08:00:00'),
 (6, 'David Habimana',    'david@mail.rw',  '0788100002', '$2y$10$pi9UUH5/xX8iAWqgifzpnek/lzwsX7PW09O2xJ91RB4b/Gq83gZDW', 'client', '2026-01-01 08:00:00');

INSERT INTO admins  (admin_id, user_id) VALUES (1, 1);
INSERT INTO staff   (staff_id, user_id, specialization) VALUES (1, 2, 'Hair'), (2, 3, 'Hair'), (3, 4, 'Nails');
INSERT INTO clients (client_id, user_id, loyalty_points) VALUES (1, 5, 0), (2, 6, 0);

INSERT INTO services (service_id, name, category, price, duration_minutes, is_active) VALUES
 (1, 'Haircut',      'Hair',   8000, 45, 1),
 (2, 'Hair Colour',  'Hair',  25000, 90, 1),
 (3, 'Blow Dry',     'Hair',   5000, 30, 1),
 (4, 'Manicure',     'Nails',  6000, 40, 1),
 (5, 'Pedicure',     'Nails',  8000, 50, 1);

-- Work shifts are NOT inserted here, because dates go out of date.
-- After importing: log in as admin@salon.rw -> Schedules -> "Create default shifts".
