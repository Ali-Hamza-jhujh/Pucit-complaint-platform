<?php
require_once __DIR__ . '/../db.php';

// Simple one‑time admin creation page. After creating an admin, delete this file.

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $c_pass   = $_POST['c_password'] ?? '';

    // Basic validation
    if (empty($name) || empty($email) || empty($password) || empty($c_pass)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif ($password !== $c_pass) {
        $error = 'Passwords do not match.';
    } else {
        // Check if user already exists
        $stmt = $conn->prepare('SELECT id FROM USERS WHERE email = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $error = 'User with this email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $conn->prepare('INSERT INTO USERS (name, email, password, role) VALUES (?, ?, ?, ?)');
                if ($ins) {
                    $role = 'admin';
                    $ins->bind_param('ssss', $name, $email, $hash, $role);
                    if ($ins->execute()) {
                        $success = 'Admin account created successfully. Delete or rename this setup file for security.';
                    } else {
                        $error = 'Database insert error: ' . htmlspecialchars($conn->error);
                    }
                    $ins->close();
                } else {
                    $error = 'Preparation error: ' . htmlspecialchars($conn->error);
                }
            }
            $stmt->close();
        } else {
            $error = 'Database error: ' . htmlspecialchars($conn->error);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Admin User – PUCIT Complaint Portal</title>
    <link rel="stylesheet" href="../style.css?v=<?= time() ?>">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card">
        <h2>Create Admin Account</h2>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><span><?= htmlspecialchars($error) ?></span></div>
        <?php elseif (!empty($success)): ?>
            <div class="alert alert-success"><span><?= htmlspecialchars($success) ?></span></div>
        <?php endif; ?>
        <form method="POST" action="setup.php">
            <div class="form-group">
                <label for="name">Full Name</label>
                <input type="text" name="name" id="name" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="email">Email (admin)</label>
                <input type="email" name="email" id="email" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="c_password">Confirm Password</label>
                <input type="password" name="c_password" id="c_password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Create Admin</button>
        </form>
        <p>After creating the admin, remove or rename this file to prevent further use.</p>
    </div>
</div>
</body>
</html>
