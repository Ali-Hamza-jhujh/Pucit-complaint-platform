<?php
/**
 * PUCIT Complaint Management Platform
 * Application Configuration
 *
 * SECURITY: This file contains sensitive credentials.
 * In production, move DB credentials to environment variables
 * or store outside the web root entirely.
 *
 * Direct HTTP access to this file is blocked via .htaccess.
 */

// -------------------------------------------------
// Database Configuration
// -------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_USER', 'devuser');
define('DB_PASS', '2026');
define('DB_NAME', 'devdb');

// -------------------------------------------------
// Application Identity
// -------------------------------------------------
define('APP_NAME',    'PUCIT Complaint Portal');
define('APP_ORG',     'Punjab University College of Information Technology');
define('APP_VERSION', '1.0.0');

// Base URL path — '' if installed at web root, '/subdir' if in a subdirectory.
define('BASE_PATH', '');

// -------------------------------------------------
// Email Domain Restriction
// Registration is locked to these domains only.
// Add 'gmail.com' here temporarily if testing without a PUCIT email.
// -------------------------------------------------
define('ALLOWED_EMAIL_DOMAINS', ['pucit.edu.pk', 'pu.edu.pk']);

// -------------------------------------------------
// File Upload Settings
// -------------------------------------------------
define('UPLOAD_DIR',        __DIR__ . '/uploads/');
define('UPLOAD_WEB_PATH',   BASE_PATH . '/uploads/');
define('UPLOAD_MAX_SIZE',   5 * 1024 * 1024);                           // 5 MB
define('ALLOWED_MIME_TYPES',    ['image/jpeg', 'image/png', 'image/webp']);
define('ALLOWED_EXTENSIONS',    ['jpg', 'jpeg', 'png', 'webp']);

// -------------------------------------------------
// Session Settings
// -------------------------------------------------
define('SESSION_NAME',      'PUCIT_SID');
define('SESSION_LIFETIME',  7200);   // 2 hours; 0 = until browser closes

// -------------------------------------------------
// Environment & Error Handling
// -------------------------------------------------
define('APP_ENV',       'development');   // Switch to 'production' before going live
define('SHOW_ERRORS',   APP_ENV === 'development');

if (SHOW_ERRORS) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('log_errors', 1);
}
