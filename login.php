<?php
// db.php handles: config, hardened session, DB connection, schema, auth helpers
require_once "db.php";

// If already logged in, redirect to respective dashboard based on role
if (is_logged_in()) {
    if (($_SESSION['user_role'] ?? '') === 'admin') {
        header("Location: admin/index.php");
    } else {
        header("Location: home.php");
    }
    exit();
}

$error = "";
$success = "";
$email = "";

if (isset($_GET["registered"])) {
    $success = "Registration successful! You can now log in with your credentials.";
}

if (isset($_GET["verified"])) {
    $success = "Your official email has been verified successfully. Please sign in below.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // Handle password reset request form
    if (isset($_POST["request_password_reset"])) {
        $csrf = $_POST["csrf_token"] ?? "";
        $reset_email = trim($_POST["reset_email"] ?? "");

        if (!verify_csrf($csrf)) {
            $error = "Security token expired. Please refresh and try again.";
        } elseif (!check_rate_limit($ip, 'reset_attempt', 3, 3600)) {
            $error = "Too many password reset requests. Please wait before trying again.";
        } elseif (empty($reset_email) || !filter_var($reset_email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid university email address.";
        } else {
            increment_rate_limit($ip, 'reset_attempt');

            // Mark reset requested flag in database
            $flag_stmt = $conn->prepare("UPDATE USERS SET password_reset_requested = 1, reset_requested_at = NOW() WHERE email = ?");
            if ($flag_stmt) {
                $flag_stmt->bind_param('s', $reset_email);
                $flag_stmt->execute();
                $flag_stmt->close();
            }

            // Dispatch notification to support email
            $to = SUPPORT_EMAIL;
            $subject = "PUCIT Portal - Password Reset Request";
            $body = "A password reset request was lodged on the PUCIT Complaint Portal.\n\n"
                  . "Target User Email: {$reset_email}\n"
                  . "Client IP: {$ip}\n"
                  . "Timestamp: " . date("Y-m-d H:i:s") . "\n\n"
                  . "Please verify user credentials and assist with account recovery.\n"
                  . "Admin Panel URL: http://dev.local/admin/users.php";
            send_system_email($to, $subject, $body, $reset_email);

            $success = "Password reset request submitted. University IT Support at " . htmlspecialchars(SUPPORT_EMAIL) . " has been notified.";
        }
    } else {
        $email = trim($_POST["email"] ?? "");
        $password = $_POST["password"] ?? "";

        if (!check_rate_limit($ip, 'login_attempt', 5, 300)) {
            $error = "Too many failed login attempts. Please wait 5 minutes before trying again.";
        } elseif (empty($email) || empty($password)) {
            $error = "Please enter both email and password.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (!$conn || $conn->connect_error) {
            $error = "Database connection error. Please ensure MySQL is active (sudo service mysql start).";
        } else {
        // Prepare secure statement to look up user
        $stmt = $conn->prepare("SELECT id, name, email, password, role, is_active, is_verified, verification_code FROM USERS WHERE email = ? LIMIT 1");
        
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // Support both hashed passwords and legacy plain text passwords
                $password_matched = false;
                if (password_verify($password, $user["password"])) {
                    $password_matched = true;
                } elseif ($password === $user["password"]) {
                    // Upgraded plain-text password to hash
                    $password_matched = true;
                    $new_hash = password_hash($password, PASSWORD_DEFAULT);
                    $up_stmt = $conn->prepare("UPDATE USERS SET password = ? WHERE id = ?");
                    if ($up_stmt) {
                        $up_stmt->bind_param("si", $new_hash, $user["id"]);
                        $up_stmt->execute();
                        $up_stmt->close();
                    }
                }

                if ($password_matched) {
                    if ((int)($user["is_active"] ?? 1) === 0) {
                        $error = "Your account has been deactivated. Please contact PUCIT administration.";
                    } elseif ((int)($user["is_verified"] ?? 1) === 0) {
                        $_SESSION['verify_email'] = $user['email'];
                        $_SESSION['verify_role']  = $user['role'] ?? 'teacher';
                        if (!empty($user['verification_code'])) {
                            $_SESSION['dev_otp'] = $user['verification_code'];
                        }
                        header("Location: verify.php");
                        exit();
                    } else {
                        // Regenerate session ID to prevent fixation
                        session_regenerate_id(true);

                        // Remember Me persistent session (30 days)
                        if (!empty($_POST['remember'])) {
                            $remember_duration = 30 * 86400; // 30 days
                            setcookie(session_name(), session_id(), [
                                'expires'  => time() + $remember_duration,
                                'path'     => '/',
                                'domain'   => '',
                                'secure'   => false,
                                'httponly' => true,
                                'samesite' => 'Lax',
                            ]);
                            $_SESSION['_remember_me'] = true;
                        }

                        // Set session variables, including role
                        $_SESSION["user_id"] = $user["id"];
                        $_SESSION["user_name"] = $user["name"];
                        $_SESSION["user_email"] = $user["email"];
                        $_SESSION["user_role"] = $user["role"] ?? "student";
                        // Redirect based on role
                        if ($_SESSION["user_role"] === "admin") {
                            header("Location: admin/index.php");
                        } else {
                            header("Location: home.php");
                        }
                        exit();
                    }
                } else {
                    increment_rate_limit($ip, 'login_attempt');
                    $error = "Invalid email or password. Please try again.";
                }
            } else {
                increment_rate_limit($ip, 'login_attempt');
                $error = "Invalid email or password. Please try again.";
            }
            $stmt->close();
        } else {
            $error = "Query preparation error: " . htmlspecialchars($conn->error);
        }
    }
}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PUCIT Complaint Portal - Login</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%230e2a47'><path d='M12 3L1 9L12 15L21 10.09V17H23V9M5 13.18V17.18C5 19.94 8.13 22 12 22C15.87 22 19 19.94 19 17.18V13.18L12 17L5 13.18Z'/></svg>">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>

