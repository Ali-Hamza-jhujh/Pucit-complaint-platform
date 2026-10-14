<?php
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
    $title = trim($_POST["title"] ?? "");
    $category = trim($_POST["category"] ?? "");
    $priority = trim($_POST["priority"] ?? "Medium");
    $description = trim($_POST["description"] ?? "");

    if (empty($title) || empty($category) || empty($description)) {
        $complaint_error = "Please fill in all complaint details.";
    } elseif ($conn && !$conn->connect_error) {
        $ins = $conn->prepare("INSERT INTO complaints (user_id, user_name, title, category, description, priority, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
        if ($ins) {
            $ins->bind_param("isssss", $user_id, $user_name, $title, $category, $description, $priority);
            if ($ins->execute()) {
                $complaint_msg = "Your complaint #{$ins->insert_id} has been submitted successfully to PUCIT Administration.";
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
    // Stats query
    $st = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as progress,
        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved
        FROM complaints WHERE user_id = " . intval($user_id));
    
    if ($st && $row = $st->fetch_assoc()) {
        $total_count = $row["total"] ?? 0;
        $pending_count = $row["pending"] ?? 0;
        $progress_count = $row["progress"] ?? 0;
        $resolved_count = $row["resolved"] ?? 0;
    }

    // Complaints list
    $res = $conn->query("SELECT * FROM complaints WHERE user_id = " . intval($user_id) . " ORDER BY id DESC LIMIT 15");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $complaints_list[] = $r;
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
    <!-- Cache-busting CSS link -->
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <!-- Inline fallback to ensure instant loading even if cached -->
    <style>
        <?= file_get_contents(__DIR__ . "/style.css") ?>
    </style>
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

        <!-- Development Notice -->
        <div class="dev-banner">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 20px;">🚧</span>
                <div>
                    <strong>PUCIT Complaint Platform — Home Page Under Active Development</strong>
                    <div style="font-size: 13px; opacity: 0.9;">You can lodge test complaints, track status changes, and extend functionality as needed.</div>
                </div>
            </div>
            <span class="badge badge-pending">WIP Mode</span>
        </div>

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
                    <div class="stat-title">In Progress</div>
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
                                                    if ($st_val === "in progress") $badge_cls = "badge-progress";
                                                    if ($st_val === "resolved") $badge_cls = "badge-resolved";
                                                ?>
                                                <span class="badge <?= $badge_cls ?>"><?= htmlspecialchars($c["status"]) ?></span>
                                            </td>
                                            <td><small style="color: var(--text-muted);"><?= date("M d, Y", strtotime($c["created_at"])) ?></small></td>
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

                    <form method="POST" action="home.php">
                        <div class="form-group">
                            <label class="form-label" for="category">Department / Category</label>
                            <select name="category" id="category" class="form-select" required>
                                <option value="" disabled selected>Select category...</option>
                                <option value="Computer Labs & Systems">Computer Labs & Systems</option>
                                <option value="Campus Wi-Fi & Internet">Campus Wi-Fi & Internet</option>
                                <option value="Classroom & Projectors">Classroom & Projectors</option>
                                <option value="Air Conditioning & Electricity">Air Conditioning & Electricity</option>
                                <option value="Examination & Records">Examination & Records</option>
                                <option value="Library Services">Library Services</option>
                                <option value="Cafeteria & Cleanliness">Cafeteria & Cleanliness</option>
                                <option value="Faculty & Academics">Faculty & Academics</option>
                                <option value="Other / General">Other / General</option>
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

</body>
</html>
