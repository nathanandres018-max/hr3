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

// For dynamic leave types:
$types_stmt = $pdo->query("SELECT type, quota FROM leave_types ORDER BY type ASC");
$leave_types = $types_stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter by employee name (optional)
$search_emp = $_GET['employee'] ?? '';
$where_sql = '';
$params = [];
if ($search_emp) {
    $where_sql = 'WHERE e.fullname LIKE ?';
    $params[] = "%$search_emp%";
}

// Get all employees
$stmt = $pdo->prepare("SELECT e.id, e.fullname, e.employee_id, e.department FROM employees e $where_sql ORDER BY e.fullname ASC");
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build leave balances for each employee
$balances = [];
foreach ($employees as $emp) {
    $emp_id = $emp['id'];
    $emp_name = $emp['fullname'];
    $emp_emp_id = $emp['employee_id'];
    $emp_dept = $emp['department'];
    $emp_bal = [];
    foreach ($leave_types as $lt) {
        $type = $lt['type']; 
        $quota = $lt['quota'];
        $stmt2 = $pdo->prepare("SELECT date_from, date_to FROM leave_requests WHERE employee_id = ? AND leave_type = ? AND status = 'approved' AND YEAR(date_from) = YEAR(CURDATE())");
        $stmt2->execute([$emp_id, $type]);
        $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        $used = 0;
        foreach ($rows as $row) {
            $days = (strtotime($row['date_to']) - strtotime($row['date_from'])) / 86400 + 1;
            $used += $days > 0 ? $days : 1;
        }
        $remaining = $quota == 0 ? -1 : max(0, $quota - $used);
        $emp_bal[$type] = ['used' => $used, 'quota' => $quota, 'remaining' => $remaining];
    }
    $balances[] = ['id' => $emp_id, 'emp_id' => $emp_emp_id, 'fullname' => $emp_name, 'department' => $emp_dept, 'leaves' => $emp_bal];
}

// DUMMY DATA for presentation if there are no records from DB
if (empty($balances)) {
    // If leave types are empty, provide defaults for dummy data
    if (empty($leave_types)) {
        $leave_types = [
            ['type' => 'Vacation', 'quota' => 15],
            ['type' => 'Sick', 'quota' => 10],
            ['type' => 'Emergency', 'quota' => 5]
        ];
    }
    $dummy_employees = [
        ['id' => 101, 'fullname' => 'Juan Dela Cruz', 'employee_id' => 'EMP001', 'department' => 'HR'],
        ['id' => 102, 'fullname' => 'Maria Santos', 'employee_id' => 'EMP002', 'department' => 'Finance'],
        ['id' => 103, 'fullname' => 'Pedro Reyes', 'employee_id' => 'EMP003', 'department' => 'Logistics'],
        ['id' => 104, 'fullname' => 'Ana Lopez', 'employee_id' => 'EMP004', 'department' => 'IT']
    ];
    $dummy_balances = [
        // Format: [used vacation, used sick, used emergency]
        [2, 1, 0],
        [5, 3, 1],
        [0, 0, 0],
        [7, 2, 2]
    ];
    $balances = [];
    foreach ($dummy_employees as $i => $emp) {
        $emp_bal = [];
        foreach ($leave_types as $j => $lt) {
            $type = $lt['type'];
            $quota = $lt['quota'];
            $used = $dummy_balances[$i][$j] ?? 0;
            $remaining = $quota == 0 ? -1 : max(0, $quota - $used);
            $emp_bal[$type] = ['used' => $used, 'quota' => $quota, 'remaining' => $remaining];
        }
        $balances[] = ['id' => $emp['id'], 'emp_id' => $emp['employee_id'], 'fullname' => $emp['fullname'], 'department' => $emp['department'], 'leaves' => $emp_bal];
    }
}

// Calculate total balance across all employees
$total_quota = 0;
$total_used = 0;
foreach ($balances as $bal) {
    foreach ($leave_types as $lt) {
        $type = $lt['type'];
        if (isset($bal['leaves'][$type])) {
            $quota = $bal['leaves'][$type]['quota'];
            $used = $bal['leaves'][$type]['used'];
            if ($quota > 0) {
                $total_quota += $quota;
                $total_used += $used;
            }
        }
    }
}

