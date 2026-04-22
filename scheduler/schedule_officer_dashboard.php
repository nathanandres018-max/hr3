<?php
session_start();
require_once("../includes/db.php");

if (
    !isset($_SESSION['username']) ||
    !isset($_SESSION['role']) ||
    empty($_SESSION['username']) ||
    empty($_SESSION['role']) ||
    $_SESSION['role'] !== 'Schedule Officer'
) {
    session_unset();
    session_destroy();
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
    header("Location: ../login.php");
    exit();
}

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3600)) {
    session_unset();
    session_destroy();
    header("Location:../login.php");
    exit();
}
$_SESSION['last_activity'] = time();
$fullname = $_SESSION['fullname'] ?? 'Schedule Officer';
$role = $_SESSION['role'] ?? 'schedule_officer';

// Terms acceptance logic for Shift & Schedule Management
if (!isset($_SESSION['schedule_terms_accepted']) || !$_SESSION['schedule_terms_accepted']) {
    $showTermsModal = true;
} else {
    $showTermsModal = false;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_terms'])) {
    $_SESSION['schedule_terms_accepted'] = true;
    header("Location: schedule_officer_dashboard.php");
    exit();
}

// ==================== REAL-TIME ANALYTICS ====================

// Get Dashboard Statistics
$stats_sql = "SELECT 
    (SELECT COUNT(*) FROM employees WHERE status = 'Active') as active_employees,
    (SELECT COUNT(*) FROM employees WHERE status = 'Inactive') as inactive_employees,
    (SELECT COUNT(*) FROM employees) as total_employees,
    (SELECT COUNT(*) FROM shifts) as total_shifts,
    (SELECT COUNT(*) FROM shifts WHERE shift_date >= CURDATE()) as upcoming_shifts,
    (SELECT COUNT(*) FROM shifts WHERE shift_date < CURDATE()) as past_shifts
    FROM DUAL";

$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get Recent Activity Logs
$logs_sql = "SELECT 
    sl.id,
    sl.action_time,
    sl.action,
    sl.status,
    sl.performed_by,
    sl.department,
    e.fullname,
    e.department as emp_dept
    FROM schedule_logs sl
    LEFT JOIN employees e ON sl.employee_id = e.id
    ORDER BY sl.action_time DESC
    LIMIT 10";

$logs_stmt = $pdo->prepare($logs_sql);
$logs_stmt->execute();
$recent_logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Schedules by Department
$dept_sql = "SELECT 
    e.department,
    COUNT(s.id) as shift_count,
    COUNT(DISTINCT s.employee_id) as employee_count
    FROM employees e
    LEFT JOIN shifts s ON e.id = s.employee_id
    WHERE e.status = 'Active'
    GROUP BY e.department
    ORDER BY shift_count DESC";

$dept_stmt = $pdo->prepare($dept_sql);
$dept_stmt->execute();
$dept_stats = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Shift Distribution
$shift_dist_sql = "SELECT 
    shift_type,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100 / (SELECT COUNT(*) FROM shifts), 1) as percentage
    FROM shifts
    GROUP BY shift_type
    ORDER BY count DESC";

$shift_dist_stmt = $pdo->prepare($shift_dist_sql);
$shift_dist_stmt->execute();
$shift_distribution = $shift_dist_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Action Statistics
$action_sql = "SELECT 
    action,
    COUNT(*) as count,
    status
    FROM schedule_logs
    GROUP BY action, status
    ORDER BY count DESC
    LIMIT 6";

