<?php
require_once __DIR__ . '/../db.php';

// Simple admin dashboard placeholder. In a real app this would list complaints, etc.

if (!is_logged_in() || ($_SESSION['user_role'] ?? '') !== 'admin') {
    // Not authorized – redirect to login or home
    header('Location: ../login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard – PUCIT Complaint Portal</title>
    <link rel="stylesheet" href="../style.css?v=<?= time() ?>">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card">
        <h2>Admin Dashboard</h2>
        <p>Welcome, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?>.</p>
        <p>This is a placeholder admin area. Implement complaint management features here.</p>
        <p><a href="../logout.php">Logout</a></p>
    </div>
</div>
</body>
</html>
