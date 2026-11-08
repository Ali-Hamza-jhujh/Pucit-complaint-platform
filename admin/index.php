<?php
/**
 * Admin Dashboard — PUCIT Complaint Portal
 *
 * Full admin panel with complaint management, status changes,
 * identity reveal, and admin action logging.
 */
require_once __DIR__ . '/../db.php';

// Enforce admin access
if (!is_logged_in() || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['user_name'] ?? 'Admin';

// ---- Handle POST actions ----
$action_msg   = '';
$action_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn && !$conn->connect_error) {

    // Verify CSRF token
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $action_error = 'Security token expired or invalid. Please refresh and try again.';
    } elseif (isset($_POST['update_status'])) {
        $complaint_id = intval($_POST['complaint_id'] ?? 0);
        $new_status   = trim($_POST['new_status'] ?? '');
        $notes        = trim($_POST['admin_notes'] ?? '');

        $valid_statuses = ['Pending', 'In Review', 'Resolved', 'Dismissed'];
        if ($complaint_id > 0 && in_array($new_status, $valid_statuses)) {
            // Get old status
            $old_stmt = $conn->prepare("SELECT status FROM complaints WHERE id = ? LIMIT 1");
            $old_stmt->bind_param('i', $complaint_id);
            $old_stmt->execute();
            $old_result = $old_stmt->get_result();
            $old_status = '';
            if ($old_row = $old_result->fetch_assoc()) {
                $old_status = $old_row['status'];
            }
            $old_stmt->close();

            // Update complaint
            $upd = $conn->prepare("UPDATE complaints SET status = ?, admin_notes = ? WHERE id = ?");
            $upd->bind_param('ssi', $new_status, $notes, $complaint_id);
            if ($upd->execute()) {
                // Log to admin_actions (immutable audit trail)
                $log = $conn->prepare("INSERT INTO admin_actions (complaint_id, admin_id, action_type, old_status, new_status, notes) VALUES (?, ?, 'status_change', ?, ?, ?)");
                $log->bind_param('iisss', $complaint_id, $admin_id, $old_status, $new_status, $notes);
                $log->execute();
                $log->close();

                $action_msg = "Complaint #{$complaint_id} updated to '{$new_status}' successfully.";
            } else {
                $action_error = "Failed to update complaint: " . htmlspecialchars($conn->error);
            }
            $upd->close();
        } else {
            $action_error = "Invalid complaint ID or status.";
        }
    }
}

// ---- Fetch overall stats ----
$stats = ['total' => 0, 'pending' => 0, 'in_review' => 0, 'resolved' => 0, 'dismissed' => 0];
if ($conn && !$conn->connect_error) {
    $res = $conn->query("SELECT
        COUNT(*) AS total,
        SUM(status = 'Pending') AS pending,
        SUM(status = 'In Review') AS in_review,
        SUM(status = 'Resolved') AS resolved,
        SUM(status = 'Dismissed') AS dismissed
        FROM complaints");
    if ($res && $row = $res->fetch_assoc()) {
        $stats = array_merge($stats, array_map('intval', $row));
    }
}

// ---- Filter & Fetch complaints ----
$filter_status   = trim($_GET['status'] ?? '');
$filter_category = trim($_GET['category'] ?? '');
$search_query    = trim($_GET['q'] ?? '');

$where_clauses = [];
$bind_types = '';
$bind_values = [];

if ($filter_status !== '') {
    $where_clauses[] = "c.status = ?";
    $bind_types .= 's';
    $bind_values[] = $filter_status;
}
if ($filter_category !== '') {
    $where_clauses[] = "c.category = ?";
    $bind_types .= 's';
    $bind_values[] = $filter_category;
}
if ($search_query !== '') {
    $where_clauses[] = "(c.title LIKE ? OR c.description LIKE ? OR c.id = ?)";
    $bind_types .= 'ssi';
    $like = "%{$search_query}%";
    $bind_values[] = $like;
    $bind_values[] = $like;
    $bind_values[] = intval($search_query);
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$sql = "SELECT c.*, u.name AS student_name, u.email AS student_email
        FROM complaints c
        LEFT JOIN USERS u ON c.user_id = u.id
        {$where_sql}
        ORDER BY c.id DESC
        LIMIT 50";

$complaints = [];
if ($conn && !$conn->connect_error) {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($bind_values)) {
            $stmt->bind_param($bind_types, ...$bind_values);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $complaints[] = $r;
        }
        $stmt->close();
    }
}

