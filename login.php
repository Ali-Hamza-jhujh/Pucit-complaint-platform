<?php
require_once "db.php";

// If already logged in, redirect straight to home page
if (is_logged_in()) {
    header("Location: home.php");
    exit();
}

$error = "";
$success = "";
$email = "";

if (isset($_GET["registered"])) {
    $success = "Registration successful! You can now log in with your credentials.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($conn->connect_error) {
        $error = "Database connection error. Please ensure MySQL is active.";
    } else {
        // Prepare secure statement to look up user
        $stmt = $conn->prepare("SELECT id, name, email, password FROM USERS WHERE email = ? LIMIT 1");
        
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
                    // Set session variables
                    $_SESSION["user_id"] = $user["id"];
                    $_SESSION["user_name"] = $user["name"];
                    $_SESSION["user_email"] = $user["email"];

                    // Redirect to home page
                    header("Location: home.php");
                    exit();
                } else {
                    $error = "Invalid email or password. Please try again.";
                }
            } else {
                $error = "No student account found with this email address.";
            }
            $stmt->close();
        } else {
            $error = "Query preparation error: " . htmlspecialchars($conn->error);
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
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="auth-wrapper">
    <div class="auth-card">
        <!-- University Branding -->
        <div class="brand-header">
            <div class="brand-badge">
                <svg fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 3L1 9L12 15L21 10.09V17H23V9M5 13.18V17.18C5 19.94 8.13 22 12 22C15.87 22 19 19.94 19 17.18V13.18L12 17L5 13.18Z" />
                </svg>
            </div>
            <h1 class="brand-title">PUCIT Portal</h1>
            <p class="brand-subtitle">Punjab University College of Information Technology</p>
            
            <h2 class="auth-card-title">Welcome Back</h2>
            <p class="auth-card-desc">Sign in with your student credentials to proceed</p>
        </div>

        <!-- Success Alert -->
        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <!-- Error Alert -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <span><?= $error ?></span>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="login.php">
            <!-- Email -->
            <div class="form-group">
                <label class="form-label" for="email">Student / PUCIT Email</label>
                <div class="input-container">
                    <span class="input-icon">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
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
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
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
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
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
                <a href="#" onclick="alert('Please contact PUCIT IT Helpdesk at support@pucit.edu.pk to reset your password.'); return false;">Forgot password?</a>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn btn-primary btn-block">
                <span>Sign In to Portal</span>
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
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
</script>

</body>
</html>
