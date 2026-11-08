<?php
// db.php handles: config, hardened session, DB connection, schema, auth helpers
require_once "db.php";

// Ensure user is authenticated
require_login();

$user_id = $_SESSION["user_id"] ?? 0;
$user_name = $_SESSION["user_name"] ?? "Student";
$user_email = $_SESSION["user_email"] ?? "";

$complaint_msg = "";
$complaint_error = "";

// Flash message on successful submission (Post/Redirect/Get pattern)
if (isset($_GET['submitted'])) {
    $sub_id = intval($_GET['submitted']);
    $complaint_msg = $_SESSION['flash_complaint_msg'] ?? "Your complaint #{$sub_id} has been submitted successfully to PUCIT Administration.";
    unset($_SESSION['flash_complaint_msg']);
}

// Handle new complaint submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["submit_complaint"])) {
    $csrf = $_POST["csrf_token"] ?? "";
    $title = trim($_POST["title"] ?? "");
    $category = trim($_POST["category"] ?? "");
    $priority = trim($_POST["priority"] ?? "Medium");
    $description = trim($_POST["description"] ?? "");

    if (!verify_csrf($csrf)) {
        $complaint_error = "Security validation failed. Please refresh the page and try again.";
    } elseif (empty($title) || empty($category) || empty($description)) {
        $complaint_error = "Please fill in all complaint details.";
    } elseif ($conn && !$conn->connect_error) {
            // Handle optional image upload
            $image_path = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES['image']['tmp_name'];
                $orig_name = basename($_FILES['image']['name']);
                $file_size = $_FILES['image']['size'];
                $mime_type = mime_content_type($tmp_name);
                $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

                if (!in_array($mime_type, ALLOWED_MIME_TYPES) || !in_array($ext, ALLOWED_EXTENSIONS)) {
                    $complaint_error = "Invalid image type. Allowed: jpg, jpeg, png, webp.";
                } elseif ($file_size > UPLOAD_MAX_SIZE) {
                    $complaint_error = "Image exceeds maximum size of " . (UPLOAD_MAX_SIZE/1024/1024) . " MB.";
                } else {
                    $new_name = bin2hex(random_bytes(16)) . "." . $ext;
                    $dest = UPLOAD_DIR . $new_name;
                    if (move_uploaded_file($tmp_name, $dest)) {
                        $image_path = UPLOAD_WEB_PATH . $new_name;
                    } else {
                        $complaint_error = "Failed to store uploaded image.";
                    }
                }
            }

            // Determine anonymity flag
            $isAnonymous = isset($_POST['anonymous']) ? 1 : 0;

            if (empty($complaint_error)) {
                // Lookup category ID if matching category exists
                $category_id = null;
                $cat_stmt = $conn->prepare("SELECT id FROM categories WHERE name = ? LIMIT 1");
                if ($cat_stmt) {
                    $cat_stmt->bind_param("s", $category);
                    $cat_stmt->execute();
                    $cat_stmt->bind_result($found_cid);
                    if ($cat_stmt->fetch()) {
                        $category_id = $found_cid;
                    }
                    $cat_stmt->close();
                }

                $ins = $conn->prepare("INSERT INTO complaints (user_id, user_name, title, category_id, category, description, priority, status, image_path, is_anonymous) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?)");
                if ($ins) {
                    $ins->bind_param("ississssi", $user_id, $user_name, $title, $category_id, $category, $description, $priority, $image_path, $isAnonymous);
                    if ($ins->execute()) {
                        $complaint_id = $ins->insert_id;
                        $complaint_msg = "Your complaint #{$complaint_id} has been submitted successfully to PUCIT Administration.";

                        // Also store attachment metadata if an image was uploaded
                        if ($image_path && isset($orig_name, $file_size, $mime_type)) {
                            $img_stmt = $conn->prepare("INSERT INTO complaint_images (complaint_id, file_path, original_name, file_size, mime_type) VALUES (?, ?, ?, ?, ?)");
                            if ($img_stmt) {
                                $img_stmt->bind_param("issis", $complaint_id, $image_path, $orig_name, $file_size, $mime_type);
                                $img_stmt->execute();
                                $img_stmt->close();
                            }
                        }

                        // Dispatch email alert to Administration
                        $admin_sub = "PUCIT Portal - New Complaint Lodged (#{$complaint_id})";
                        $admin_msg = "A new complaint has been lodged on the PUCIT Complaint Portal.\n\n"
                                   . "Ticket ID: #{$complaint_id}\n"
                                   . "Subject: {$title}\n"
                                   . "Category: {$category}\n"
                                   . "Priority: {$priority}\n"
                                   . "Submitted By: " . ($isAnonymous ? "Anonymous (Identity Protected)" : $user_name . " ({$user_email})") . "\n\n"
                                   . "Description:\n{$description}\n\n"
                                   . "Review on Admin Panel: http://dev.local/admin/index.php?q={$complaint_id}";
                        send_system_email(SUPPORT_EMAIL, $admin_sub, $admin_msg);

                        // Confirmation email to complainant
                        if (!empty($user_email)) {
                            $user_sub = "PUCIT Portal - Complaint Received (Ticket #{$complaint_id})";
                            $user_msg = "Dear {$user_name},\n\n"
                                      . "Your complaint has been successfully recorded by PUCIT Administration.\n\n"
                                      . "Ticket ID: #{$complaint_id}\n"
                                      . "Subject: {$title}\n"
                                      . "Category: {$category}\n"
                                      . "Status: Pending Review\n\n"
                                      . "You can track the progress of your grievance at: http://dev.local/home.php\n\n"
                                      . "Regards,\nPUCIT Administration";
                            send_system_email($user_email, $user_sub, $user_msg);
                        }

                        // Post/Redirect/Get pattern: Redirect to prevent duplicate form resubmissions on page reload
                        $_SESSION['flash_complaint_msg'] = "Your complaint #{$complaint_id} has been submitted successfully to PUCIT Administration.";
                        header("Location: home.php?submitted={$complaint_id}");
                        exit();
                    } else {
                        $complaint_error = "Error submitting complaint: " . htmlspecialchars($conn->error);
                    }
                    $ins->close();
                } else {
                    $complaint_error = "Statement preparation error: " . htmlspecialchars($conn->error);
                }
            }
        }
}

