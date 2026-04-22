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

// Fetch leave types from DB for filter
$types_stmt = $pdo->query("SELECT DISTINCT leave_type FROM leave_requests WHERE leave_type IS NOT NULL ORDER BY leave_type");
$leave_types = $types_stmt->fetchAll(PDO::FETCH_COLUMN);

// Filters
$where = [];
$params = [];
if (!empty($_GET['employee'])) {
    $where[] = "e.fullname LIKE ?";
    $params[] = "%" . $_GET['employee'] . "%";
}
if (!empty($_GET['type'])) {
    $where[] = "l.leave_type = ?";
    $params[] = $_GET['type'];
}
if (!empty($_GET['status'])) {
    $where[] = "l.status = ?";
    $params[] = $_GET['status'];
}
if (!empty($_GET['date_from']) && !empty($_GET['date_to'])) {
    $where[] = "l.date_from >= ? AND l.date_to <= ?";
    $params[] = $_GET['date_from'];
    $params[] = $_GET['date_to'];
}
$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare("
        SELECT l.*, e.fullname, e.id AS empid, e.employee_id
        FROM leave_requests l
        JOIN employees e ON l.employee_id = e.id
        $where_sql
        ORDER BY l.requested_at DESC
    ");
    $stmt->execute($params);
    $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment;filename=leave_history_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Employee','Type','Date From','Date To','Days','Reason','Status','Requested At']);
    foreach ($leaves as $l) {
        $days = (strtotime($l['date_to']) - strtotime($l['date_from'])) / 86400 + 1;
        fputcsv($out, [
            $l['fullname'], $l['leave_type'], $l['date_from'], $l['date_to'],
            $days > 0 ? (int)$days : 1, $l['reason'] ?? '', $l['status'], $l['requested_at']
        ]);
    }
    fclose($out);
    exit();
}

