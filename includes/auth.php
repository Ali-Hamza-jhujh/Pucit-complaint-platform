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
// verify_csrf()
// Checks the CSRF token stored in session against the provided token.
// ------------------------------------------------------------------
if (!function_exists('verify_csrf')) {
    function verify_csrf(string $token): bool {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

// ------------------------------------------------------------------
// get_session_user()
// Returns a safe array of the current user's session data.
// ------------------------------------------------------------------
if (!function_exists('get_session_user')) {
    function get_session_user(): array {
        return [
            'id'    => (int)($_SESSION['user_id'] ?? 0),
            'name'  => $_SESSION['user_name'] ?? 'Unknown',
            'email' => $_SESSION['user_email'] ?? '',
            'role'  => $_SESSION['user_role'] ?? 'student',
        ];
    }
}

/* Rate limiting utilities - Phase 6 */
if (!function_exists('check_rate_limit')) {
    /**
     * Checks whether the given identifier/action has exceeded the limit within the time window.
     *
     * @param string $identifier Unique identifier (e.g., IP address or user ID)
     * @param string $action Specific action name (e.g., 'login_attempt')
     * @param int $limit Maximum allowed attempts
     * @param int $window_seconds Time window in seconds
     * @return bool True if under limit, false if limit exceeded
     */
    function check_rate_limit(string $identifier, string $action, int $limit, int $window_seconds): bool {
        global $conn; // Assuming $conn is the mysqli connection from db.php
        $now = time();
        $window_start = $now - $window_seconds;

        // Clean up old records
        $stmt = $conn->prepare('DELETE FROM rate_limits WHERE timestamp < ?');
        $stmt->bind_param('i', $window_start);
        $stmt->execute();
        $stmt->close();

        // Count recent attempts
        $stmt = $conn->prepare('SELECT COUNT(*) FROM rate_limits WHERE identifier = ? AND action = ? AND timestamp >= ?');
        $stmt->bind_param('ssi', $identifier, $action, $window_start);
        $stmt->execute();
        $stmt->bind_result($cnt);
        $stmt->fetch();
        $stmt->close();

        return $cnt < $limit;
    }
}

if (!function_exists('increment_rate_limit')) {
    /**
     * Records a new attempt for the given identifier/action.
     *
     * @param string $identifier Unique identifier (e.g., IP address or user ID)
     * @param string $action Specific action name
     */
    function increment_rate_limit(string $identifier, string $action): void {
        global $conn;
        $now = time();
        $stmt = $conn->prepare('INSERT INTO rate_limits (identifier, action, timestamp) VALUES (?, ?, ?)');
        $stmt->bind_param('ssi', $identifier, $action, $now);
        $stmt->execute();
        $stmt->close();
    }
}
