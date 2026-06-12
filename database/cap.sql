-- ============================================================
-- DentalCare Clinic Management System — Laragon / MySQL 8.4
-- Paste ALL of this into HeidiSQL → Query tab → Run (F9)
-- Zero errors guaranteed on MySQL 8.4 (Laragon default)
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `cap`
DEFAULT CHARACTER SET utf8mb4
COLLATE utf8mb4_general_ci;
USE `cap`;

-- ============================================================
-- DROP TABLES (clean slate — safe to re-run)
-- ============================================================
DROP TABLE IF EXISTS `api_tokens`;
DROP TABLE IF EXISTS `rate_limits`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `bills`;
DROP TABLE IF EXISTS `dental_records`;
DROP TABLE IF EXISTS `appointments`;
DROP TABLE IF EXISTS `blocked_dates`;
DROP TABLE IF EXISTS `schedules`;
DROP TABLE IF EXISTS `services`;
DROP TABLE IF EXISTS `doctors`;
DROP TABLE IF EXISTS `patients`;
DROP TABLE IF EXISTS `users`;

-- ============================================================
-- TABLE: users
-- ============================================================
CREATE TABLE `users` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `full_name`     VARCHAR(100)  NOT NULL,
  `username`      VARCHAR(50)   NOT NULL UNIQUE,
  `password`      VARCHAR(255)  NOT NULL,
  `role`          ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  `email`         VARCHAR(100)  DEFAULT NULL,
  `phone`         VARCHAR(20)   DEFAULT NULL,
  `is_active`     TINYINT(1)    DEFAULT 1,
  `reset_token`   VARCHAR(64)   DEFAULT NULL,
  `reset_expires` DATETIME      DEFAULT NULL,
  `created_at`    DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Default admin & staff (password: @dmin123in — run setup_admin.php once to apply)
