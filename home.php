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
                $ins->bind_param("isisssssi", $user_id, $user_name, $title, $category_id, $category, $description, $priority, $image_path, $isAnonymous);
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
                } else {
                    $complaint_error = "Error submitting complaint: " . htmlspecialchars($conn->error);
                }
                $ins->close();
            }
    }
}

// Fetch stats and complaints
$total_count = 0;
$pending_count = 0;
$progress_count = 0;
$resolved_count = 0;
$complaints_list = [];

if ($conn && !$conn->connect_error) {
    // Stats query (overall)
    $st = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'In Review' THEN 1 ELSE 0 END) as in_review,
        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved
        FROM complaints WHERE user_id = " . intval($user_id));
    
    if ($st && $row = $st->fetch_assoc()) {
        $total_count = $row["total"] ?? 0;
        $pending_count = $row["pending"] ?? 0;
        $progress_count = $row["in_review"] ?? 0;
        $resolved_count = $row["resolved"] ?? 0;
    }

    // Complaints list
    $res = $conn->query("SELECT * FROM complaints WHERE user_id = " . intval($user_id) . " ORDER BY id DESC LIMIT 15");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $complaints_list[] = $r;
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
                    <div class="nav-subtitle-text">Student Grievance System</div>
                </div>
            </a>

            <div class="nav-actions">
                <a href="profile.php" class="btn btn-outline btn-sm" style="color: #fff; border-color: rgba(255,255,255,0.3);">
                    Profile
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
            <div class="stat-card">
                <div class="stat-icon total">📋</div>
                <div>
                    <div class="stat-number"><?= $total_count ?></div>
                    <div class="stat-title">Total Lodged</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon pending">⏳</div>
                <div>
                    <div class="stat-number"><?= $pending_count ?></div>
                    <div class="stat-title">Pending Review</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon progress">⚙️</div>
                <div>
                    <div class="stat-number"><?= $progress_count ?></div>
                    <div class="stat-title">In Review</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon resolved">✅</div>
                <div>
                    <div class="stat-number"><?= $resolved_count ?></div>
                    <div class="stat-title">Resolved</div>
                </div>
            </div>
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
                        <span>My Complaint Tickets</span>
                    </h3>
                    <span style="font-size: 13px; color: var(--text-secondary);"><?= count($complaints_list) ?> entries</span>
                </div>
                <div class="panel-body">
                    <?php if (empty($complaints_list)): ?>
                        <div class="empty-state">
                            <svg width="48" height="48" style="width: 48px; height: 48px;" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <h4>No complaints filed yet</h4>
                            <p style="font-size: 13px; margin-top: 4px;">Use the form on the right to submit your first grievance or request to PUCIT staff.</p>
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
                                                ?>
                                                <span class="badge <?= $badge_cls ?>"><?= htmlspecialchars($c["status"]) ?></span>
                                            </td>
                                            <td><small style="color: var(--text-muted);"><?= date("M d, Y", strtotime($c["created_at"])) ?></small></td>
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

            <div class="detail-row">
                <div class="detail-label">Description</div>
                <div class="detail-value" style="white-space: pre-wrap;"><?= htmlspecialchars($c['description']) ?></div>
            </div>

            <?php if (!empty($c['image_path'])): ?>
            <div class="detail-row">
                <div class="detail-label">Attached Photo</div>
                <img src="<?= htmlspecialchars(ltrim($c['image_path'], '/')) ?>" alt="Attached evidence" class="complaint-img">
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
