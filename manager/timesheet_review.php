<?php
session_start();
require_once("../includes/db.php");

// === ANTI-BYPASS: Prevent browser caching of protected pages ===
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Session timeout handling (15 minutes inactivity)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 900)) {
    session_unset();
    session_destroy();
    header("Location: ../login.php?timeout=1");
    exit();
}

// === ANTI-BYPASS: Require logged-in user ===
if (!isset($_SESSION['username']) || empty($_SESSION['username'])) {
    header("Location: ../login.php");
    exit();
}

// === ANTI-BYPASS: Role enforcement — only 'HR Manager' role allowed ===
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'HR Manager') {
    session_unset();
    session_destroy();
    header("Location: ../login.php?unauthorized=1");
    exit();
}

// === ANTI-BYPASS: Session fingerprint — bind session to browser user-agent ===
$currentFingerprint = md5($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
if (!isset($_SESSION['fingerprint'])) {
    $_SESSION['fingerprint'] = $currentFingerprint;
} elseif ($_SESSION['fingerprint'] !== $currentFingerprint) {
    session_unset();
    session_destroy();
    header("Location: ../login.php?unauthorized=1");
    exit();
}

// === ANTI-BYPASS: Generate CSRF token for forms ===
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$_SESSION['last_activity'] = time();
$fullname = $_SESSION['fullname'] ?? 'HR Manager';
$role = $_SESSION['role'] ?? 'HR Manager';

// Payroll Cut-Off Filter (bi-monthly)
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$selected_cutoff = isset($_GET['cutoff']) && $_GET['cutoff'] == '2' ? '2' : '1';

$year = (int)substr($selected_month, 0, 4);
$mon = (int)substr($selected_month, 5, 2);
if ($selected_cutoff == '1') {
    $period_from = sprintf('%04d-%02d-01', $year, $mon);
    $period_to = sprintf('%04d-%02d-15', $year, $mon);
} else {
    $period_from = sprintf('%04d-%02d-16', $year, $mon);
    $period_to = date('Y-m-t', strtotime("$year-$mon-01"));
}

// Handle approve/reject actions with HR notes (saved to 'notes' column)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['timesheet_id'], $_POST['action'], $_POST['notes_modal'])) {
    $timesheet_id = (int)$_POST['timesheet_id'];
    $allowed_actions = ['approved', 'rejected'];
    $new_status = in_array($_POST['action'], $allowed_actions) ? $_POST['action'] : 'pending';
    $notes = trim($_POST['notes_modal']);
    $reviewed_by = $fullname;
    $reviewed_at = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("UPDATE timesheets SET status = ?, notes = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?");
    $stmt->execute([$new_status, $notes, $reviewed_by, $reviewed_at, $timesheet_id]);
    header("Location: timesheet_review.php?month=$selected_month&cutoff=$selected_cutoff&success=1");
    exit();
}

