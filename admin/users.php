<?php
/**
 * Admin — User Management
 * PUCIT Complaint Portal
 *
 * Allows administrators to view students and admins, search by name/email,
 * and toggle account active status.
 */
require_once __DIR__ . '/../db.php';

if (!is_logged_in() || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['user_name'] ?? 'Admin';
$msg   = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn && !$conn->connect_error) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = "Security token expired or invalid. Please refresh and try again.";
    }
    // Toggle active / suspended status
    elseif (isset($_POST['toggle_status'])) {
        $target_id = intval($_POST['user_id'] ?? 0);
        if ($target_id === $admin_id) {
            $error = "You cannot deactivate your own administrative account.";
        } elseif ($target_id > 0) {
            $stmt = $conn->prepare("UPDATE USERS SET is_active = NOT is_active WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $target_id);
                if ($stmt->execute()) {
                    $msg = "User account status updated.";
                } else {
                    $error = "Failed to update status: " . htmlspecialchars($conn->error);
                }
                $stmt->close();
            }
        }
    }
    // Reset user password
    elseif (isset($_POST['reset_user_password'])) {
        $target_id  = intval($_POST['user_id'] ?? 0);
        $temp_pass  = trim($_POST['temp_password'] ?? '');

        if ($target_id <= 0 || strlen($temp_pass) < 6) {
            $error = "Temporary password must be at least 6 characters long.";
        } else {
            $hashed = password_hash($temp_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE USERS SET password = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $hashed, $target_id);
                if ($stmt->execute()) {
                    $msg = "Password reset successfully for User #{$target_id}. New temporary password set.";
                } else {
                    $error = "Failed to reset password: " . htmlspecialchars($conn->error);
                }
                $stmt->close();
            }
        }
    }
    // Change user role
    elseif (isset($_POST['change_user_role'])) {
        $target_id = intval($_POST['user_id'] ?? 0);
        $new_role  = trim($_POST['new_role'] ?? '');
        if ($target_id === $admin_id) {
            $error = "You cannot modify your own administrative role.";
        } elseif ($target_id > 0 && in_array($new_role, ['student', 'teacher', 'admin'])) {
            $stmt = $conn->prepare("UPDATE USERS SET role = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $new_role, $target_id);
                if ($stmt->execute()) {
                    $msg = "Role updated to " . ucfirst($new_role) . " for User #{$target_id}.";
                } else {
                    $error = "Failed to update role: " . htmlspecialchars($conn->error);
                }
                $stmt->close();
            }
        }
    }
}

