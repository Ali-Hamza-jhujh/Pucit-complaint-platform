<?php
/**
 * Student Profile & Password Management
 * PUCIT Complaint Portal
 *
 * Fulfills FR 1.3: Students can view account details and securely
 * update passwords, with official email kept read-only.
 */
require_once "db.php";
require_login();

$user_id    = (int)($_SESSION["user_id"] ?? 0);
$user_name  = $_SESSION["user_name"] ?? "Student";
$user_email = $_SESSION["user_email"] ?? "";
$user_role  = $_SESSION["user_role"] ?? "student";

$error   = "";
$success = "";

// Fetch current user details from DB
$user_data = null;
if ($conn && !$conn->connect_error) {
    $stmt = $conn->prepare("SELECT id, name, email, role, created_at FROM USERS WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $user_data = $res->fetch_assoc();
        $stmt->close();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrf = $_POST["csrf_token"] ?? "";

    if (!verify_csrf($csrf)) {
        $error = "Security token expired or invalid. Please refresh and try again.";
    } elseif (isset($_POST["update_password"])) {
        $current_pass = $_POST["current_password"] ?? "";
        $new_pass     = $_POST["new_password"] ?? "";
        $confirm_pass = $_POST["confirm_password"] ?? "";

        if (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
            $error = "Please fill in all password fields.";
        } elseif (strlen($new_pass) < 6) {
            $error = "New password must be at least 6 characters long.";
        } elseif ($new_pass !== $confirm_pass) {
            $error = "New password and confirmation do not match.";
        } elseif ($conn && !$conn->connect_error) {
            // Verify current password
            $pwd_stmt = $conn->prepare("SELECT password FROM USERS WHERE id = ? LIMIT 1");
            if ($pwd_stmt) {
                $pwd_stmt->bind_param("i", $user_id);
                $pwd_stmt->execute();
                $pwd_stmt->bind_result($db_hash);
                $pwd_stmt->fetch();
                $pwd_stmt->close();

                if (!password_verify($current_pass, $db_hash)) {
                    $error = "The current password you entered is incorrect.";
                } else {
                    $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
                    $upd_stmt = $conn->prepare("UPDATE USERS SET password = ? WHERE id = ?");
                    if ($upd_stmt) {
                        $upd_stmt->bind_param("si", $new_hash, $user_id);
                        if ($upd_stmt->execute()) {
                            $success = "Your password has been updated successfully.";
                        } else {
                            $error = "Failed to update password: " . htmlspecialchars($conn->error);
                        }
                        $upd_stmt->close();
                    }
                }
            }
        }
    } elseif (isset($_POST["update_name"])) {
        $new_name = trim($_POST["name"] ?? "");
        if (empty($new_name)) {
            $error = "Name cannot be empty.";
        } elseif ($conn && !$conn->connect_error) {
            $u_stmt = $conn->prepare("UPDATE USERS SET name = ? WHERE id = ?");
            if ($u_stmt) {
                $u_stmt->bind_param("si", $new_name, $user_id);
                if ($u_stmt->execute()) {
                    $_SESSION["user_name"] = $new_name;
                    $user_name = $new_name;
                    if ($user_data) $user_data["name"] = $new_name;
                    $success = "Profile name updated successfully.";
                } else {
                    $error = "Failed to update name: " . htmlspecialchars($conn->error);
                }
                $u_stmt->close();
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
    <title>My Profile — PUCIT Complaint Portal</title>
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
</head>
<body>

    <!-- Main Navigation Bar -->
    <nav class="navbar">
        <div class="nav-container">
            <a href="home.php" class="nav-brand">
                <div class="nav-logo-icon">
                    <svg width="24" height="24" style="width: 24px; height: 24px;" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 3L1 9L12 15L21 10.09V17H23V9M5 13.18V17.18C5 19.94 8.13 22 12 22C15.87 22 19 19.94 19 17.18V13.18L12 17L5 13.18Z" />
                    </svg>
                </div>
                <div>
                    <div class="nav-title-text">PUCIT Complaint Portal</div>
                    <div class="nav-subtitle-text">Student Grievance System</div>
                </div>
            </a>

            <div class="nav-actions">
                <a href="home.php" class="btn btn-outline btn-sm" style="color: #fff; border-color: rgba(255,255,255,0.3);">
                    Dashboard
                </a>
                <div class="user-profile-badge">
                    <div class="user-avatar"><?= strtoupper(substr($user_name, 0, 1)) ?></div>
                    <span><?= htmlspecialchars($user_name) ?></span>
                </div>
                <a href="logout.php" class="btn btn-logout" title="Sign out">
                    <svg width="15" height="15" style="width: 15px; height: 15px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="dashboard-container" style="max-width: 800px; margin-top: 30px;">

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <svg width="20" height="20" style="min-width: 20px;" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <svg width="20" height="20" style="min-width: 20px;" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Account Overview Panel -->
        <div class="panel" style="margin-bottom: 24px;">
            <div class="panel-header">
                <h3 class="panel-title">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    <span>Student Profile Details</span>
                </h3>
            </div>
            <div class="panel-body">
                <form method="POST" action="profile.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                    <div class="form-group">
                        <label class="form-label" for="email">Official University Email (Unchangeable)</label>
                        <input type="email" id="email" class="form-control" style="padding-left: 14px; background: var(--surface-muted); color: var(--text-muted); cursor: not-allowed;" value="<?= htmlspecialchars($user_data['email'] ?? $user_email) ?>" readonly>
                        <small style="color: var(--text-muted); font-size: 12px;">Official email is permanently bound to your university registration and cannot be modified.</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="name">Display Name</label>
                        <input type="text" name="name" id="name" class="form-control" style="padding-left: 14px;" value="<?= htmlspecialchars($user_data['name'] ?? $user_name) ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Member Since</label>
                        <div style="font-size: 14px; color: var(--text-secondary); padding: 8px 0;">
                            <?= isset($user_data['created_at']) ? date("F d, Y", strtotime($user_data['created_at'])) : "N/A" ?>
                        </div>
                    </div>

                    <button type="submit" name="update_name" class="btn btn-primary btn-sm">
                        <span>Save Name</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- Security & Password Change Panel -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    <span>Change Account Password</span>
                </h3>
            </div>
            <div class="panel-body">
                <form method="POST" action="profile.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                    <div class="form-group">
                        <label class="form-label" for="current_password">Current Password</label>
                        <input type="password" name="current_password" id="current_password" class="form-control" style="padding-left: 14px;" placeholder="Enter current password" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="new_password">New Password</label>
                        <input type="password" name="new_password" id="new_password" class="form-control" style="padding-left: 14px;" placeholder="Minimum 6 characters" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm New Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" style="padding-left: 14px;" placeholder="Re-enter new password" required>
                    </div>

                    <button type="submit" name="update_password" class="btn btn-primary btn-sm">
                        <span>Update Password</span>
                    </button>
                </form>
            </div>
        </div>

        <div class="site-copy" style="margin-top: 40px; margin-bottom: 20px;">
            &copy; <?= date("Y") ?> PUCIT (Punjab University College of Information Technology) — Student Grievance Redressal System.
        </div>
    </div>

</body>
</html>