// Insert timesheets for enrolled employees with no timesheet for this period
$stmtEmployee = $pdo->query("SELECT id, fullname, username FROM employees WHERE status='Active' AND face_enrolled=1");
$employees = $stmtEmployee->fetchAll(PDO::FETCH_ASSOC);
foreach ($employees as $emp) {
    $check = $pdo->prepare("SELECT id FROM timesheets WHERE employee_id = ? AND period_from = ? AND period_to = ?");
    $check->execute([$emp['id'], $period_from, $period_to]);
    if (!$check->fetch()) {
        $insert = $pdo->prepare("INSERT INTO timesheets 
            (employee_id, period_from, period_to, submitted_at, status) 
            VALUES (?, ?, ?, NOW(), 'pending')");
        $insert->execute([$emp['id'], $period_from, $period_to]);
    }
}

// Fetch all timesheets for this period, per enrolled employee
$stmt = $pdo->prepare("
    SELECT t.*, e.fullname, e.username, e.department
    FROM timesheets t
    JOIN employees e ON t.employee_id = e.id
    WHERE t.period_from = ? AND t.period_to = ? AND e.face_enrolled = 1
    ORDER BY t.status DESC, e.fullname ASC, t.submitted_at ASC
");
$stmt->execute([$period_from, $period_to]);
$timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics for this period
$stats = [
    'total' => count($timesheets),
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0
];
foreach ($timesheets as $ts) {
    if ($ts['status'] == 'approved') $stats['approved']++;
    elseif ($ts['status'] == 'pending') $stats['pending']++;
    elseif ($ts['status'] == 'rejected') $stats['rejected']++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Timesheet Review & Approval - HR Manager | ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        * { transition: all 0.3s ease; }
        html, body { height: 100%; }
        body { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #fafbfc 100%); color: #22223b; font-size: 16px; margin: 0; padding: 0; }
        
        .wrapper { display: flex; min-height: 100vh; }
        
        .sidebar { 
            background: linear-gradient(180deg, #181818ff 0%, #1a1a2e 100%); 
            color: #fff; 
            width: 220px; 
            position: fixed; 
            left: 0; 
            top: 0; 
            height: 100vh; 
            z-index: 1040; 
            overflow-y: auto; 
            padding: 1rem 0.3rem; 
            box-shadow: 2px 0 15px rgba(0,0,0,0.1);
        }

        .sidebar::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
        .sidebar::-webkit-scrollbar-thumb { background: #9A66ff; border-radius: 3px; }

        .sidebar a, .sidebar button { 
            color: #bfc7d1; 
            background: none; 
            border: none; 
            font-size: 0.95rem; 
            padding: 0.45rem 0.7rem; 
            border-radius: 8px; 
            display: flex; 
            align-items: center; 
            gap: 0.7rem; 
            margin-bottom: 0.1rem; 
            width: 100%; 
            text-align: left; 
            white-space: nowrap; 
            cursor: pointer;
        }

        .sidebar a.active, .sidebar a:hover, .sidebar button.active, .sidebar button:hover { 
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
            color: #fff; 
            padding-left: 1rem;
            box-shadow: 0 2px 8px rgba(154, 102, 255, 0.3);
        }

        .sidebar hr { border-top: 1px solid #232a43; margin: 0.7rem 0; }
        .sidebar h6 { font-size: 0.75rem; font-weight: 700; letter-spacing: 1px; color: #9A66ff; }
        .sidebar .nav-link ion-icon { font-size: 1.2rem; }

        .content-wrapper { flex: 1; margin-left: 220px; display: flex; flex-direction: column; }
        
        .topbar { 
            padding: 1.5rem 2rem; 
            background: #fff; 
            border-bottom: 2px solid #f0f0f0; 
            box-shadow: 0 2px 8px rgba(140,140,200,0.05); 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            gap: 2rem;
        }

        .topbar .profile { display: flex; align-items: center; gap: 1.2rem; }
        .topbar .profile-img { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 3px solid #9A66ff; }
        .topbar .profile-info { line-height: 1.1; }
        .topbar .profile-info strong { font-size: 1.08rem; font-weight: 600; color: #22223b; }
        .topbar .profile-info small { color: #9A66ff; font-size: 0.93rem; font-weight: 500; }

        .dashboard-title { 
            font-family: 'QuickSand', 'Poppins', Arial, sans-serif; 
            font-size: 2rem; 
            font-weight: 800; 
            margin: 0; 
            color: #22223b; 
            letter-spacing: -0.5px;
        }

        .main-content { 
            flex: 1; 
            overflow-y: auto; 
            padding: 2rem 2rem;
        }

        .stats-container { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); 
            gap: 1.5rem; 
            margin-bottom: 2rem;
        }

        .stat-card { 
            background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%); 
            border-radius: 15px; 
            padding: 1.5rem; 
            box-shadow: 0 4px 15px rgba(140,140,200,0.08); 
            border: 1px solid #f0f0f0; 
            display: flex; 
            align-items: center; 
            gap: 1.5rem;
        }

        .stat-card.total { border-left: 5px solid #3b82f6; }
        .stat-card.approved { border-left: 5px solid #22c55e; }
        .stat-card.pending { border-left: 5px solid #f59e0b; }
        .stat-card.rejected { border-left: 5px solid #ef4444; }

        .stat-icon { 
            width: 60px; 
            height: 60px; 
            border-radius: 12px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 1.8rem; 
            flex-shrink: 0;
        }

        .stat-card.total .stat-icon { background: #dbeafe; color: #3b82f6; }
        .stat-card.approved .stat-icon { background: #dcfce7; color: #22c55e; }
        .stat-card.pending .stat-icon { background: #fef3c7; color: #f59e0b; }
        .stat-card.rejected .stat-icon { background: #fee2e2; color: #ef4444; }

        .stat-text h3 { font-size: 2rem; font-weight: 800; margin: 0; color: #22223b; }
        .stat-text p { font-size: 0.9rem; color: #6c757d; margin: 0; }

        .filter-section { 
            background: #f8f9ff; 
            border: 1px solid #e0e7ff; 
            border-radius: 12px; 
            padding: 1.5rem; 
            margin-bottom: 2rem;
        }

        .filter-section form { display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap; }

        .card { 
            border-radius: 18px; 
            box-shadow: 0 4px 15px rgba(140,140,200,0.08); 
            border: 1px solid #f0f0f0;
            margin-bottom: 2rem;
        }

        .card-header { 
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
            color: white; 
            border-radius: 18px 18px 0 0; 
            padding: 1.5rem; 
            border: none; 
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            font-size: 1.15rem;
        }

        .card-body { padding: 0; }

        .table-responsive { background: #fff; border-radius: 0 0 18px 18px; overflow: hidden; }

        .table { font-size: 0.98rem; color: #22223b; margin-bottom: 0; }

        .table th { 
            color: #6c757d; 
            font-weight: 700; 
            border: none; 
            background: #f9f9fc; 
            padding: 1.2rem 1rem;
            font-size: 0.92rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table td { 
            border-bottom: 1px solid #e8e8f0; 
            padding: 1.2rem 1rem; 
            vertical-align: middle;
        }

        .table tbody tr { transition: all 0.2s ease; }
        .table tbody tr:hover { background: #f8f8fb; }
        .table tbody tr:last-child td { border-bottom: none; }

        .status-badge { 
            padding: 0.5rem 0.85rem; 
            border-radius: 20px; 
            font-weight: 600;
            display: inline-block;
            font-size: 0.85rem;
        }

        .status-badge.approved { background: #dcfce7; color: #065f46; }
        .status-badge.pending { background: #fef3c7; color: #92400e; }
        .status-badge.rejected { background: #fee2e2; color: #991b1b; }

        .btn-primary { 
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
            border: none; 
            border-radius: 8px; 
            padding: 0.65rem 1.5rem; 
            font-weight: 600;
        }

        .btn-primary:hover { 
            background: linear-gradient(90deg, #8654e0 0%, #360090 100%);
            transform: translateY(-2px);
            color: white;
        }

        .btn-success { 
            background: #10b981; 
            border: none; 
            border-radius: 8px; 
            padding: 0.5rem 1rem; 
            font-weight: 600;
        }

        .btn-success:hover { background: #059669; }

        .btn-danger { 
            background: #ef4444; 
            border: none; 
            border-radius: 8px; 
            padding: 0.5rem 1rem; 
            font-weight: 600;
        }

        .btn-danger:hover { background: #dc2626; }

        .btn-info { 
            background: #0ea5e9; 
            border: none; 
            border-radius: 8px; 
            padding: 0.5rem 1rem; 
            font-weight: 600;
        }

        .btn-info:hover { background: #0284c7; }

        .form-control, .form-select { 
            border-radius: 8px; 
            border: 1px solid #e0e7ff; 
            padding: 0.7rem 1rem;
        }

        .form-control:focus, .form-select:focus { 
            border-color: #9A66ff; 
            box-shadow: 0 0 0 0.2rem rgba(154, 102, 255, 0.15);
        }

        .alert { 
            border-radius: 12px; 
            border: none; 
            border-left: 4px solid; 
            padding: 1.2rem; 
            margin-bottom: 1.5rem;
        }

        .alert-success { 
            background: #d1fae5; 
            color: #065f46; 
            border-left-color: #10b981;
        }

        .modal-content { 
            border-radius: 18px; 
            border: 1px solid #e0e7ff; 
            box-shadow: 0 6px 32px rgba(70, 57, 130, 0.15);
        }

        .modal-header { 
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
            color: #fff; 
            border-bottom: none; 
            border-radius: 18px 18px 0 0; 
            padding: 1.5rem;
        }

        .modal-header h5 { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; font-size: 1.23rem; font-weight: 700; }

        .modal-body { 
            padding: 2rem; 
            background: #fafbfc;
        }

        .modal-body label { 
            font-weight: 600; 
            margin-bottom: 0.5rem; 
            color: #22223b;
        }

        .modal-body textarea { 
            border-radius: 8px; 
            border: 1px solid #e0e7ff; 
            padding: 0.7rem 1rem;
        }

        .modal-body textarea:focus { 
            border-color: #9A66ff; 
            box-shadow: 0 0 0 0.2rem rgba(154, 102, 255, 0.15);
        }

        .modal-footer { 
            border-top: 1px solid #e0e7ff; 
            padding: 1.2rem 2rem; 
            background: #fafbfc;
        }

        .btn-close { filter: brightness(1.8); }

        .badge { padding: 0.5rem 0.85rem; border-radius: 20px; font-weight: 600; }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #9ca3af;
        }

        .empty-state ion-icon {
            font-size: 3rem;
            color: #d1d5db;
            display: block;
            margin-bottom: 1rem;
        }

        @media (max-width: 1200px) { 
            .sidebar { width: 180px; } 
            .content-wrapper { margin-left: 180px; } 
            .main-content { padding: 1.5rem 1rem; } 
            .stats-container { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 900px) { 
            .sidebar { left: -220px; width: 220px; } 
            .sidebar.show { left: 0; } 
            .content-wrapper { margin-left: 0; } 
            .main-content { padding: 1rem; } 
            .topbar { padding: 1rem 1.5rem; } 
            .filter-section form { flex-direction: column; }
            .filter-section form > * { width: 100%; }
            .stats-container { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 700px) { 
            .dashboard-title { font-size: 1.4rem; } 
            .main-content { padding: 1rem 0.8rem; } 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; }
            .stats-container { grid-template-columns: 1fr; }
            .card-body { padding: 1.2rem; }
            .table th, .table td { padding: 0.8rem 0.5rem; font-size: 0.85rem; }
            .topbar { flex-direction: column; gap: 1rem; justify-content: flex-start; }
        }

        @media (max-width: 500px) { 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; } 
            .main-content { padding: 0.8rem 0.5rem; }
            .table { font-size: 0.8rem; }
            .table th, .table td { padding: 0.6rem 0.3rem; }
            .btn-sm { padding: 0.35rem 0.6rem; font-size: 0.75rem; }
        }

        @media (min-width: 1400px) { 
            .sidebar { width: 260px; padding: 2rem 1rem; } 
            .content-wrapper { margin-left: 260px; } 
            .main-content { padding: 2.5rem 2.5rem; } 
            .stats-container { grid-template-columns: repeat(4, 1fr); }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <!-- Sidebar -->
    <div class="sidebar d-flex flex-column justify-content-between shadow-sm border-end">
        <div>
            <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
                <img src="../assets/images/image.png" class="img-fluid me-2" style="height: 55px;" alt="Logo">
            </div>
            <div class="mb-4">
                <nav class="nav flex-column">
                    <a class="nav-link" href="../manager/manager_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
                </nav>
            </div>
            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Timesheet Management</h6>
                <nav class="nav flex-column">
                    <a class="nav-link active" href="../manager/timesheet_review.php"><ion-icon name="checkmark-done-outline"></ion-icon>Review & Approval</a>
                    <a class="nav-link" href="../manager/timesheet_reports.php"><ion-icon name="document-text-outline"></ion-icon>Timesheet Reports</a>
                </nav>
            </div>
            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Leave Management</h6>
                <nav class="nav flex-column">
                    <a class="nav-link" href="../manager/leave_requests.php"><ion-icon name="calendar-outline"></ion-icon>Leave Requests</a>
                    <a class="nav-link" href="../manager/leave_balance.php"><ion-icon name="wallet-outline"></ion-icon>Leave Balance</a>
                    <a class="nav-link" href="../manager/leave_history.php"><ion-icon name="time-outline"></ion-icon>Leave History</a>
                    <a class="nav-link" href="../manager/leave_calendar.php"><ion-icon name="calendar-outline"></ion-icon>Leave Calendar</a>
                </nav>
            </div>
        </div>
        <div class="p-3 border-top mb-2">
            <a class="nav-link text-danger" href="../logout.php">
                <ion-icon name="log-out-outline"></ion-icon>Logout
            </a>
        </div>
    </div>

    <!-- Main Content Wrapper -->
    <div class="content-wrapper">
        <!-- Top Bar -->
        <div class="topbar">
            <span class="dashboard-title">Timesheet Review & Approval</span>
            <div class="profile">
                <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
                <div class="profile-info">
                    <strong><?= htmlspecialchars($fullname) ?></strong><br>
                    <small>HR Manager</small>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Success Alert -->
            <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <ion-icon name="checkmark-circle-outline" style="margin-right: 0.5rem;"></ion-icon>
                    <span>Timesheet has been successfully processed!</span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card total">
                    <div class="stat-icon">
                        <ion-icon name="document-text-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['total'] ?></h3>
                        <p>Total Timesheets</p>
                    </div>
                </div>
                <div class="stat-card approved">
                    <div class="stat-icon">
                        <ion-icon name="checkmark-circle-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['approved'] ?></h3>
                        <p>Approved</p>
                    </div>
                </div>
                <div class="stat-card pending">
                    <div class="stat-icon">
                        <ion-icon name="time-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['pending'] ?></h3>
                        <p>Pending Review</p>
                    </div>
                </div>
                <div class="stat-card rejected">
                    <div class="stat-icon">
                        <ion-icon name="close-circle-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['rejected'] ?></h3>
                        <p>Rejected</p>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="get" action="">
                    <div class="form-group mb-0">
                        <label for="month" style="font-weight: 600; margin-bottom: 0.5rem; display: block;">
                            <ion-icon name="calendar-outline" style="margin-right: 0.3rem;"></ion-icon>Payroll Month
                        </label>
                        <input type="month" id="month" name="month" class="form-control" value="<?= htmlspecialchars($selected_month) ?>" style="max-width: 200px;">
                    </div>
                    <div class="form-group mb-0">
                        <label for="cutoff" style="font-weight: 600; margin-bottom: 0.5rem; display: block;">
                            <ion-icon name="layers-outline" style="margin-right: 0.3rem;"></ion-icon>Cut-Off Period
                        </label>
                        <select id="cutoff" name="cutoff" class="form-select" style="max-width: 200px;">
                            <option value="1" <?= $selected_cutoff == '1' ? 'selected' : '' ?>>1st–15th of Month</option>
                            <option value="2" <?= $selected_cutoff == '2' ? 'selected' : '' ?>>16th–End of Month</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="align-self: flex-end;">
                        <ion-icon name="funnel-outline"></ion-icon> Filter
                    </button>
                </form>
            </div>

            <!-- Timesheets Table -->
            <div class="card">
                <div class="card-header">
                    <ion-icon name="clipboard-outline"></ion-icon>
                    Timesheets for Review — <?= date('F Y', strtotime($selected_month . '-01')) ?> (Cut-off <?= $selected_cutoff == '1' ? '1st–15th' : '16th–End' ?>)
                </div>
                <div class="card-body">
                    <?php if (!empty($timesheets)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 20%;">Employee Name</th>
                                        <th style="width: 15%;">Username</th>
                                        <th style="width: 12%;">Department</th>
                                        <th style="width: 15%;">Submitted At</th>
                                        <th style="width: 10%;">Status</th>
                                        <th style="width: 20%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($timesheets as $ts): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($ts['fullname']) ?></strong>
                                            </td>
                                            <td>
                                                <span class="text-muted">@<?= htmlspecialchars($ts['username']) ?></span>
                                            </td>
                                            <td><?= htmlspecialchars($ts['department'] ?? '-') ?></td>
                                            <td>
                                                <small class="text-muted">
                                                    <?= $ts['submitted_at'] ? date('M d, Y H:i', strtotime($ts['submitted_at'])) : 'N/A' ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="status-badge <?= htmlspecialchars($ts['status']) ?>">
                                                    <?php if ($ts['status'] == 'approved'): ?>
                                                        <ion-icon name="checkmark-circle-outline"></ion-icon> Approved
                                                    <?php elseif ($ts['status'] == 'rejected'): ?>
                                                        <ion-icon name="close-circle-outline"></ion-icon> Rejected
                                                    <?php else: ?>
                                                        <ion-icon name="time-outline"></ion-icon> Pending
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-info" onclick="viewDetails(<?= (int)$ts['id'] ?>)">
                                                    <ion-icon name="eye-outline"></ion-icon> View
                                                </button>
                                                <?php if ($ts['status'] == 'pending'): ?>
                                                    <button class="btn btn-sm btn-success" onclick="openNotesModal('approved', <?= (int)$ts['id'] ?>, '<?= htmlspecialchars($ts['fullname'], ENT_QUOTES) ?>')">
                                                        <ion-icon name="checkmark-outline"></ion-icon> Approve
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" onclick="openNotesModal('rejected', <?= (int)$ts['id'] ?>, '<?= htmlspecialchars($ts['fullname'], ENT_QUOTES) ?>')">
                                                        <ion-icon name="close-outline"></ion-icon> Reject
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <ion-icon name="document-outline"></ion-icon>
                            <p><strong>No timesheets found</strong></p>
                            <p class="text-muted">No timesheets available for review in this payroll period.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Timesheet Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailsModalLabel">
                    <ion-icon name="clipboard-outline"></ion-icon> Timesheet Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="modalLoading" style="text-align: center; padding: 2rem;">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading timesheet details...</p>
                </div>
                <div id="modalTimesheetDetails" style="display: none;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Notes Modal for Approve/Reject -->
<div class="modal fade" id="notesModal" tabindex="-1" aria-labelledby="notesModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="notesModalLabel">
                    <ion-icon name="create-outline"></ion-icon> 
                    <span id="notesActionTitle">Approve Timesheet</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="timesheet_id" id="modalTimesheetId">
                <input type="hidden" name="action" id="modalAction">
                
                <p class="text-muted mb-3">
                    Employee: <strong id="employeeNameDisplay"></strong>
                </p>
                
                <div class="mb-3">
                    <label for="notes_modal" class="form-label">
                        <strong id="notesLabelAction">Approval</strong> Notes <span class="text-danger">*</span>
                    </label>
                    <textarea 
                        class="form-control" 
                        id="notes_modal" 
                        name="notes_modal" 
                        rows="5" 
                        required
                        placeholder="Enter notes to be sent to the employee...">
                    </textarea>
                    <small class="text-muted d-block mt-1">These notes will be visible to the employee.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="notesModalSubmitBtn">Submit</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function viewDetails(tsId) {
    var modal = new bootstrap.Modal(document.getElementById('detailsModal'));
    document.getElementById('modalTimesheetDetails').style.display = 'none';
    document.getElementById('modalLoading').style.display = 'block';
    
    fetch('timesheet_modal_data.php?id=' + encodeURIComponent(tsId))
        .then(r => r.text())
        .then(html => {
            document.getElementById('modalTimesheetDetails').innerHTML = html;
            document.getElementById('modalTimesheetDetails').style.display = 'block';
            document.getElementById('modalLoading').style.display = 'none';
        })
        .catch(err => {
            document.getElementById('modalTimesheetDetails').innerHTML = '<div class="alert alert-danger">Failed to load timesheet details.</div>';
            document.getElementById('modalTimesheetDetails').style.display = 'block';
            document.getElementById('modalLoading').style.display = 'none';
        });
    
    modal.show();
}

function openNotesModal(action, timesheetId, employeeName) {
    var modal = new bootstrap.Modal(document.getElementById('notesModal'));
    document.getElementById('modalTimesheetId').value = timesheetId;
    document.getElementById('modalAction').value = action;
    document.getElementById('notes_modal').value = '';
    document.getElementById('employeeNameDisplay').textContent = employeeName;
    
    var actionText = action === 'approved' ? 'Approval' : 'Rejection';
    var actionTitle = action === 'approved' ? 'Approve Timesheet' : 'Reject Timesheet';
    
    document.getElementById('notesModalLabel').innerHTML = '<ion-icon name="create-outline"></ion-icon> ' + actionTitle;
    document.getElementById('notesActionTitle').textContent = actionTitle;
    document.getElementById('notesLabelAction').textContent = actionText;
    
    document.getElementById('notes_modal').placeholder = action === 'approved'
        ? 'E.g. Good job! Your timesheet has been approved. Thank you for accurate reporting.'
        : 'E.g. Please correct your time-in for 03-09-2025 and resubmit.';
    
    if (action === 'approved') {
        document.getElementById('notesModalSubmitBtn').className = 'btn btn-success';
        document.getElementById('notesModalSubmitBtn').innerHTML = '<ion-icon name="checkmark-outline"></ion-icon> Approve';
    } else {
        document.getElementById('notesModalSubmitBtn').className = 'btn btn-danger';
        document.getElementById('notesModalSubmitBtn').innerHTML = '<ion-icon name="close-outline"></ion-icon> Reject';
    }
    
    modal.show();
}
</script>

<script>
// ===== SESSION INACTIVITY TIMEOUT (15 minutes, warn at 13 min) =====
(function() {
  var SESSION_TIMEOUT = 15 * 60 * 1000;
  var WARN_BEFORE    = 2 * 60 * 1000;
  var idleTimer, warnTimer;
  var warned = false;

  function resetTimers() {
    warned = false;
    clearTimeout(idleTimer);
    clearTimeout(warnTimer);
    hideWarning();
    warnTimer = setTimeout(showWarning, SESSION_TIMEOUT - WARN_BEFORE);
    idleTimer = setTimeout(logoutNow, SESSION_TIMEOUT);
  }

  function showWarning() {
    if (warned) return;
    warned = true;
    var el = document.getElementById('sessionTimeoutWarning');
    if (el) el.style.display = 'flex';
  }

  function hideWarning() {
    var el = document.getElementById('sessionTimeoutWarning');
    if (el) el.style.display = 'none';
  }

  function logoutNow() {
    window.location.href = '../login.php?timeout=1';
  }

  var banner = document.createElement('div');
  banner.id = 'sessionTimeoutWarning';
  banner.style.cssText = 'display:none;position:fixed;top:0;left:0;width:100%;z-index:9999;background:linear-gradient(90deg,#f59e0b,#d97706);color:#fff;padding:0.9rem 1.5rem;align-items:center;justify-content:center;gap:1rem;font-weight:600;font-size:0.97rem;box-shadow:0 4px 16px rgba(0,0,0,0.15);';
  banner.innerHTML = '<ion-icon name="alert-circle-outline" style="font-size:1.4rem;"></ion-icon>'
    + '<span>Your session will expire in <strong>2 minutes</strong> due to inactivity.</span>'
    + '<button onclick="this.parentElement.style.display=\'none\'" style="background:rgba(255,255,255,0.2);border:none;color:#fff;border-radius:8px;padding:0.4rem 1rem;font-weight:700;cursor:pointer;">Dismiss</button>';
  document.body.appendChild(banner);

  ['mousemove','mousedown','keydown','touchstart','scroll','click'].forEach(function(evt) {
    document.addEventListener(evt, resetTimers, {passive:true});
  });

  resetTimers();
})();
</script>

</body>
</html>