// Current year
$current_year = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leave Balance - HR Manager | ViaHale TNVS HR3</title>
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

        .stat-card.quota { border-left: 5px solid #3b82f6; }
        .stat-card.used { border-left: 5px solid #f59e0b; }
        .stat-card.remaining { border-left: 5px solid #22c55e; }

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

        .stat-card.quota .stat-value { color: #3b82f6; }
        .stat-card.used .stat-value { color: #f59e0b; }
        .stat-card.remaining .stat-value { color: #22c55e; }

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

        .search-box { 
            border-radius: 8px; 
            font-size: 1rem; 
            padding: 0.8rem 1.2rem; 
            border: 1px solid #e0e7ff; 
            background: #fff;
            flex: 1;
            min-width: 260px;
        }

        .search-box:focus { 
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

        .btn-secondary { 
            background: #6b7280; 
            border: none; 
            border-radius: 8px; 
            padding: 0.65rem 1.5rem;
            color: white;
            font-weight: 600;
        }

        .btn-secondary:hover { 
            background: #4b5563;
            transform: translateY(-2px);
        }

        .btn-view-balance { 
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%); 
            color: white; 
            border: none; 
            border-radius: 8px; 
            padding: 0.45rem 0.9rem; 
            font-weight: 600; 
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .btn-view-balance:hover { 
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            color: white;
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

        .employee-dept { 
            display: inline-block; 
            background: #e0e7ff; 
            color: #4311a5; 
            padding: 0.25rem 0.6rem; 
            border-radius: 4px; 
            font-weight: 600; 
            font-size: 0.75rem;
        }

        .balance-summary { 
            display: flex; 
            gap: 1.5rem; 
            align-items: center;
            text-align: center;
        }

        .balance-item { 
            flex: 1;
        }

        .balance-item-value { 
            font-weight: 700; 
            color: #22223b; 
            font-size: 1.1rem;
        }

        .balance-item-label { 
            font-size: 0.8rem; 
            color: #6c757d; 
            margin-top: 0.3rem; 
            font-weight: 600;
        }

        .progress-bar-custom { 
            height: 24px; 
            border-radius: 12px;
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

        .modal-title { 
            font-size: 1.23rem; 
            font-weight: 700;
        }

        .modal-body { 
            background: #fafbfc; 
            padding: 1.7rem 1.5rem;
        }

        .modal-footer { 
            background: #fafbfc; 
            border-top: 1px solid #e0e7ff; 
            padding: 1.2rem 1.5rem;
            border-radius: 0 0 18px 18px;
        }

        .btn-close { 
            filter: brightness(1.8);
        }

        .leave-type-row { 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid #e8e8f0;
        }

        .leave-type-row:last-child { 
            border-bottom: none;
        }

        .leave-type-name { 
            font-weight: 600; 
            color: #22223b;
            flex: 1;
        }

        .leave-type-values { 
            display: flex; 
            gap: 1.5rem; 
            flex: 1;
            justify-content: flex-end;
            text-align: right;
        }

        .leave-value { 
            display: flex; 
            flex-direction: column; 
            align-items: center;
        }

        .leave-value-number { 
            font-size: 1.3rem; 
            font-weight: 700;
        }

        .leave-value-label { 
            font-size: 0.75rem; 
            color: #6c757d; 
            margin-top: 0.2rem;
        }

        .progress-wrapper { 
            width: 150px;
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .progress { 
            height: 8px; 
            border-radius: 4px; 
            background: #e8e8f0;
        }

        .progress-bar { 
            border-radius: 4px; 
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
        }

        .progress-label { 
            font-size: 0.75rem; 
            color: #6c757d; 
            text-align: center;
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
            .search-box { min-width: 100%; }
            .modal-body { padding: 1.2rem; }
            .modal-footer { padding: 1rem 1.2rem; }
            .topbar { flex-direction: column; gap: 1rem; justify-content: flex-start; }
            .balance-summary { flex-direction: column; gap: 1rem; }
        }

        @media (max-width: 500px) { 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; } 
            .main-content { padding: 0.8rem 0.5rem; }
            .card-body { padding: 1rem 0.8rem; }
            .dashboard-title { font-size: 1.2rem; }
            .stat-card { padding: 1rem; }
            .table th, .table td { padding: 0.8rem 0.5rem; font-size: 0.85rem; }
        }

        @media (min-width: 1400px) { 
            .sidebar { width: 260px; padding: 2rem 1rem; } 
            .content-wrapper { margin-left: 260px; } 
            .main-content { padding: 2.5rem 2.5rem; } 
            .stats-container { grid-template-columns: repeat(3, 1fr); }
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
                    <a class="nav-link active" href="../manager/leave_balance.php"><ion-icon name="wallet-outline"></ion-icon>Leave Balance</a>
                    <a class="nav-link" href="../manager/leave_history.php"><ion-icon name="time-outline"></ion-icon>Leave History</a>
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
                <ion-icon name="bar-chart-outline"></ion-icon> Leave Balance Management
            </span>
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
            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card quota">
                    <div class="stat-label">Total Quota</div>
                    <div class="stat-value"><?= $total_quota ?></div>
                    <small style="color: #6c757d;">Days across all employees</small>
                </div>
                <div class="stat-card used">
                    <div class="stat-label">Total Used</div>
                    <div class="stat-value"><?= $total_used ?></div>
                    <small style="color: #6c757d;">Days used this year (<?= $current_year ?>)</small>
                </div>
                <div class="stat-card remaining">
                    <div class="stat-label">Total Remaining</div>
                    <div class="stat-value"><?= max(0, $total_quota - $total_used) ?></div>
                    <small style="color: #6c757d;">Days available</small>
                </div>
            </div>

            <!-- Search Section -->
            <div class="search-section">
                <form method="get" action="">
                    <div style="display: flex; gap: 1rem; align-items: center; flex: 1; flex-wrap: wrap;">
                        <label style="font-weight: 600; margin: 0; white-space: nowrap;">
                            <ion-icon name="search-outline" style="margin-right: 0.4rem;"></ion-icon>Search Employee:
                        </label>
                        <input type="text" class="search-box" name="employee" value="<?= htmlspecialchars($search_emp) ?>" placeholder="Enter employee name">
                    </div>
                    <button class="btn btn-primary" type="submit">
                        <ion-icon name="search-outline"></ion-icon> Search
                    </button>
                    <?php if ($search_emp): ?>
                        <a href="leave_balance.php" class="btn btn-secondary">
                            <ion-icon name="refresh-outline"></ion-icon> Reset
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Leave Balance Table -->
            <div class="card">
                <div class="card-header">
                    <ion-icon name="list-outline"></ion-icon> Employee Leave Balance Overview (<?= $current_year ?>)
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($balances)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>
                                            <ion-icon name="person-outline"></ion-icon> Employee
                                        </th>
                                        <th style="text-align: center;">Total Balance</th>
                                        <th style="text-align: center;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($balances as $row): ?>
                                        <?php
                                            $emp_total_quota = 0;
                                            $emp_total_used = 0;
                                            foreach ($leave_types as $lt) {
                                                $type = $lt['type'];
                                                if (isset($row['leaves'][$type])) {
                                                    $quota = $row['leaves'][$type]['quota'];
                                                    $used = $row['leaves'][$type]['used'];
                                                    if ($quota > 0) {
                                                        $emp_total_quota += $quota;
                                                        $emp_total_used += $used;
                                                    }
                                                }
                                            }
                                            $emp_total_remaining = max(0, $emp_total_quota - $emp_total_used);
                                            $used_percent = $emp_total_quota > 0 ? round(($emp_total_used / $emp_total_quota) * 100) : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="employee-cell">
                                                    <span class="employee-name"><?= htmlspecialchars($row['fullname']) ?></span>
                                                    <span class="employee-meta">
                                                        <strong style="color: #667eea;"><?= htmlspecialchars($row['emp_id']) ?></strong>
                                                        <?php if ($row['department']): ?>
                                                            <span class="employee-dept"><?= htmlspecialchars($row['department']) ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td style="text-align: center;">
                                                <div class="balance-summary" style="justify-content: center;">
                                                    <div class="balance-item">
                                                        <div class="balance-item-value" style="color: #f59e0b;"><?= $emp_total_used ?></div>
                                                        <div class="balance-item-label">Used</div>
                                                    </div>
                                                    <div class="balance-item">
                                                        <div class="balance-item-value" style="color: #667eea;"><?= $emp_total_quota ?></div>
                                                        <div class="balance-item-label">Quota</div>
                                                    </div>
                                                    <div class="balance-item">
                                                        <div class="balance-item-value" style="color: #10b981;"><?= $emp_total_remaining ?></div>
                                                        <div class="balance-item-label">Remaining</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td style="text-align: center;">
                                                <button class="btn-view-balance" data-bs-toggle="modal" data-bs-target="#balanceModal<?= $row['id'] ?>">
                                                    <ion-icon name="eye-outline"></ion-icon> View Details
                                                </button>
                                            </td>
                                        </tr>

                                        <!-- Balance Details Modal -->
                                        <div class="modal fade" id="balanceModal<?= $row['id'] ?>" tabindex="-1" aria-labelledby="balanceLabel<?= $row['id'] ?>" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title" id="balanceLabel<?= $row['id'] ?>">
                                                            <ion-icon name="bar-chart-outline"></ion-icon> Leave Balance Details — <?= htmlspecialchars($row['fullname']) ?>
                                                        </h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div style="margin-bottom: 1.5rem;">
                                                            <strong><?= htmlspecialchars($row['fullname']) ?></strong>
                                                            <div style="font-size: 0.9rem; color: #6c757d; margin-top: 0.3rem;">
                                                                <span style="display: inline-block; margin-right: 1rem;">ID: <?= htmlspecialchars($row['emp_id']) ?></span>
                                                                <span>Dept: <?= htmlspecialchars($row['department'] ?? 'N/A') ?></span>
                                                            </div>
                                                        </div>
                                                        <hr style="margin: 1rem 0;">
                                                        <h6 style="font-weight: 700; margin-bottom: 1rem; color: #22223b;">Leave Type Breakdown (<?= $current_year ?>)</h6>
                                                        <?php foreach ($leave_types as $lt): 
                                                            $type = $lt['type'];
                                                            if (!isset($row['leaves'][$type])) continue;
                                                            
                                                            $quota = $row['leaves'][$type]['quota'];
                                                            $used = $row['leaves'][$type]['used'];
                                                            $remaining = $row['leaves'][$type]['remaining'];
                                                            $percent = $quota > 0 ? round(($used / $quota) * 100) : 0;
                                                        ?>
                                                            <div class="leave-type-row">
                                                                <div class="leave-type-name"><?= htmlspecialchars($type) ?></div>
                                                                <div class="leave-type-values">
                                                                    <div class="leave-value">
                                                                        <div class="leave-value-number" style="color: #f59e0b;"><?= $used ?></div>
                                                                        <div class="leave-value-label">Used</div>
                                                                    </div>
                                                                    <div class="leave-value">
                                                                        <div class="leave-value-number" style="color: #667eea;"><?= $quota ?></div>
                                                                        <div class="leave-value-label">Quota</div>
                                                                    </div>
                                                                    <div class="progress-wrapper">
                                                                        <div class="progress" role="progressbar" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100">
                                                                            <div class="progress-bar" style="width: <?= $percent ?>%"></div>
                                                                        </div>
                                                                        <div class="progress-label"><?= $percent ?>%</div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="padding: 3rem 1rem; text-align: center;">
                            <ion-icon name="document-outline" style="font-size: 3rem; color: #d1d5db; display: block; margin-bottom: 1rem;"></ion-icon>
                            <p style="color: #6c757d; margin: 0;">No employee leave balance data found.</p>
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