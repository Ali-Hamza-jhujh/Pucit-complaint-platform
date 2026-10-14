<?php
// Start session across all pages
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database Configuration
$db_host = "localhost";
$db_user = "devuser";
$db_pass = "2026";
$db_name = "devdb";

// Connect to MySQL
$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check if tables exist and create them if not
if ($conn && !$conn->connect_error) {
    // USERS Table
    $conn->query("CREATE TABLE IF NOT EXISTS USERS (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(120) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // COMPLAINTS Table for PUCIT Platform
    $conn->query("CREATE TABLE IF NOT EXISTS complaints (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        user_name VARCHAR(100) NULL,
        title VARCHAR(200) NOT NULL,
        category VARCHAR(100) NOT NULL,
        description TEXT NOT NULL,
        priority VARCHAR(20) DEFAULT 'Medium',
        status VARCHAR(20) DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Auth helper function
function is_logged_in() {
    return isset($_SESSION['user_id']) || isset($_SESSION['user_email']);
}

// Redirect if not logged in
function require_login() {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit();
    }
}
?>
