<?php
/**
 * Admin — Category Management (CRUD)
 * PUCIT Complaint Portal
 *
 * Allows admins to create, edit, toggle, and reorder complaint categories.
 */
require_once __DIR__ . '/../db.php';

if (!is_logged_in() || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$admin_name = $_SESSION['user_name'] ?? 'Admin';
$msg   = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn && !$conn->connect_error) {

    // CSRF verification
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Security token expired or invalid. Please refresh and try again.';
    }
    // ---- Create category ----
    elseif (isset($_POST['add_category'])) {
        $name = trim($_POST['cat_name'] ?? '');
        $desc = trim($_POST['cat_desc'] ?? '');
        $order = intval($_POST['cat_order'] ?? 0);

        if ($name === '') {
            $error = 'Category name is required.';
        } else {
            $stmt = $conn->prepare("INSERT INTO categories (name, description, sort_order) VALUES (?, ?, ?)");
            $stmt->bind_param('ssi', $name, $desc, $order);
            if ($stmt->execute()) {
                $msg = "Category \"{$name}\" created successfully.";
            } else {
                $error = 'Failed to create category: ' . htmlspecialchars($conn->error);
            }
            $stmt->close();
        }
    }

    // ---- Update category ----
    if (isset($_POST['edit_category'])) {
        $id    = intval($_POST['cat_id'] ?? 0);
        $name  = trim($_POST['cat_name'] ?? '');
        $desc  = trim($_POST['cat_desc'] ?? '');
        $order = intval($_POST['cat_order'] ?? 0);

        if ($id > 0 && $name !== '') {
            $stmt = $conn->prepare("UPDATE categories SET name = ?, description = ?, sort_order = ? WHERE id = ?");
            $stmt->bind_param('ssii', $name, $desc, $order, $id);
            if ($stmt->execute()) {
                $msg = "Category #{$id} updated.";
            } else {
                $error = 'Update failed: ' . htmlspecialchars($conn->error);
            }
            $stmt->close();
        }
    }

    // ---- Toggle active/inactive ----
    if (isset($_POST['toggle_category'])) {
        $id = intval($_POST['cat_id'] ?? 0);
        if ($id > 0) {
            $conn->query("UPDATE categories SET is_active = NOT is_active WHERE id = {$id}");
            $msg = "Category #{$id} toggled.";
        }
    }

    // ---- Delete category ----
    if (isset($_POST['delete_category'])) {
        $id = intval($_POST['cat_id'] ?? 0);
        if ($id > 0) {
            // Check if any complaints reference this category
            $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM complaints WHERE category_id = ?");
            $chk->bind_param('i', $id);
            $chk->execute();
            $chk->bind_result($cnt);
            $chk->fetch();
            $chk->close();

            if ($cnt > 0) {
                $error = "Cannot delete: {$cnt} complaint(s) are linked to this category. Deactivate it instead.";
            } else {
                $del = $conn->prepare("DELETE FROM categories WHERE id = ?");
                $del->bind_param('i', $id);
                $del->execute();
                $del->close();
                $msg = "Category #{$id} deleted.";
            }
        }
    }
}

