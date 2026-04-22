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

// Handle extraction of approved timesheet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extract_timesheet'])) {
    $timesheet_id = (int)$_POST['timesheet_id'];
    
    // Get timesheet details
    $stmt = $pdo->prepare("
        SELECT t.*, e.id as emp_id, e.employee_id, e.fullname
        FROM timesheets t
        JOIN employees e ON t.employee_id = e.id
        WHERE t.id = ? AND t.status = 'approved'
    ");
    $stmt->execute([$timesheet_id]);
    $ts = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ts) {
        // Get attendance logs for this period
        $stmt2 = $pdo->prepare("
            SELECT * FROM attendance
            WHERE employee_id = ? AND date >= ? AND date <= ? AND time_out IS NOT NULL
            ORDER BY date
        ");
        $stmt2->execute([$ts['employee_id'], $ts['period_from'], $ts['period_to']]);
        $logs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        $total_hours = 0;
        $total_minutes = 0;
        $total_days = count($logs);
        
        foreach ($logs as $log) {
            if ($log['time_in'] && $log['time_out']) {
                $start = strtotime($log['time_in']);
                $end = strtotime($log['time_out']);
                $seconds = $end - $start;
                $total_minutes += round($seconds / 60);
            }
        }
        
        // Convert total minutes to hours and minutes
        $total_hours = floor($total_minutes / 60);
        $remaining_minutes = $total_minutes % 60;
        
        // Prepare payload for microservice API
        $payload = [
            'employee_code' => $ts['employee_id'],
            'employee_name' => $ts['fullname'],
            'period_from' => $ts['period_from'],
            'period_to' => $ts['period_to'],
            'total_days_worked' => $total_days,
            'total_hours' => $total_hours,
            'total_minutes' => $remaining_minutes,
            'attendance_details' => $logs,
            'extraction_date' => date('Y-m-d H:i:s'),
            'extracted_by' => $fullname,
            'extraction_status' => 'ready'
        ];
        
        // Insert into extracted_timesheets table
        $insert = $pdo->prepare("
            INSERT INTO extracted_timesheets 
            (timesheet_id, employee_id, employee_code, fullname, period_from, period_to, 
             total_days_worked, total_hours, total_minutes, extracted_by, status, payload)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ");
        
        $insert->execute([
            $timesheet_id,
            $ts['emp_id'],
            $ts['employee_id'],
            $ts['fullname'],
            $ts['period_from'],
            $ts['period_to'],
            $total_days,
            $total_hours,
            $remaining_minutes,
            $fullname,
            json_encode($payload)
        ]);
        
        $_SESSION['success_message'] = "Timesheet extracted successfully!";
    } else {
        $_SESSION['error_message'] = "Only approved timesheets can be extracted.";
    }
    
    header("Location: timesheet_reports.php");
    exit();
}

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_timesheets,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
    FROM timesheets";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Fetch all timesheets and associated employee info
$stmt = $pdo->query("
    SELECT t.*, e.fullname, e.username, e.employee_id
    FROM timesheets t
    JOIN employees e ON t.employee_id = e.id
    ORDER BY t.submitted_at DESC
    LIMIT 50
");
$timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Timesheet Reports - Admin | ViaHale TNVS HR3</title>
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
            transition: all 0.3s ease;
        }

        .stat-card:hover { 
            transform: translateY(-4px); 
            box-shadow: 0 8px 24px rgba(140,140,200,0.15); 
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
        .table tbody tr:hover { background: #f8f8fb; }
        .table tbody tr:last-child td { border-bottom: none; }

        .status-badge { 
            padding: 0.5rem 0.85rem; 
            border-radius: 20px; 
            font-size: 0.85rem; 
            font-weight: 600; 
            display: inline-block;
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

        .btn-sm { 
            padding: 0.5rem 1rem; 
            font-size: 0.85rem; 
            border-radius: 6px;
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

        .alert-danger { 
            background: #fee2e2; 
            color: #991b1b; 
            border-left-color: #dc2626;
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

        .modal-footer { 
            border-top: 1px solid #e0e7ff; 
            padding: 1.2rem 2rem; 
            background: #fafbfc;
        }

        .btn-close { filter: brightness(1.8); }

        .badge { padding: 0.5rem 0.85rem; border-radius: 20px; font-weight: 600; }

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
            .table th, .table td { padding: 0.8rem 0.5rem; font-size: 0.85rem; }
            .topbar { flex-direction: column; gap: 1rem; justify-content: flex-start; }
        }

        @media (max-width: 500px) { 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; } 
            .main-content { padding: 0.8rem 0.5rem; }
            .table { font-size: 0.8rem; }
            .table th, .table td { padding: 0.6rem 0.3rem; }
            .btn-sm { padding: 0.4rem 0.8rem; font-size: 0.75rem; }
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
                    <a class="nav-link active" href="../admin/timesheet_reports.php">
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

    <!-- Main Content Wrapper -->
    <div class="content-wrapper">
        <!-- Top Bar -->
        <div class="topbar">
            <h1 class="dashboard-title">Timesheet Reports</h1>
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
                        <ion-icon name="document-text-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['total_timesheets'] ?? 0 ?></h3>
                        <p>Total Timesheets</p>
                    </div>
                </div>
                <div class="stat-card approved">
                    <div class="stat-icon">
                        <ion-icon name="checkmark-circle-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['approved_count'] ?? 0 ?></h3>
                        <p>Approved</p>
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
                <div class="stat-card rejected">
                    <div class="stat-icon">
                        <ion-icon name="close-circle-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['rejected_count'] ?? 0 ?></h3>
                        <p>Rejected</p>
                    </div>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <ion-icon name="checkmark-circle-outline" style="margin-right: 0.5rem;"></ion-icon>
                    <strong>Success!</strong> <?= htmlspecialchars($_SESSION['success_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <ion-icon name="alert-circle-outline" style="margin-right: 0.5rem;"></ion-icon>
                    <strong>Error!</strong> <?= htmlspecialchars($_SESSION['error_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Timesheets Table -->
            <div class="card">
                <div class="card-header">
                    <ion-icon name="document-text-outline"></ion-icon> All Submitted Timesheets
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Employee ID</th>
                                    <th>Employee</th>
                                    <th>Username</th>
                                    <th>Period</th>
                                    <th>Submitted At</th>
                                    <th>Status</th>
                                    <th style="width: 200px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($timesheets as $ts): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($ts['employee_id']) ?></strong></td>
                                    <td><?= htmlspecialchars($ts['fullname']) ?></td>
                                    <td>@<?= htmlspecialchars($ts['username']) ?></td>
                                    <td>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($ts['period_from']) ?> to <?= htmlspecialchars($ts['period_to']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small>
                                            <?= htmlspecialchars(date('M d, Y H:i', strtotime($ts['submitted_at']))) ?>
                                        </small>
                                    </td>
                                    <td>
                                      <?php
                                      $status = strtolower($ts['status'] ?? 'pending');
                                      $badge_class = 'pending';
                                      if ($status === 'approved') $badge_class = 'approved';
                                      elseif ($status === 'rejected') $badge_class = 'rejected';
                                      ?>
                                      <span class="status-badge <?= $badge_class ?>">
                                        <ion-icon name="<?= $status === 'approved' ? 'checkmark-circle-outline' : ($status === 'rejected' ? 'close-circle-outline' : 'time-outline') ?>" style="margin-right: 0.3rem; font-size: 0.9rem; vertical-align: middle;"></ion-icon>
                                        <?= htmlspecialchars(ucfirst($status)) ?>
                                      </span>
                                    </td>
                                    <td>
                                        <button type="button"
                                          class="btn btn-sm btn-primary view-details-btn"
                                          data-timesheet-id="<?= $ts['id'] ?>"
                                          data-bs-toggle="modal"
                                          data-bs-target="#detailsModal"
                                          style="margin-right: 0.5rem;">
                                          <ion-icon name="eye-outline"></ion-icon> View
                                        </button>
                                        <?php if ($status === 'approved'): ?>
                                          <form method="post" style="display: inline;">
                                            <input type="hidden" name="timesheet_id" value="<?= $ts['id'] ?>">
                                            <button type="submit" name="extract_timesheet" class="btn btn-sm btn-success">
                                              <ion-icon name="download-outline"></ion-icon> Extract
                                            </button>
                                          </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($timesheets)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <ion-icon name="document-outline" style="font-size: 2.5rem; color: #d1d5db; display: block; margin-bottom: 0.5rem;"></ion-icon>
                                        No timesheets found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
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
                    <ion-icon name="document-text-outline"></ion-icon> Timesheet Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="modalLoading" style="display:none;">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="text-muted mt-2">Loading timesheet details...</p>
                    </div>
                </div>
                <div id="modalTimesheetDetails"></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// AJAX modal logic
document.querySelectorAll('.view-details-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var id = this.dataset.timesheetId;
        document.getElementById('modalTimesheetDetails').innerHTML = '';
        document.getElementById('modalLoading').style.display = '';
        
        fetch('timesheet_modal_data.php?id=' + encodeURIComponent(id))
            .then(r => r.text())
            .then(html => {
                document.getElementById('modalTimesheetDetails').innerHTML = html;
                document.getElementById('modalLoading').style.display = 'none';
            })
            .catch(err => {
                document.getElementById('modalTimesheetDetails').innerHTML = '<div class="alert alert-danger">Error loading timesheet details.</div>';
                document.getElementById('modalLoading').style.display = 'none';
            });
    });
});
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
