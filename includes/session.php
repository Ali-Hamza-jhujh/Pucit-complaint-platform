<?php
/**
 * includes/session.php
 * Centralized, hardened session configuration.
 *
 * Always loaded via db.php before any output is sent.
 * Sets HttpOnly, SameSite=Lax, and enforces session lifetime
 * so these protections apply consistently to every page.
 */

if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../config.php';
}

if (session_status() === PHP_SESSION_NONE) {

    $sess_name     = defined('SESSION_NAME')     ? SESSION_NAME     : 'PUCIT_SID';
    $sess_lifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 7200;

    session_name($sess_name);

    // Harden the session cookie:
    //   httponly  → JavaScript cannot read the session cookie (mitigates XSS cookie theft)
    //   samesite  → Browser will not send cookie on cross-site requests (additional CSRF layer)
    //   secure    → Set to true once HTTPS is configured
    session_set_cookie_params([
        'lifetime' => $sess_lifetime,
        'path'     => '/',
        'domain'   => '',       // current domain only
        'secure'   => false,    // TODO: set true when HTTPS is in place
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    // Track when this session was first created
    if (!isset($_SESSION['_initialized'])) {
        $_SESSION['_initialized'] = true;
        $_SESSION['_created_at']  = time();
    }

    // Ensure CSRF token is always available for active session
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    // Enforce session lifetime: expire server-side even if cookie persists
    if ($sess_lifetime > 0 && isset($_SESSION['_created_at'])) {
        $effective_lifetime = !empty($_SESSION['_remember_me']) ? (30 * 86400) : $sess_lifetime;
        if ((time() - $_SESSION['_created_at']) > $effective_lifetime) {
            // Session has expired — destroy and start fresh
            session_unset();
            session_destroy();
            session_start();
            $_SESSION['_initialized'] = true;
            $_SESSION['_created_at']  = time();
        }
    }
}
