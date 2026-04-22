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

// === ANTI-BYPASS: Role enforcement — only 'HR3 Admin' role allowed ===
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'HR3 Admin') {
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
$fullname = $_SESSION['fullname'] ?? 'Administrator';
$role = $_SESSION['role'] ?? 'admin';

// Filter options
$departments = ['HR', 'LOGISTICS', 'CORE', 'FINANCIAL'];
$actions = [
    'Created Shift', 'Edited Shift', 'Deleted Shift',
    'Created Recurring Shift', 'Deleted Recurring Shift',
    'Swap Requested', 'Swap Approved', 'Swap Rejected',
    'Schedule Published', 'Schedule Conflict Flagged',
    'Schedule Manual Edit', 'Schedule Auto-Generated'
];
$statuses = ['Success', 'Pending', 'Conflict', 'Rejected'];

$where = [];
$params = [];
if (!empty($_GET['department'])) { $where[] = 'l.department = ?'; $params[] = $_GET['department']; }
if (!empty($_GET['action'])) { $where[] = 'l.action = ?'; $params[] = $_GET['action']; }
if (!empty($_GET['status'])) { $where[] = 'l.status = ?'; $params[] = $_GET['status']; }
if (!empty($_GET['performed_by'])) { $where[] = 'l.performed_by LIKE ?'; $params[] = '%'.$_GET['performed_by'].'%'; }
if (!empty($_GET['employee'])) { $where[] = 'e.fullname LIKE ?'; $params[] = '%'.$_GET['employee'].'%'; }
if (!empty($_GET['date_from'])) { $where[] = 'l.action_time >= ?'; $params[] = $_GET['date_from'].' 00:00:00'; }
if (!empty($_GET['date_to'])) { $where[] = 'l.action_time <= ?'; $params[] = $_GET['date_to'].' 23:59:59'; }
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT l.*, e.fullname, e.department AS emp_dept 
        FROM schedule_logs l 
        LEFT JOIN employees e ON l.employee_id = e.id 
        $where_sql 
        ORDER BY l.action_time DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_logs,
    SUM(CASE WHEN status = 'Success' THEN 1 ELSE 0 END) as success_count,
    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN status = 'Conflict' THEN 1 ELSE 0 END) as conflict_count
    FROM schedule_logs";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Schedule Logs - Admin | ViaHale TNVS HR3</title>
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
            font-family: 'QuickSand','Poppins',Arial,sans-serif; 
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
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
            transition: all 0.3s ease;
        }

        .stat-card:hover { 
            transform: translateY(-4px); 
            box-shadow: 0 8px 24px rgba(140,140,200,0.15); 
        }

        .stat-card.success { border-left: 4px solid #22c55e; }
        .stat-card.pending { border-left: 4px solid #f59e0b; }
        .stat-card.conflict { border-left: 4px solid #ef4444; }
        .stat-card.total { border-left: 4px solid #3b82f6; }

        .stat-icon { 
            width: 50px; 
            height: 50px; 
            border-radius: 10px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 1.5rem;
        }

        .stat-card.success .stat-icon { background: #dcfce7; color: #22c55e; }
        .stat-card.pending .stat-icon { background: #fef3c7; color: #f59e0b; }
        .stat-card.conflict .stat-icon { background: #fee2e2; color: #ef4444; }
        .stat-card.total .stat-icon { background: #dbeafe; color: #3b82f6; }

        .stat-text h3 { font-size: 1.8rem; font-weight: 700; margin: 0; color: #22223b; }
        .stat-text p { font-size: 0.9rem; color: #6c757d; margin: 0; }

        .dashboard-col { 
            background: #fff; 
            border-radius: 18px; 
            box-shadow: 0 4px 15px rgba(140,140,200,0.08); 
            padding: 2rem 1.5rem; 
            margin-bottom: 2rem; 
            border: 1px solid #f0f0f0;
        }

        .dashboard-col h5 { 
            font-family: 'QuickSand', 'Poppins', Arial, sans-serif; 
            font-size: 1.35rem; 
            font-weight: 700; 
            margin-bottom: 1.5rem; 
            color: #22223b; 
            display: flex; 
            align-items: center; 
            gap: 0.8rem;
        }

        .dashboard-col h5::before { 
            content: ''; 
            width: 4px; 
            height: 28px; 
            background: linear-gradient(180deg, #9A66ff 0%, #4311a5 100%); 
            border-radius: 2px;
        }

        .filter-form { 
            background: #f8f9ff; 
            padding: 1.5rem; 
            border-radius: 12px; 
            margin-bottom: 1.5rem; 
            border: 1px solid #e0e7ff;
        }

        .filter-form .form-select, .filter-form .form-control { 
            border: 1px solid #e0e7ff; 
            border-radius: 8px; 
            padding: 0.6rem 0.8rem; 
            font-size: 0.95rem;
        }

        .filter-form .form-select:focus, .filter-form .form-control:focus { 
            border-color: #9A66ff; 
            box-shadow: 0 0 0 0.2rem rgba(154, 102, 255, 0.15);
        }

        .filter-form .btn-primary { 
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
            border: none; 
            border-radius: 8px; 
            padding: 0.6rem 1.5rem; 
            font-weight: 600;
        }

        .filter-form .btn-primary:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 4px 12px rgba(154, 102, 255, 0.4);
        }

        .table { font-size: 0.98rem; color: #22223b; margin: 0; }
        .table th { color: #6c757d; font-weight: 700; border: none; background: transparent; font-size: 0.92rem; text-transform: uppercase; letter-spacing: 0.5px; padding: 1rem 0.8rem; }
        .table td { border: none; background: transparent; vertical-align: middle; padding: 1.2rem 0.8rem; border-bottom: 1px solid #f0f0f0; }
        .table tbody tr { transition: all 0.2s ease; }
        .table tbody tr:hover { background: #f8f9ff; }
        .table tbody tr:last-child td { border-bottom: none; }

        .status-badge { padding: 0.5rem 0.85rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600; display: inline-block; }
        .status-badge.success { background: #dcfce7; color: #22c55e; }
        .status-badge.pending { background: #fef3c7; color: #d97706; }
        .status-badge.conflict, .status-badge.danger { background: #fee2e2; color: #dc2626; }
        .status-badge.rejected { background: #fecaca; color: #991b1b; }

        .btn-link { color: #9A66ff; text-decoration: none; font-weight: 600; padding: 0.4rem 0.8rem; border-radius: 6px; }
        .btn-link:hover { background: #f0e6ff; color: #4311a5; }

        .table-responsive { border-radius: 12px; overflow: hidden; }

        .alert-info { background: linear-gradient(135deg, #dbeafe 0%, #e0f2fe 100%); border: 1px solid #93c5fd; color: #1e40af; border-radius: 12px; }

        .modal-content { border: none; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .modal-header { border-bottom: 1px solid #f0f0f0; background: #f8f9ff; }
        .modal-title { color: #22223b; font-weight: 700; }
        .btn-close { filter: brightness(0) saturate(100%) invert(33%) sepia(85%) saturate(1337%) hue-rotate(263deg); }
        .modal-body pre { background: #f8f9ff; border: 1px solid #e0e7ff; border-radius: 8px; padding: 1rem; color: #22223b; }

        @media (max-width: 1200px) { 
            .sidebar { width: 180px; } 
            .content-wrapper { margin-left: 180px; } 
            .main-content { padding: 1.5rem 1rem; } 
            .stats-container { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
        }
        @media (max-width: 900px) { 
            .sidebar { left: -220px; width: 220px; } 
            .sidebar.show { left: 0; } 
            .content-wrapper { margin-left: 0; } 
            .main-content { padding: 1rem; }
        }
        @media (max-width: 700px) { 
            .dashboard-title { font-size: 1.3rem; } 
            .main-content { padding: 0.7rem 0.2rem; } 
            .sidebar { width: 100vw; left: -100vw; } 
            .sidebar.show { left: 0; } 
            .stats-container { grid-template-columns: 1fr; } 
            .filter-form { padding: 1rem; } 
            .filter-form .form-select, .filter-form .form-control { min-width: 100%; margin-bottom: 0.8rem; } 
            .table { font-size: 0.85rem; } 
            .table th, .table td { padding: 0.7rem 0.4rem; }
            .topbar { flex-direction: column; gap: 0.5rem; }
        }
        @media (max-width: 500px) { 
            .sidebar { width: 100vw; left: -100vw; } 
            .sidebar.show { left: 0; } 
            .main-content { padding: 0.1rem 0.01rem; } 
            .dashboard-col { padding: 1rem 0.8rem; } 
            .dashboard-title { font-size: 1.1rem; }
        }
        @media (min-width: 1400px) { 
            .sidebar { width: 260px; padding: 2rem 1rem; } 
            .content-wrapper { margin-left: 260px; } 
            .main-content { padding: 2rem 2rem; }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <!-- Sidebar -->
    <div class="sidebar d-flex flex-column justify-content-between shadow-sm border-end">
        <div>
            <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
                <img src="../assets/images/image.png" class="img-fluid" style="height:55px" alt="Logo">
            </div>

            <div class="mb-4">
                <nav class="nav flex-column">
                    <a class="nav-link" href="../admin/admin_dashboard.php">
                        <ion-icon name="home-outline"></ion-icon>Dashboard
                    </a>
                </nav>
            </div>

            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Time & Attendance</h6>
                <nav class="nav flex-column">
                    <a class="nav-link" href="../admin/attendance_logs.php">
                        <ion-icon name="list-outline"></ion-icon>Attendance Logs
                    </a>
                    <a class="nav-link" href="../admin/attendance_stats.php">
                        <ion-icon name="stats-chart-outline"></ion-icon>Attendance Statistics
                    </a>
                    <a class="nav-link" href="../admin/face_enrollment.php">
                        <ion-icon name="camera-outline"></ion-icon>Face Enrollment
                    </a>
                </nav>
            </div>

            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Employee Management</h6>
                <nav class="nav flex-column">
                    <a class="nav-link" href="../admin/add_employee.php">
                        <ion-icon name="person-add-outline"></ion-icon>Add Employee
                    </a>
                    <a class="nav-link" href="../admin/employee_management.php">
                        <ion-icon name="people-outline"></ion-icon>Employee List
                    </a>
                </nav>
            </div>

            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Leave Management</h6>
                <nav class="nav flex-column">
                    <a class="nav-link" href="../admin/leave_requests.php">
                        <ion-icon name="calendar-outline"></ion-icon>Leave Requests
                    </a>
                </nav>
            </div>

            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Timesheet Management</h6>
                <nav class="nav flex-column">
                    <a class="nav-link" href="../admin/timesheet_reports.php">
                        <ion-icon name="document-text-outline"></ion-icon>Timesheet Reports
                    </a>
                </nav>
            </div>

            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Schedule Management</h6>
                <nav class="nav flex-column">
                    <a class="nav-link active" href="../admin/schedule_logs.php">
                        <ion-icon name="reader-outline"></ion-icon>Schedule Logs
                    </a>
                    <a class="nav-link" href="../admin/schedule_reports.php">
                        <ion-icon name="document-text-outline"></ion-icon>Schedule Reports
                    </a>
                </nav>
            </div>
            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Claims & Reimbursement</h6>
                <nav class="nav flex-column">
                    <a class="nav-link" href="processed_claims.php">
                        <ion-icon name="checkmark-done-outline"></ion-icon>Processed Claims
                    </a>
                    <a class="nav-link" href="reimbursement_policies.php">
                        <ion-icon name="settings-outline"></ion-icon>Reimbursement Policies
                    </a>
                    <a class="nav-link" href="audit_reports.php">
                        <ion-icon name="document-text-outline"></ion-icon>Audit & Reports
                    </a>
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
            <h1 class="dashboard-title">Schedule Logs</h1>
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
                <div class="stat-card total">
                    <div class="stat-icon">
                        <ion-icon name="list-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['total_logs'] ?? 0 ?></h3>
                        <p>Total Logs</p>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <ion-icon name="checkmark-circle-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['success_count'] ?? 0 ?></h3>
                        <p>Successful</p>
                    </div>
                </div>
                <div class="stat-card pending">
                    <div class="stat-icon">
                        <ion-icon name="time-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['pending_count'] ?? 0 ?></h3>
                        <p>Pending</p>
                    </div>
                </div>
                <div class="stat-card conflict">
                    <div class="stat-icon">
                        <ion-icon name="alert-circle-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['conflict_count'] ?? 0 ?></h3>
                        <p>Conflicts</p>
                    </div>
                </div>
            </div>

            <div class="dashboard-col">
                <h5><ion-icon name="filter-outline"></ion-icon>Schedule Activity Log</h5>
                <form class="row filter-form" method="get">
                    <div class="col-auto"><input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>" placeholder="From"></div>
                    <div class="col-auto"><input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>" placeholder="To"></div>
                    <div class="col-auto">
                        <select class="form-select" name="department">
                            <option value="">Department</option>
                            <?php foreach($departments as $d): ?>
                                <option value="<?= $d ?>" <?= (($_GET['department'] ?? '') === $d) ? 'selected' : '' ?>><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <select class="form-select" name="action">
                            <option value="">Action</option>
                            <?php foreach($actions as $a): ?>
                                <option value="<?= $a ?>" <?= (($_GET['action'] ?? '') === $a) ? 'selected' : '' ?>><?= $a ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <select class="form-select" name="status">
                            <option value="">Status</option>
                            <?php foreach($statuses as $s): ?>
                                <option value="<?= $s ?>" <?= (($_GET['status'] ?? '') === $s) ? 'selected' : '' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto"><input type="text" class="form-control" name="employee" placeholder="Employee" value="<?= htmlspecialchars($_GET['employee'] ?? '') ?>"></div>
                    <div class="col-auto"><input type="text" class="form-control" name="performed_by" placeholder="Performed By" value="<?= htmlspecialchars($_GET['performed_by'] ?? '') ?>"></div>
                    <div class="col-auto"><button class="btn btn-primary" type="submit"><ion-icon name="search-outline"></ion-icon>Filter</button></div>
                </form>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Action</th>
                                <th>Status</th>
                                <th>Performed By</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($logs as $log): ?>
                            <tr>
                                <td>
                                    <span style="font-weight: 600;"><?= htmlspecialchars(date('M d, Y', strtotime($log['action_time']))) ?></span><br>
                                    <small style="color: #9A66ff;"><?= htmlspecialchars(date('H:i:s', strtotime($log['action_time']))) ?></small>
                                </td>
                                <td><?= htmlspecialchars($log['fullname'] ?? '-') ?></td>
                                <td><span style="background: #f0e6ff; padding: 0.3rem 0.6rem; border-radius: 6px; font-size: 0.9rem;"><?= htmlspecialchars($log['department'] ?: $log['emp_dept']) ?></span></td>
                                <td><?= htmlspecialchars($log['action']) ?></td>
                                <td>
                                    <?php
                                        $badge = "success";
                                        if ($log['status'] === 'Pending') $badge = "pending";
                                        if ($log['status'] === 'Conflict') $badge = "conflict";
                                        if ($log['status'] === 'Rejected') $badge = "rejected";
                                    ?>
                                    <span class="status-badge <?= $badge ?>"><?= htmlspecialchars($log['status']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($log['performed_by']) ?></td>
                                <td>
                                    <?php if ($log['details']): ?>
                                        <button class="btn btn-link btn-sm" data-bs-toggle="modal" data-bs-target="#logModal<?= $log['id'] ?>"><ion-icon name="eye-outline"></ion-icon>View</button>
                                        <div class="modal fade" id="logModal<?= $log['id'] ?>" tabindex="-1" aria-labelledby="logModalLabel<?= $log['id'] ?>" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title" id="logModalLabel<?= $log['id'] ?>"><ion-icon name="document-text-outline"></ion-icon>Log Details</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <pre><?= htmlspecialchars($log['details']) ?></pre>
                                                        <?php if ($log['notes']) echo '<hr><div><strong>Notes:</strong> '.htmlspecialchars($log['notes']).'</div>'; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if(count($logs) === 0): ?>
                    <div class="alert alert-info text-center mt-4">
                        <ion-icon name="search-outline" style="font-size: 1.5rem; margin-bottom: 0.5rem;"></ion-icon>
                        <p style="margin: 0.5rem 0 0 0;">No logs found for the selected filters.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.sidebar a').forEach(link => {
    link.addEventListener('click', function() {
        document.querySelectorAll('.sidebar a').forEach(a => a.classList.remove('active'));
        this.classList.add('active');
    });
});

// === ANTI-BYPASS: Client-side idle timer with 2-minute warning ===
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
