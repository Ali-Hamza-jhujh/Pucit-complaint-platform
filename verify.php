<?php
/**
 * verify.php
 * Official PUCIT Email Verification Portal
 *
 * Verifies 6-digit OTP code sent to official emails to confirm identity
 * and activate Teacher / Faculty accounts.
 */
require_once "db.php";

$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$error = "";
$success = "";

$verify_email = $_SESSION['verify_email'] ?? trim($_GET['email'] ?? '');
$verify_role  = $_SESSION['verify_role']  ?? 'teacher';
$dev_otp      = $_SESSION['dev_otp']      ?? '';

// If no verification in progress, redirect to login
if (empty($verify_email)) {
    header("Location: login.php");
    exit();
}

// Handle OTP submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["verify_otp"])) {
    $csrf = $_POST["csrf_token"] ?? "";
    $code = trim($_POST["otp_code"] ?? "");

    if (!check_rate_limit($ip, 'verify_attempt', 6, 600)) {
        $error = "Too many failed attempts. Please wait 10 minutes before trying again.";
    } elseif (!verify_csrf($csrf)) {
        $error = "Security token expired. Please refresh and try again.";
    } elseif (empty($code) || strlen($code) !== 6 || !ctype_digit($code)) {
        $error = "Please enter a valid 6-digit verification code.";
    } elseif ($conn && !$conn->connect_error) {
        $stmt = $conn->prepare("SELECT id, name, role, verification_code FROM USERS WHERE email = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $verify_email);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($user = $res->fetch_assoc()) {
                if ($user['verification_code'] === $code) {
                    // Activate account
                    $upd = $conn->prepare("UPDATE USERS SET is_verified = 1, verification_code = NULL WHERE id = ?");
                    if ($upd) {
                        $upd->bind_param("i", $user['id']);
                        $upd->execute();
                        $upd->close();
                    }
                    unset($_SESSION['verify_email'], $_SESSION['verify_role'], $_SESSION['dev_otp']);
                    header("Location: login.php?verified=1");
                    exit();
                } else {
                    increment_rate_limit($ip, 'verify_attempt');
                    $error = "Invalid verification code. Please check your inbox and try again.";
                }
            } else {
                $error = "User account not found.";
            }
            $stmt->close();
        }
    }
}

// Handle resend OTP request
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["resend_otp"])) {
    $csrf = $_POST["csrf_token"] ?? "";
    if (!verify_csrf($csrf)) {
        $error = "Security validation failed. Please refresh.";
    } elseif (!check_rate_limit($ip, 'resend_attempt', 3, 600)) {
        $error = "Please wait a few minutes before requesting another code.";
    } elseif ($conn && !$conn->connect_error) {
        $new_otp = (string)random_int(100000, 999999);
        $stmt = $conn->prepare("UPDATE USERS SET verification_code = ? WHERE email = ? AND is_verified = 0");
        if ($stmt) {
            $stmt->bind_param("ss", $new_otp, $verify_email);
            $stmt->execute();
            $stmt->close();

            $subject = "PUCIT Portal - Your New Verification Code";
            $message = "Your new 6-digit verification code is: {$new_otp}\n\nRegards,\nPUCIT Administration";
            $headers = "From: " . SUPPORT_EMAIL . "\r\nReply-To: " . SUPPORT_EMAIL;
            @mail($verify_email, $subject, $message, $headers);

            $_SESSION['dev_otp'] = $new_otp;
            $dev_otp = $new_otp;
            $success = "A fresh 6-digit verification code has been dispatched to your email.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email — PUCIT Complaint Portal</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%230e2a47'><path d='M12 3L1 9L12 15L21 10.09V17H23V9M5 13.18V17.18C5 19.94 8.13 22 12 22C15.87 22 19 19.94 19 17.18V13.18L12 17L5 13.18Z'/></svg>">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>

<div class="auth-wrapper">
    <div class="auth-card">
        <!-- University Branding -->
        <div class="brand-header">
            <div class="brand-badge">
                <svg width="34" height="34" style="width: 34px; height: 34px; display: block;" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 3L1 9L12 15L21 10.09V17H23V9M5 13.18V17.18C5 19.94 8.13 22 12 22C15.87 22 19 19.94 19 17.18V13.18L12 17L5 13.18Z" />
                </svg>
            </div>
            <h1 class="brand-title">PUCIT Portal</h1>
            <p class="brand-subtitle">Punjab University College of Information Technology</p>
            
            <h2 class="auth-card-title">Verify Official Email</h2>
            <p class="auth-card-desc">
                We sent a 6-digit verification code to<br>
                <strong style="color: var(--navy);"><?= htmlspecialchars($verify_email) ?></strong>
            </p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <svg width="20" height="20" style="min-width: 20px;" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <svg width="20" height="20" style="min-width: 20px;" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <?php if (APP_ENV === 'development' && !empty($dev_otp)): ?>
            <div class="alert alert-info" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-size:13px; margin-bottom:18px;">
                💡 <strong>Dev Environment Notice:</strong> Since local email sending is in test mode, your 6-digit code is: <strong><?= htmlspecialchars($dev_otp) ?></strong>
            </div>
        <?php endif; ?>

        <!-- OTP Verification Form -->
        <form method="POST" action="verify.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            
            <div class="form-group">
                <label class="form-label" for="otp_code">6-Digit Verification Code</label>
                <div class="input-container">
                    <span class="input-icon">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </span>
                    <input 
                        type="text" 
                        name="otp_code" 
                        id="otp_code"
                        class="form-control" 
                        placeholder="e.g. 583921" 
                        maxlength="6"
                        pattern="\d{6}"
                        style="letter-spacing: 4px; font-weight: 700; font-size: 18px; text-align: center;"
                        required 
                        autofocus
                    >
                </div>
            </div>

            <button type="submit" name="verify_otp" class="btn btn-primary" style="margin-top: 10px;">
                Verify & Activate Account &rarr;
            </button>
        </form>

        <!-- Resend Code -->
        <form method="POST" action="verify.php" style="margin-top: 14px; text-align: center;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <button type="submit" name="resend_otp" style="background: none; border: none; color: var(--gold); font-weight: 600; cursor: pointer; text-decoration: underline; font-size: 13px;">
                Didn't receive the email? Resend code
            </button>
        </form>

        <div class="auth-footer" style="margin-top: 20px;">
            <p>Back to <a href="login.php">Sign In</a></p>
        </div>
    </div>
</div>

</body>
</html>