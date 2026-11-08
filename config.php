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
// Environment Variables Loader (.env support)
// -------------------------------------------------
if (file_exists(__DIR__ . '/.env')) {
    $env_lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env_lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($env_k, $env_v) = explode('=', $line, 2);
            $env_k = trim($env_k);
            $env_v = trim($env_v, " \t\n\r\0\x0B\"'");
            if (!getenv($env_k)) {
                putenv("{$env_k}={$env_v}");
                $_ENV[$env_k] = $env_v;
            }
        }
    }
}

// -------------------------------------------------
// Database Configuration
// -------------------------------------------------
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'devuser');
define('DB_PASS', getenv('DB_PASS') ?: '2026');
define('DB_NAME', getenv('DB_NAME') ?: 'devdb');

// -------------------------------------------------
// Application Identity
// -------------------------------------------------
define('APP_NAME',      'PUCIT Complaint Portal');
define('APP_ORG',       'Punjab University College of Information Technology');
define('APP_VERSION',   '1.0.0');
define('SUPPORT_EMAIL', 'oalihamza8@gmail.com');

// -------------------------------------------------
// SMTP & Email Delivery Settings
// -------------------------------------------------
define('SMTP_ENABLED',  filter_var(getenv('SMTP_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN));
define('SMTP_HOST',     getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT',     (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_USER',     getenv('SMTP_USER') ?: 'oalihamza8@gmail.com');
define('SMTP_PASS',     getenv('SMTP_PASS') ?: '');
define('SMTP_FROM_NAME',getenv('SMTP_FROM_NAME') ?: APP_NAME);

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

// Disable PCRE JIT to avoid memory allocation warnings in hardened Linux environments
@ini_set('pcre.jit', '0');

// Multibyte string polyfill if ext-mbstring is not installed in environment
if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth(string $string, int $start, int $width, string $trim_marker = '', ?string $encoding = null): string {
        $len = strlen($string);
        if ($len <= $width) {
            return (string)substr($string, $start);
        }
        $marker_len = strlen($trim_marker);
        if ($width <= $marker_len) {
            return (string)substr($string, $start, $width);
        }
        return (string)substr($string, $start, $width - $marker_len) . $trim_marker;
    }
}

if (SHOW_ERRORS) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('log_errors', 1);
}

// Mailer Service
require_once __DIR__ . '/includes/mailer.php';