// Fetch leave history with employee info
$stmt = $pdo->prepare("
    SELECT l.*, e.fullname, e.id AS empid, e.employee_id, e.username, e.department
    FROM leave_requests l
    JOIN employees e ON l.employee_id = e.id
    $where_sql
    ORDER BY l.requested_at DESC
");
$stmt->execute($params);
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to check for overlapping leaves
function hasOverlappingLeaves($pdo, $employee_id, $date_from, $date_to, $exclude_id = null) {
    $query = "SELECT * FROM leave_requests 
              WHERE employee_id = ? 
              AND (
                  (date_from <= ? AND date_to >= ?)
                  OR (date_from >= ? AND date_from <= ?)
                  OR (date_to >= ? AND date_to <= ?)
              )
              AND status IN ('pending', 'approved')";
    
    $params = [$employee_id, $date_to, $date_from, $date_from, $date_to, $date_from, $date_to];
    
    if ($exclude_id) {
        $query .= " AND id != ?";
        $params[] = $exclude_id;
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function formatDateRange($date_from, $date_to) {
    return date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to));
}

// Calculate statistics
$total_approved = 0;
$total_pending = 0;
$total_rejected = 0;
$total_days_used = 0;

foreach ($leaves as $l) {
    if ($l['status'] === 'approved') $total_approved++;
    elseif ($l['status'] === 'pending') $total_pending++;
    elseif ($l['status'] === 'rejected') $total_rejected++;
    
    if ($l['status'] === 'approved') {
        $days = (strtotime($l['date_to']) - strtotime($l['date_from'])) / 86400 + 1;
        $total_days_used += $days > 0 ? $days : 1;
    }
}

$statuses = ['pending', 'approved', 'rejected'];
$current_year = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leave History - HR Manager | ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        * { transition: all 0.3s ease; }
        html, body { height: 100%; }
        body { 
            font-family: 'QuickSand', 'Poppins', Arial, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #fafbfc 100%); 
            color: #22223b; 
            font-size: 16px; 
            margin: 0; 
            padding: 0; 
        }
        
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
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .dashboard-title ion-icon { font-size: 2.2rem; color: #9A66ff; }

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
            flex-direction: column;
            gap: 0.8rem;
        }

        .stat-card.approved { border-left: 5px solid #10b981; }
        .stat-card.pending { border-left: 5px solid #f59e0b; }
        .stat-card.rejected { border-left: 5px solid #ef4444; }
        .stat-card.days { border-left: 5px solid #3b82f6; }

        .stat-card .stat-label { 
            font-size: 0.9rem; 
            color: #6c757d; 
            font-weight: 500;
        }

        .stat-card .stat-value { 
            font-size: 2.5rem; 
            font-weight: 800; 
            color: #22223b;
        }

        .stat-card.approved .stat-value { color: #10b981; }
        .stat-card.pending .stat-value { color: #f59e0b; }
        .stat-card.rejected .stat-value { color: #ef4444; }
        .stat-card.days .stat-value { color: #3b82f6; }

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

        .card-body { padding: 1.5rem; }

        .table-responsive { 
            border-radius: 0 0 18px 18px; 
            overflow: hidden;
            background: #fff;
        }

        .table { 
            font-size: 0.98rem; 
            color: #22223b; 
            margin-bottom: 0;
        }

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
        .table tbody tr:hover { background-color: #f8f8fb; }
        .table tbody tr:last-child td { border-bottom: none; }

        .search-section { 
            background: #f8f9ff; 
            border: 1px solid #e0e7ff; 
            border-radius: 12px; 
            padding: 1.5rem; 
            margin-bottom: 2rem;
        }

        .search-section form { 
            display: flex; 
            gap: 1rem; 
            align-items: center; 
            flex-wrap: wrap;
        }

        .form-control, .form-select { 
            border-radius: 8px; 
            font-size: 0.95rem; 
            padding: 0.8rem 1.2rem; 
            border: 1px solid #e0e7ff; 
            background: #fff;
        }

        .form-control:focus, .form-select:focus { 
            border-color: #9A66ff; 
            box-shadow: 0 0 0 0.2rem rgba(154, 102, 255, 0.15);
        }

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

        .btn-outline-secondary { 
            background: #fff;
            border: 1.5px solid #9A66ff;
            color: #9A66ff;
            border-radius: 8px; 
            padding: 0.65rem 1.5rem;
            font-weight: 600;
        }

        .btn-outline-secondary:hover { 
            background: #9A66ff;
            color: white;
            transform: translateY(-2px);
        }

        .employee-cell { 
            display: flex; 
            flex-direction: column; 
            gap: 0.3rem;
        }

        .employee-name { 
            font-weight: 700; 
            color: #22223b; 
            font-size: 0.98rem;
        }

        .employee-meta { 
            font-size: 0.85rem; 
            color: #6c757d;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .employee-id { 
            display: inline-block; 
            background: #e0e7ff; 
            color: #4311a5; 
            padding: 0.25rem 0.6rem; 
            border-radius: 4px; 
            font-weight: 600; 
            font-size: 0.75rem;
        }

        .status-badge { 
            padding: 0.4rem 0.9rem; 
            border-radius: 12px; 
            font-size: 0.85rem; 
            font-weight: 600; 
            display: inline-block;
            text-transform: capitalize;
        }

        .status-badge.approved { 
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46; 
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.15);
        }

        .status-badge.pending { 
            background: linear-gradient(135deg, #fef3c7 0%, #fcd34d 100%);
            color: #92400e;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.15);
        }

        .status-badge.rejected { 
            background: linear-gradient(135deg, #fee2e2 0%, #fca5a5 100%);
            color: #7f1d1d;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.15);
        }

        .conflict-info { 
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border-left: 4px solid #ef4444; 
            padding: 0.8rem; 
            border-radius: 8px; 
            margin-top: 0.8rem; 
            font-size: 0.9rem;
        }

        .conflict-info strong { color: #b91c1c; }

        .conflict-info ul { margin: 0.5rem 0 0 1.5rem; padding: 0; }

        .conflict-info li { 
            color: #7f1d1d;
            margin-bottom: 0.4rem;
        }

        .date-cell { 
            font-weight: 500;
            color: #22223b;
        }

        .days-badge { 
            background: #e0e7ff; 
            color: #4311a5; 
            padding: 0.3rem 0.7rem; 
            border-radius: 6px; 
            font-weight: 700; 
            font-size: 0.9rem;
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
            .search-section form { flex-direction: column; }
            .search-section form > * { width: 100%; }
            .stats-container { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 700px) { 
            .dashboard-title { font-size: 1.4rem; } 
            .main-content { padding: 1rem 0.8rem; } 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; }
            .stats-container { grid-template-columns: 1fr; }
            .topbar { flex-direction: column; gap: 1rem; justify-content: flex-start; }
            .search-section form { flex-direction: column; }
            .search-section form > * { width: 100%; }
            .table { font-size: 0.85rem; }
            .table th, .table td { padding: 0.8rem 0.5rem; }
        }

        @media (max-width: 500px) { 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; } 
            .main-content { padding: 0.8rem 0.5rem; }
            .card-body { padding: 1rem 0.8rem; }
            .dashboard-title { font-size: 1.2rem; }
            .stat-card { padding: 1rem; }
            .table th, .table td { padding: 0.6rem 0.3rem; font-size: 0.75rem; }
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
    <div class="sidebar d-flex flex-column justify-content-between shadow-sm">
        <div>
            <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
                <img src="../assets/images/image.png" class="img-fluid" style="height: 55px;" alt="Logo">
            </div>
            <div class="mb-4">
                <nav class="nav flex-column">
                    <a class="nav-link" href="../manager/manager_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
                </nav>
            </div>
            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Timesheet Management</h6>
                <nav class="nav flex-column">
                    <a class="nav-link" href="../manager/timesheet_review.php"><ion-icon name="checkmark-done-outline"></ion-icon>Review & Approval</a>
                    <a class="nav-link" href="../manager/timesheet_reports.php"><ion-icon name="document-text-outline"></ion-icon>Timesheet Reports</a>
                </nav>
            </div>
            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Leave Management</h6>
                <nav class="nav flex-column">
                    <a class="nav-link" href="../manager/leave_requests.php"><ion-icon name="calendar-outline"></ion-icon>Leave Requests</a>
                    <a class="nav-link" href="../manager/leave_balance.php"><ion-icon name="wallet-outline"></ion-icon>Leave Balance</a>
                    <a class="nav-link active" href="../manager/leave_history.php"><ion-icon name="time-outline"></ion-icon>Leave History</a>
                    <a class="nav-link" href="../manager/leave_calendar.php"><ion-icon name="calendar-number-outline"></ion-icon>Leave Calendar</a>
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
            <span class="dashboard-title">
                <ion-icon name="time-outline"></ion-icon> Leave History
            </span>
            <div class="profile">
                <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
                <div class="profile-info">
                    <strong><?= htmlspecialchars($fullname) ?></strong><br>
                    <small><?= htmlspecialchars(ucfirst($role)) ?></small>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card approved">
                    <div class="stat-label">Approved Requests</div>
                    <div class="stat-value"><?= $total_approved ?></div>
                    <small style="color: #6c757d;">Leave requests</small>
                </div>
                <div class="stat-card pending">
                    <div class="stat-label">Pending Requests</div>
                    <div class="stat-value"><?= $total_pending ?></div>
                    <small style="color: #6c757d;">Awaiting approval</small>
                </div>
                <div class="stat-card rejected">
                    <div class="stat-label">Rejected Requests</div>
                    <div class="stat-value"><?= $total_rejected ?></div>
                    <small style="color: #6c757d;">Denied requests</small>
                </div>
                <div class="stat-card days">
                    <div class="stat-label">Days Used</div>
                    <div class="stat-value"><?= $total_days_used ?></div>
                    <small style="color: #6c757d;">Approved leave days</small>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="search-section">
                <form method="get" class="filter-form" role="search" aria-label="Filter leave history">
                    <label style="font-weight: 600; margin: 0; white-space: nowrap; display: flex; align-items: center; gap: 0.5rem;">
                        <ion-icon name="search-outline"></ion-icon> Filters:
                    </label>
                    <input type="text" class="form-control" name="employee" value="<?= htmlspecialchars($_GET['employee'] ?? '') ?>" placeholder="Employee Name" aria-label="Employee name">
                    <select name="type" class="form-select" aria-label="Leave type">
                        <option value="">All Types</option>
                        <?php foreach ($leave_types as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= (($_GET['type'] ?? '') === $t) ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" class="form-select" aria-label="Status">
                        <option value="">All Status</option>
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?= $s ?>" <?= (($_GET['status'] ?? '') === $s) ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>" aria-label="Date from">
                    <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>" aria-label="Date to">
                    <button type="submit" class="btn btn-primary"><ion-icon name="search-outline"></ion-icon> Filter</button>
                    <a href="leave_history.php" class="btn btn-outline-secondary"><ion-icon name="refresh-outline"></ion-icon> Reset</a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-outline-secondary"><ion-icon name="download-outline"></ion-icon> Export CSV</a>
                </form>
            </div>

            <!-- Leave History Table -->
            <div class="card">
                <div class="card-header">
                    <ion-icon name="list-outline"></ion-icon> Leave History Records (<?= $current_year ?>)
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($leaves)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>
                                            <ion-icon name="person-outline"></ion-icon> Employee
                                        </th>
                                        <th style="text-align: center;">
                                            <ion-icon name="bookmark-outline"></ion-icon> Type
                                        </th>
                                        <th>From Date</th>
                                        <th>To Date</th>
                                        <th style="text-align: center;">Days</th>
                                        <th>Reason</th>
                                        <th style="text-align: center;">Status</th>
                                        <th>Requested At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($leaves as $l): ?>
                                        <?php
                                            $overlapping = hasOverlappingLeaves($pdo, $l['employee_id'], $l['date_from'], $l['date_to'], $l['id']);
                                            $has_conflict = !empty($overlapping);
                                            $days = (strtotime($l['date_to']) - strtotime($l['date_from'])) / 86400 + 1;
                                            $days_out = $days > 0 ? (int)$days : 1;
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="employee-cell">
                                                    <span class="employee-name"><?= htmlspecialchars($l['fullname']) ?></span>
                                                    <span class="employee-meta">
                                                        <span class="employee-id"><?= htmlspecialchars($l['employee_id']) ?></span>
                                                    </span>
                                                </div>
                                            </td>
                                            <td style="text-align: center;">
                                                <span style="background: #e0e7ff; color: #4311a5; padding: 0.3rem 0.8rem; border-radius: 6px; font-weight: 600; font-size: 0.9rem;">
                                                    <?= htmlspecialchars($l['leave_type']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="date-cell"><?= date('M d, Y', strtotime($l['date_from'])) ?></span>
                                            </td>
                                            <td>
                                                <span class="date-cell"><?= date('M d, Y', strtotime($l['date_to'])) ?></span>
                                            </td>
                                            <td style="text-align: center;">
                                                <span class="days-badge"><?= $days_out ?></span>
                                            </td>
                                            <td>
                                                <span title="<?= htmlspecialchars($l['reason'] ?? '') ?>" style="color: #6c757d;">
                                                    <?= htmlspecialchars(strlen($l['reason'] ?? '') > 40 ? substr($l['reason'], 0, 40) . '...' : ($l['reason'] ?? 'N/A')) ?>
                                                </span>
                                            </td>
                                            <td style="text-align: center;">
                                                <span class="status-badge <?= strtolower($l['status']) ?>"><?= ucfirst($l['status']) ?></span>
                                                <?php if ($has_conflict): ?>
                                                    <div class="conflict-info" role="status" aria-live="polite">
                                                        <strong><ion-icon name="warning-outline" style="margin-right: 0.3rem;"></ion-icon>Overlapping Leave(s):</strong>
                                                        <ul>
                                                            <?php foreach ($overlapping as $overlap): ?>
                                                                <li>
                                                                    <strong><?= htmlspecialchars($overlap['leave_type']) ?></strong> 
                                                                    (<?= formatDateRange($overlap['date_from'], $overlap['date_to']) ?>) 
                                                                    <span class="status-badge <?= $overlap['status'] === 'approved' ? 'approved' : 'pending' ?>" style="margin-left: 0.5rem;">
                                                                        <?= ucfirst($overlap['status']) ?>
                                                                    </span>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span style="color: #6c757d; font-size: 0.9rem;">
                                                    <?= date('M d, Y', strtotime($l['requested_at'])) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="padding: 3rem 1rem; text-align: center;">
                            <ion-icon name="document-outline" style="font-size: 3rem; color: #d1d5db; display: block; margin-bottom: 1rem;"></ion-icon>
                            <p style="color: #6c757d; margin: 0; font-weight: 500;">No leave history records found.</p>
                            <small style="color: #9ca3af;">Try adjusting your filters to see more results.</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

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