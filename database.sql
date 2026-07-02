-- Car Rental Management System Database
-- Created: January 21, 2026

CREATE DATABASE IF NOT EXISTS car_rental_db;
USE car_rental_db;

-- Users table (for companies/agents and admin)
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    company_name VARCHAR(100),
    phone VARCHAR(20),
    role ENUM('admin', 'agent', 'superadmin') DEFAULT 'agent',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Cars table
CREATE TABLE IF NOT EXISTS cars (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    brand VARCHAR(50) NOT NULL,
    model VARCHAR(50) NOT NULL,
    year INT NOT NULL,
    color VARCHAR(30),
    plate_number VARCHAR(20) UNIQUE NOT NULL,
    daily_rate DECIMAL(10,2) NOT NULL,
    weekly_rate DECIMAL(10,2),
    monthly_rate DECIMAL(10,2),
    status ENUM('available', 'rented', 'maintenance') DEFAULT 'available',
    image VARCHAR(255),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Customers table
CREATE TABLE IF NOT EXISTS customers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20) NOT NULL,
    address TEXT,
    id_number VARCHAR(50),
    license_number VARCHAR(50),
    license_expiry_date DATE,
    psv_expiry_date DATE,
    ic_front_photo VARCHAR(255),
    ic_back_photo VARCHAR(255),
    license_front_photo VARCHAR(255),
    license_back_photo VARCHAR(255),
    psv_front_photo VARCHAR(255),
    psv_back_photo VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Rentals table for agreement duration and payment schedule setup
CREATE TABLE IF NOT EXISTS rentals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    car_id INT NOT NULL,
    customer_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    total_days INT NOT NULL DEFAULT 1,
    agreement_duration VARCHAR(50),
    payment_frequency ENUM('daily', 'weekly', 'monthly') NOT NULL DEFAULT 'monthly',
    daily_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
    rate_amount DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_status ENUM('pending', 'partial', 'paid') DEFAULT 'pending',
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

-- Payment records generated from the selected daily, weekly, or monthly payment schedule
CREATE TABLE IF NOT EXISTS rental_payment_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    rental_id INT NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    due_date DATE NOT NULL,
    amount_due DECIMAL(10,2) NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_date DATE,
    receipt_photo VARCHAR(255),
    status ENUM('pending', 'paid') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE CASCADE
);

-- Vehicle expense and maintenance records
CREATE TABLE IF NOT EXISTS maintenance_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    car_id INT NOT NULL,
    expense_category ENUM('maintenance', 'cleaning', 'repair', 'parts_replacement', 'accessory', 'loan_installment', 'insurance_roadtax', 'fuel_toll_parking', 'inspection', 'other') NOT NULL DEFAULT 'maintenance',
    service_date DATE NOT NULL,
    service_type VARCHAR(100) NOT NULL,
    vendor VARCHAR(100),
    cost DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('sent', 'in_progress', 'completed') DEFAULT 'sent',
    receipt_photo VARCHAR(255),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
);

-- Insert default admin account
-- Password: admin123 (hashed)
INSERT INTO users (username, email, password, full_name, role) VALUES
('admin', 'admin@crms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin');

-- Sample data for testing
INSERT INTO users (username, email, password, full_name, company_name, phone, role) VALUES
('agent1', 'agent1@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Doe', 'ABC Rentals', '123-456-7890', 'agent');
