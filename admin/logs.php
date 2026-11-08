<?php
/**
 * Admin — Immutable Audit Logs
 * PUCIT Complaint Portal
 *
 * Fulfills FR 3.2 & NFR 3.1: Displays an unalterable history of all administrative
 * actions performed on complaint tickets for transparency and accountability.
 */
require_once __DIR__ . '/../db.php';

if (!is_logged_in() || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['user_name'] ?? 'Admin';

// Filters
$filter_complaint = intval($_GET['complaint_id'] ?? 0);
$filter_admin     = intval($_GET['admin_id'] ?? 0);

$where = [];
$bind_types = '';
$bind_params = [];

if ($filter_complaint > 0) {
    $where[] = "a.complaint_id = ?";
    $bind_types .= 'i';
    $bind_params[] = $filter_complaint;
}

if ($filter_admin > 0) {
    $where[] = "a.admin_id = ?";
    $bind_types .= 'i';
    $bind_params[] = $filter_admin;
}

$where_clause = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

$logs = [];
if ($conn && !$conn->connect_error) {
    $sql = "SELECT a.*, u.name AS admin_name, u.email AS admin_email, c.title AS complaint_title
            FROM admin_actions a
            LEFT JOIN USERS u ON a.admin_id = u.id
            LEFT JOIN complaints c ON a.complaint_id = c.id
            {$where_clause}
            ORDER BY a.id DESC
            LIMIT 100";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($bind_params)) {
            $stmt->bind_param($bind_types, ...$bind_params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $logs[] = $r;
        }
        $stmt->close();
    }
}

// Fetch list of admins for filter dropdown
$admins_list = [];
if ($conn && !$conn->connect_error) {
    $a_res = $conn->query("SELECT id, name FROM USERS WHERE role = 'admin' ORDER BY name ASC");
    if ($a_res) {
        while ($ar = $a_res->fetch_assoc()) {
            $admins_list[] = $ar;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs — PUCIT Admin</title>
    <link rel="stylesheet" href="../style.css?v=<?= time() ?>">
    <style>
        <?= file_get_contents(__DIR__ . '/../style.css') ?>
        .admin-nav-links { display: flex; gap: 6px; }
        .admin-nav-links a {
            color: #cbd5e1; font-size: 13px; padding: 6px 14px;
            border-radius: var(--radius-full); transition: var(--transition);
        }
        .admin-nav-links a:hover, .admin-nav-links a.active {
            background: rgba(255,255,255,0.15); color: #fff; text-decoration: none;
        }
        .filters-bar {
            display: flex; flex-wrap: wrap; gap: 12px; align-items: center;
            margin-bottom: 20px; padding: 16px 20px;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-md);
        }
        .filters-bar select, .filters-bar input[type="number"] {
            padding: 8px 12px; font-size: 13px; border: 1.5px solid var(--border);
            border-radius: var(--radius-sm); font-family: inherit; min-width: 160px;
        }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="nav-container">
            <a href="index.php" class="nav-brand">
                <div class="nav-logo-icon">
                    <svg width="24" height="24" style="width:24px;height:24px;" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 3L1 9L12 15L21 10.09V17H23V9M5 13.18V17.18C5 19.94 8.13 22 12 22C15.87 22 19 19.94 19 17.18V13.18L12 17L5 13.18Z" />
                    </svg>
                </div>
                <div>
                    <div class="nav-title-text">PUCIT Admin Portal</div>
                    <div class="nav-subtitle-text">Complaint Management System</div>
                </div>
            </a>
            <div class="nav-actions">
                <div class="admin-nav-links">
                    <a href="index.php">Complaints</a>
                    <a href="categories.php">Categories</a>
                    <a href="users.php">Users</a>
                    <a href="logs.php" class="active">Audit Logs</a>
                </div>
                <div class="user-profile-badge">
                    <div class="user-avatar"><?= strtoupper(substr($admin_name, 0, 1)) ?></div>
                    <span><?= htmlspecialchars($admin_name) ?></span>
                </div>
                <a href="../logout.php" class="btn btn-logout" title="Sign out">
                    <svg width="15" height="15" style="width:15px;height:15px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="dashboard-container" style="margin-top: 24px;">

        <div style="margin-bottom: 20px;">
            <h2>📜 System Audit Trail</h2>
            <p style="color: var(--text-secondary); font-size: 14px;">
                Immutable history of administrative actions, status transitions, and resolution comments.
            </p>
        </div>

        <!-- Filter Bar -->
        <form method="GET" action="logs.php" class="filters-bar">
            <strong style="font-size: 14px; color: var(--text-secondary);">Filter:</strong>
            <select name="admin_id">
                <option value="0">All Administrators</option>
                <?php foreach ($admins_list as $a): ?>
                    <option value="<?= $a['id'] ?>" <?= $filter_admin === (int)$a['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="complaint_id" placeholder="Complaint #ID..." value="<?= $filter_complaint > 0 ? $filter_complaint : '' ?>">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <a href="logs.php" class="btn btn-outline btn-sm">Clear</a>
        </form>

        <!-- Audit Log Table -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">
                    <span>Audit Entries</span>
                </h3>
                <span style="font-size: 13px; color: var(--text-secondary);"><?= count($logs) ?> recorded events</span>
            </div>
            <div class="panel-body" style="padding: 0;">
                <?php if (empty($logs)): ?>
                    <div class="empty-state">
                        <h4>No audit entries found</h4>
                        <p style="font-size: 13px; margin-top: 4px;">Administrative status modifications will automatically appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="complaints-table">
                            <thead>
                                <tr>
                                    <th>Log #</th>
                                    <th>Complaint</th>
                                    <th>Administrator</th>
                                    <th>Action Type</th>
                                    <th>Previous Status</th>
                                    <th>New Status</th>
                                    <th>Notes / Resolution</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $l): ?>
                                    <tr>
                                        <td><strong>#<?= $l['id'] ?></strong></td>
                                        <td>
                                            <a href="index.php?q=<?= $l['complaint_id'] ?>">
                                                #<?= $l['complaint_id'] ?> — <?= htmlspecialchars(mb_strimwidth($l['complaint_title'] ?? 'Ticket', 0, 30, '...')) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($l['admin_name'] ?? 'Admin #' . $l['admin_id']) ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge badge-low"><?= htmlspecialchars($l['action_type']) ?></span>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($l['old_status'] ?? 'None') ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($l['new_status'] ?? 'N/A') ?></strong>
                                        </td>
                                        <td>
                                            <small style="color: var(--text-secondary);">
                                                <?= htmlspecialchars($l['notes'] ?: 'No notes attached') ?>
                                            </small>
                                        </td>
                                        <td>
                                            <small style="color: var(--text-muted);">
                                                <?= date('M d, Y h:i A', strtotime($l['created_at'])) ?>
                                            </small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="site-copy" style="margin-top: 40px; margin-bottom: 20px;">
            &copy; <?= date("Y") ?> PUCIT — Admin Portal
        </div>
    </div>

</body>
</html>
