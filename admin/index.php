<?php
require_once __DIR__ . '/../db.php';

// Simple admin dashboard placeholder. In a real app this would list complaints, etc.

if (!is_logged_in() || ($_SESSION['user_role'] ?? '') !== 'admin') {
    // Not authorized – redirect to login or home
    header('Location: ../login.php');
    exit();
}

// Fetch overall stats for admin view
$stats = [];
if ($conn && !$conn->connect_error) {
    $res = $conn->query("SELECT COUNT(*) AS total, SUM(status = 'Pending') AS pending, SUM(status = 'In Progress') AS in_progress, SUM(status = 'Resolved') AS resolved FROM complaints");
    if ($res && $row = $res->fetch_assoc()) {
        $stats = $row;
    }
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
        <nav>
            <ul>
                <li><a href="index.php">Complaints</a></li>
                <li><a href="categories.php">Manage Categories</a></li>
                <li><a href="users.php">User List</a></li>
                <li><a href="../logout.php">Logout</a></li>
            </ul>
        </nav>
        <h3>Overall Complaint Stats</h3>
        <ul>
            <li>Total: <?= $stats['total'] ?? 0 ?></li>
            <li>Pending: <?= $stats['pending'] ?? 0 ?></li>
            <li>In Progress: <?= $stats['in_progress'] ?? 0 ?></li>
            <li>Resolved: <?= $stats['resolved'] ?? 0 ?></li>
        </ul>
        <p>This is a placeholder admin area. Implement detailed complaint management here.</p>
    </div>
</div>
</body>
</html>