// Stats
$user_stats = ['total' => 0, 'students' => 0, 'teachers' => 0, 'admins' => 0];
if ($conn && !$conn->connect_error) {
    $s_res = $conn->query("SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN COALESCE(role, 'student') = 'student' THEN 1 ELSE 0 END) AS students,
        SUM(CASE WHEN role = 'teacher' THEN 1 ELSE 0 END) AS teachers,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS admins
        FROM USERS");
    if ($s_res && $row = $s_res->fetch_assoc()) {
        $user_stats = array_merge($user_stats, array_map('intval', $row));
    }
}

// Filters
$role_filter = trim($_GET['role'] ?? '');
$search = trim($_GET['q'] ?? '');

$where = [];
$bind_types = '';
$bind_params = [];

if ($role_filter === 'student') {
    $where[] = "COALESCE(role, 'student') = 'student'";
} elseif ($role_filter === 'teacher') {
    $where[] = "role = 'teacher'";
} elseif ($role_filter === 'admin') {
    $where[] = "role = 'admin'";
}

if ($search !== '') {
    $where[] = "(name LIKE ? OR email LIKE ?)";
    $bind_types .= 'ss';
    $like = "%{$search}%";
    $bind_params[] = $like;
    $bind_params[] = $like;
}

$where_clause = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

$users = [];
if ($conn && !$conn->connect_error) {
    $sql = "SELECT id, name, email, role, is_active, created_at FROM USERS {$where_clause} ORDER BY id DESC LIMIT 100";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($bind_params)) {
            $stmt->bind_param($bind_types, ...$bind_params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $users[] = $r;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users — PUCIT Admin</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%230e2a47'><path d='M12 3L1 9L12 15L21 10.09V17H23V9M5 13.18V17.18C5 19.94 8.13 22 12 22C15.87 22 19 19.94 19 17.18V13.18L12 17L5 13.18Z'/></svg>">
    <link rel="stylesheet" href="../style.css?v=<?= filemtime(__DIR__ . '/../style.css') ?>">
    <style>
        .badge-active { background: #d1fae5; color: #065f46; }
        .badge-inactive { background: #fee2e2; color: #991b1b; }
        .badge-role-admin { background: #ede9fe; color: #6b21a8; font-weight: 700; }
        .badge-role-teacher { background: #fef3c7; color: #92400e; font-weight: 700; }
        .badge-role-student { background: #e0f2fe; color: #0369a1; }
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
                    <a href="users.php" class="active">Users</a>
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

    <div class="dashboard-container" style="margin-top: 24px;">

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total">👥</div>
                <div>
                    <div class="stat-number"><?= $user_stats['total'] ?></div>
                    <div class="stat-title">Total Users</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon progress">🎓</div>
                <div>
                    <div class="stat-number"><?= $user_stats['students'] ?></div>
                    <div class="stat-title">Students</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon pending" style="background:#fef3c7; color:#92400e;">👨‍🏫</div>
                <div>
                    <div class="stat-number"><?= $user_stats['teachers'] ?></div>
                    <div class="stat-title">Teachers / Faculty</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon pending">🛡️</div>
                <div>
                    <div class="stat-number"><?= $user_stats['admins'] ?></div>
                    <div class="stat-title">Admins</div>
                </div>
            </div>
        </div>

        <?php if (!empty($msg)): ?>
            <div class="alert alert-success"><span><?= htmlspecialchars($msg) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><span><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <!-- Filters -->
        <form method="GET" action="users.php" class="filters-bar">
            <strong style="font-size: 14px; color: var(--text-secondary);">Filter:</strong>
            <select name="role">
                <option value="">All Roles</option>
                <option value="student" <?= $role_filter === 'student' ? 'selected' : '' ?>>Students</option>
                <option value="teacher" <?= $role_filter === 'teacher' ? 'selected' : '' ?>>Teachers</option>
                <option value="admin"   <?= $role_filter === 'admin'   ? 'selected' : '' ?>>Admins</option>
            </select>
            <input type="text" name="q" placeholder="Search by name or email..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <a href="users.php" class="btn btn-outline btn-sm">Clear</a>
        </form>

        <!-- User Table -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">
                    <span>👤 Registered Users</span>
                </h3>
                <span style="font-size: 13px; color: var(--text-secondary);"><?= count($users) ?> entries</span>
            </div>
            <div class="panel-body" style="padding: 0;">
                <?php if (empty($users)): ?>
                    <div class="empty-state">
                        <h4>No users found</h4>
                        <p style="font-size: 13px; margin-top: 4px;">Try adjusting your search criteria.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="complaints-table">
                            <thead>
                                <tr>
                                    <th>#ID</th>
                                    <th>Name</th>
                                    <th>Official Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Joined On</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td><strong>#<?= $u['id'] ?></strong></td>
                                        <td><?= htmlspecialchars($u['name']) ?></td>
                                        <td><?= htmlspecialchars($u['email']) ?></td>
                                        <td>
                                            <?php if ($u['role'] === 'admin'): ?>
                                                <span class="badge badge-role-admin">Admin</span>
                                            <?php elseif ($u['role'] === 'teacher'): ?>
                                                <span class="badge badge-role-teacher">Teacher</span>
                                            <?php else: ?>
                                                <span class="badge badge-role-student">Student</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($u['is_active']): ?>
                                                <span class="badge badge-active">Active</span>
                                            <?php else: ?>
                                                <span class="badge badge-inactive">Suspended</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><small style="color: var(--text-muted);"><?= date('M d, Y', strtotime($u['created_at'])) ?></small></td>
                                        <td>
                                            <?php if ($u['id'] !== $admin_id): ?>
                                                <div style="display: flex; gap: 6px; align-items: center;">
                                                    <form method="POST" action="users.php" style="margin: 0;">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                        <input type="hidden" name="change_user_role" value="1">
                                                        <select name="new_role" onchange="if(confirm('Change role to ' + this.value + '?')) this.form.submit(); else this.value='<?= $u['role'] ?>';" style="padding: 4px 6px; font-size: 11px; border-radius: 4px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-primary); cursor: pointer;" title="Change account role">
                                                            <option value="student" <?= $u['role'] === 'student' ? 'selected' : '' ?>>Student</option>
                                                            <option value="teacher" <?= $u['role'] === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                                                            <option value="admin"   <?= $u['role'] === 'admin'   ? 'selected' : '' ?>>Admin</option>
                                                        </select>
                                                    </form>
                                                    <form method="POST" action="users.php" style="margin: 0;">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                        <button type="submit" name="toggle_status" class="btn btn-outline btn-sm">
                                                            <?= $u['is_active'] ? 'Suspend' : 'Activate' ?>
                                                        </button>
                                                    </form>
                                                    <button type="button" class="btn btn-outline btn-sm" onclick="openResetModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($u['email']), ENT_QUOTES) ?>')">
                                                        Reset Password
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <small style="color: var(--text-muted);">Current User</small>
                                            <?php endif; ?>
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

    <!-- Admin Password Reset Modal -->
    <div class="detail-modal" id="reset-modal">
        <div class="detail-card" style="max-width: 480px;">
            <button class="btn-close-modal" onclick="closeResetModal()">&times;</button>
            <h3>🔑 Reset User Password</h3>
            <p style="font-size: 13.5px; color: var(--text-secondary); margin-bottom: 16px;">
                Set a new temporary password for <strong id="reset_user_info"></strong>.
            </p>

            <form method="POST" action="users.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="user_id" id="reset_user_id">

                <div class="form-group">
                    <label class="form-label" for="temp_password">New Temporary Password</label>
                    <div style="display: flex; gap: 8px;">
                        <input type="text" name="temp_password" id="temp_password" class="form-control" style="padding-left: 14px;" placeholder="Minimum 6 characters" required>
                        <button type="button" class="btn btn-outline btn-sm" onclick="generateTempPass()" style="white-space: nowrap;">
                            Generate
                        </button>
                    </div>
                    <small style="color: var(--text-muted); font-size: 12px; display: block; margin-top: 4px;">
                        Provide this password to the student. They can log in and update it on their Profile.
                    </small>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="closeResetModal()">Cancel</button>
                    <button type="submit" name="reset_user_password" class="btn btn-primary btn-sm">Save New Password</button>
                </div>
            </form>
        </div>
    </div>

<script>
function openResetModal(userId, userName, userEmail) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_user_info').textContent = userName + ' (' + userEmail + ')';
    document.getElementById('temp_password').value = '';
    document.getElementById('reset-modal').classList.add('open');
}

function closeResetModal() {
    document.getElementById('reset-modal').classList.remove('open');
}

function generateTempPass() {
    var chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$';
    var pass = '';
    for (var i = 0; i < 10; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('temp_password').value = pass;
}

document.getElementById('reset-modal').addEventListener('click', function(e) {
    if (e.target === this) closeResetModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeResetModal();
});
</script>

</body>
</html>