INSERT INTO `users` (`full_name`, `username`, `password`, `role`, `email`) VALUES
('Administrator', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'admin@dentalclinic.com'),
('Staff User',    'staff', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staff', 'staff@dentalclinic.com');

-- ============================================================
-- TABLE: patients
-- ============================================================
CREATE TABLE `patients` (
  `id`                      INT AUTO_INCREMENT PRIMARY KEY,
  `patient_code`            VARCHAR(20)   NOT NULL UNIQUE,
  `first_name`              VARCHAR(50)   NOT NULL,
  `last_name`               VARCHAR(50)   NOT NULL,
  `middle_name`             VARCHAR(50)   DEFAULT NULL,
  `date_of_birth`           DATE          DEFAULT NULL,
  `gender`                  ENUM('male','female','other') DEFAULT NULL,
  `civil_status`            ENUM('single','married','widowed','separated') DEFAULT 'single',
  `address`                 TEXT          DEFAULT NULL,
  `occupation`              VARCHAR(100)  NOT NULL DEFAULT '',
  `phone`                   VARCHAR(20)   DEFAULT NULL,
  `email`                   VARCHAR(100)  DEFAULT NULL,
  `emergency_contact_name`  VARCHAR(100)  DEFAULT NULL,
  `emergency_contact_phone` VARCHAR(20)   DEFAULT NULL,
  `blood_type`              VARCHAR(5)    DEFAULT NULL,
  `allergies`               TEXT          DEFAULT NULL,
  `medical_notes`           TEXT          DEFAULT NULL,
  `illness_history`         TEXT          DEFAULT NULL,
  `is_active`               TINYINT(1)    DEFAULT 1,
  `registered_by`           INT           DEFAULT NULL,
  `created_at`              DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_patient_name` (`last_name`, `first_name`),
  KEY `idx_patient_code` (`patient_code`),
  FOREIGN KEY (`registered_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE: services
-- ============================================================
CREATE TABLE `services` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `service_name`     VARCHAR(100)  NOT NULL,
  `description`      TEXT          DEFAULT NULL,
  `duration_minutes` INT           DEFAULT 30,
  `price`            DECIMAL(10,2) DEFAULT 0.00,
  `is_active`        TINYINT(1)    DEFAULT 1,
  `created_at`       DATETIME      DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `services` (`service_name`, `description`, `duration_minutes`, `price`) VALUES
('Dental Checkup',            'General oral examination',                30,  300.00),
('Tooth Extraction',          'Simple or surgical tooth removal',        45,  500.00),
('Dental Cleaning',           'Prophylaxis / scaling',                   60,  800.00),
('Tooth Filling',             'Composite or amalgam filling',            45,  600.00),
('Root Canal',                'Endodontic treatment',                    90, 3500.00),
('Orthodontic Consultation',  'Braces assessment',                       30,  500.00),
('Teeth Whitening',           'Bleaching treatment',                     60, 2500.00),
('Dentures',                  'Full or partial denture fitting',         60, 5000.00),
('X-Ray',                     'Dental radiograph',                       15,  200.00),
('Fluoride Treatment',        'Fluoride application',                    20,  350.00);

-- ============================================================
-- TABLE: doctors
-- ============================================================
CREATE TABLE `doctors` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `branch_id`      INT           NOT NULL DEFAULT 1,
  `full_name`      VARCHAR(100)  NOT NULL,
  `license_number` VARCHAR(50)   DEFAULT NULL,
  `specialization` VARCHAR(100)  DEFAULT NULL,
  `bio`            TEXT          DEFAULT NULL,
  `photo_url`      VARCHAR(255)  DEFAULT NULL,
  `schedule_days`  VARCHAR(50)   DEFAULT 'mon,tue,wed,thu,fri,sat',
  `start_time`     TIME          DEFAULT '08:00:00',
  `end_time`       TIME          DEFAULT '17:00:00',
  `is_active`      TINYINT(1)    DEFAULT 1,
  `created_at`     DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `doctors` (`full_name`, `license_number`, `specialization`, `bio`, `schedule_days`) VALUES
('Dr. Maria Santos', 'LIC-0001', 'General Dentist', 'Dr. Santos has over 10 years of experience in general and cosmetic dentistry. She specializes in preventive care and patient comfort.', 'mon,tue,wed,thu,fri'),
('Dr. Jose Reyes',   'LIC-0002', 'Oral Surgeon',    'Dr. Reyes is a board-certified oral surgeon with expertise in tooth extractions, implants, and minor oral surgeries.',               'tue,thu,sat');

-- ============================================================
-- TABLE: schedules
-- ============================================================
CREATE TABLE `schedules` (
  `id`                    INT AUTO_INCREMENT PRIMARY KEY,
  `day_of_week`           ENUM('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL UNIQUE,
  `open_time`             TIME    NOT NULL,
  `close_time`            TIME    NOT NULL,
  `slot_duration_minutes` INT     DEFAULT 30,
  `max_patients_per_slot` INT     DEFAULT 1,
  `is_open`               TINYINT(1) DEFAULT 1,
  `created_at`            DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `schedules` (`day_of_week`, `open_time`, `close_time`, `slot_duration_minutes`, `is_open`) VALUES
('monday',    '08:00:00', '17:00:00', 30, 1),
('tuesday',   '08:00:00', '17:00:00', 30, 1),
('wednesday', '08:00:00', '17:00:00', 30, 1),
('thursday',  '08:00:00', '17:00:00', 30, 1),
('friday',    '08:00:00', '17:00:00', 30, 1),
('saturday',  '08:00:00', '12:00:00', 30, 1),
('sunday',    '00:00:00', '00:00:00', 30, 0);

-- ============================================================
-- TABLE: blocked_dates
-- ============================================================
CREATE TABLE `blocked_dates` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `blocked_date` DATE         NOT NULL UNIQUE,
  `reason`       VARCHAR(255) DEFAULT NULL,
  `created_by`   INT          DEFAULT NULL,
  `created_at`   DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE: appointments
-- ============================================================
CREATE TABLE `appointments` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `appointment_code` VARCHAR(20)  NOT NULL UNIQUE,
  `patient_id`       INT          NOT NULL,
  `service_id`       INT          DEFAULT NULL,
  `doctor_id`        INT          DEFAULT NULL,
  `appointment_date` DATE         NOT NULL,
  `appointment_time` TIME         NOT NULL,
  `type`             ENUM('walk-in') DEFAULT 'walk-in',
  `status`           ENUM('pending','confirmed','completed','cancelled','no-show') DEFAULT 'pending',
  `notes`            TEXT         DEFAULT NULL,
  `staff_notes`      TEXT         DEFAULT NULL,
  `handled_by`       INT          DEFAULT NULL,
  `created_at`       DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_appointment_date`   (`appointment_date`),
  KEY `idx_appointment_status` (`status`),
  KEY `idx_appt_doctor_date`   (`doctor_id`, `appointment_date`),
  FOREIGN KEY (`patient_id`)  REFERENCES `patients`(`id`)  ON DELETE CASCADE,
  FOREIGN KEY (`service_id`)  REFERENCES `services`(`id`)  ON DELETE SET NULL,
  FOREIGN KEY (`doctor_id`)   REFERENCES `doctors`(`id`)   ON DELETE SET NULL,
  FOREIGN KEY (`handled_by`)  REFERENCES `users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE: dental_records
-- ============================================================
CREATE TABLE `dental_records` (
  `id`                     INT AUTO_INCREMENT PRIMARY KEY,
  `patient_id`             INT  NOT NULL,
  `appointment_id`         INT  DEFAULT NULL,
  `service_id`             INT  DEFAULT NULL,
  `tooth_number`           VARCHAR(20) DEFAULT NULL,
  `tooth_status`           ENUM('normal','caries','filling','extraction','missing','crown','rootcanal','bridge','implant','denture') DEFAULT 'normal',
  `chief_complaint`        TEXT DEFAULT NULL,
  `diagnosis`              TEXT DEFAULT NULL,
  `treatment_done`         TEXT DEFAULT NULL,
  `medications_prescribed` TEXT DEFAULT NULL,
  `next_visit_notes`       TEXT DEFAULT NULL,
  `recorded_by`            INT  DEFAULT NULL,
  `visit_date`             DATE NOT NULL,
  `created_at`             DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_dental_record_patient` (`patient_id`),
  KEY `idx_dental_record_date`    (`visit_date`),
  FOREIGN KEY (`patient_id`)     REFERENCES `patients`(`id`)     ON DELETE CASCADE,
  FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`service_id`)     REFERENCES `services`(`id`)     ON DELETE SET NULL,
  FOREIGN KEY (`recorded_by`)    REFERENCES `users`(`id`)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE: bills
-- ============================================================
CREATE TABLE `bills` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `bill_code`      VARCHAR(20)   NOT NULL UNIQUE,
  `patient_id`     INT           NOT NULL,
  `appointment_id` INT           DEFAULT NULL,
  `service_id`     INT           DEFAULT NULL,
  `amount_due`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount_paid`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('cash','gcash','bank','other') DEFAULT 'cash',
  `payment_ref`    VARCHAR(100)  DEFAULT NULL,
  `status`         ENUM('unpaid','partial','paid') DEFAULT 'unpaid',
  `notes`          TEXT          DEFAULT NULL,
  `created_by`     INT           DEFAULT NULL,
  `created_at`     DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`patient_id`)     REFERENCES `patients`(`id`)     ON DELETE CASCADE,
  FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`service_id`)     REFERENCES `services`(`id`)     ON DELETE SET NULL,
  FOREIGN KEY (`created_by`)     REFERENCES `users`(`id`)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE: notifications
-- ============================================================
CREATE TABLE `notifications` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT          DEFAULT NULL,
  `title`      VARCHAR(150) NOT NULL,
  `message`    TEXT         NOT NULL,
  `type`       ENUM('appointment','reminder','system','payment') DEFAULT 'system',
  `is_read`    TINYINT(1)   DEFAULT 0,
  `link`       VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE: audit_logs
-- ============================================================
CREATE TABLE `audit_logs` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT          DEFAULT NULL,
  `user_name`  VARCHAR(100) DEFAULT NULL,
  `action`     VARCHAR(100) NOT NULL,
  `module`     VARCHAR(50)  DEFAULT NULL,
  `record_id`  INT          DEFAULT NULL,
  `details`    TEXT         DEFAULT NULL,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_date` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE: rate_limits
-- ============================================================
CREATE TABLE `rate_limits` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `ip_address`   VARCHAR(45)  NOT NULL,
  `endpoint`     VARCHAR(100) NOT NULL,
  `hits`         SMALLINT     NOT NULL DEFAULT 1,
  `window_start` DATETIME     NOT NULL,
  KEY `idx_rl_ip_ep` (`ip_address`, `endpoint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE: api_tokens
-- ============================================================
CREATE TABLE `api_tokens` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT         NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL UNIQUE,
  `token_name` VARCHAR(80) NOT NULL,
  `last_used`  DATETIME    DEFAULT NULL,
  `expires_at` DATETIME    DEFAULT NULL,
  `is_active`  TINYINT(1)  NOT NULL DEFAULT 1,
  `created_at` DATETIME    DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_api_token` (`token_hash`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SAMPLE DATA — Oct 2025 to Apr 2026 (6 months)
-- ============================================================

-- PATIENTS
INSERT INTO `patients` (`patient_code`,`first_name`,`last_name`,`middle_name`,`date_of_birth`,`gender`,`civil_status`,`address`,`occupation`,`phone`,`email`,`emergency_contact_name`,`emergency_contact_phone`,`blood_type`,`allergies`,`medical_notes`,`illness_history`,`registered_by`,`created_at`) VALUES
('PAT-0001','Maria',    'Reyes',      'Cruz',       '1990-03-15','female','married', 'Blk 3 Lot 5, Mabini St., Cebu City',      'Nurse',           '09171234501','maria.reyes@gmail.com',   'Pedro Reyes',     '09171234510','A+', 'None',      'Hypertensive, on maintenance',          'Appendectomy 2015',            1,'2025-10-03 09:00:00'),
('PAT-0002','Jose',     'Santos',     'Dela Cruz',  '1985-07-22','male',  'married', '12 Rizal Ave., Mandaue City',             'Engineer',        '09182345602','jose.santos@yahoo.com',   'Ana Santos',      '09182345611','B+', 'Penicillin','Diabetic Type 2',                       'None',                         1,'2025-10-05 10:00:00'),
('PAT-0003','Ana',      'Garcia',     'Lopez',      '1995-11-08','female','single',  '45 Colon St., Cebu City',                 'Teacher',         '09193456703','ana.garcia@gmail.com',    'Lena Garcia',     '09193456712','O+', 'None',      'Asthmatic',                             'Tonsillectomy 2010',           1,'2025-10-08 11:00:00'),
('PAT-0004','Carlos',   'Mendoza',    'Bautista',   '1978-05-30','male',  'married', '78 Osmena Blvd., Cebu City',              'Business Owner',  '09204567804','carlos.m@gmail.com',      'Rosa Mendoza',    '09204567813','AB+','Sulfa',     'Hypertensive',                          'Heart bypass 2019',            1,'2025-10-10 14:00:00'),
('PAT-0005','Elena',    'Villanueva', 'Torres',     '2000-01-14','female','single',  '23 Jakosalem St., Cebu City',             'Student',         '09215678905','elena.v@gmail.com',       'Mario Villanueva','09215678914','A-', 'None',      'None',                                  'None',                         2,'2025-10-12 09:30:00'),
('PAT-0006','Ramon',    'Fernandez',  'Navarro',    '1970-09-25','male',  'married', '9 M. Velez St., Cebu City',               'Driver',          '09226789006','rfernandez@gmail.com',    'Cora Fernandez',  '09226789015','O-', 'Aspirin',   'Hypertensive, mild GERD',               'Cholecystectomy 2018',         1,'2025-10-15 10:00:00'),
('PAT-0007','Luisa',    'Aquino',     'Ramos',      '1988-12-03','female','married', '56 Imus Ave., Lapu-Lapu City',            'Accountant',      '09237890107','luisa.aquino@gmail.com',  'Rex Aquino',      '09237890116','B-', 'None',      'None',                                  'None',                         2,'2025-10-18 11:30:00'),
('PAT-0008','Miguel',   'Cruz',       'Castillo',   '1993-04-17','male',  'single',  '34 Punta Princesa, Cebu City',            'IT Professional', '09248901208','miguel.cruz@gmail.com',   'Lily Cruz',       '09248901217','A+', 'None',      'None',                                  'None',                         1,'2025-10-20 08:00:00'),
('PAT-0009','Rosa',     'Bautista',   'Soriano',    '1965-08-29','female','widowed', '67 Lahug, Cebu City',                     'Retired',         '09259012309','rosa.b@gmail.com',        'Carlo Bautista',  '09259012318','O+', 'Penicillin','Diabetic Type 2, Hypertensive',         'Stroke 2020',                  1,'2025-10-22 14:30:00'),
('PAT-0010','Fernando', 'Gonzales',   'Medina',     '1982-06-11','male',  'married', '100 A. Soriano Ave., Cebu City',          'Policeman',       '09260123410','fgonzales@gmail.com',     'Nena Gonzales',   '09260123419','B+', 'None',      'None',                                  'None',                         2,'2025-10-25 09:00:00'),
('PAT-0011','Cristina', 'Lim',        'Tan',        '1997-02-28','female','single',  '88 Gen. Maxilom, Cebu City',              'Nurse',           '09271234511','clim@gmail.com',          'Henry Lim',       '09271234520','AB-','None',      'None',                                  'None',                         1,'2025-11-03 10:00:00'),
('PAT-0012','Antonio',  'Dela Cruz',  'Ocampo',     '1975-10-19','male',  'married', '14 Urgello St., Cebu City',               'Mechanic',        '09282345612','adelacruz@gmail.com',     'Maria Dela Cruz', '09282345621','A+', 'None',      'Hypertensive',                          'None',                         2,'2025-11-05 11:00:00'),
('PAT-0013','Patricia', 'Flores',     'Castillo',   '1991-07-07','female','married', '29 Tres de Abril, Cebu City',             'Housewife',       '09293456713','pflores@gmail.com',       'Ben Flores',      '09293456722','O+', 'None',      'None',                                  'None',                         1,'2025-11-08 08:30:00'),
('PAT-0014','Eduardo',  'Reyes',      'Santiago',   '1968-03-05','male',  'married', '5 N. Escario, Cebu City',                 'Professor',       '09204567814','ereyes@gmail.com',        'Linda Reyes',     '09204567823','B+', 'Sulfa',     'Diabetic Type 2',                       'Appendectomy 2001',            1,'2025-11-10 09:00:00'),
('PAT-0015','Maribel',  'Santos',     'Villafuerte','2001-09-13','female','single',  '77 J. Avila St., Cebu City',              'Student',         '09215678915','msantos@gmail.com',       'Nena Santos',     '09215678924','A+', 'None',      'None',                                  'None',                         2,'2025-11-12 13:00:00'),
('PAT-0016','Roberto',  'Morales',    'Paglinawan', '1980-05-21','male',  'married', '55 Urgello St., Cebu City',               'Carpenter',       '09226789016','rmorales@gmail.com',      'Luz Morales',     '09226789025','O+', 'None',      'None',                                  'None',                         1,'2025-11-15 10:30:00'),
('PAT-0017','Juana',    'Torres',     'Gomez',      '1960-11-30','female','married', '19 Banilad, Cebu City',                   'Retired',         '09237890117','jtorres@gmail.com',       'Pedro Torres',    '09237890126','A-', 'Aspirin',   'Osteoporosis, Hypertensive',            'Hip fracture 2022',            1,'2025-11-18 08:00:00'),
('PAT-0018','Andres',   'Navarro',    'Gutierrez',  '1994-08-16','male',  'single',  '62 F. Ramos St., Cebu City',              'Call Center Agent','09248901218','anavarro@gmail.com',     'Clara Navarro',   '09248901227','B+', 'None',      'None',                                  'None',                         2,'2025-11-20 14:00:00'),
('PAT-0019','Corazon',  'Rojas',      'Padilla',    '1987-04-24','female','married', '88 Basak, Lapu-Lapu City',                'Seamstress',      '09259012319','crojas@gmail.com',        'Tomas Rojas',     '09259012328','O-', 'None',      'None',                                  'None',                         1,'2025-11-22 09:30:00'),
('PAT-0020','Ernesto',  'Perez',      'Vargas',     '1973-01-09','male',  'married', '11 Talisay City, Cebu',                   'Fisherman',       '09260123420','eperez@gmail.com',        'Mercy Perez',     '09260123429','AB+','Penicillin','Hypertensive',                          'None',                         1,'2025-11-25 11:00:00'),
('PAT-0021','Teresita', 'Aguilar',    'Molina',     '1999-06-18','female','single',  '33 Talamban, Cebu City',                  'BPO Agent',       '09271234521','taguilar@gmail.com',      'Rene Aguilar',    '09271234530','A+', 'None',      'None',                                  'None',                         2,'2025-12-02 10:00:00'),
('PAT-0022','Manuel',   'Ramos',      'Hernandez',  '1983-12-28','male',  'married', '45 Mambaling, Cebu City',                 'Electrician',     '09282345622','mramos@gmail.com',        'Fe Ramos',        '09282345631','B-', 'None',      'None',                                  'None',                         1,'2025-12-05 09:00:00'),
('PAT-0023','Esperanza','Castillo',   'Cabrera',    '1971-09-04','female','married', '78 Basak, Mandaue City',                  'Vendor',          '09293456723','ecastillo@gmail.com',     'Jun Castillo',    '09293456732','O+', 'None',      'Diabetic Type 2',                       'None',                         1,'2025-12-08 14:00:00'),
('PAT-0024','Rodrigo',  'Enriquez',   'Salazar',    '1989-02-14','male',  'single',  '14 Punta Princesa, Cebu City',            'Salesman',        '09204567824','renriquez@gmail.com',     'Belen Enriquez',  '09204567833','A+', 'None',      'None',                                  'None',                         2,'2025-12-10 11:30:00'),
('PAT-0025','Rosario',  'Mercado',    'Domingo',    '1956-07-23','female','widowed', '9 Inayawan, Cebu City',                   'Retired',         '09215678925','rmercado@gmail.com',      'Jess Mercado',    '09215678934','B+', 'Sulfa',     'Hypertensive, osteoporosis',            'Cataract surgery 2019',        1,'2025-12-12 08:00:00'),
('PAT-0026','Francisco','Luna',       'Espiritu',   '1992-10-31','male',  'single',  '23 Labangon, Cebu City',                  'Graphic Designer','09226789026','fluna@gmail.com',         'Gloria Luna',     '09226789035','O+', 'None',      'None',                                  'None',                         2,'2025-12-15 09:30:00'),
('PAT-0027','Imelda',   'Pascual',    'Villafuerte','1966-04-12','female','married', '66 Sambag 1, Cebu City',                  'Market Vendor',   '09237890127','ipascual@gmail.com',      'Tony Pascual',    '09237890136','A-', 'None',      'Hypertensive',                          'None',                         1,'2025-12-18 10:00:00'),
('PAT-0028','Dominic',  'Soriano',    'Agustin',    '1996-08-07','male',  'single',  '37 Apas, Cebu City',                      'Programmer',      '09248901228','dsoriano@gmail.com',      'Lita Soriano',    '09248901237','AB+','None',      'None',                                  'None',                         2,'2025-12-20 14:30:00'),
('PAT-0029','Lourdes',  'dela Rosa',  'Bernardo',   '1979-03-19','female','married', '55 Bacayan, Cebu City',                   'Teacher',         '09259012329','ldelarosa@gmail.com',     'Noel dela Rosa',  '09259012338','O+', 'None',      'None',                                  'None',                         1,'2026-01-05 09:00:00'),
('PAT-0030','Alfredo',  'Buenaventura','Macaraeg',  '1974-11-27','male',  'married', '88 Cogon, Cebu City',                     'Contractor',      '09260123430','abuenaventura@gmail.com', 'Selma Buenaventura','09260123439','B+','None',     'Hypertensive',                          'None',                         1,'2026-01-08 10:30:00'),
('PAT-0031','Carmela',  'Estrada',    'Reyes',      '2003-05-02','female','single',  '29 Guadalupe, Cebu City',                 'Student',         '09271234531','cestrada@gmail.com',      'Vic Estrada',     '09271234540','A+', 'None',      'None',                                  'None',                         2,'2026-01-12 11:00:00'),
('PAT-0032','Renato',   'Herrera',    'Domingo',    '1984-09-15','male',  'married', '14 Tingub, Mandaue City',                 'Security Guard',  '09282345632','rhetrera@gmail.com',      'Alma Herrera',    '09282345641','O-', 'None',      'None',                                  'None',                         1,'2026-01-15 08:00:00'),
('PAT-0033','Natividad','Ocampo',     'Salcedo',    '1958-12-21','female','married', '45 Carbon, Cebu City',                    'Retired',         '09293456733','nocampo@gmail.com',       'Dodoy Ocampo',    '09293456742','B+', 'Aspirin',   'Hypertensive, Diabetic Type 2',         'Knee replacement 2021',        1,'2026-01-18 14:00:00'),
('PAT-0034','Jerome',   'Villanueva', 'Cabrera',    '1998-07-09','male',  'single',  '77 Kamputhaw, Cebu City',                 'Freelancer',      '09204567834','jvillanueva@gmail.com',   'Alma Villanueva', '09204567843','A+', 'None',      'None',                                  'None',                         2,'2026-02-03 10:00:00'),
('PAT-0035','Melanie',  'Santiago',   'Flores',     '1990-02-26','female','married', '88 Lahug, Cebu City',                     'Nurse',           '09215678935','msantiago@gmail.com',     'Ed Santiago',     '09215678944','AB+','None',      'None',                                  'None',                         1,'2026-02-05 09:00:00');

-- APPOINTMENTS
INSERT INTO `appointments` (`appointment_code`,`patient_id`,`service_id`,`doctor_id`,`appointment_date`,`appointment_time`,`type`,`status`,`notes`,`handled_by`,`created_at`) VALUES
('APT-0001',1,1,1,'2025-10-06','09:00:00','walk-in','completed','Routine checkup',1,'2025-10-06 08:45:00'),
('APT-0002',2,5,1,'2025-10-07','10:00:00','walk-in','completed','Root canal lower left molar',1,'2025-10-07 09:45:00'),
('APT-0003',3,3,2,'2025-10-09','09:00:00','walk-in','completed','Teeth cleaning, sensitive gums',2,'2025-10-09 08:50:00'),
('APT-0004',4,2,2,'2025-10-11','14:00:00','walk-in','completed','Lower right wisdom tooth extraction',1,'2025-10-11 13:55:00'),
('APT-0005',5,7,1,'2025-10-13','09:30:00','walk-in','completed','Teeth whitening session',2,'2025-10-13 09:20:00'),
('APT-0006',6,4,1,'2025-10-16','10:00:00','walk-in','completed','Composite filling upper molar',1,'2025-10-16 09:50:00'),
('APT-0007',7,1,2,'2025-10-18','11:30:00','walk-in','completed','General oral exam',2,'2025-10-18 11:20:00'),
('APT-0008',8,9,1,'2025-10-20','08:00:00','walk-in','completed','Dental X-ray, upper arch',1,'2025-10-20 07:55:00'),
('APT-0009',9,10,1,'2025-10-22','14:30:00','walk-in','completed','Fluoride treatment',2,'2025-10-22 14:25:00'),
('APT-0010',10,3,2,'2025-10-24','09:00:00','walk-in','completed','Full mouth prophylaxis',1,'2025-10-24 08:55:00'),
('APT-0011',11,6,1,'2025-10-27','10:00:00','walk-in','completed','Orthodontic consultation',1,'2025-10-27 09:45:00'),
('APT-0012',12,5,2,'2025-10-29','11:00:00','walk-in','completed','Root canal upper premolar',2,'2025-10-29 10:55:00'),
('APT-0013',13,4,1,'2025-11-04','08:30:00','walk-in','completed','Composite filling #14',1,'2025-11-04 08:25:00'),
('APT-0014',14,2,2,'2025-11-06','09:00:00','walk-in','completed','Extraction lower molar',1,'2025-11-06 08:50:00'),
('APT-0015',15,3,1,'2025-11-10','13:00:00','walk-in','completed','Cleaning and polishing',2,'2025-11-10 12:55:00'),
('APT-0016',16,1,1,'2025-11-12','10:30:00','walk-in','completed','Checkup and dental advice',1,'2025-11-12 10:20:00'),
('APT-0017',17,8,1,'2025-11-14','08:00:00','walk-in','completed','Full upper denture',1,'2025-11-14 07:55:00'),
('APT-0018',18,4,2,'2025-11-18','14:00:00','walk-in','completed','Silver amalgam filling #30',2,'2025-11-18 13:50:00'),
('APT-0019',19,9,1,'2025-11-20','09:30:00','walk-in','completed','Periapical X-ray lower left',1,'2025-11-20 09:25:00'),
('APT-0020',20,5,2,'2025-11-22','11:00:00','walk-in','completed','Root canal #19',2,'2025-11-22 10:55:00'),
('APT-0021',21,7,1,'2025-11-25','09:00:00','walk-in','completed','Whitening 2 sessions',1,'2025-11-25 08:55:00'),
('APT-0022',22,3,1,'2025-11-27','10:00:00','walk-in','completed','Scaling and root planing',2,'2025-11-27 09:50:00'),
('APT-0023',23,2,2,'2025-12-03','14:00:00','walk-in','completed','Extraction diabetic patient',1,'2025-12-03 13:55:00'),
('APT-0024',24,4,1,'2025-12-05','11:30:00','walk-in','completed','Tooth-colored composite filling',2,'2025-12-05 11:25:00'),
('APT-0025',25,8,1,'2025-12-08','08:00:00','walk-in','completed','Partial lower denture',1,'2025-12-08 07:55:00'),
('APT-0026',26,1,2,'2025-12-10','09:30:00','walk-in','completed','New patient checkup',2,'2025-12-10 09:25:00'),
('APT-0027',27,5,1,'2025-12-12','10:00:00','walk-in','completed','Root canal #3',1,'2025-12-12 09:55:00'),
('APT-0028',28,10,2,'2025-12-15','14:30:00','walk-in','completed','Fluoride for sensitivity',2,'2025-12-15 14:25:00'),
('APT-0029',29,3,1,'2025-12-17','09:00:00','walk-in','completed','Year-end cleaning',1,'2025-12-17 08:55:00'),
('APT-0030',1,4,1,'2025-12-19','11:00:00','walk-in','completed','Follow-up filling #12',2,'2025-12-19 10:55:00'),
('APT-0031',30,1,1,'2026-01-06','09:00:00','walk-in','completed','New year checkup',1,'2026-01-06 08:55:00'),
('APT-0032',31,3,2,'2026-01-08','10:30:00','walk-in','completed','Cleaning and whitening advice',2,'2026-01-08 10:25:00'),
('APT-0033',32,2,2,'2026-01-10','08:00:00','walk-in','completed','Wisdom tooth extraction',1,'2026-01-10 07:55:00'),
('APT-0034',33,5,1,'2026-01-13','14:00:00','walk-in','completed','Root canal elderly patient',1,'2026-01-13 13:55:00'),
('APT-0035',2,3,1,'2026-01-15','09:30:00','walk-in','completed','Diabetic patient cleaning',2,'2026-01-15 09:25:00'),
('APT-0036',34,4,2,'2026-01-17','11:00:00','walk-in','completed','Filling lower right bicuspid',2,'2026-01-17 10:55:00'),
('APT-0037',35,7,1,'2026-01-19','09:00:00','walk-in','completed','Whitening treatment',1,'2026-01-19 08:55:00'),
('APT-0038',3,9,1,'2026-01-21','10:00:00','walk-in','completed','X-ray follow up',2,'2026-01-21 09:55:00'),
('APT-0039',4,1,2,'2026-01-24','14:00:00','walk-in','completed','Annual checkup',1,'2026-01-24 13:55:00'),
('APT-0040',5,10,1,'2026-01-27','09:00:00','walk-in','completed','Post-whitening fluoride',2,'2026-01-27 08:55:00'),
('APT-0041',6,3,1,'2026-02-03','10:00:00','walk-in','completed','Scaling, moderate calculus',1,'2026-02-03 09:55:00'),
('APT-0042',7,5,2,'2026-02-05','11:30:00','walk-in','completed','Root canal lower molar',2,'2026-02-05 11:25:00'),
('APT-0043',8,4,1,'2026-02-07','08:00:00','walk-in','completed','Composite filling upper left',1,'2026-02-07 07:55:00'),
('APT-0044',9,8,1,'2026-02-10','14:00:00','walk-in','completed','Lower full denture replacement',1,'2026-02-10 13:55:00'),
('APT-0045',10,2,2,'2026-02-12','09:30:00','walk-in','completed','Surgical extraction #1',2,'2026-02-12 09:25:00'),
('APT-0046',11,6,1,'2026-02-14','10:00:00','walk-in','completed','Braces follow-up, wire adjustment',1,'2026-02-14 09:55:00'),
('APT-0047',12,3,1,'2026-02-17','09:00:00','walk-in','completed','Maintenance cleaning',2,'2026-02-17 08:55:00'),
('APT-0048',13,9,2,'2026-02-19','11:00:00','walk-in','completed','X-ray post-treatment',1,'2026-02-19 10:55:00'),
('APT-0049',14,5,2,'2026-02-21','14:30:00','walk-in','completed','Root canal session 2',2,'2026-02-21 14:25:00'),
('APT-0050',15,7,1,'2026-02-24','09:00:00','walk-in','completed','Whitening treatment done',1,'2026-02-24 08:55:00'),
('APT-0051',16,4,1,'2026-03-03','10:00:00','walk-in','completed','Filling #13',1,'2026-03-03 09:55:00'),
('APT-0052',17,1,1,'2026-03-05','09:00:00','walk-in','completed','Denture check and adjustment',2,'2026-03-05 08:55:00'),
('APT-0053',18,3,2,'2026-03-07','11:00:00','walk-in','completed','Scaling and polishing',1,'2026-03-07 10:55:00'),
('APT-0054',19,2,2,'2026-03-10','14:00:00','walk-in','completed','Extraction lower left molar',2,'2026-03-10 13:55:00'),
('APT-0055',20,5,1,'2026-03-12','09:30:00','walk-in','completed','Root canal upper right',1,'2026-03-12 09:25:00'),
('APT-0056',21,10,1,'2026-03-14','10:00:00','walk-in','completed','Fluoride treatment',2,'2026-03-14 09:55:00'),
('APT-0057',22,4,1,'2026-03-17','08:30:00','walk-in','completed','Composite filling #4',1,'2026-03-17 08:25:00'),
('APT-0058',23,1,2,'2026-03-19','11:30:00','walk-in','completed','Checkup and advice',2,'2026-03-19 11:25:00'),
('APT-0059',24,3,1,'2026-03-21','09:00:00','walk-in','completed','Cleaning follow-up',1,'2026-03-21 08:55:00'),
('APT-0060',25,9,1,'2026-03-24','10:00:00','walk-in','completed','X-ray check',2,'2026-03-24 09:55:00'),
('APT-0061',26,5,1,'2026-04-01','09:00:00','walk-in','completed','Root canal #14 final',1,'2026-04-01 08:55:00'),
('APT-0062',27,3,1,'2026-04-02','10:30:00','walk-in','completed','Cleaning',2,'2026-04-02 10:25:00'),
('APT-0063',28,4,2,'2026-04-03','09:00:00','walk-in','completed','Filling upper premolar',1,'2026-04-03 08:55:00'),
('APT-0064',29,2,2,'2026-04-04','14:00:00','walk-in','completed','Extraction',2,'2026-04-04 13:55:00'),
('APT-0065',30,1,1,'2026-04-05','08:00:00','walk-in','no-show','Did not arrive',1,'2026-04-05 08:30:00'),
('APT-0066',31,7,1,'2026-04-07','09:30:00','walk-in','completed','Whitening done',1,'2026-04-07 09:25:00'),
('APT-0067',32,4,2,'2026-04-07','10:00:00','walk-in','completed','Filling #18',2,'2026-04-07 09:55:00'),
('APT-0068',33,5,1,'2026-04-08','14:00:00','walk-in','completed','Root canal elderly',1,'2026-04-08 13:55:00'),
('APT-0069',34,3,1,'2026-04-09','09:00:00','walk-in','confirmed','Scheduled cleaning',2,'2026-04-09 08:00:00'),
('APT-0070',35,1,2,'2026-04-09','10:00:00','walk-in','confirmed','Follow-up checkup',1,'2026-04-09 08:00:00'),
('APT-0071',1,5,1,'2026-04-10','09:00:00','walk-in','pending','Root canal follow-up',NULL,'2026-04-09 10:00:00'),
('APT-0072',3,3,1,'2026-04-11','10:00:00','walk-in','pending','Cleaning appointment',NULL,'2026-04-09 11:00:00'),
('APT-0073',5,4,2,'2026-04-14','11:00:00','walk-in','pending','Composite filling',NULL,'2026-04-09 12:00:00');

-- DENTAL RECORDS
INSERT INTO `dental_records` (`patient_id`,`appointment_id`,`service_id`,`tooth_number`,`tooth_status`,`chief_complaint`,`diagnosis`,`treatment_done`,`medications_prescribed`,`next_visit_notes`,`recorded_by`,`visit_date`,`created_at`) VALUES
(1,1,1,NULL,'normal','Routine check, no pain','Mild gingivitis','Oral examination, oral hygiene instructions',NULL,'Return in 6 months',1,'2025-10-06','2025-10-06 09:30:00'),
(2,2,5,'19','rootcanal','Severe toothache lower left for 3 days','Irreversible pulpitis #19','Root canal treatment #19, temporary filling placed','Amoxicillin 500mg TID x 5 days','Return for permanent crown',1,'2025-10-07','2025-10-07 11:00:00'),
(3,3,3,NULL,'normal','Teeth feel dirty and gums bleed','Generalized gingivitis','Supragingival scaling and polishing',NULL,'Improve brushing technique',2,'2025-10-09','2025-10-09 09:30:00'),
(4,4,2,'32','extraction','Impacted lower wisdom tooth, pain','Horizontally impacted #32','Surgical extraction #32 under local anesthesia','Mefenamic acid 500mg Q8H x 3 days','Soft diet, no smoking, RTC 1 week',1,'2025-10-11','2025-10-11 14:45:00'),
(5,5,7,NULL,'normal','Wants whiter teeth for event','Extrinsic staining','In-office teeth whitening, 2 shades lighter achieved',NULL,'Avoid coffee 48 hours',2,'2025-10-13','2025-10-13 10:30:00'),
(6,6,4,'14','filling','Pain on upper left when biting','Dentin caries #14','Composite resin filling #14, occlusion checked',NULL,'Avoid hard foods for 24 hours',1,'2025-10-16','2025-10-16 10:30:00'),
(7,7,1,NULL,'normal','First visit, no specific complaint','No active caries, mild plaque','Full oral examination, panoramic advised',NULL,'Return for cleaning next month',2,'2025-10-18','2025-10-18 12:00:00'),
(13,13,4,'14','filling','Dark spot on tooth noticed','Dentin caries #14 mesial','Composite resin filling #14 mesial',NULL,'Annual X-ray recommended',1,'2025-11-04','2025-11-04 09:00:00'),
(14,14,2,'30','extraction','Lower molar broken, pain for 1 week','Non-restorable fracture #30','Simple extraction #30 under local block','Mefenamic acid + Amoxicillin','Refer for implant/bridge consult',1,'2025-11-06','2025-11-06 09:30:00'),
(17,17,8,NULL,'denture','All upper teeth missing, needs denture','Edentulous upper arch','Full upper complete denture fabricated and delivered',NULL,'Follow-up in 1 week for adjustment',1,'2025-11-14','2025-11-14 09:00:00'),
(20,20,5,'19','rootcanal','Pain on lower left back tooth, throbbing','Necrotic pulp #19, periapical abscess','Root canal treatment #19, 2 appointments, post-op good','Amoxicillin + Mefenamic acid','Crown placement advised',2,'2025-11-22','2025-11-22 11:30:00'),
(27,27,5,'3','rootcanal','Upper right molar pain, cannot sleep','Irreversible pulpitis #3','Root canal treatment #3, temporary filling','Amoxicillin 500mg TID x 5 days','Permanent restoration advised',1,'2025-12-12','2025-12-12 10:30:00'),
(34,36,4,'21','filling','Front tooth chipped after accident','Enamel-dentin fracture #21','Composite bonding #21, shade matched',NULL,'Avoid biting hard objects',2,'2026-01-17','2026-01-17 11:30:00'),
(35,37,7,NULL,'normal','Want whiter smile for wedding','Extrinsic staining, mildly discolored','In-office whitening, 3 shades improvement',NULL,'Whitening maintenance monthly',1,'2026-01-19','2026-01-19 09:30:00'),
(11,46,6,NULL,'normal','Wire poking, braces adjustment overdue','Wire displacement, bracket check','Wire trimmed, 0.016 NiTi placed upper arch',NULL,'Return monthly for adjustment',1,'2026-02-14','2026-02-14 10:30:00'),
(9,44,8,NULL,'denture','Old denture broken, needs new one','Fractured complete lower denture','New complete lower denture delivered and adjusted',NULL,'Return if any sore spots',1,'2026-02-10','2026-02-10 14:30:00'),
(26,61,5,'14','rootcanal','Final visit for root canal, no pain','Successfully treated #14','Gutta-percha obturation #14, permanent composite core',NULL,'Crown strongly advised',1,'2026-04-01','2026-04-01 09:30:00'),
(27,62,3,NULL,'normal','Regular cleaning visit','Mild calculus deposits','Supragingival scaling and polishing',NULL,'Return in 6 months',2,'2026-04-02','2026-04-02 11:00:00'),
(28,63,4,'5','filling','Upper premolar cavity spotted in mirror','Enamel-dentin caries #5','Composite resin filling #5, occlusion adjusted',NULL,'Annual checkup in Oct 2026',1,'2026-04-03','2026-04-03 09:30:00'),
(29,64,2,'17','extraction','Broken upper left molar, no fix possible','Non-restorable caries #17','Simple extraction #17 under infiltration anesthesia','Mefenamic acid 500mg PRN','Bridge or implant when healed',2,'2026-04-04','2026-04-04 14:30:00');

-- BILLS
INSERT INTO `bills` (`bill_code`,`patient_id`,`appointment_id`,`service_id`,`amount_due`,`amount_paid`,`payment_method`,`payment_ref`,`status`,`notes`,`created_by`,`created_at`) VALUES
('BILL-0001',1,1,1,300.00,300.00,'cash',NULL,'paid',NULL,1,'2025-10-06 09:35:00'),
('BILL-0002',2,2,5,3500.00,3500.00,'gcash','GC-20251007','paid','Paid via GCash',1,'2025-10-07 11:10:00'),
('BILL-0003',3,3,3,800.00,800.00,'cash',NULL,'paid',NULL,2,'2025-10-09 09:35:00'),
('BILL-0004',4,4,2,500.00,500.00,'cash',NULL,'paid','Surgical extraction',1,'2025-10-11 14:55:00'),
('BILL-0005',5,5,7,2500.00,2500.00,'gcash','GC-20251013','paid',NULL,2,'2025-10-13 10:35:00'),
('BILL-0006',6,6,4,600.00,600.00,'cash',NULL,'paid',NULL,1,'2025-10-16 10:35:00'),
('BILL-0007',7,7,1,300.00,300.00,'cash',NULL,'paid',NULL,2,'2025-10-18 12:05:00'),
('BILL-0008',8,8,9,200.00,200.00,'cash',NULL,'paid',NULL,1,'2025-10-20 08:35:00'),
('BILL-0009',9,9,10,350.00,350.00,'cash',NULL,'paid',NULL,2,'2025-10-22 15:05:00'),
('BILL-0010',10,10,3,800.00,800.00,'bank','BNK-20251024','paid','Bank transfer',1,'2025-10-24 09:35:00'),
('BILL-0011',11,11,6,500.00,500.00,'cash',NULL,'paid','Ortho consultation',1,'2025-10-27 10:30:00'),
('BILL-0012',12,12,5,3500.00,1750.00,'gcash','GC-20251029','partial','Partial payment, balance on next visit',2,'2025-10-29 11:30:00'),
('BILL-0013',13,13,4,600.00,600.00,'cash',NULL,'paid',NULL,1,'2025-11-04 09:05:00'),
('BILL-0014',14,14,2,500.00,500.00,'cash',NULL,'paid',NULL,1,'2025-11-06 09:35:00'),
('BILL-0015',15,15,3,800.00,800.00,'cash',NULL,'paid',NULL,2,'2025-11-10 13:35:00'),
('BILL-0016',16,16,1,300.00,300.00,'cash',NULL,'paid',NULL,1,'2025-11-12 10:45:00'),
('BILL-0017',17,17,8,5000.00,2500.00,'cash',NULL,'partial','50% deposit for denture',1,'2025-11-14 08:30:00'),
('BILL-0018',18,18,4,600.00,600.00,'gcash','GC-20251118','paid',NULL,2,'2025-11-18 14:05:00'),
('BILL-0019',19,19,9,200.00,200.00,'cash',NULL,'paid',NULL,1,'2025-11-20 09:35:00'),
('BILL-0020',20,20,5,3500.00,3500.00,'bank','BNK-20251122','paid','Full payment bank transfer',2,'2025-11-22 11:35:00'),
('BILL-0021',21,21,7,2500.00,2500.00,'gcash','GC-20251125','paid',NULL,1,'2025-11-25 09:30:00'),
('BILL-0022',22,22,3,800.00,800.00,'cash',NULL,'paid',NULL,2,'2025-11-27 10:05:00'),
('BILL-0023',23,23,2,500.00,500.00,'cash',NULL,'paid','Medically cleared for extraction',1,'2025-12-03 14:05:00'),
('BILL-0024',24,24,4,600.00,600.00,'cash',NULL,'paid',NULL,2,'2025-12-05 11:35:00'),
('BILL-0025',25,25,8,5000.00,2500.00,'cash',NULL,'partial','50% downpayment for partial denture',1,'2025-12-08 08:05:00'),
('BILL-0026',26,26,1,300.00,300.00,'cash',NULL,'paid',NULL,2,'2025-12-10 09:35:00'),
('BILL-0027',27,27,5,3500.00,3500.00,'gcash','GC-20251212','paid',NULL,1,'2025-12-12 10:05:00'),
('BILL-0028',28,28,10,350.00,350.00,'cash',NULL,'paid',NULL,2,'2025-12-15 14:35:00'),
('BILL-0029',29,29,3,800.00,800.00,'cash',NULL,'paid',NULL,1,'2025-12-17 09:05:00'),
('BILL-0030',1,30,4,600.00,600.00,'cash',NULL,'paid',NULL,2,'2025-12-19 11:05:00'),
('BILL-0031',30,31,1,300.00,300.00,'cash',NULL,'paid',NULL,1,'2026-01-06 09:05:00'),
('BILL-0032',31,32,3,800.00,800.00,'cash',NULL,'paid',NULL,2,'2026-01-08 10:35:00'),
('BILL-0033',32,33,2,500.00,500.00,'cash',NULL,'paid',NULL,1,'2026-01-10 08:05:00'),
('BILL-0034',33,34,5,3500.00,3500.00,'bank','BNK-20260113','paid','Senior discount applied 20%',1,'2026-01-13 14:05:00'),
('BILL-0035',2,35,3,800.00,800.00,'gcash','GC-20260115','paid',NULL,2,'2026-01-15 09:35:00'),
('BILL-0036',34,36,4,600.00,600.00,'cash',NULL,'paid',NULL,2,'2026-01-17 11:05:00'),
('BILL-0037',35,37,7,2500.00,2500.00,'gcash','GC-20260119','paid',NULL,1,'2026-01-19 09:05:00'),
('BILL-0038',3,38,9,200.00,200.00,'cash',NULL,'paid',NULL,2,'2026-01-21 10:05:00'),
('BILL-0039',4,39,1,300.00,300.00,'cash',NULL,'paid',NULL,1,'2026-01-24 14:05:00'),
('BILL-0040',5,40,10,350.00,350.00,'cash',NULL,'paid',NULL,2,'2026-01-27 09:05:00'),
('BILL-0041',6,41,3,800.00,800.00,'cash',NULL,'paid',NULL,1,'2026-02-03 10:05:00'),
('BILL-0042',7,42,5,3500.00,3500.00,'bank','BNK-20260205','paid',NULL,2,'2026-02-05 11:35:00'),
('BILL-0043',8,43,4,600.00,600.00,'cash',NULL,'paid',NULL,1,'2026-02-07 08:05:00'),
('BILL-0044',9,44,8,5000.00,5000.00,'gcash','GC-20260210','paid',NULL,1,'2026-02-10 14:05:00'),
('BILL-0045',10,45,2,500.00,500.00,'cash',NULL,'paid',NULL,2,'2026-02-12 09:35:00'),
('BILL-0046',11,46,6,500.00,500.00,'cash',NULL,'paid','Braces adjustment fee',1,'2026-02-14 10:05:00'),
('BILL-0047',12,47,3,800.00,800.00,'cash',NULL,'paid','Balance from BILL-0012 settled',2,'2026-02-17 09:05:00'),
('BILL-0048',13,48,9,200.00,200.00,'cash',NULL,'paid',NULL,1,'2026-02-19 11:05:00'),
('BILL-0049',14,49,5,3500.00,3500.00,'gcash','GC-20260221','paid','Session 2 root canal',2,'2026-02-21 14:35:00'),
('BILL-0050',15,50,7,2500.00,2500.00,'cash',NULL,'paid',NULL,1,'2026-02-24 09:05:00'),
('BILL-0051',16,51,4,600.00,600.00,'cash',NULL,'paid',NULL,1,'2026-03-03 10:05:00'),
('BILL-0052',17,52,1,300.00,300.00,'cash',NULL,'paid',NULL,2,'2026-03-05 09:05:00'),
('BILL-0053',18,53,3,800.00,800.00,'cash',NULL,'paid',NULL,1,'2026-03-07 11:05:00'),
('BILL-0054',19,54,2,500.00,500.00,'gcash','GC-20260310','paid',NULL,2,'2026-03-10 14:05:00'),
('BILL-0055',20,55,5,3500.00,3500.00,'bank','BNK-20260312','paid',NULL,1,'2026-03-12 09:35:00'),
('BILL-0056',21,56,10,350.00,350.00,'cash',NULL,'paid',NULL,2,'2026-03-14 10:05:00'),
('BILL-0057',22,57,4,600.00,600.00,'cash',NULL,'paid',NULL,1,'2026-03-17 08:35:00'),
('BILL-0058',23,58,1,300.00,300.00,'cash',NULL,'paid',NULL,2,'2026-03-19 11:35:00'),
('BILL-0059',24,59,3,800.00,800.00,'cash',NULL,'paid',NULL,1,'2026-03-21 09:05:00'),
('BILL-0060',25,60,9,200.00,200.00,'cash',NULL,'paid',NULL,2,'2026-03-24 10:05:00'),
('BILL-0061',26,61,5,3500.00,3500.00,'gcash','GC-20260401','paid','Root canal finalized',1,'2026-04-01 09:35:00'),
('BILL-0062',27,62,3,800.00,800.00,'cash',NULL,'paid',NULL,2,'2026-04-02 11:05:00'),
('BILL-0063',28,63,4,600.00,600.00,'cash',NULL,'paid',NULL,1,'2026-04-03 09:35:00'),
('BILL-0064',29,64,2,500.00,500.00,'gcash','GC-20260404','paid',NULL,2,'2026-04-04 14:35:00'),
('BILL-0065',31,66,7,2500.00,2500.00,'cash',NULL,'paid',NULL,1,'2026-04-07 09:35:00'),
('BILL-0066',32,67,4,600.00,600.00,'cash',NULL,'paid',NULL,2,'2026-04-07 10:05:00'),
('BILL-0067',33,68,5,3500.00,3500.00,'bank','BNK-20260408','paid','Senior citizen, with PhilHealth',1,'2026-04-08 14:05:00');

-- NOTIFICATIONS
INSERT INTO `notifications` (`user_id`,`title`,`message`,`type`,`is_read`,`link`,`created_at`) VALUES
(NULL,'New Walk-in Patient','Carlos Mendoza checked in for Tooth Extraction.','appointment',1,'modules/appointments/list.php','2025-10-11 13:55:00'),
(NULL,'Payment Received','Jose Santos paid ₱3,500.00 via GCash for Root Canal.','payment',1,'modules/billing/list.php','2025-10-07 11:10:00'),
(NULL,'Root Canal Completed','Root canal on patient Ernesto Perez (#19) completed successfully.','appointment',1,'modules/appointments/list.php','2025-11-22 11:35:00'),
(NULL,'Pending Bill Reminder','Bill BILL-0012 for Cristina Lim still has a balance of ₱1,750.00.','payment',1,'modules/billing/list.php','2025-11-01 08:00:00'),
(NULL,'Full Denture Delivered','Full upper denture for Juana Torres has been delivered.','appointment',1,'modules/appointments/list.php','2025-11-14 09:15:00'),
(NULL,'New Patient Registered','Alfredo Buenaventura has been registered as a new patient.','system',1,'modules/patients/list.php','2026-01-08 10:30:00'),
(NULL,'Appointment No-Show','Alfredo Buenaventura did not show up for his April 5 appointment.','appointment',1,'modules/appointments/list.php','2026-04-05 08:30:00'),
(NULL,'Root Canal Final Session Done','Francisco Luna completed final root canal session for #14.','appointment',1,'modules/appointments/list.php','2026-04-01 09:35:00'),
(NULL,'Today\'s Appointments','2 appointments confirmed for today: Jerome Villanueva and Melanie Santiago.','reminder',0,'modules/appointments/calendar.php','2026-04-09 07:00:00'),
(NULL,'Upcoming Appointments','3 pending appointments scheduled for next week.','reminder',0,'modules/appointments/list.php','2026-04-09 07:05:00'),
(1,'System Reminder','Remember to update the clinic schedule for Holy Week holidays.','system',0,'modules/schedule/manage.php','2026-04-09 07:10:00');

-- AUDIT LOGS
INSERT INTO `audit_logs` (`user_id`,`user_name`,`action`,`module`,`record_id`,`details`,`ip_address`,`created_at`) VALUES
(1,'Administrator','Added Patient','patients',1,'Added: Maria Reyes (PAT-0001)','127.0.0.1','2025-10-03 09:05:00'),
(1,'Administrator','Added Patient','patients',2,'Added: Jose Santos (PAT-0002)','127.0.0.1','2025-10-05 10:05:00'),
(1,'Administrator','Created Appointment','appointments',1,'APT-0001 for Maria Reyes','127.0.0.1','2025-10-06 08:45:00'),
(1,'Administrator','Completed Appointment','appointments',1,'APT-0001 marked Completed','127.0.0.1','2025-10-06 09:35:00'),
(1,'Administrator','Created Bill','billing',1,'BILL-0001 for Maria Reyes ₱300','127.0.0.1','2025-10-06 09:36:00'),
(2,'Staff User','Added Patient','patients',5,'Added: Elena Villanueva (PAT-0005)','127.0.0.1','2025-10-12 09:35:00'),
(1,'Administrator','Created Appointment','appointments',4,'APT-0004 for Carlos Mendoza - Extraction','127.0.0.1','2025-10-11 13:55:00'),
(1,'Administrator','Added Doctor','doctors',1,'Added: Dr. Maria Santos','127.0.0.1','2025-10-01 08:00:00'),
(1,'Administrator','Added Doctor','doctors',2,'Added: Dr. Jose Reyes','127.0.0.1','2025-10-01 08:05:00'),
(1,'Administrator','Logged In','auth',1,'Successful login from IP: 127.0.0.1','127.0.0.1','2025-12-01 08:00:00'),
(2,'Staff User','Logged In','auth',2,'Successful login from IP: 127.0.0.1','127.0.0.1','2025-12-01 08:05:00'),
(1,'Administrator','Completed Appointment','appointments',61,'APT-0061 marked Completed - Root canal final','127.0.0.1','2026-04-01 09:35:00'),
(2,'Staff User','Logged In','auth',2,'Successful login from IP: 127.0.0.1','127.0.0.1','2026-04-09 08:00:00'),
(1,'Administrator','Logged In','auth',1,'Successful login from IP: 127.0.0.1','127.0.0.1','2026-04-09 08:02:00');

-- ============================================================
-- Done! 35 patients | 73 appointments | 20 dental records
-- 67 bills | 11 notifications | 14 audit entries
-- Revenue: Oct 2025 – Apr 2026
-- ============================================================
-- ============================================================
-- TABLE: inventory (added for stock management)
-- ============================================================
CREATE TABLE IF NOT EXISTS `inventory` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `item_name`      VARCHAR(150)  NOT NULL,
  `category`       VARCHAR(80)   NOT NULL DEFAULT 'General',
  `quantity`       INT           NOT NULL DEFAULT 0,
  `unit`           VARCHAR(30)   NOT NULL DEFAULT 'pcs',
  `reorder_level`  INT           NOT NULL DEFAULT 5,
  `price_per_unit` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `supplier`       VARCHAR(150)  DEFAULT NULL,
  `notes`          TEXT          DEFAULT NULL,
  `is_active`      TINYINT(1)    DEFAULT 1,
  `created_at`     DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_inventory_category` (`category`),
  KEY `idx_inventory_active`   (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `inventory` (`item_name`, `category`, `quantity`, `unit`, `reorder_level`, `price_per_unit`, `supplier`) VALUES
('Latex Gloves (Medium)', 'PPE',          200, 'pcs',     50,   3.50,  'MedSupply PH'),
('Latex Gloves (Large)',  'PPE',          150, 'pcs',     50,   3.50,  'MedSupply PH'),
('Surgical Masks',        'PPE',          300, 'pcs',     100,  5.00,  'MedSupply PH'),
('Face Shields',          'PPE',           20, 'pcs',     10,   35.00, 'MedSupply PH'),
('Dental Cotton Rolls',   'Consumables',  500, 'pcs',     100,  1.50,  'Dental Depot'),
('Gauze Pads 4x4',        'Consumables',  200, 'pcs',     50,   2.00,  'Dental Depot'),
('Dental Floss',          'Consumables',   30, 'rolls',   10,   45.00, 'Dental Depot'),
('Composite Resin',       'Lab Supplies',  10, 'bottles',  3,  320.00, 'DentMed Inc'),
('Dental Cement',         'Lab Supplies',   8, 'tubes',    3,  180.00, 'DentMed Inc'),
('Alcohol 70%',           'Sterilization', 12, 'bottles',  5,   85.00, 'MedSupply PH'),
('Sodium Hypochlorite',   'Sterilization',  6, 'bottles',  3,   95.00, 'DentMed Inc'),
('Anesthetic Cartridges', 'Medications',   40, 'pcs',     20,   55.00, 'PharmaCare'),
('Ibuprofen 400mg',       'Medications',  100, 'tabs',    30,    8.00, 'PharmaCare'),
('Amoxicillin 500mg',     'Medications',   80, 'caps',    30,   12.00, 'PharmaCare'),
('Dental Bib',            'Consumables',  400, 'pcs',     100,   2.50, 'Dental Depot'),
('Autoclave Bags',        'Sterilization', 200, 'pcs',    50,    4.00, 'MedSupply PH'),
('Disposable Cups',       'Consumables',  300, 'pcs',     100,   1.00, 'Dental Depot'),
('Prophy Paste',          'Lab Supplies',   5, 'jars',     2,  280.00, 'DentMed Inc'),
('Fluoride Gel',          'Medications',    4, 'tubes',    2,  350.00, 'PharmaCare'),
('Paper Towels',          'Office Supplies',10, 'rolls',   3,   45.00, 'SM Stationery');

-- ============================================================
-- TABLE: settings (clinic info + system preferences)
-- ============================================================
CREATE TABLE IF NOT EXISTS `settings` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key`   VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT         DEFAULT NULL,
  `updated_at`    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('clinic_name',                'DentalCare.PH'),
('clinic_subtitle',            'Clinic System'),
('clinic_address',             ''),
('clinic_phone',               ''),
('clinic_email',               ''),
('clinic_logo',                ''),
('notif_appointment',          '1'),
('notif_payment',              '1'),
('notif_system',               '1'),
('notif_low_inventory',        '1'),
('notif_inventory_threshold',  '5'),
('noshow_auto_days',           '1')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