$action_stmt = $pdo->prepare($action_sql);
$action_stmt->execute();
$action_stats = $action_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Schedule Officer | ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { transition: all 0.3s ease; }
        html, body { height: 100%; }
        body { 
            font-family: 'QuickSand','Poppins',Arial,sans-serif; 
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
        .topbar .profile-img { 
            width: 45px; 
            height: 45px; 
            border-radius: 50%; 
            object-fit: cover; 
            border: 3px solid #9A66ff; 
        }
        .topbar .profile-info { line-height: 1.1; }
        .topbar .profile-info strong { 
            font-size: 1.08rem; 
            font-weight: 600; 
            color: #22223b; 
        }
        .topbar .profile-info small { 
            color: #9A66ff; 
            font-size: 0.93rem; 
            font-weight: 500; 
        }

        .dashboard-title { 
            font-family: 'QuickSand','Poppins',Arial,sans-serif; 
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
            align-items: center; 
            gap: 1.5rem;
        }

        .stat-card.total { border-left: 5px solid #3b82f6; }
        .stat-card.active { border-left: 5px solid #22c55e; }
        .stat-card.inactive { border-left: 5px solid #ef4444; }
        .stat-card.shifts { border-left: 5px solid #f59e0b; }
        .stat-card.upcoming { border-left: 5px solid #8b5cf6; }
        .stat-card.past { border-left: 5px solid #6b7280; }

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
        .stat-card.active .stat-icon { background: #dcfce7; color: #22c55e; }
        .stat-card.inactive .stat-icon { background: #fee2e2; color: #ef4444; }
        .stat-card.shifts .stat-icon { background: #fef3c7; color: #f59e0b; }
        .stat-card.upcoming .stat-icon { background: #ede9fe; color: #8b5cf6; }
        .stat-card.past .stat-icon { background: #f3f4f6; color: #6b7280; }

        .stat-text h3 { font-size: 2rem; font-weight: 800; margin: 0; color: #22223b; }
        .stat-text p { font-size: 0.9rem; color: #6c757d; margin: 0; }

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

        .table { 
            font-size: 0.98rem; 
            color: #22223b; 
            margin-bottom: 0;
        }

        .table th { 
            color: #6c757d; 
            font-weight: 700; 
            border: none; 
            background: transparent; 
            font-size: 0.92rem; 
            text-transform: uppercase; 
            letter-spacing: 0.5px; 
            padding: 1rem 0.8rem;
        }

        .table td { 
            border-bottom: 1px solid #e8e8f0; 
            padding: 1.2rem 0.8rem; 
            vertical-align: middle;
        }

        .table tbody tr { transition: all 0.2s ease; }
        .table tbody tr:hover { background-color: #f8f9ff; }
        .table tbody tr:last-child td { border-bottom: none; }

        .badge { 
            padding: 0.5rem 0.85rem; 
            border-radius: 20px; 
            font-weight: 600;
        }

        .alert { 
            border-radius: 12px; 
            border: none; 
            border-left: 4px solid; 
            padding: 1.2rem; 
            margin-bottom: 1.5rem;
        }

        .alert-info { 
            background: #dbeafe; 
            color: #1e40af; 
            border-left-color: #0284c7;
        }

        /* Terms Modal Styling */
        .modal-backdrop { 
            background: rgba(0, 0, 0, 0.5) !important;
            backdrop-filter: blur(4px) !important;
        }

        .modal-backdrop.show { 
            opacity: 1 !important;
        }

        .modal { 
            display: none !important;
        }

        .modal.show { 
            display: flex !important;
            align-items: center;
            justify-content: center;
            z-index: 2000 !important;
        }

        .modal-content { 
            border-radius: 18px; 
            border: 1px solid #e0e7ff; 
            box-shadow: 0 10px 40px rgba(70, 57, 130, 0.25);
            max-width: 700px;
            width: 90vw;
        }

        .modal-header { 
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
            color: #fff; 
            border-bottom: none; 
            border-radius: 18px 18px 0 0; 
            padding: 1.8rem 2rem;
        }

        .modal-title { 
            font-size: 1.3rem; 
            font-weight: 700;
            letter-spacing: -0.3px;
        }

        .modal-body { 
            background: #fafbfc; 
            padding: 2rem;
            max-height: 70vh;
            overflow-y: auto;
            font-size: 0.95rem;
            line-height: 1.6;
            color: #22223b;
        }

        .modal-body h6 {
            font-weight: 700;
            color: #22223b;
            margin-top: 1.2rem;
            margin-bottom: 0.8rem;
        }

        .modal-body ul { 
            margin: 0.8rem 0 0 1.8rem;
            padding: 0;
        }

        .modal-body li { 
            margin-bottom: 0.6rem;
            color: #4b5563;
        }

        .modal-body p {
            margin: 0.8rem 0;
            color: #4b5563;
        }

        .modal-footer { 
            background: #fafbfc; 
            border-top: 1px solid #e0e7ff; 
            padding: 1.5rem 2rem;
            border-radius: 0 0 18px 18px;
        }

        .modal-footer .btn { 
            min-height: 45px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
        }

        .btn-close { 
            filter: brightness(1.8);
            opacity: 0.8;
        }

        .btn-close:hover { 
            opacity: 1;
        }

        .chart-container { 
            position: relative; 
            height: 300px; 
            margin-bottom: 1.5rem;
        }

        .stats-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); 
            gap: 1.5rem;
        }

        /* Quick Actions Styling */
        .btn { 
            border: none;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
        }

        .btn-primary:hover {
            background: linear-gradient(90deg, #8654e0 0%, #360090 100%);
            transform: translateY(-2px);
            color: white;
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
            .modal-content { width: 95vw; }
            .modal-body { padding: 1.5rem; }
        }

        @media (max-width: 500px) { 
            .sidebar { width: 100%; } 
            .main-content { padding: 0.8rem 0.5rem; } 
            .card-body { padding: 1rem 0.8rem; } 
            .dashboard-title { font-size: 1.2rem; } 
            .stat-card { padding: 1rem; gap: 1rem; } 
            .stat-icon { width: 50px; height: 50px; font-size: 1.3rem; } 
            .stat-text h3 { font-size: 1.5rem; }
            .table th, .table td { padding: 0.4rem 0.2rem; font-size: 0.75rem; }
            .modal-body { font-size: 0.9rem; }
        }

        @media (min-width: 1400px) { 
            .sidebar { width: 260px; padding: 2rem 1rem; } 
            .content-wrapper { margin-left: 260px; } 
            .main-content { padding: 2.5rem 2.5rem; } 
            .stats-container { grid-template-columns: repeat(6, 1fr); }
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
                    <a class="nav-link active" href="../scheduler/schedule_officer_dashboard.php">
                        <ion-icon name="home-outline"></ion-icon>Dashboard
                    </a>
                </nav>
            </div>
            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Shift & Schedule Management</h6>
                <nav class="nav flex-column">
                    <a class="nav-link" href="../scheduler/shift_scheduling.php">
                        <ion-icon name="calendar-outline"></ion-icon>Shift Scheduling
                    </a>
                    <a class="nav-link" href="../scheduler/edit_update_schedules.php">
                        <ion-icon name="pencil-outline"></ion-icon>Edit/Update Schedules
                    </a>
                    <a class="nav-link" href="../scheduler/schedule_logs.php">
                        <ion-icon name="reader-outline"></ion-icon>Schedule Logs
                    </a>
                    <a class="nav-link" href="../scheduler/schedule_reports.php">
                        <ion-icon name="document-text-outline"></ion-icon>Schedule Reports
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
    <div class="content-wrapper" id="mainDashboardContent">
        <!-- Top Bar -->
        <div class="topbar">
            <span class="dashboard-title">
                <ion-icon name="home-outline"></ion-icon> Welcome back, <?= htmlspecialchars($fullname) ?>!
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
        <div class="main-content" <?php echo $showTermsModal ? 'style="filter: blur(8px); pointer-events: none;"' : ''; ?>>
            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card total">
                    <div class="stat-icon">
                        <ion-icon name="people-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['total_employees'] ?? 0 ?></h3>
                        <p>Total Employees</p>
                    </div>
                </div>
                <div class="stat-card active">
                    <div class="stat-icon">
                        <ion-icon name="checkmark-circle-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['active_employees'] ?? 0 ?></h3>
                        <p>Active</p>
                    </div>
                </div>
                <div class="stat-card inactive">
                    <div class="stat-icon">
                        <ion-icon name="close-circle-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['inactive_employees'] ?? 0 ?></h3>
                        <p>Inactive</p>
                    </div>
                </div>
                <div class="stat-card shifts">
                    <div class="stat-icon">
                        <ion-icon name="calendar-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['total_shifts'] ?? 0 ?></h3>
                        <p>Total Shifts</p>
                    </div>
                </div>
                <div class="stat-card upcoming">
                    <div class="stat-icon">
                        <ion-icon name="arrow-forward-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['upcoming_shifts'] ?? 0 ?></h3>
                        <p>Upcoming</p>
                    </div>
                </div>
                <div class="stat-card past">
                    <div class="stat-icon">
                        <ion-icon name="checkmark-done-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['past_shifts'] ?? 0 ?></h3>
                        <p>Completed</p>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="stats-grid">
                <!-- Shift Distribution Chart -->
                <div class="card">
                    <div class="card-header">
                        <ion-icon name="pie-chart-outline"></ion-icon> Shift Type Distribution
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="shiftChart"></canvas>
                        </div>
                        <div style="font-size: 0.9rem;">
                            <?php foreach ($shift_distribution as $shift): ?>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                    <span><strong><?= htmlspecialchars($shift['shift_type']) ?></strong></span>
                                    <span class="badge" style="background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: white;">
                                        <?= $shift['count'] ?> (<?= $shift['percentage'] ?>%)
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Department Schedules Chart -->
                <div class="card">
                    <div class="card-header">
                        <ion-icon name="bar-chart-outline"></ion-icon> Department Coverage
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="deptChart"></canvas>
                        </div>
                        <div style="font-size: 0.9rem;">
                            <?php foreach ($dept_stats as $dept): ?>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                    <span><strong><?= htmlspecialchars($dept['department'] ?? 'Unassigned') ?></strong></span>
                                    <span class="badge bg-info">
                                        <?= $dept['employee_count'] ?> emp | <?= $dept['shift_count'] ?> shifts
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card">
                <div class="card-header">
                    <ion-icon name="time-outline"></ion-icon> Recent Scheduling Activity
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Employee</th>
                                    <th>Action</th>
                                    <th>Department</th>
                                    <th>Performed By</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_logs)): ?>
                                    <?php foreach ($recent_logs as $log): ?>
                                        <tr>
                                            <td>
                                                <small class="text-muted">
                                                    <?= date('M d, Y H:i', strtotime($log['action_time'])) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($log['fullname'] ?? '-') ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">
                                                    <?= htmlspecialchars($log['action']) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($log['department'] ?? $log['emp_dept'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($log['performed_by']) ?></td>
                                            <td>
                                                <?php 
                                                    $status_class = 'bg-success';
                                                    if ($log['status'] === 'Pending') $status_class = 'bg-warning';
                                                    if ($log['status'] === 'Conflict') $status_class = 'bg-danger';
                                                    if ($log['status'] === 'Rejected') $status_class = 'bg-secondary';
                                                ?>
                                                <span class="badge <?= $status_class ?>">
                                                    <?= htmlspecialchars($log['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">
                                            <ion-icon name="information-circle-outline" style="font-size: 1.5rem; display: block; margin-bottom: 0.5rem;"></ion-icon>
                                            No recent activity found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <ion-icon name="flash-outline"></ion-icon> Quick Actions
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-3 col-sm-6">
                            <a href="shift_scheduling.php" class="btn btn-primary w-100" style="border-radius: 8px; padding: 0.8rem;">
                                <ion-icon name="calendar-outline"></ion-icon>
                                <span style="display: block; margin-top: 0.5rem; font-size: 0.9rem;">Create Shift</span>
                            </a>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <a href="edit_update_schedules.php" class="btn w-100" style="background: #0ea5e9; color: white; border-radius: 8px; padding: 0.8rem; border: none; font-weight: 600;">
                                <ion-icon name="pencil-outline"></ion-icon>
                                <span style="display: block; margin-top: 0.5rem; font-size: 0.9rem;">Edit Schedule</span>
                            </a>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <a href="schedule_reports.php" class="btn w-100" style="background: #f59e0b; color: white; border-radius: 8px; padding: 0.8rem; border: none; font-weight: 600;">
                                <ion-icon name="document-text-outline"></ion-icon>
                                <span style="display: block; margin-top: 0.5rem; font-size: 0.9rem;">View Reports</span>
                            </a>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <a href="schedule_logs.php" class="btn w-100" style="background: #8b5cf6; color: white; border-radius: 8px; padding: 0.8rem; border: none; font-weight: 600;">
                                <ion-icon name="reader-outline"></ion-icon>
                                <span style="display: block; margin-top: 0.5rem; font-size: 0.9rem;">View Logs</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Terms Modal (Improved) -->
    <?php if ($showTermsModal): ?>
        <div class="modal fade show" id="termsModal" tabindex="-1" aria-modal="true" role="dialog" style="display: flex; z-index: 1999;">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <form method="post" class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <ion-icon name="document-text-outline" style="margin-right: 0.5rem;"></ion-icon>
                            Terms & Conditions for Shift & Schedule Management
                        </h5>
                        <button type="button" class="btn-close btn-close-white" disabled style="opacity: 0.5; cursor: not-allowed;"></button>
                    </div>
                    <div class="modal-body">
                        <p style="font-size: 1rem; margin-bottom: 1.2rem; color: #4b5563; font-weight: 500;">
                            Welcome to the Shift & Schedule Management module of the ViaHale HR System. By using this system, you agree to abide by the following Terms and Conditions.
                        </p>
                        
                        <hr style="margin: 1.5rem 0; border-color: #e0e7ff;">

                        <h6>📋 1. Legal Compliance</h6>
                        <p style="margin-bottom: 1rem;">All scheduling, shift management, and employee data handling within this system are subject to:</p>
                        <ul>
                            <li>Labor Code of the Philippines (PD 442) - Articles 82-94 governing working hours, overtime, rest days, and holidays</li>
                            <li>DOLE Department Orders and Advisories - Rules and guidelines for flexible work arrangements</li>
                            <li>Occupational Safety and Health Standards (OSHS) - Ensuring schedules don't compromise employee health</li>
                            <li>Data Privacy Act of 2012 (RA 10173) - Secure handling of employee personal and schedule data</li>
                            <li>Company Policy and CBA - Internal scheduling rules and employee rights</li>
                            <li>BIR and DOLE Record-Keeping Requirements - Maintain accurate records for 3-5 years</li>
                        </ul>

                        <h6>👤 2. User Responsibilities</h6>
                        <p>All users are responsible for ensuring the accuracy, fairness, and legality of schedules. Falsification or unauthorized alteration is strictly prohibited.</p>

                        <h6>🛡️ 3. Employee Rights</h6>
                        <p>Employees have the right to be informed of assigned schedules and must be able to submit requests for shift swaps and availability updates.</p>

                        <h6>🔐 4. Data Privacy and Security</h6>
                        <p>All employee data is handled in strict compliance with the Data Privacy Act. Only authorized users may access or modify schedules.</p>

                        <h6>📊 5. Record-Keeping and Audit</h6>
                        <p>All scheduling actions and logs are retained for audit and compliance purposes per legal requirements.</p>

                        <div style="background: #f0f9ff; border-left: 4px solid #0284c7; padding: 1.2rem; border-radius: 8px; margin-top: 1.5rem;">
                            <p style="margin: 0; color: #0c4a6e; font-weight: 500;">
                                <ion-icon name="information-circle-outline" style="margin-right: 0.5rem; vertical-align: middle;"></ion-icon>
                                <strong>Important:</strong> By clicking "I Accept and Proceed", you acknowledge that you have read, understood, and agree to comply with all terms and conditions outlined above.
                            </p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="accept_terms" class="btn btn-primary w-100" style="padding: 0.75rem;">
                            <ion-icon name="checkmark-circle-outline" style="margin-right: 0.5rem;"></ion-icon>
                            I Accept and Proceed
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal Backdrop -->
        <div class="modal-backdrop fade show" id="termsBackdrop" style="display: block; z-index: 1998;"></div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Initialize Charts
    document.addEventListener('DOMContentLoaded', function() {
        // Shift Distribution Pie Chart
        const shiftCtx = document.getElementById('shiftChart');
        if (shiftCtx) {
            new Chart(shiftCtx, {
                type: 'doughnut',
                data: {
                    labels: [<?php foreach ($shift_distribution as $s) echo "'" . htmlspecialchars($s['shift_type']) . "',"; ?>],
                    datasets: [{
                        data: [<?php foreach ($shift_distribution as $s) echo $s['count'] . ","; ?>],
                        backgroundColor: ['#9A66ff', '#4311a5', '#667eea', '#8b5cf6', '#a78bfa']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { 
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                font: { size: 12, weight: '600' }
                            }
                        } 
                    }
                }
            });
        }

        // Department Bar Chart
        const deptCtx = document.getElementById('deptChart');
        if (deptCtx) {
            new Chart(deptCtx, {
                type: 'bar',
                data: {
                    labels: [<?php foreach ($dept_stats as $d) echo "'" . htmlspecialchars($d['department'] ?? 'Unassigned') . "',"; ?>],
                    datasets: [{
                        label: 'Shift Count',
                        data: [<?php foreach ($dept_stats as $d) echo $d['shift_count'] . ","; ?>],
                        backgroundColor: ['#9A66ff', '#4311a5', '#667eea', '#8b5cf6', '#a78bfa']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { 
                        y: { 
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        } 
                    },
                    plugins: { 
                        legend: { 
                            display: true,
                            labels: {
                                padding: 15,
                                font: { size: 12, weight: '600' }
                            }
                        } 
                    }
                }
            });
        }
    });

    // Session timeout check
    setTimeout(function() {
        window.location.href = "../login.php?session=expired";
    }, 3600000);
</script>
</body>
</html>