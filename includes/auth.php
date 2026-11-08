<?php
/**
 * includes/auth.php
 * Authentication and Authorization Helpers (Role-Based Access Control).
 *
 * Provides the core functions used on every page to check identity and role.
 * Loaded automatically by db.php — never include this file directly.
 */

if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../config.php';
}

// Ensure session is active before we inspect it
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session.php';
}

// ------------------------------------------------------------------
// is_logged_in()
// Returns true if a valid authenticated session exists.
// ------------------------------------------------------------------
if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool {
        return !empty($_SESSION['user_id']);
    }
}

// ------------------------------------------------------------------
// is_admin()
// Returns true only when the logged-in user holds the 'admin' role.
// ------------------------------------------------------------------
if (!function_exists('is_admin')) {
    function is_admin(): bool {
        return is_logged_in() && ($_SESSION['user_role'] ?? '') === 'admin';
    }
}

// ------------------------------------------------------------------
// require_login()
// Redirect to login page if no authenticated session is present.
// Works from any directory depth because it uses BASE_PATH constant.
// ------------------------------------------------------------------
if (!function_exists('require_login')) {
    function require_login(): void {
        if (!is_logged_in()) {
            header('Location: ' . BASE_PATH . '/login.php');
            exit();
        }
    }
}

// ------------------------------------------------------------------
// require_admin()
// Redirect appropriately if the visitor is not an authenticated admin.
//   • Not logged in  → login page
//   • Logged in but not admin (student) → student dashboard
// ------------------------------------------------------------------
if (!function_exists('require_admin')) {
    function require_admin(): void {
        if (!is_logged_in()) {
            header('Location: ' . BASE_PATH . '/login.php');
            exit();
        }
        if (!is_admin()) {
            // A student trying to access the admin area
            header('Location: ' . BASE_PATH . '/home.php');
            exit();
        }
    }
}

// ------------------------------------------------------------------
// get_session_user()
// Returns a safe array of the current user's session data.
// ------------------------------------------------------------------
if (!function_exists('verify_csrf')) {
    function verify_csrf(string $token): bool {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
    function get_session_user(): array {
        return [
            'id'    => (int)($_SESSION['user_id']    ?? 0),
            'name'  => $_SESSION['user_name']         ?? 'Unknown',
            'email' => $_SESSION['user_email']        ?? '',
            'role'  => $_SESSION['user_role']         ?? 'student',
        ];
    }
}
