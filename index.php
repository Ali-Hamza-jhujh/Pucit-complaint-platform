<?php
require_once "db.php";

// If already logged in, redirect to home page
if (is_logged_in()) {
    header("Location: home.php");
    exit();
}

$error = "";
$success = "";
$name = "";
$email = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $pass = $_POST["password"] ?? "";
    $c_pass = $_POST["c_password"] ?? "";

    // Validation
    if (empty($name) || empty($email) || empty($pass) || empty($c_pass)) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($pass) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($pass !== $c_pass) {
        $error = "Password and Confirm Password do not match.";
    } elseif ($conn->connect_error) {
        $error = "Database connection error. Please ensure MySQL is active.";
    } else {
        // Check if email already exists
        $check_stmt = $conn->prepare("SELECT id FROM USERS WHERE email = ? LIMIT 1");
        if ($check_stmt) {
            $check_stmt->bind_param("s", $email);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $error = "An account with this email already exists. <a href='login.php'>Login here</a>.";
            } else {
                // Hash password securely
                $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
                $insert_stmt = $conn->prepare("INSERT INTO USERS (name, email, password) VALUES (?, ?, ?)");
                
                if ($insert_stmt) {
                    $insert_stmt->bind_param("sss", $name, $email, $hashed_pass);
                    if ($insert_stmt->execute()) {
                        // Automatically log in the user and redirect to home.php
                        $_SESSION["user_id"] = $insert_stmt->insert_id;
                        $_SESSION["user_name"] = $name;
                        $_SESSION["user_email"] = $email;
                        
                        header("Location: home.php?welcome=1");
                        exit();
                    } else {
                        $error = "Failed to create account: " . htmlspecialchars($conn->error);
                    }
                    $insert_stmt->close();
                } else {
                    $error = "Database statement error: " . htmlspecialchars($conn->error);
                }
            }
            $check_stmt->close();
        } else {
            $error = "Database error: " . htmlspecialchars($conn->error);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PUCIT Complaint Portal - Student Registration</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="auth-wrapper">
    <div class="auth-card">
        <!-- University Branding -->
        <div class="brand-header">
            <div class="brand-badge">
                <!-- Academic Graduation / College Shield SVG Icon -->
                <svg fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 3L1 9L12 15L21 10.09V17H23V9M5 13.18V17.18C5 19.94 8.13 22 12 22C15.87 22 19 19.94 19 17.18V13.18L12 17L5 13.18Z" />
                </svg>
            </div>
            <h1 class="brand-title">PUCIT Portal</h1>
            <p class="brand-subtitle">Punjab University College of Information Technology</p>
            
            <h2 class="auth-card-title">Create Account</h2>
            <p class="auth-card-desc">Register to lodge complaints and track grievance resolutions</p>
        </div>

        <!-- Alert Notification -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <span><?= $error ?></span>
            </div>
        <?php endif; ?>

        <!-- Signup Form -->
        <form method="POST" action="index.php" autocomplete="on">
            <!-- Full Name -->
            <div class="form-group">
                <label class="form-label" for="name">Full Name</label>
                <div class="input-container">
                    <span class="input-icon">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </span>
                    <input 
                        type="text" 
                        name="name" 
                        id="name"
                        class="form-control" 
                        placeholder="e.g. Ali Hamza" 
                        value="<?= htmlspecialchars($name) ?>" 
                        required
                    >
                </div>
            </div>

            <!-- Email Address -->
            <div class="form-group">
                <label class="form-label" for="email">Student / College Email</label>
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
                        placeholder="Minimum 6 characters" 
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

            <!-- Confirm Password -->
            <div class="form-group">
                <label class="form-label" for="c_password">Confirm Password</label>
                <div class="input-container">
                    <span class="input-icon">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </span>
                    <input 
                        type="password" 
                        name="c_password" 
                        id="c_password"
                        class="form-control" 
                        placeholder="Re-enter your password" 
                        required
                    >
                    <button type="button" class="toggle-password" onclick="togglePassVisibility('c_password', this)" title="Show/Hide Password">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn btn-primary btn-block">
                <span>Create Student Account</span>
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
            </button>
        </form>

        <!-- Switch to Login -->
        <div class="auth-footer">
            Already have an account? <a href="login.php">Sign In here</a>
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
