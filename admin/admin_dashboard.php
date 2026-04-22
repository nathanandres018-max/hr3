<?php
session_start();
include_once("../connection.php");

// Require logged-in user
if (!isset($_SESSION['username']) || empty($_SESSION['username'])) {
    header("Location: ../login.php");
    exit();
}

// Session timeout check
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3600)) {
    session_unset();
    session_destroy();
    header("Location: ../login.php");
    exit();
}
$_SESSION['last_activity'] = time();

$fullname = $_SESSION['fullname'] ?? 'Administrator';
$role = $_SESSION['role'] ?? 'admin';

// Get Dashboard Statistics using prepared statements
$stats = [
    'total_employees' => 0,
    'active_employees' => 0,
    'inactive_employees' => 0,
    'total_shifts' => 0,
    'upcoming_shifts' => 0,
    'past_shifts' => 0,
    'pending_leaves' => 0,
    'approved_leaves' => 0
];

// Total Employees
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM employees");
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $stats['total_employees'] = $row['count'];
}
$stmt->close();

// Active Employees
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM employees WHERE status = 'Active'");
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $stats['active_employees'] = $row['count'];
}
$stmt->close();

// Inactive Employees
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM employees WHERE status = 'Inactive'");
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $stats['inactive_employees'] = $row['count'];
}
$stmt->close();

// Total Shifts
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM shifts");
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $stats['total_shifts'] = $row['count'];
}
$stmt->close();

// Upcoming Shifts
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM shifts WHERE shift_date >= CURDATE()");
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $stats['upcoming_shifts'] = $row['count'];
}
$stmt->close();

// Past Shifts
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM shifts WHERE shift_date < CURDATE()");
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $stats['past_shifts'] = $row['count'];
}
$stmt->close();

// Pending Leave Requests
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM leave_requests WHERE status = 'pending'");
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $stats['pending_leaves'] = $row['count'];
}
$stmt->close();

// Approved Leave Requests
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM leave_requests WHERE status = 'approved'");
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $stats['approved_leaves'] = $row['count'];
}
$stmt->close();

// Recent Activity Logs
$recent_logs = [];
$stmt = $conn->prepare("
    SELECT sl.id, sl.action_time, sl.action, sl.status, sl.performed_by, e.fullname
    FROM schedule_logs sl
    LEFT JOIN employees e ON sl.employee_id = e.id
    ORDER BY sl.action_time DESC
    LIMIT 10
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_logs[] = $row;
}
$stmt->close();

// Get Shift Distribution
$shift_distribution = [];
$stmt = $conn->prepare("
    SELECT shift_type, COUNT(*) as count
    FROM shifts
    GROUP BY shift_type
    ORDER BY count DESC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $shift_distribution[] = $row;
}
$stmt->close();

