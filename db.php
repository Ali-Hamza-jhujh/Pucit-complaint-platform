<?php
/**
 * db.php — Central Application Bootstrap
 *
 * Every page includes this file first. It is responsible for:
 *   1. Loading application configuration  (config.php)
 *   2. Starting a hardened PHP session    (includes/session.php)
 *   3. Opening the MySQL connection
 *   4. Auto-creating / migrating the database schema
 *   5. Loading authentication helpers     (includes/auth.php)
 *
 * Direct HTTP access to this file is blocked via .htaccess.
 */

// ---- 1. Configuration -----------------------------------------------
require_once __DIR__ . '/config.php';

// ---- 2. Hardened Session --------------------------------------------
require_once __DIR__ . '/includes/session.php';

// ---- 3. Database Connection -----------------------------------------
mysqli_report(MYSQLI_REPORT_OFF); // Handle errors explicitly; avoid unhandled exceptions

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (Throwable $e) {
    $conn = false;
}

// ---- 4. Schema Bootstrap --------------------------------------------
if ($conn && !$conn->connect_error) {

    $conn->set_charset('utf8mb4');

    // Only run DDL bootstrap if tables are not yet initialized
    $table_check = $conn->query("SHOW TABLES LIKE 'USERS'");
    $schema_initialized = ($table_check && $table_check->num_rows > 0);

    if (!$schema_initialized) {

    // ================================================================
    // TABLE: USERS
    // Stores student and admin accounts.
    // Note: Table name kept as 'USERS' (uppercase) to match existing
    //       production data. MySQL on Linux is case-sensitive.
    // ================================================================
    $conn->query("CREATE TABLE IF NOT EXISTS USERS (
        id         INT          AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(100) NOT NULL,
        email      VARCHAR(120) NOT NULL UNIQUE,
        password   VARCHAR(255) NOT NULL,
        role       ENUM('student','admin') NOT NULL DEFAULT 'student',
        is_active  TINYINT(1)   NOT NULL DEFAULT 1,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Safe cross-version column migration helper
    $add_col = function($table, $column, $definition) use ($conn) {
        $chk = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE `{$table}` ADD `{$column}` {$definition}");
        }
    };

    // Migration: ensure new columns exist in USERS table
    $add_col('USERS', 'role', "ENUM('student','admin') NOT NULL DEFAULT 'student'");
    $add_col('USERS', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1");
    @$conn->query("ALTER TABLE USERS MODIFY COLUMN password VARCHAR(255) NOT NULL");

    // ================================================================
    // TABLE: categories
    // Admin-managed complaint categories (replaces hardcoded HTML list).
    // ================================================================
    $conn->query("CREATE TABLE IF NOT EXISTS categories (
        id          INT          AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(100) NOT NULL,
        description VARCHAR(255) DEFAULT '',
        is_active   TINYINT(1)   NOT NULL DEFAULT 1,
        sort_order  INT          NOT NULL DEFAULT 0,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default categories only once (when table is empty)
    $cat_check = $conn->query("SELECT COUNT(*) AS cnt FROM categories");
    if ($cat_check && ($cat_row = $cat_check->fetch_assoc()) && (int)$cat_row['cnt'] === 0) {
        $default_cats = [
            ['Computer Labs & Systems',       'Issues with laboratory computers, software, or hardware'],
            ['Campus Wi-Fi & Internet',       'Network connectivity and internet access issues'],
            ['Classroom & Projectors',        'Projector, whiteboard, or classroom facility issues'],
            ['Air Conditioning & Electricity','HVAC, fans, or electrical supply issues'],
            ['Examination & Records',         'Exam scheduling, results, or academic record issues'],
            ['Library Services',              'Library access, books, or reading room issues'],
            ['Cafeteria & Cleanliness',       'Food, hygiene, or campus cleanliness concerns'],
            ['Faculty & Academics',           'Teaching quality, attendance, or academic concerns'],
            ['Fee & Finance',                 'Fee payment, scholarship, or financial issues'],
            ['Other / General',               'Issues not covered by other categories'],
        ];
        $cat_ins = $conn->prepare(
            "INSERT INTO categories (name, description, sort_order) VALUES (?, ?, ?)"
        );
        if ($cat_ins) {
            foreach ($default_cats as $i => $cat) {
                $order = $i + 1;
                $cat_ins->bind_param('ssi', $cat[0], $cat[1], $order);
                $cat_ins->execute();
            }
            $cat_ins->close();
        }
    }

    // ================================================================
    // TABLE: complaints
    // Core table. user_id links to USERS but is never exposed through
    // student-facing queries — only admins see the identity JOIN.
    //
    // user_name column kept temporarily for backwards compatibility.
    // It will be removed in Phase 3 when complaint submission is rewritten.
    // ================================================================
    $conn->query("CREATE TABLE IF NOT EXISTS complaints (
        id           INT          AUTO_INCREMENT PRIMARY KEY,
        user_id      INT          NOT NULL,
        user_name    VARCHAR(100) NULL,
        title        VARCHAR(200) NOT NULL,
        category_id  INT          DEFAULT NULL,
        category     VARCHAR(100) NOT NULL,
        description  TEXT         NOT NULL,
        priority     VARCHAR(20)  NOT NULL DEFAULT 'Medium',
        status       VARCHAR(30)  NOT NULL DEFAULT 'Pending',
        is_anonymous TINYINT(1)   NOT NULL DEFAULT 0,
        image_path   VARCHAR(255) DEFAULT NULL,
        admin_notes  TEXT         DEFAULT NULL,
        created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Migration: ensure required columns exist in complaints table
    $add_col('complaints', 'is_anonymous', "TINYINT(1) NOT NULL DEFAULT 1");
    $add_col('complaints', 'image_path', "VARCHAR(255) DEFAULT NULL");
    $add_col('complaints', 'admin_notes', "TEXT DEFAULT NULL");
    $add_col('complaints', 'category_id', "INT DEFAULT NULL");
    $add_col('complaints', 'updated_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

    // Data migration: normalize legacy status value
    // Old value 'In Progress' → new canonical value 'In Review'
    @$conn->query("UPDATE complaints SET status = 'In Review'
                   WHERE status = 'In Progress'");

    // NFR 3.3 Cascading Safeguards: Foreign key constraint to prevent student profile deletion with active complaints
    $fk_user = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND CONSTRAINT_NAME = 'fk_complaints_user'");
    if ($fk_user && $fk_user->num_rows === 0) {
        @$conn->query("ALTER TABLE complaints ADD CONSTRAINT fk_complaints_user FOREIGN KEY (user_id) REFERENCES USERS(id) ON DELETE RESTRICT");
    }

    $fk_cat = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND CONSTRAINT_NAME = 'fk_complaints_category'");
    if ($fk_cat && $fk_cat->num_rows === 0) {
        @$conn->query("ALTER TABLE complaints ADD CONSTRAINT fk_complaints_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL");
    }

    // ================================================================
    // TABLE: admin_actions
    // Immutable audit log. Every admin action on a complaint is recorded
    // here. Rows must never be updated or deleted (enforced by application
    // logic and ideally by DB-level privileges in production).
    // ================================================================
    $conn->query("CREATE TABLE IF NOT EXISTS admin_actions (
        id           INT         AUTO_INCREMENT PRIMARY KEY,
        complaint_id INT         NOT NULL,
        admin_id     INT         NOT NULL,
        action_type  VARCHAR(50) NOT NULL,
        old_status   VARCHAR(30) DEFAULT NULL,
        new_status   VARCHAR(30) DEFAULT NULL,
        notes        TEXT        DEFAULT NULL,
        created_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ================================================================
    // TABLE: complaint_images
    // Stores paths to optional uploaded images.
    // Supports future multi-image expansion.
    // ================================================================
    $conn->query("CREATE TABLE IF NOT EXISTS complaint_images (
        id            INT          AUTO_INCREMENT PRIMARY KEY,
        complaint_id  INT          NOT NULL,
        file_path     VARCHAR(500) NOT NULL,
        original_name VARCHAR(255) DEFAULT NULL,
        file_size     INT          DEFAULT NULL,
        mime_type     VARCHAR(100) DEFAULT NULL,
        uploaded_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ================================================================
    // TABLE: rate_limits
    // Used by the rate-limiting layer (Phase 6).
    // Created here so the table is available from day one.
    // ================================================================
    $conn->query("CREATE TABLE IF NOT EXISTS rate_limits (
        id           INT          AUTO_INCREMENT PRIMARY KEY,
        identifier   VARCHAR(100) NOT NULL,
        action       VARCHAR(50)  NOT NULL,
        attempts     INT          NOT NULL DEFAULT 1,
        window_start TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_identifier_action (identifier, action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    } // end initial schema creation

} // end schema bootstrap

// ---- 5. Auth Helpers ------------------------------------------------
require_once __DIR__ . '/includes/auth.php';