<div class="auth-wrapper">
    <div class="auth-card">
        <!-- University Branding -->
        <div class="brand-header">
            <div class="brand-badge">
                <svg width="34" height="34" style="width: 34px; height: 34px; max-width: 34px; max-height: 34px; display: block;" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 3L1 9L12 15L21 10.09V17H23V9M5 13.18V17.18C5 19.94 8.13 22 12 22C15.87 22 19 19.94 19 17.18V13.18L12 17L5 13.18Z" />
                </svg>
            </div>
            <h1 class="brand-title">PUCIT Portal</h1>
            <p class="brand-subtitle">Punjab University College of Information Technology</p>
            
            <h2 class="auth-card-title">Welcome Back</h2>
            <p class="auth-card-desc">Sign in with your university credentials to proceed</p>
        </div>

        <!-- Success Alert -->
        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <svg width="20" height="20" style="min-width: 20px;" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <!-- Error Alert -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <svg width="20" height="20" style="min-width: 20px;" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <span><?= $error ?></span>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="login.php">
            <!-- Email -->
            <div class="form-group">
                <label class="form-label" for="email">Official PUCIT Email (Student or Faculty)</label>
                <div class="input-container">
                    <span class="input-icon">
                        <svg width="18" height="18" style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </span>
                    <input 
                        type="email" 
                        name="email" 
                        id="email"
                        class="form-control" 
                        placeholder="e.g. bsef22m001@pucit.edu.pk" 
                        value="<?= htmlspecialchars($email) ?>" 
                        required
                    >
                </div>
            </div>

            <!-- Password -->
            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <div class="input-container">
                    <span class="input-icon">
                        <svg width="18" height="18" style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </span>
                    <input 
                        type="password" 
                        name="password" 
                        id="password"
                        class="form-control" 
                        placeholder="Enter your password" 
                        required
                    >
                    <button type="button" class="toggle-password" onclick="togglePassVisibility('password', this)" title="Show/Hide Password">
                        <svg width="18" height="18" style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                </div>
            </div>

            <div class="form-actions-inline">
                <label class="checkbox-label">
                    <input type="checkbox" name="remember" checked>
                    <span>Remember me</span>
                </label>
                <a href="#forgot" onclick="openForgotModal(); return false;">Forgot password?</a>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn btn-primary btn-block">
                <span>Sign In to Portal</span>
                <svg width="18" height="18" style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
            </button>
        </form>

        <!-- Switch to Signup -->
        <div class="auth-footer">
            Don't have an account yet? <a href="index.php">Register here</a>
        </div>
    </div>

    <div class="site-copy">
        &copy; <?= date("Y") ?> PUCIT - Punjab University College of Information Technology
    </div>
</div>

<!-- Password Reset Help Modal -->
<div class="detail-modal" id="forgot-modal">
    <div class="detail-card" style="max-width: 480px;">
        <button class="btn-close-modal" onclick="closeForgotModal()">&times;</button>
        <h3>🔐 Password Reset Assistance</h3>
        <p style="font-size: 14px; color: var(--text-secondary); margin-bottom: 16px;">
            To safeguard student privacy and academic integrity, automated password resets are handled through the official university IT Helpdesk.
        </p>

        <form method="POST" action="login.php" style="margin-bottom: 16px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <div class="form-group" style="margin-bottom: 12px;">
                <label class="form-label" for="reset_email">Enter Registered University Email</label>
                <input type="email" name="reset_email" id="reset_email" class="form-control" style="padding-left: 14px;" placeholder="e.g. bsef22m001@pucit.edu.pk" required>
            </div>
            <button type="submit" name="request_password_reset" class="btn btn-primary btn-block btn-sm">
                <span>Send Reset Request to Support</span>
            </button>
        </form>

        <div style="background: var(--surface-muted); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 12px 14px; margin-bottom: 16px;">
            <div style="font-size: 12.5px; font-weight: 600; color: var(--primary); margin-bottom: 4px;">Manual Support:</div>
            <div style="font-size: 12.5px; color: var(--text-secondary); line-height: 1.5;">
                You can also email directly to <strong><?= htmlspecialchars(SUPPORT_EMAIL) ?></strong> with your roll number and student card photo.
            </div>
        </div>

        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <button type="button" class="btn btn-outline btn-sm" onclick="closeForgotModal()">Close</button>
            <a href="mailto:<?= htmlspecialchars(SUPPORT_EMAIL) ?>?subject=Password%20Reset%20Request%20-%20PUCIT%20Complaint%20Portal" class="btn btn-outline btn-sm">
                Direct Email
            </a>
        </div>
    </div>
</div>

<script>
function togglePassVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    if (input.type === "password") {
        input.type = "text";
        btn.style.color = "var(--secondary)";
    } else {
        input.type = "password";
        btn.style.color = "var(--text-muted)";
    }
}

function openForgotModal() {
    document.getElementById('forgot-modal').classList.add('open');
}

function closeForgotModal() {
    document.getElementById('forgot-modal').classList.remove('open');
}

document.getElementById('forgot-modal').addEventListener('click', function(e) {
    if (e.target === this) closeForgotModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeForgotModal();
});
</script>

</body>
</html>
