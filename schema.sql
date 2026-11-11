-- ========================================================
-- Hospital & Clinic CRM System - Database Schema
-- Compatible with MySQL 5.7+ / 8.0+ / MariaDB & phpMyAdmin
-- Engine: InnoDB, Charset: utf8mb4
-- ========================================================

CREATE DATABASE IF NOT EXISTS `hospital_crm` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `hospital_crm`;

-- Import into a NEW empty hospital_crm database. This schema intentionally does not drop existing tables.

-- 1. Roles Table
CREATE TABLE `roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL UNIQUE,
  `description` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Permissions Table
CREATE TABLE `permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `permission_key` VARCHAR(100) NOT NULL UNIQUE,
  `description` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Role Permissions (Composite unique)
CREATE TABLE `role_permissions` (
  `role_id` INT NOT NULL,
  `permission_id` INT NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Users Table
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `role_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(50) NULL,
  `status` ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Departments Table
CREATE TABLE `departments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `code` VARCHAR(20) NOT NULL UNIQUE,
  `description` TEXT NULL,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Doctors Table
CREATE TABLE `doctors` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `department_id` INT NOT NULL,
  `specialization` VARCHAR(150) NOT NULL,
  `qualification` VARCHAR(200) NOT NULL,
  `fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `room_number` VARCHAR(50) NULL,
  `experience_years` INT DEFAULT 0,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Patients Table
CREATE TABLE `patients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NULL UNIQUE,
  `assigned_doctor_id` INT NULL,
  `patient_number` VARCHAR(50) NOT NULL UNIQUE,
  `date_of_birth` DATE NOT NULL,
  `gender` ENUM('Male', 'Female', 'Other') NOT NULL,
  `full_name` VARCHAR(150) NULL,
  `email` VARCHAR(150) NULL,
  `phone` VARCHAR(50) NULL,
  `blood_group` VARCHAR(10) NULL,
  `address` TEXT NULL,
  `emergency_name` VARCHAR(150) NULL,
  `emergency_phone` VARCHAR(50) NULL,
  `allergies` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  KEY `idx_patients_assigned_doctor` (`assigned_doctor_id`),
  CONSTRAINT `fk_patients_assigned_doctor` FOREIGN KEY (`assigned_doctor_id`) REFERENCES `doctors`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Staff Profiles
CREATE TABLE `staff_profiles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `job_title` VARCHAR(100) NOT NULL,
  `department_id` INT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Doctor Schedules
CREATE TABLE `doctor_schedules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `doctor_id` INT NOT NULL,
  `weekday` ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `slot_minutes` INT DEFAULT 30,
  FOREIGN KEY (`doctor_id`) REFERENCES `doctors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Appointments Table
CREATE TABLE `appointments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `token_number` INT NOT NULL,
  `patient_id` INT NOT NULL,
  `doctor_id` INT NOT NULL,
  `appointment_date` DATE NOT NULL,
  `start_at` TIME NOT NULL,
  `end_at` TIME NOT NULL,
  `status` ENUM('requested', 'confirmed', 'checked_in', 'in_consultation', 'completed', 'cancelled', 'no_show') DEFAULT 'requested',
  `reason` TEXT NULL,
  `notes` TEXT NULL,
  `created_by` VARCHAR(50) DEFAULT 'patient',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_doctor_appointment_slot` (`doctor_id`, `appointment_date`, `start_at`),
  UNIQUE KEY `uq_patient_appointment_slot` (`patient_id`, `appointment_date`, `start_at`),
  FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`doctor_id`) REFERENCES `doctors`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. Medical Records Table
CREATE TABLE `medical_records` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `patient_id` INT NOT NULL,
  `doctor_id` INT NOT NULL,
  `appointment_id` INT NOT NULL,
  `diagnosis` TEXT NOT NULL,
  `symptoms` TEXT NULL,
  `notes` TEXT NULL,
  `bp` VARCHAR(50) NULL,
  `pulse` VARCHAR(50) NULL,
  `temp` VARCHAR(50) NULL,
  `weight` VARCHAR(50) NULL,
  `spo2` VARCHAR(50) NULL,
  `follow_up_date` DATE NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`doctor_id`) REFERENCES `doctors`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. Prescriptions Table
CREATE TABLE `prescriptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `prescription_number` VARCHAR(50) NOT NULL UNIQUE,
  `medical_record_id` INT NOT NULL,
  `doctor_id` INT NOT NULL,
  `instructions` TEXT NULL,
  `issued_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`medical_record_id`) REFERENCES `medical_records`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`doctor_id`) REFERENCES `doctors`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. Prescription Items Table
CREATE TABLE `prescription_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `prescription_id` INT NOT NULL,
  `medicine_name` VARCHAR(200) NOT NULL,
  `dosage` VARCHAR(100) NOT NULL,
  `frequency` VARCHAR(100) NOT NULL,
  `duration` VARCHAR(100) NOT NULL,
  `notes` TEXT NULL,
  FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. Invoices Table
CREATE TABLE `invoices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
  `patient_id` INT NOT NULL,
  `appointment_id` INT NULL,
  `total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount_paid` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('paid', 'partial', 'unpaid') DEFAULT 'unpaid',
  `due_date` DATE NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. Payments Table
CREATE TABLE `payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `method` ENUM('cash', 'credit_card', 'bank_transfer', 'insurance') DEFAULT 'cash',
  `reference` VARCHAR(100) NULL,
  `received_by` VARCHAR(150) NOT NULL,
  `paid_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 16. Notifications Table
CREATE TABLE `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `read_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 17. Audit Logs Table
CREATE TABLE `audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(100) NOT NULL,
  `entity_id` VARCHAR(100) NULL,
  `metadata` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 18. Public Inquiries (contact / appointment inquiry form)
CREATE TABLE `inquiries` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(50) NULL,
  `inquiry_type` ENUM('general', 'appointment') NOT NULL DEFAULT 'general',
  `message` TEXT NOT NULL,
  `status` ENUM('new', 'in_progress', 'resolved') NOT NULL DEFAULT 'new',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 19. Settings Table
CREATE TABLE `settings` (
  `setting_key` VARCHAR(100) PRIMARY KEY,
  `setting_value` TEXT NOT NULL,
  `updated_by` INT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================================
-- Seed Initial Data & Demonstration Users
-- Password for all test users: password123
-- Password hash generated with password_hash('password123', PASSWORD_BCRYPT).
-- ========================================================

INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'super_admin', 'Full system oversight, global security, role & permission control'),
(2, 'admin', 'Clinic operations, user, doctor, patient and schedule management'),
(3, 'doctor', 'Clinical appointments, medical records and electronic prescriptions'),
(4, 'staff', 'Front desk registration, scheduling, patient check-in and billing'),
(5, 'patient', 'Personal health portal, appointment booking and medical history'),
(6, 'visitor', 'Public clinic browsing, inquiries and appointment requests');

INSERT INTO `departments` (`id`, `name`, `code`, `description`) VALUES
(1, 'Cardiology', 'CARDIO', 'Heart & cardiovascular diseases'),
(2, 'General Medicine', 'GENMED', 'Primary healthcare and chronic disease care'),
(3, 'Orthopedics', 'ORTHO', 'Joints, bones and sports surgery'),
(4, 'Pediatrics', 'PEDIAT', 'Child and adolescent healthcare');

INSERT INTO `users` (`id`, `role_id`, `name`, `email`, `password_hash`, `phone`, `status`) VALUES
(1, 1, 'Eleanor Vance', 'superadmin@apexhealth.com', '$2y$12$b08uEYXHhA5SRikqT7EtcOPPUWoBcbhhPULgHzq.e/TnqXBlI23Um', '+1-555-100-0001', 'active'),
(2, 2, 'Marcus Brody', 'admin@apexhealth.com', '$2y$12$b08uEYXHhA5SRikqT7EtcOPPUWoBcbhhPULgHzq.e/TnqXBlI23Um', '+1-555-100-0002', 'active'),
(3, 3, 'Dr. Sarah Khan', 'dr.sarah@apexhealth.com', '$2y$12$b08uEYXHhA5SRikqT7EtcOPPUWoBcbhhPULgHzq.e/TnqXBlI23Um', '+1-555-100-0003', 'active'),
(4, 3, 'Dr. Ahmed Malik', 'dr.ahmed@apexhealth.com', '$2y$12$b08uEYXHhA5SRikqT7EtcOPPUWoBcbhhPULgHzq.e/TnqXBlI23Um', '+1-555-100-0004', 'active'),
(5, 4, 'Jessica Reynolds', 'reception@apexhealth.com', '$2y$12$b08uEYXHhA5SRikqT7EtcOPPUWoBcbhhPULgHzq.e/TnqXBlI23Um', '+1-555-100-0005', 'active'),
(6, 5, 'Robert Miller', 'robert.patient@gmail.com', '$2y$12$b08uEYXHhA5SRikqT7EtcOPPUWoBcbhhPULgHzq.e/TnqXBlI23Um', '+1-555-234-5678', 'active');

INSERT INTO `doctors` (`id`, `user_id`, `department_id`, `specialization`, `qualification`, `fee`, `room_number`, `experience_years`) VALUES
(1, 3, 1, 'Cardiology & Echo', 'MD, FACC', 150.00, 'Suite 302', 14),
(2, 4, 2, 'Internal Medicine', 'MBBS, MRCGP', 90.00, 'Suite 104', 11);

INSERT INTO `doctor_schedules` (`doctor_id`, `weekday`, `start_time`, `end_time`, `slot_minutes`) VALUES
(1, 'Monday', '08:00:00', '17:00:00', 30),
(1, 'Tuesday', '08:00:00', '17:00:00', 30),
(1, 'Wednesday', '08:00:00', '17:00:00', 30),
(1, 'Thursday', '08:00:00', '17:00:00', 30),
(1, 'Friday', '08:00:00', '17:00:00', 30),
(2, 'Monday', '09:00:00', '18:00:00', 30),
(2, 'Tuesday', '09:00:00', '18:00:00', 30),
(2, 'Wednesday', '09:00:00', '18:00:00', 30),
(2, 'Thursday', '09:00:00', '18:00:00', 30),
(2, 'Friday', '09:00:00', '18:00:00', 30);

INSERT INTO `patients` (`id`, `user_id`, `patient_number`, `date_of_birth`, `gender`, `blood_group`, `address`, `emergency_name`, `emergency_phone`, `allergies`) VALUES
(1, 6, 'PAT-2026-0042', '1984-06-14', 'Male', 'O+', '142 Oakridge Valley Lane', 'Linda Miller', '+1-555-234-5679', 'Penicillin'),
(2, NULL, 'PAT-2026-0043', '1992-11-20', 'Female', 'B+', '88 Cypress Creek Court', 'Raj Patel', '+1-555-876-9999', 'Latex');

UPDATE `patients` p JOIN `users` u ON u.id=p.user_id SET p.full_name=u.name,p.email=u.email,p.phone=u.phone WHERE p.user_id IS NOT NULL;
UPDATE `patients` SET full_name='Walk-in Patient', phone='+1-555-876-9999', email=NULL WHERE patient_number='PAT-2026-0043';

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('clinic_name', 'Apex Health & Wellness Medical Center'),
('clinic_email', 'contact@apexhealthcrm.com'),
('clinic_phone', '+1 (555) 382-9000'),
('clinic_address', 'Suite 400, 742 Evergreen Healthcare Blvd'),
('currency_symbol', '$'),
('tax_rate_percent', '5.0'),
('appointment_slot_minutes', '30');
