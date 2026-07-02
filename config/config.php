<?php
// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Site configuration
define('SITE_NAME', 'Car Rental Management System');

$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
);

if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $forwardedProto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
    if ($forwardedProto === 'http' || $forwardedProto === 'https') {
        $isHttps = $forwardedProto === 'https';
    }
}

$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$basePath = ($basePath === '' || $basePath === '.') ? '' : $basePath;

define('SITE_URL', $scheme . '://' . $host . $basePath . '/');

// Include database connection
require_once __DIR__ . '/database.php';

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Check if user is admin
function isAdmin() {
    return isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'superadmin');
}

// Check if user is superadmin
function isSuperAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin';
}

// Redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . 'login.php');
        exit();
    }
}

// Redirect if not admin
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . 'dashboard.php');
        exit();
    }
}

// Redirect if not superadmin
function requireSuperAdmin() {
    requireLogin();
    if (!isSuperAdmin()) {
        header('Location: ' . SITE_URL . 'dashboard.php');
        exit();
    }
}

// Sanitize input
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Format currency
function formatCurrency($amount) {
    return 'RM ' . number_format($amount, 2);
}

// Format date
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

function tableColumnExists($conn, $table, $column) {
    $schema = DB_NAME;
    $stmt = $conn->prepare("SELECT COUNT(*) AS count
                            FROM INFORMATION_SCHEMA.COLUMNS
                            WHERE TABLE_SCHEMA = ?
                              AND TABLE_NAME = ?
                              AND COLUMN_NAME = ?");
    $stmt->bind_param("sss", $schema, $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = intval($result->fetch_assoc()['count'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function ensurePricingSchema($conn) {
    if (!tableColumnExists($conn, 'cars', 'weekly_rate')) {
        $conn->query("ALTER TABLE cars ADD COLUMN weekly_rate DECIMAL(10,2) NULL AFTER daily_rate");
    }

    if (!tableColumnExists($conn, 'cars', 'monthly_rate')) {
        $conn->query("ALTER TABLE cars ADD COLUMN monthly_rate DECIMAL(10,2) NULL AFTER weekly_rate");
    }
}

function tableExists($conn, $table) {
    $schema = DB_NAME;
    $stmt = $conn->prepare("SELECT COUNT(*) AS count
                            FROM INFORMATION_SCHEMA.TABLES
                            WHERE TABLE_SCHEMA = ?
                              AND TABLE_NAME = ?");
    $stmt->bind_param("ss", $schema, $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = intval($result->fetch_assoc()['count'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function ensureRentalSchema($conn) {
    ensurePricingSchema($conn);

    if (!tableExists($conn, 'rentals')) {
        $conn->query("CREATE TABLE rentals (
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
        )");
    }

    if (!tableColumnExists($conn, 'rentals', 'agreement_duration')) {
        $conn->query("ALTER TABLE rentals ADD COLUMN agreement_duration VARCHAR(50) AFTER end_date");
    }

    if (!tableColumnExists($conn, 'rentals', 'total_days')) {
        $conn->query("ALTER TABLE rentals ADD COLUMN total_days INT NOT NULL DEFAULT 1 AFTER end_date");
    }

    if (!tableColumnExists($conn, 'rentals', 'payment_frequency')) {
        $conn->query("ALTER TABLE rentals ADD COLUMN payment_frequency ENUM('daily', 'weekly', 'monthly') NOT NULL DEFAULT 'monthly' AFTER agreement_duration");
    }

    if (!tableColumnExists($conn, 'rentals', 'rate_amount')) {
        $conn->query("ALTER TABLE rentals ADD COLUMN rate_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER payment_frequency");
    }

    if (!tableColumnExists($conn, 'rentals', 'daily_rate')) {
        $conn->query("ALTER TABLE rentals ADD COLUMN daily_rate DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER payment_frequency");
    }

    if (!tableColumnExists($conn, 'rentals', 'total_paid')) {
        $conn->query("ALTER TABLE rentals ADD COLUMN total_paid DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER total_amount");
    }

    if (!tableColumnExists($conn, 'rentals', 'payment_status')) {
        $conn->query("ALTER TABLE rentals ADD COLUMN payment_status ENUM('pending', 'partial', 'paid') DEFAULT 'pending' AFTER total_paid");
    }

    if (!tableExists($conn, 'rental_payment_records')) {
        $conn->query("CREATE TABLE rental_payment_records (
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
        )");
    }

    if (!tableColumnExists($conn, 'rental_payment_records', 'receipt_photo')) {
        $conn->query("ALTER TABLE rental_payment_records ADD COLUMN receipt_photo VARCHAR(255) AFTER paid_date");
    }
}

function ensureMaintenanceSchema($conn) {
    if (!tableExists($conn, 'maintenance_records')) {
        $conn->query("CREATE TABLE maintenance_records (
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
        )");
    }

    if (!tableColumnExists($conn, 'maintenance_records', 'receipt_photo')) {
        $conn->query("ALTER TABLE maintenance_records ADD COLUMN receipt_photo VARCHAR(255) AFTER status");
    }

    if (!tableColumnExists($conn, 'maintenance_records', 'expense_category')) {
        $conn->query("ALTER TABLE maintenance_records ADD COLUMN expense_category ENUM('maintenance', 'cleaning', 'repair', 'parts_replacement', 'accessory', 'loan_installment', 'insurance_roadtax', 'fuel_toll_parking', 'inspection', 'other') NOT NULL DEFAULT 'maintenance' AFTER car_id");
    }
}

function paymentFrequencyLabel($frequency) {
    $labels = [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
    ];
    return $labels[$frequency] ?? 'Monthly';
}
?>