// Fetch categories for filter dropdown
$categories = [];
if ($conn && !$conn->connect_error) {
    $cat_res = $conn->query("SELECT DISTINCT category FROM complaints ORDER BY category ASC");
    if ($cat_res) {
        while ($cr = $cat_res->fetch_assoc()) {
            $categories[] = $cr['category'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — PUCIT Complaint Portal</title>
    <link rel="stylesheet" href="../style.css?v=<?= filemtime(__DIR__ . '/../style.css') ?>">
</head>
<body>

    <!-- Navigation Bar -->
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
                    <a href="index.php" class="active">Complaints</a>
                    <a href="categories.php">Categories</a>
                    <a href="users.php">Users</a>
                    <a href="logs.php">Audit Logs</a>
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

    <div class="dashboard-container">

        <!-- Stats Cards -->
        <div class="stats-grid" style="margin-top: 24px;">
            <div class="stat-card">
                <div class="stat-icon total">📋</div>
                <div>
                    <div class="stat-number"><?= $stats['total'] ?></div>
                    <div class="stat-title">Total Complaints</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon pending">⏳</div>
                <div>
                    <div class="stat-number"><?= $stats['pending'] ?></div>
                    <div class="stat-title">Pending</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon progress">🔍</div>
                <div>
                    <div class="stat-number"><?= $stats['in_review'] ?></div>
                    <div class="stat-title">In Review</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon resolved">✅</div>
                <div>
                    <div class="stat-number"><?= $stats['resolved'] ?></div>
                    <div class="stat-title">Resolved</div>
                </div>
            </div>
        </div>

        <!-- Action Messages -->
        <?php if (!empty($action_msg)): ?>
            <div class="alert alert-success"><span><?= htmlspecialchars($action_msg) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($action_error)): ?>
            <div class="alert alert-danger"><span><?= htmlspecialchars($action_error) ?></span></div>
        <?php endif; ?>

        <!-- Filters Bar -->
        <form method="GET" action="index.php" class="filters-bar">
            <strong style="font-size: 14px; color: var(--text-secondary);">Filter:</strong>
            <select name="status">
                <option value="">All Statuses</option>
                <option value="Pending"   <?= $filter_status === 'Pending'   ? 'selected' : '' ?>>Pending</option>
                <option value="In Review"  <?= $filter_status === 'In Review'  ? 'selected' : '' ?>>In Review</option>
                <option value="Resolved"   <?= $filter_status === 'Resolved'   ? 'selected' : '' ?>>Resolved</option>
                <option value="Dismissed"  <?= $filter_status === 'Dismissed'  ? 'selected' : '' ?>>Dismissed</option>
            </select>
            <select name="category">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= $filter_category === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="q" placeholder="Search by title or #ID..." value="<?= htmlspecialchars($search_query) ?>">
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
            <a href="index.php" class="btn btn-outline btn-sm">Clear</a>
        </form>

        <!-- Complaints Table -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">
                    <svg width="18" height="18" style="width:18px;height:18px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                    <span>All Complaints</span>
                </h3>
                <span style="font-size: 13px; color: var(--text-secondary);"><?= count($complaints) ?> results</span>
            </div>
            <div class="panel-body" style="padding: 0;">
                <?php if (empty($complaints)): ?>
                    <div class="empty-state">
                        <h4>No complaints found</h4>
                        <p style="font-size: 13px; margin-top: 4px;">Try adjusting your filters or search query.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="complaints-table">
                            <thead>
                                <tr>
                                    <th>#ID</th>
                                    <th>Subject</th>
                                    <th>Category</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Anonymous</th>
                                    <th>Filed By</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($complaints as $c): ?>
                                    <tr>
                                        <td><strong>#<?= $c['id'] ?></strong></td>
                                        <td><?= htmlspecialchars(mb_strimwidth($c['title'], 0, 50, '...')) ?></td>
                                        <td><small><?= htmlspecialchars($c['category']) ?></small></td>
                                        <td>
                                            <?php
                                                $prio = strtolower($c['priority'] ?? 'medium');
                                                $prio_class = 'badge-medium';
                                                if ($prio === 'high') $prio_class = 'badge-high';
                                                if ($prio === 'low') $prio_class = 'badge-low';
                                            ?>
                                            <span class="badge <?= $prio_class ?>"><?= htmlspecialchars($c['priority']) ?></span>
                                        </td>
                                        <td>
                                            <?php
                                                $sv = strtolower($c['status'] ?? 'pending');
                                                $bc = 'badge-pending';
                                                if ($sv === 'in review') $bc = 'badge-progress';
                                                if ($sv === 'resolved') $bc = 'badge-resolved';
                                                if ($sv === 'dismissed') $bc = 'badge-high';
                                            ?>
                                            <span class="badge <?= $bc ?>"><?= htmlspecialchars($c['status']) ?></span>
                                        </td>
                                        <td><?= $c['is_anonymous'] ? '🔒 Yes' : 'No' ?></td>
                                        <td>
                                            <small style="color: var(--text-secondary);">
                                                <?= htmlspecialchars($c['student_name'] ?? 'Unknown') ?>
                                            </small>
                                        </td>
                                        <td><small style="color: var(--text-muted);"><?= date('M d, Y', strtotime($c['created_at'])) ?></small></td>
                                        <td>
                                            <button type="button" class="btn btn-outline btn-sm" onclick="openDetail(<?= $c['id'] ?>)">View</button>
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

    <!-- Detail Modals (one per complaint) -->
    <?php foreach ($complaints as $c): ?>
    <div class="detail-modal" id="modal-<?= $c['id'] ?>">
        <div class="detail-card">
            <button class="btn-close-modal" onclick="closeDetail(<?= $c['id'] ?>)">&times;</button>
            <h3>Complaint #<?= $c['id'] ?> — <?= htmlspecialchars($c['title']) ?></h3>

            <div class="detail-row">
                <div class="detail-label">Category</div>
                <div class="detail-value"><?= htmlspecialchars($c['category']) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Priority</div>
                <div class="detail-value"><?= htmlspecialchars($c['priority']) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Current Status</div>
                <div class="detail-value">
                    <?php
                        $sv2 = strtolower($c['status'] ?? 'pending');
                        $bc2 = 'badge-pending';
                        if ($sv2 === 'in review') $bc2 = 'badge-progress';
                        if ($sv2 === 'resolved') $bc2 = 'badge-resolved';
                        if ($sv2 === 'dismissed') $bc2 = 'badge-high';
                    ?>
                    <span class="badge <?= $bc2 ?>"><?= htmlspecialchars($c['status']) ?></span>
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Description</div>
                <div class="detail-value"><?= nl2br(htmlspecialchars($c['description'])) ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Filed On</div>
                <div class="detail-value"><?= date('F d, Y — h:i A', strtotime($c['created_at'])) ?></div>
            </div>

            <?php if (!empty($c['image_path'])): ?>
            <div class="detail-row">
                <div class="detail-label">Attached Image</div>
                <img src="<?= htmlspecialchars('../' . ltrim($c['image_path'], '/')) ?>" alt="Complaint image" class="complaint-img">
            </div>
            <?php endif; ?>

            <?php if (!empty($c['admin_notes'])): ?>
            <div class="detail-row">
                <div class="detail-label">Admin Notes</div>
                <div class="detail-value"><?= nl2br(htmlspecialchars($c['admin_notes'])) ?></div>
            </div>
            <?php endif; ?>

            <!-- Identity Reveal (Admin Only) -->
            <div class="identity-reveal">
                <h4>🔓 Student Identity (Admin Eyes Only)</h4>
                <div class="detail-row">
                    <div class="detail-label">Full Name</div>
                    <div class="detail-value"><strong><?= htmlspecialchars($c['student_name'] ?? 'Unknown') ?></strong></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Email Address</div>
                    <div class="detail-value"><?= htmlspecialchars($c['student_email'] ?? 'N/A') ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">User ID</div>
                    <div class="detail-value">#<?= $c['user_id'] ?></div>
                </div>
                <?php if ($c['is_anonymous']): ?>
                    <small style="color: #b45309;">⚠ This complaint was filed anonymously. The student's identity is hidden from public view.</small>
                <?php endif; ?>
            </div>

            <!-- Status Update Form -->
            <form method="POST" action="index.php" class="action-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="complaint_id" value="<?= $c['id'] ?>">
                <div class="detail-label" style="margin-bottom: 8px;">Update Status</div>
                <select name="new_status">
                    <option value="Pending"    <?= $c['status'] === 'Pending'    ? 'selected' : '' ?>>Pending</option>
                    <option value="In Review"  <?= $c['status'] === 'In Review'  ? 'selected' : '' ?>>In Review</option>
                    <option value="Resolved"   <?= $c['status'] === 'Resolved'   ? 'selected' : '' ?>>Resolved</option>
                    <option value="Dismissed"  <?= $c['status'] === 'Dismissed'  ? 'selected' : '' ?>>Dismissed</option>
                </select>
                <div class="detail-label" style="margin-bottom: 8px;">Admin Notes</div>
                <textarea name="admin_notes" rows="3" placeholder="Add resolution notes, follow-up actions, etc."><?= htmlspecialchars($c['admin_notes'] ?? '') ?></textarea>
                <button type="submit" name="update_status" class="btn btn-primary btn-sm">Save Changes</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>

<script>
function openDetail(id) {
    document.getElementById('modal-' + id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeDetail(id) {
    document.getElementById('modal-' + id).classList.remove('open');
    document.body.style.overflow = '';
}
// Close on backdrop click
document.querySelectorAll('.detail-modal').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.classList.remove('open');
            document.body.style.overflow = '';
        }
    });
});
// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.detail-modal.open').forEach(function(m) {
            m.classList.remove('open');
        });
        document.body.style.overflow = '';
    }
});
</script>

</body>
</html>