// Fetch stats and complaints
$total_count = 0;
$pending_count = 0;
$progress_count = 0;
$resolved_count = 0;
$dismissed_count = 0;
$complaints_list = [];

$view_scope = trim($_GET['view'] ?? 'my');
if ($view_scope !== 'all') {
    $view_scope = 'my';
}

$filter_status = trim($_GET['status'] ?? '');
$filter_category = trim($_GET['category'] ?? '');
$search_query = trim($_GET['q'] ?? '');
$valid_statuses = ['Pending', 'In Review', 'Resolved', 'Dismissed'];

if ($conn && !$conn->connect_error) {
    // Stats query - scoped to active view (my tickets vs campus feed)
    $scope_condition = ($view_scope === 'my') ? "WHERE user_id = " . intval($user_id) : "WHERE 1=1";
    $st = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'In Review' THEN 1 ELSE 0 END) as in_review,
        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN status = 'Dismissed' THEN 1 ELSE 0 END) as dismissed
        FROM complaints {$scope_condition}");
    
    if ($st && $row = $st->fetch_assoc()) {
        $total_count     = (int)($row["total"] ?? 0);
        $pending_count   = (int)($row["pending"] ?? 0);
        $progress_count  = (int)($row["in_review"] ?? 0);
        $resolved_count  = (int)($row["resolved"] ?? 0);
        $dismissed_count = (int)($row["dismissed"] ?? 0);
    }

    // Build filter clause
    $where = [];
    $bind_types = '';
    $bind_params = [];

    if ($view_scope === 'my') {
        $where[] = "user_id = ?";
        $bind_types .= 'i';
        $bind_params[] = $user_id;
    }

    if ($filter_status !== '' && in_array($filter_status, $valid_statuses)) {
        $where[] = "status = ?";
        $bind_types .= 's';
        $bind_params[] = $filter_status;
    }

    if ($filter_category !== '') {
        $where[] = "category = ?";
        $bind_types .= 's';
        $bind_params[] = $filter_category;
    }

    if ($search_query !== '') {
        $where[] = "(title LIKE ? OR description LIKE ?)";
        $bind_types .= 'ss';
        $like = "%{$search_query}%";
        $bind_params[] = $like;
        $bind_params[] = $like;
    }

    $where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

    // Pagination calculation
    $per_page = 10;
    $page = max(1, intval($_GET['page'] ?? 1));
    $cnt_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM complaints " . $where_sql);
    $total_filtered = 0;
    if ($cnt_stmt) {
        if (!empty($bind_params)) {
            $cnt_stmt->bind_param($bind_types, ...$bind_params);
        }
        $cnt_stmt->execute();
        $cnt_res = $cnt_stmt->get_result();
        if ($cr = $cnt_res->fetch_assoc()) {
            $total_filtered = (int)$cr['cnt'];
        }
        $cnt_stmt->close();
    }
    $total_pages = max(1, (int)ceil($total_filtered / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    // Complaints list
    $stmt = $conn->prepare("SELECT * FROM complaints " . $where_sql . " ORDER BY id DESC LIMIT ? OFFSET ?");
    if ($stmt) {
        $limit_types = $bind_types . 'ii';
        $limit_params = array_merge($bind_params, [$per_page, $offset]);
        $stmt->bind_param($limit_types, ...$limit_params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $complaints_list[] = $r;
        }
        $stmt->close();
    }

    // Fetch administrative action & resolution history for visible complaints
    $complaint_history = [];
    if (!empty($complaints_list)) {
        $c_ids = array_map(function($c) { return intval($c['id']); }, $complaints_list);
        $in_clause = implode(',', $c_ids);
        $hist_sql = "SELECT a.*, u.name AS handler_name, u.role AS handler_role
                     FROM admin_actions a
                     LEFT JOIN USERS u ON a.admin_id = u.id
                     WHERE a.complaint_id IN ({$in_clause})
                     ORDER BY a.id ASC";
        $hist_res = $conn->query($hist_sql);
        if ($hist_res) {
            while ($h = $hist_res->fetch_assoc()) {
                $complaint_history[$h['complaint_id']][] = $h;
            }
        }
    }

    // Load active categories from database
    $categories_list = [];
    $cat_res = $conn->query("SELECT id, name FROM categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
    if ($cat_res) {
        while ($cat = $cat_res->fetch_assoc()) {
            $categories_list[] = $cat;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PUCIT Complaint Platform - Home Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%230e2a47'><path d='M12 3L1 9L12 15L21 10.09V17H23V9M5 13.18V17.18C5 19.94 8.13 22 12 22C15.87 22 19 19.94 19 17.18V13.18L12 17L5 13.18Z'/></svg>">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
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
                    <div class="nav-subtitle-text"><?= ($_SESSION['user_role'] ?? '') === 'teacher' ? 'Faculty Portal' : 'Student Grievance System' ?></div>
                </div>
            </a>

            <div class="nav-actions">
                <a href="profile.php" class="btn btn-nav" title="Manage your profile and security">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    <span>Profile</span>
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

    <div class="dashboard-container">

        <!-- Hero Welcome -->
        <div class="hero-welcome">
            <div class="hero-text">
                <h1>Welcome back, <?= htmlspecialchars($user_name) ?>! 👋</h1>
                <p>Welcome to the official PUCIT Grievance & Complaint Redressal Platform. Report facility issues, computer lab problems, or academic inquiries directly to college administration.</p>
            </div>
            <a href="#new-complaint" class="btn btn-accent">
                <svg width="18" height="18" style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span>File New Complaint</span>
            </a>
        </div>

        <!-- Quick Stats Cards -->
        <div class="stats-grid">
            <a href="home.php?view=<?= urlencode($view_scope) ?>" class="stat-card" style="text-decoration:none; <?= $filter_status === '' ? 'border-color:var(--secondary);' : '' ?>">
                <div class="stat-icon total">📋</div>
                <div>
                    <div class="stat-number"><?= $total_count ?></div>
                    <div class="stat-title"><?= $view_scope === 'my' ? 'My Total Lodged' : 'Campus Total' ?></div>
                </div>
            </a>

            <a href="home.php?view=<?= urlencode($view_scope) ?>&status=Pending" class="stat-card" style="text-decoration:none; <?= $filter_status === 'Pending' ? 'border-color:var(--secondary);' : '' ?>">
                <div class="stat-icon pending">⏳</div>
                <div>
                    <div class="stat-number"><?= $pending_count ?></div>
                    <div class="stat-title">Pending Review</div>
                </div>
            </a>

            <a href="home.php?view=<?= urlencode($view_scope) ?>&status=In+Review" class="stat-card" style="text-decoration:none; <?= $filter_status === 'In Review' ? 'border-color:var(--secondary);' : '' ?>">
                <div class="stat-icon progress">⚙️</div>
                <div>
                    <div class="stat-number"><?= $progress_count ?></div>
                    <div class="stat-title">In Review</div>
                </div>
            </a>

            <a href="home.php?view=<?= urlencode($view_scope) ?>&status=Resolved" class="stat-card" style="text-decoration:none; <?= $filter_status === 'Resolved' ? 'border-color:var(--secondary);' : '' ?>">
                <div class="stat-icon resolved">✅</div>
                <div>
                    <div class="stat-number"><?= $resolved_count ?></div>
                    <div class="stat-title">Resolved</div>
                </div>
            </a>

            <?php if ($dismissed_count > 0): ?>
            <a href="home.php?view=<?= urlencode($view_scope) ?>&status=Dismissed" class="stat-card" style="text-decoration:none; <?= $filter_status === 'Dismissed' ? 'border-color:var(--secondary);' : '' ?>">
                <div class="stat-icon" style="background:#fee2e2; color:#b91c1c;">🚫</div>
                <div>
                    <div class="stat-number"><?= $dismissed_count ?></div>
                    <div class="stat-title">Dismissed</div>
                </div>
            </a>
            <?php endif; ?>
        </div>

        <!-- View Scope Tabs -->
        <div style="display: flex; gap: 8px; margin-bottom: 20px;">
            <a href="home.php?view=my" class="btn <?= $view_scope === 'my' ? 'btn-primary' : 'btn-outline' ?> btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
                <span>📋</span>
                <span>My Complaints</span>
            </a>
            <a href="home.php?view=all" class="btn <?= $view_scope === 'all' ? 'btn-primary' : 'btn-outline' ?> btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
                <span>🌐</span>
                <span>Campus Grievances & Resolutions</span>
            </a>
        </div>

        <!-- Main Content Area -->
        <div class="dashboard-grid">
            <!-- Left: Complaints History -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <svg width="18" height="18" style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                        <span><?= $view_scope === 'my' ? 'My Complaint Tickets' : 'Campus Grievance Feed & Resolutions' ?></span>
                    </h3>
                    <span style="font-size: 13px; color: var(--text-secondary);"><?= $total_filtered ?> entries</span>
                </div>

                <!-- Filters Bar -->
                <form method="GET" action="home.php" class="filters-bar" style="margin: 0; padding: 10px 16px; border-bottom: 1px solid var(--border-color); background: var(--surface-muted); display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                    <input type="hidden" name="view" value="<?= htmlspecialchars($view_scope) ?>">
                    <select name="status" style="padding: 5px 8px; font-size: 12px; border-radius: 4px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-primary);">
                        <option value="">All Statuses</option>
                        <option value="Pending"   <?= $filter_status === 'Pending'   ? 'selected' : '' ?>>Pending</option>
                        <option value="In Review" <?= $filter_status === 'In Review' ? 'selected' : '' ?>>In Review</option>
                        <option value="Resolved"  <?= $filter_status === 'Resolved'  ? 'selected' : '' ?>>Resolved</option>
                        <option value="Dismissed" <?= $filter_status === 'Dismissed' ? 'selected' : '' ?>>Dismissed</option>
                    </select>
                    <select name="category" style="padding: 5px 8px; font-size: 12px; border-radius: 4px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-primary);">
                        <option value="">All Categories</option>
                        <?php foreach ($categories_list as $cat): ?>
                            <option value="<?= htmlspecialchars($cat['name']) ?>" <?= $filter_category === $cat['name'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="q" placeholder="Search subject or description..." value="<?= htmlspecialchars($search_query) ?>" style="padding: 5px 8px; font-size: 12px; border-radius: 4px; border: 1px solid var(--border-color); flex: 1; min-width: 140px; background: var(--bg-card); color: var(--text-primary);">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="home.php?view=<?= urlencode($view_scope) ?>" class="btn btn-outline btn-sm">Clear</a>
                </form>

                <div class="panel-body">
                    <?php if (empty($complaints_list)): ?>
                        <div class="empty-state">
                            <svg width="48" height="48" style="width: 48px; height: 48px;" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <h4>No complaints found</h4>
                            <p style="font-size: 13px; margin-top: 4px;">Try adjusting your filters or use the form on the right to file a new grievance.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="complaints-table">
                                <thead>
                                    <tr>
                                        <th>Ticket #</th>
                                        <th>Subject</th>
                                        <th>Category</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <?php if ($view_scope === 'all'): ?>
                                            <th>Filed By</th>
                                        <?php endif; ?>
                                        <th>Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($complaints_list as $c): ?>
                                        <tr>
                                            <td><strong>#<?= $c["id"] ?></strong></td>
                                            <td><?= htmlspecialchars($c["title"]) ?></td>
                                            <td><small><?= htmlspecialchars($c["category"]) ?></small></td>
                                            <td>
                                                <?php
                                                    $prio = strtolower($c["priority"] ?? "medium");
                                                    $prio_class = "badge-medium";
                                                    if ($prio === "high") $prio_class = "badge-high";
                                                    if ($prio === "low") $prio_class = "badge-low";
                                                ?>
                                                <span class="badge <?= $prio_class ?>"><?= htmlspecialchars($c["priority"]) ?></span>
                                            </td>
                                            <td>
                                                <?php
                                                    $st_val = strtolower($c["status"] ?? "pending");
                                                    $badge_cls = "badge-pending";
                                                    if ($st_val === "in review") $badge_cls = "badge-progress";
                                                    if ($st_val === "resolved") $badge_cls = "badge-resolved";
                                                    if ($st_val === "dismissed") $badge_cls = "badge-high";
                                                ?>
                                                <span class="badge <?= $badge_cls ?>"><?= htmlspecialchars($c["status"]) ?></span>
                                            </td>
                                            <?php if ($view_scope === 'all'): ?>
                                                <td>
                                                    <?php if ((int)$c["user_id"] === (int)$user_id): ?>
                                                        <span class="badge badge-role-student">You</span>
                                                    <?php else: ?>
                                                        <small style="color: var(--text-muted);">PUCIT Student</small>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                            <td>
                                                <div style="font-size: 12px; line-height: 1.4;">
                                                    <div><span style="color: var(--text-muted);">Filed:</span> <?= date("M d, Y", strtotime($c["created_at"])) ?></div>
                                                    <?php if ($c["status"] === 'Resolved' && !empty($c['updated_at'])): ?>
                                                        <div style="color: #065f46; font-weight: 600; font-size: 11px; margin-top: 2px;">
                                                            ✓ <?= date("M d, Y", strtotime($c["updated_at"])) ?>
                                                        </div>
                                                    <?php elseif (!empty($c['updated_at']) && $c['updated_at'] !== $c['created_at']): ?>
                                                        <div style="color: #0284c7; font-size: 11px; margin-top: 2px;">
                                                            ↻ <?= date("M d, Y", strtotime($c["updated_at"])) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-outline btn-sm" onclick="openDetail(<?= $c['id'] ?>)">View</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <div>
                                Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong>
                            </div>
                            <div class="pagination-controls">
                                <?php
                                    $p_params = $_GET;
                                    unset($p_params['page']);
                                    $p_qs = http_build_query($p_params);
                                    $p_base = 'home.php?' . ($p_qs ? $p_qs . '&' : '');
                                ?>
                                <?php if ($page > 1): ?>
                                    <a href="<?= $p_base ?>page=<?= $page - 1 ?>" class="btn btn-outline btn-sm">&laquo; Prev</a>
                                <?php endif; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="<?= $p_base ?>page=<?= $page + 1 ?>" class="btn btn-outline btn-sm">Next &raquo;</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right: Submit Form -->
            <div class="panel" id="new-complaint">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <svg width="18" height="18" style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        <span>File a Complaint</span>
                    </h3>
                </div>
                <div class="panel-body">
                    <?php if (!empty($complaint_msg)): ?>
                        <div class="alert alert-success">
                            <span><?= htmlspecialchars($complaint_msg) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($complaint_error)): ?>
                        <div class="alert alert-danger">
                            <span><?= htmlspecialchars($complaint_error) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="home.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <div class="form-group">
                            <label class="form-label" for="category">Department / Category</label>
                            <select name="category" id="category" class="form-select" required>
                                <option value="" disabled selected>Select category...</option>
                                <?php foreach ($categories_list as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                                <?php if (empty($categories_list)): ?>
                                    <option value="Other / General">Other / General</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="priority">Priority Level</label>
                            <select name="priority" id="priority" class="form-select">
                                <option value="Low">Low - Normal Request</option>
                                <option value="Medium" selected>Medium - Standard Issue</option>
                                <option value="High">High - Urgent / Exam Impacting</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="title">Complaint Subject / Title</label>
                            <input type="text" name="title" id="title" class="form-control" style="padding-left: 14px;" placeholder="e.g. Lab 4 PC-12 Not Working" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="description">Detailed Description</label>
                            <textarea name="description" id="description" class="form-control" rows="4" style="padding-left: 14px; resize: vertical;" placeholder="Provide details like room number, error message, or issue specifics..." required></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="image">Attach Image (optional)</label>
                            <input type="file" name="image" id="image" class="form-control" style="padding-left: 14px;" accept=".jpg,.jpeg,.png,.webp">
                            <small style="color: var(--text-muted); font-size: 12px;">JPG, PNG or WebP — max 5 MB</small>
                        </div>

                        <div class="form-group">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="anonymous" id="anonymous" value="1" checked>
                                <label class="form-check-label" for="anonymous">Submit anonymously (identity protected from public view)</label>
                            </div>
                        </div>
<button type="submit" name="submit_complaint" class="btn btn-primary btn-block">
                            <span>Submit Complaint</span>
                            <svg width="18" height="18" style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="site-copy" style="margin-top: 40px; margin-bottom: 20px;">
            &copy; <?= date("Y") ?> PUCIT (Punjab University College of Information Technology) — Student Grievance Redressal System.
        </div>
    </div>

    <!-- Complaint Detail Modals for Students -->
    <?php foreach ($complaints_list as $c): ?>
    <div class="detail-modal" id="modal-<?= $c['id'] ?>">
        <div class="detail-card">
            <button class="btn-close-modal" onclick="closeDetail(<?= $c['id'] ?>)">&times;</button>
            <h3>Ticket #<?= $c['id'] ?> — <?= htmlspecialchars($c['title']) ?></h3>

            <div class="detail-row">
                <div class="detail-label">Department / Category</div>
                <div class="detail-value"><?= htmlspecialchars($c['category']) ?></div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Complainant</div>
                <div class="detail-value">
                    <?php if ((int)$c["user_id"] === (int)$user_id): ?>
                        <strong>Filed by You (<?= htmlspecialchars($user_name) ?>)</strong>
                    <?php else: ?>
                        <span style="color: var(--text-muted);">PUCIT Student (Identity Anonymous / Protected)</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Status</div>
                <div class="detail-value">
                    <?php
                        $st_v = strtolower($c['status'] ?? 'pending');
                        $badge_c = 'badge-pending';
                        if ($st_v === 'in review') $badge_c = 'badge-progress';
                        if ($st_v === 'resolved') $badge_c = 'badge-resolved';
                        if ($st_v === 'dismissed') $badge_c = 'badge-high';
                    ?>
                    <span class="badge <?= $badge_c ?>"><?= htmlspecialchars($c['status']) ?></span>
                </div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Priority</div>
                <div class="detail-value"><?= htmlspecialchars($c['priority']) ?></div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Submission Date</div>
                <div class="detail-value"><?= date('F d, Y — h:i A', strtotime($c['created_at'])) ?></div>
            </div>

            <?php if ($c['status'] === 'Resolved'): ?>
            <div class="detail-row">
                <div class="detail-label">Resolved Date</div>
                <div class="detail-value" style="color: #065f46; font-weight: 600;">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="vertical-align: -2px; margin-right: 4px; color: #10b981;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    <?= date('F d, Y — h:i A', strtotime($c['updated_at'])) ?>
                </div>
            </div>
            <?php elseif (!empty($c['updated_at']) && $c['updated_at'] !== $c['created_at']): ?>
            <div class="detail-row">
                <div class="detail-label">Last Status Update</div>
                <div class="detail-value" style="color: #0369a1; font-weight: 600;">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align: -2px; margin-right: 4px; color: #0284c7;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <?= date('F d, Y — h:i A', strtotime($c['updated_at'])) ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="detail-row">
                <div class="detail-label">Description</div>
                <div class="detail-value" style="white-space: pre-wrap;"><?= htmlspecialchars($c['description']) ?></div>
            </div>

            <?php if (!empty($c['image_path'])): ?>
            <div class="detail-row">
                <div class="detail-label">Attached Photo</div>
                <a href="<?= htmlspecialchars(ltrim($c['image_path'], '/')) ?>" target="_blank" rel="noopener" title="Click to view full image">
                    <img src="<?= htmlspecialchars(ltrim($c['image_path'], '/')) ?>" alt="Attached evidence" class="complaint-img">
                </a>
            </div>
            <?php endif; ?>

            <?php if (!empty($c['admin_notes'])): ?>
            <div class="admin-response-box">
                <h4>
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>Administrative Response</span>
                </h4>
                <div style="font-size: 13.5px; color: #166534; white-space: pre-wrap;"><?= htmlspecialchars($c['admin_notes']) ?></div>
            </div>
            <?php else: ?>
            <div style="margin-top: 16px; padding: 12px; background: #f8fafc; border-radius: var(--radius-sm); font-size: 13px; color: var(--text-muted);">
                ℹ️ No administrative notes have been logged for this ticket yet.
            </div>
            <?php endif; ?>

            <!-- Complete Action & Resolution History Dropdown -->
            <?php $hist_items = $complaint_history[$c['id']] ?? []; ?>
            <details class="ticket-history-dropdown" <?= !empty($hist_items) ? 'open' : '' ?>>
                <summary class="history-dropdown-summary">
                    <div class="history-dropdown-title">
                        <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>Status & Action History</span>
                    </div>
                    <div class="history-dropdown-meta">
                        <span class="history-count-badge">
                            <?= count($hist_items) ?> <?= count($hist_items) === 1 ? 'update' : 'updates' ?>
                        </span>
                        <svg class="history-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                        </svg>
                    </div>
                </summary>
                <div class="history-dropdown-body">
                    <div class="timeline-container">
                        <?php if (!empty($hist_items)): ?>
                            <?php foreach (array_reverse($hist_items) as $h): ?>
                                <?php
                                    $h_st = strtolower($h['new_status'] ?? '');
                                    $dot_cls = 'timeline-dot-pending';
                                    $badge_h = 'badge-pending';
                                    if ($h_st === 'in review') { $dot_cls = 'timeline-dot-progress'; $badge_h = 'badge-progress'; }
                                    elseif ($h_st === 'resolved') { $dot_cls = 'timeline-dot-resolved'; $badge_h = 'badge-resolved'; }
                                    elseif ($h_st === 'dismissed') { $dot_cls = 'timeline-dot-dismissed'; $badge_h = 'badge-high'; }
                                ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot <?= $dot_cls ?>"></div>
                                    <div class="timeline-content">
                                        <div class="timeline-header">
                                            <div class="timeline-status-change">
                                                <span class="badge <?= $badge_h ?>"><?= htmlspecialchars($h['new_status']) ?></span>
                                                <?php if (!empty($h['old_status']) && $h['old_status'] !== $h['new_status']): ?>
                                                    <span class="timeline-status-arrow">← from <?= htmlspecialchars($h['old_status']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="timeline-time">
                                                <?= date('M d, Y · h:i A', strtotime($h['created_at'])) ?>
                                            </div>
                                        </div>
                                        <div class="timeline-handler">
                                            <span class="timeline-handler-icon">
                                                <?php if (($h['handler_role'] ?? '') === 'teacher'): ?>
                                                    👨‍🏫 Teacher / Faculty
                                                <?php else: ?>
                                                    🏛️ PUCIT Administration
                                                <?php endif; ?>
                                            </span>
                                            <?php if (!empty($h['handler_name'])): ?>
                                                <span class="timeline-handler-name">(<?= htmlspecialchars($h['handler_name']) ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($h['notes'])): ?>
                                            <div class="timeline-notes">
                                                <div class="timeline-notes-label">Administrative Remarks:</div>
                                                <div class="timeline-notes-text"><?= nl2br(htmlspecialchars($h['notes'])) ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Initial lodging event -->
                        <div class="timeline-item timeline-item-origin">
                            <div class="timeline-dot timeline-dot-origin"></div>
                            <div class="timeline-content">
                                <div class="timeline-header">
                                    <div class="timeline-status-change">
                                        <span class="badge badge-pending">Pending</span>
                                        <span class="timeline-status-arrow">Initial Submission</span>
                                    </div>
                                    <div class="timeline-time">
                                        <?= date('M d, Y · h:i A', strtotime($c['created_at'])) ?>
                                    </div>
                                </div>
                                <div class="timeline-handler">
                                    <span class="timeline-handler-icon">📝 Complainant</span>
                                    <span class="timeline-handler-name">Ticket Lodged</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </details>
        </div>
    </div>
    <?php endforeach; ?>

<script>
function openDetail(id) {
    var modal = document.getElementById('modal-' + id);
    if (modal) {
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
}
function closeDetail(id) {
    var modal = document.getElementById('modal-' + id);
    if (modal) {
        modal.classList.remove('open');
        document.body.style.overflow = '';
    }
}
document.querySelectorAll('.detail-modal').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.classList.remove('open');
            document.body.style.overflow = '';
        }
    });
});
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