// Get Department Statistics
$dept_stats = [];
$stmt = $conn->prepare("
    SELECT department, COUNT(*) as employee_count
    FROM employees
    WHERE status = 'Active'
    GROUP BY department
    ORDER BY employee_count DESC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $dept_stats[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - ViaHale TNVS HR3</title>
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
        .stat-card.leaves { border-left: 5px solid #8b5cf6; }

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
        .stat-card.leaves .stat-icon { background: #ede9fe; color: #8b5cf6; }

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

        .chart-container { 
            position: relative; 
            height: 300px; 
            margin-bottom: 1.5rem;
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
            color: #0c4a6e; 
            border-left-color: #0284c7;
        }

        .btn { 
            border: none; 
            border-radius: 8px; 
            font-weight: 600; 
            transition: all 0.2s ease; 
            padding: 0.65rem 1.2rem; 
        }

        .btn-primary { 
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
            color: white;
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
        }

        @media (max-width: 500px) { 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; } 
            .main-content { padding: 0.8rem 0.5rem; }
            .table { font-size: 0.85rem; }
            .table th, .table td { padding: 0.4rem 0.2rem; font-size: 0.75rem; }
        }

        @media (min-width: 1400px) { 
            .sidebar { width: 260px; padding: 2rem 1rem; } 
            .content-wrapper { margin-left: 260px; } 
            .main-content { padding: 2.5rem 2.5rem; } 
            .stats-container { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
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
                    <a class="nav-link active" href="../admin/admin_dashboard.php">
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
                    <a class="nav-link" href="../admin/schedule_logs.php">
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

    <!-- Main Content -->
    <div class="content-wrapper">
        <!-- Top Bar -->
        <div class="topbar">
            <h1 class="dashboard-title">Admin Dashboard</h1>
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
                        <ion-icon name="people-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['total_employees'] ?></h3>
                        <p>Total Employees</p>
                    </div>
                </div>

                <div class="stat-card active">
                    <div class="stat-icon">
                        <ion-icon name="checkmark-circle-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['active_employees'] ?></h3>
                        <p>Active</p>
                    </div>
                </div>

                <div class="stat-card inactive">
                    <div class="stat-icon">
                        <ion-icon name="close-circle-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['inactive_employees'] ?></h3>
                        <p>Inactive</p>
                    </div>
                </div>

                <div class="stat-card shifts">
                    <div class="stat-icon">
                        <ion-icon name="calendar-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['total_shifts'] ?></h3>
                        <p>Total Shifts</p>
                    </div>
                </div>

                <div class="stat-card leaves">
                    <div class="stat-icon">
                        <ion-icon name="document-text-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['pending_leaves'] ?></h3>
                        <p>Pending Leaves</p>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row g-3 mb-3">
                <!-- Shift Distribution Chart -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <ion-icon name="pie-chart-outline"></ion-icon> Shift Distribution
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
                                            <?= $shift['count'] ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Department Distribution -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <ion-icon name="bar-chart-outline"></ion-icon> Employees by Department
                        </div>
                        <div class="card-body">
                            <div style="font-size: 0.9rem;">
                                <?php foreach ($dept_stats as $dept): ?>
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.8rem;">
                                        <span><strong><?= htmlspecialchars($dept['department'] ?? 'Unassigned') ?></strong></span>
                                        <span class="badge bg-info">
                                            <?= $dept['employee_count'] ?> employees
                                        </span>
                                    </div>
                                    <div style="background: #e0e7ff; height: 8px; border-radius: 4px; margin-bottom: 1rem;">
                                        <div style="background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); height: 100%; border-radius: 4px; width: <?= min(($dept['employee_count'] / max(array_column($dept_stats, 'employee_count'))) * 100, 100) ?>%;"></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card">
                <div class="card-header">
                    <ion-icon name="time-outline"></ion-icon> Recent Activity Logs
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Employee</th>
                                    <th>Action</th>
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
                                                <strong><?= htmlspecialchars($log['fullname'] ?? 'System') ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">
                                                    <?= htmlspecialchars($log['action']) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($log['performed_by']) ?></td>
                                            <td>
                                                <?php 
                                                    $status_class = 'bg-success';
                                                    if ($log['status'] === 'Pending') $status_class = 'bg-warning';
                                                    if ($log['status'] === 'Failed') $status_class = 'bg-danger';
                                                ?>
                                                <span class="badge <?= $status_class ?>">
                                                    <?= htmlspecialchars($log['status'] ?? 'N/A') ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">
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
                            <a href="../admin/attendance_logs.php" class="btn btn-info w-100" style="border-radius: 8px; padding: 0.8rem; background: #0ea5e9; border: none;">
                                <ion-icon name="list-outline"></ion-icon>
                                <span style="display: block; margin-top: 0.5rem; font-size: 0.9rem;">Attendance Logs</span>
                            </a>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <a href="../admin/attendance_stats.php" class="btn w-100" style="background: #f59e0b; color: white; border-radius: 8px; padding: 0.8rem; border: none;">
                                <ion-icon name="stats-chart-outline"></ion-icon>
                                <span style="display: block; margin-top: 0.5rem; font-size: 0.9rem;">Statistics</span>
                            </a>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <a href="../admin/face_enrollment.php" class="btn w-100" style="background: #8b5cf6; color: white; border-radius: 8px; padding: 0.8rem; border: none;">
                                <ion-icon name="camera-outline"></ion-icon>
                                <span style="display: block; margin-top: 0.5rem; font-size: 0.9rem;">Face Enrollment</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
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
                        backgroundColor: ['#9A66ff', '#4311a5', '#667eea', '#8b5cf6', '#a855f7']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { position: 'bottom' }
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