// ---- Fetch all categories ----
$categories = [];
if ($conn && !$conn->connect_error) {
    $res = $conn->query("SELECT * FROM categories ORDER BY sort_order ASC, name ASC");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $categories[] = $r;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Categories — PUCIT Admin</title>
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
        .add-form {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 20px 24px; margin-bottom: 24px;
        }
        .add-form h3 { font-size: 16px; color: var(--primary); margin-bottom: 16px; }
        .form-row {
            display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
        }
        .form-row .form-group { margin-bottom: 0; flex: 1; min-width: 180px; }
        .form-row .form-group.small { flex: 0 0 100px; min-width: 80px; }
        .cat-actions { display: flex; gap: 6px; }
        .badge-active { background: #d1fae5; color: #065f46; }
        .badge-inactive { background: #fee2e2; color: #991b1b; }
        .edit-modal {
            display: none; position: fixed; inset: 0; z-index: 999;
            background: rgba(0,0,0,0.5); align-items: center; justify-content: center;
        }
        .edit-modal.open { display: flex; }
        .edit-card {
            background: var(--surface); border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl); max-width: 500px; width: 95%; padding: 28px 32px;
        }
        .edit-card h3 { margin-bottom: 16px; color: var(--primary); }
        .btn-close-modal {
            float: right; background: none; border: none; font-size: 24px;
            cursor: pointer; color: var(--text-muted); line-height: 1;
        }
        .btn-close-modal:hover { color: var(--text-primary); }
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
                    <a href="categories.php" class="active">Categories</a>
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

    <div class="dashboard-container" style="margin-top: 24px;">

        <?php if (!empty($msg)): ?>
            <div class="alert alert-success"><span><?= htmlspecialchars($msg) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><span><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <!-- Add New Category -->
        <div class="add-form">
            <h3>➕ Add New Category</h3>
            <form method="POST" action="categories.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="cat_name">Category Name</label>
                        <input type="text" name="cat_name" id="cat_name" class="form-control" style="padding-left:14px;" placeholder="e.g. Sports & Recreation" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="cat_desc">Description</label>
                        <input type="text" name="cat_desc" id="cat_desc" class="form-control" style="padding-left:14px;" placeholder="Brief description...">
                    </div>
                    <div class="form-group small">
                        <label class="form-label" for="cat_order">Order</label>
                        <input type="number" name="cat_order" id="cat_order" class="form-control" style="padding-left:14px;" value="0" min="0">
                    </div>
                    <div class="form-group small" style="flex: 0 0 auto;">
                        <button type="submit" name="add_category" class="btn btn-primary btn-sm">Add</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Categories List -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">
                    <span>📂 All Categories</span>
                </h3>
                <span style="font-size: 13px; color: var(--text-secondary);"><?= count($categories) ?> total</span>
            </div>
            <div class="panel-body" style="padding: 0;">
                <?php if (empty($categories)): ?>
                    <div class="empty-state">
                        <h4>No categories yet</h4>
                        <p style="font-size:13px; margin-top:4px;">Use the form above to add your first category.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="complaints-table">
                            <thead>
                                <tr>
                                    <th>#ID</th>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th>Order</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $cat): ?>
                                    <tr>
                                        <td><strong>#<?= $cat['id'] ?></strong></td>
                                        <td><?= htmlspecialchars($cat['name']) ?></td>
                                        <td><small style="color:var(--text-secondary);"><?= htmlspecialchars($cat['description'] ?? '') ?></small></td>
                                        <td><?= $cat['sort_order'] ?></td>
                                        <td>
                                            <?php if ($cat['is_active']): ?>
                                                <span class="badge badge-active">Active</span>
                                            <?php else: ?>
                                                <span class="badge badge-inactive">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><small style="color:var(--text-muted);"><?= date('M d, Y', strtotime($cat['created_at'])) ?></small></td>
                                        <td>
                                            <div class="cat-actions">
                                                <button type="button" class="btn btn-outline btn-sm" onclick="openEdit(<?= $cat['id'] ?>, '<?= htmlspecialchars(addslashes($cat['name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($cat['description'] ?? ''), ENT_QUOTES) ?>', <?= $cat['sort_order'] ?>)">Edit</button>
                                                <form method="POST" action="categories.php" style="margin:0;">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                                    <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                                                    <button type="submit" name="toggle_category" class="btn btn-outline btn-sm"><?= $cat['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                                                </form>
                                                <form method="POST" action="categories.php" style="margin:0;" onsubmit="return confirm('Delete category #<?= $cat['id'] ?>? This cannot be undone.');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                                    <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                                                    <button type="submit" name="delete_category" class="btn btn-outline btn-sm" style="color:var(--danger); border-color:var(--danger);">Delete</button>
                                                </form>
                                            </div>
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

    <!-- Edit Modal -->
    <div class="edit-modal" id="edit-modal">
        <div class="edit-card">
            <button class="btn-close-modal" onclick="closeEdit()">&times;</button>
            <h3>Edit Category</h3>
            <form method="POST" action="categories.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="cat_id" id="edit_cat_id">
                <div class="form-group">
                    <label class="form-label" for="edit_name">Name</label>
                    <input type="text" name="cat_name" id="edit_name" class="form-control" style="padding-left:14px;" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="edit_desc">Description</label>
                    <input type="text" name="cat_desc" id="edit_desc" class="form-control" style="padding-left:14px;">
                </div>
                <div class="form-group">
                    <label class="form-label" for="edit_order">Sort Order</label>
                    <input type="number" name="cat_order" id="edit_order" class="form-control" style="padding-left:14px;" min="0">
                </div>
                <button type="submit" name="edit_category" class="btn btn-primary btn-sm">Save Changes</button>
            </form>
        </div>
    </div>

<script>
function openEdit(id, name, desc, order) {
    document.getElementById('edit_cat_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_desc').value = desc;
    document.getElementById('edit_order').value = order;
    document.getElementById('edit-modal').classList.add('open');
}
function closeEdit() {
    document.getElementById('edit-modal').classList.remove('open');
}
document.getElementById('edit-modal').addEventListener('click', function(e) {
    if (e.target === this) closeEdit();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeEdit();
});
</script>

</body>
</html>
