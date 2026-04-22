<?php
include_once("../includes/db.php");
session_start();

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
$role = $_SESSION['role'] ?? 'hr_manager';

// Terms acceptance logic
if (!isset($_SESSION['leave_terms_accepted']) || !$_SESSION['leave_terms_accepted']) {
    $showTermsModal = true;
} else {
    $showTermsModal = false;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_terms'])) {
    $_SESSION['leave_terms_accepted'] = true;
    header("Location: manager_dashboard.php");
    exit();
}

// ============================================================================
// ENHANCED METRICS ENDPOINT WITH ADVANCED ANALYTICS
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'metrics') {
    header('Content-Type: application/json; charset=utf-8');

    $metrics = [
        'pending_timesheets' => 0,
        'pending_leave' => 0,
        'escalated_claims' => 0,
        'schedules_count' => 0,
        'extracted_pending' => 0,
        'overdue_approvals' => 0,
        'employees_on_leave' => 0,
        'monthly_approvals' => ['labels' => [], 'approved' => [], 'submitted' => [], 'rejected' => []],
        'leave_status_breakdown' => [],
        'timesheet_status_breakdown' => [],
        'department_metrics' => [],
        'approval_rate' => 0,
        'avg_approval_time' => 0,
        'recent_activity' => [],
        'top_leave_types' => [],
        'employee_utilization' => []
    ];

    try {
        // 1) pending timesheets
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM timesheets WHERE status = 'pending'");
        $stmt->execute();
        $metrics['pending_timesheets'] = (int)$stmt->fetchColumn();

        // 2) pending leave
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'");
        $stmt->execute();
        $metrics['pending_leave'] = (int)$stmt->fetchColumn();

        // 3) escalated claims
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM claims WHERE (escalated = 1 OR status = 'escalated')");
            $metrics['escalated_claims'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $metrics['escalated_claims'] = 0;
        }

        // 4) schedules (shifts)
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM shifts WHERE shift_date >= CURDATE()");
            $metrics['schedules_count'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $metrics['schedules_count'] = 0;
        }

        // 5) extracted_timesheets pending
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM extracted_timesheets WHERE status = 'pending'");
            $metrics['extracted_pending'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $metrics['extracted_pending'] = 0;
        }

        // 6) overdue approvals (pending > 7 days)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM timesheets WHERE status = 'pending' AND submitted_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute();
        $metrics['overdue_approvals'] = (int)$stmt->fetchColumn();

        // 7) employees on leave today/this week
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT employee_id) FROM leave_requests 
            WHERE status = 'approved' 
            AND date_from <= ? AND date_to >= ?
        ");
        $stmt->execute([$today, $today]);
        $metrics['employees_on_leave'] = (int)$stmt->fetchColumn();

        // 8) monthly approvals - last 6 months
        $months = [];
        $labels = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = date('Y-m', strtotime("-{$i} months"));
            $months[] = $m;
            $labels[] = date('M Y', strtotime($m . '-01'));
        }
        $approved_counts = array_fill(0, count($months), 0);
        $submitted_counts = array_fill(0, count($months), 0);
        $rejected_counts = array_fill(0, count($months), 0);

        foreach ($months as $idx => $m) {
            $first = $m . '-01';
            $last = date('Y-m-t', strtotime($first));
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM timesheets WHERE status = 'approved' AND submitted_at BETWEEN ? AND ?");
            $stmt->execute([$first . ' 00:00:00', $last . ' 23:59:59']);
            $approved_counts[$idx] = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM timesheets WHERE submitted_at BETWEEN ? AND ?");
            $stmt->execute([$first . ' 00:00:00', $last . ' 23:59:59']);
            $submitted_counts[$idx] = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM timesheets WHERE status = 'rejected' AND submitted_at BETWEEN ? AND ?");
            $stmt->execute([$first . ' 00:00:00', $last . ' 23:59:59']);
            $rejected_counts[$idx] = (int)$stmt->fetchColumn();
        }

        $metrics['monthly_approvals'] = [
            'labels' => $labels,
            'approved' => $approved_counts,
            'submitted' => $submitted_counts,
            'rejected' => $rejected_counts
        ];

        // 9) leave status breakdown
        $stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM leave_requests GROUP BY status");
        $stmt->execute();
        $breakdown = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $breakdown[$r['status']] = (int)$r['cnt'];
        }
        $metrics['leave_status_breakdown'] = $breakdown;

        // 10) timesheet status breakdown
        $stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM timesheets GROUP BY status");
        $stmt->execute();
        $ts_breakdown = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ts_breakdown[$r['status']] = (int)$r['cnt'];
        }
        $metrics['timesheet_status_breakdown'] = $ts_breakdown;

        // 11) department metrics
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    e.department,
                    COUNT(DISTINCT e.id) as total_employees,
                    SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) as pending_ts,
                    SUM(CASE WHEN lr.status = 'pending' THEN 1 ELSE 0 END) as pending_leaves
                FROM employees e
                LEFT JOIN timesheets t ON e.id = t.employee_id
                LEFT JOIN leave_requests lr ON e.id = lr.employee_id
                WHERE e.status = 'Active'
                GROUP BY e.department
                LIMIT 10
            ");
            $stmt->execute();
            $dept_data = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $dept_data[] = $r;
            }
            $metrics['department_metrics'] = $dept_data;
        } catch (Throwable $e) {
            $metrics['department_metrics'] = [];
        }

        // 12) approval rate (%)
        $stmt = $pdo->query("SELECT COUNT(*) FROM timesheets");
        $total_ts = (int)$stmt->fetchColumn();
        if ($total_ts > 0) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM timesheets WHERE status = 'approved'");
            $approved_ts = (int)$stmt->fetchColumn();
            $metrics['approval_rate'] = round(($approved_ts / $total_ts) * 100, 2);
        }

        // 13) average approval time (days)
        try {
            $stmt = $pdo->prepare("
                SELECT AVG(DATEDIFF(reviewed_at, submitted_at)) as avg_days
                FROM timesheets
                WHERE status IN ('approved', 'rejected') AND reviewed_at IS NOT NULL
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $metrics['avg_approval_time'] = round($result['avg_days'] ?? 0, 1);
        } catch (Throwable $e) {
            $metrics['avg_approval_time'] = 0;
        }

        // 14) top leave types
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    leave_type,
                    COUNT(*) as count,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count
                FROM leave_requests
                WHERE leave_type IS NOT NULL
                GROUP BY leave_type
                ORDER BY count DESC
                LIMIT 5
            ");
            $stmt->execute();
            $top_leaves = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $top_leaves[] = $r;
            }
            $metrics['top_leave_types'] = $top_leaves;
        } catch (Throwable $e) {
            $metrics['top_leave_types'] = [];
        }

        // 15) employee utilization (leave balance summary)
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    e.id,
                    e.fullname,
                    e.department,
                    COUNT(DISTINCT CASE WHEN lr.status = 'approved' THEN lr.id END) as approved_leaves,
                    COUNT(DISTINCT CASE WHEN lr.status = 'pending' THEN lr.id END) as pending_leaves,
                    COUNT(DISTINCT t.id) as submitted_timesheets
                FROM employees e
                LEFT JOIN leave_requests lr ON e.id = lr.employee_id
                LEFT JOIN timesheets t ON e.id = t.employee_id
                WHERE e.status = 'Active'
                GROUP BY e.id, e.fullname, e.department
                ORDER BY approved_leaves DESC
                LIMIT 5
            ");
            $stmt->execute();
            $util = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $util[] = $r;
            }
            $metrics['employee_utilization'] = $util;
        } catch (Throwable $e) {
            $metrics['employee_utilization'] = [];
        }

        // 16) recent activity (union + enhanced)
        $recent = [];
        $sql = "
            SELECT 
                submitted_at as date, 
                e.fullname AS user, 
                CONCAT('Timesheet ', t.status) AS activity, 
                'Timesheet' AS module, 
                t.status AS status,
                'timesheet' as type
            FROM timesheets t
            LEFT JOIN employees e ON t.employee_id = e.id
            WHERE t.submitted_at IS NOT NULL
            UNION ALL
            SELECT 
                requested_at as date, 
                e.fullname AS user, 
                CONCAT('Leave Request ', lr.status) AS activity, 
                'Leave Management' AS module, 
                lr.status AS status,
                'leave' as type
            FROM leave_requests lr
            LEFT JOIN employees e ON lr.employee_id = e.id
            WHERE lr.requested_at IS NOT NULL
            ORDER BY date DESC
            LIMIT 15
        ";
        try {
            $stmt = $pdo->query($sql);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $recent[] = [
                    'date' => $r['date'],
                    'user' => $r['user'],
                    'activity' => $r['activity'],
                    'module' => $r['module'],
                    'status' => $r['status'],
                    'type' => $r['type']
                ];
            }
        } catch (Throwable $e) {
            $recent = [];
        }
        $metrics['recent_activity'] = $recent;

    } catch (Throwable $e) {
        // Log error if needed: error_log($e->getMessage());
    }

    echo json_encode($metrics);
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HR Manager Dashboard - Advanced Analytics | ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .blurred-bg {
            filter: blur(14px) brightness(0.87);
            pointer-events: none !important;
            user-select: none !important;
        }
        
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

        .stats-cards { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); 
            gap: 1.5rem; 
            margin-bottom: 2rem;
        }

        .stats-card { 
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

        .stats-card:hover { 
            transform: translateY(-4px); 
            box-shadow: 0 8px 24px rgba(140,140,200,0.12);
        }

        .stats-card.pending { border-left: 5px solid #f59e0b; }
        .stats-card.success { border-left: 5px solid #10b981; }
        .stats-card.danger { border-left: 5px solid #ef4444; }
        .stats-card.info { border-left: 5px solid #3b82f6; }

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

        .stats-card.pending .stat-icon { background: #fef3c7; color: #f59e0b; }
        .stats-card.success .stat-icon { background: #dcfce7; color: #10b981; }
        .stats-card.danger .stat-icon { background: #fee2e2; color: #ef4444; }
        .stats-card.info .stat-icon { background: #dbeafe; color: #3b82f6; }

        .stat-text h3 { font-size: 2rem; font-weight: 800; margin: 0; color: #22223b; }
        .stat-text p { font-size: 0.9rem; color: #6c757d; margin: 0; }

        .dashboard-row { display: flex; gap: 1.5rem; margin-bottom: 2rem; flex-wrap: wrap; }
        
        .dashboard-col { 
            background: #fff; 
            border-radius: 18px; 
            box-shadow: 0 4px 15px rgba(140,140,200,0.08); 
            padding: 2rem; 
            flex: 1; 
            min-width: 320px; 
            border: 1px solid #f0f0f0;
        }

        .dashboard-col h5 { 
            font-size: 1.35rem; 
            font-weight: 700; 
            color: #22223b; 
            margin-bottom: 1.5rem; 
            display: flex; 
            align-items: center; 
            gap: 0.8rem;
        }

        .dashboard-col h5 ion-icon { color: #9A66ff; font-size: 1.5rem; }

        .table { font-size: 0.95rem; color: #22223b; margin-bottom: 0; }
        .table th { 
            color: #6c757d; 
            font-weight: 700; 
            border: none; 
            background: transparent; 
            padding: 1rem 0.8rem;
            font-size: 0.92rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .table td { 
            border: none; 
            background: transparent; 
            padding: 1rem 0.8rem; 
            vertical-align: middle;
            border-bottom: 1px solid #f0f0f0;
        }
        .table tbody tr:hover { background-color: #f8f9ff; }
        .table tbody tr:last-child td { border-bottom: none; }

        .status-badge { 
            padding: 0.4rem 0.85rem; 
            border-radius: 20px; 
            font-weight: 600; 
            font-size: 0.85rem;
            display: inline-block;
        }

        .status-badge.approved { background: #d1fae5; color: #065f46; }
        .status-badge.pending { background: #fef3c7; color: #92400e; }
        .status-badge.rejected { background: #fee2e2; color: #991b1b; }
        .status-badge.escalated { background: #fce7f3; color: #831843; }

        .metric-item { 
            padding: 1rem 0; 
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .metric-item:last-child { border-bottom: none; }
        .metric-label { font-size: 0.9rem; color: #6c757d; font-weight: 500; }
        .metric-value { font-size: 1.5rem; font-weight: 800; color: #22223b; }

        .chart-container { position: relative; height: 320px; margin-bottom: 1.5rem; }

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

        .modal-title { font-size: 1.23rem; font-weight: 700; }
        .modal-body { background: #fafbfc; padding: 1.7rem 1.5rem; }

        .modal-backdrop-blur {
            position: fixed;
            top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(33,30,70,0.30);
            z-index: 1040;
            backdrop-filter: blur(16px);
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

        @media (max-width: 1200px) { 
            .sidebar { width: 180px; } 
            .content-wrapper { margin-left: 180px; } 
            .main-content { padding: 1.5rem 1rem; } 
            .stats-cards { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 900px) { 
            .sidebar { left: -220px; width: 220px; } 
            .sidebar.show { left: 0; } 
            .content-wrapper { margin-left: 0; } 
            .main-content { padding: 1rem; } 
            .topbar { padding: 1rem 1.5rem; }
            .stats-cards { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 700px) { 
            .dashboard-title { font-size: 1.4rem; } 
            .main-content { padding: 1rem 0.8rem; } 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; }
            .stats-cards { grid-template-columns: 1fr; }
            .dashboard-col { padding: 1.2rem; }
            .topbar { flex-direction: column; gap: 1rem; justify-content: flex-start; }
        }

        @media (max-width: 500px) { 
            .sidebar { width: 100%; } 
            .main-content { padding: 0.8rem 0.5rem; }
            .dashboard-title { font-size: 1.2rem; }
            .stat-text h3 { font-size: 1.5rem; }
        }

        @media (min-width: 1400px) { 
            .sidebar { width: 260px; padding: 2rem 1rem; } 
            .content-wrapper { margin-left: 260px; } 
            .main-content { padding: 2.5rem 2.5rem; } 
            .stats-cards { grid-template-columns: repeat(4, 1fr); }
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
                    <a class="nav-link active" href="../manager/manager_dashboard.php">
                        <ion-icon name="home-outline"></ion-icon>Dashboard
                    </a>
                </nav>
            </div>
            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Timesheet Management</h6>
                <nav class="nav flex-column">
                    <a class="nav-link" href="../manager/timesheet_review.php">
                        <ion-icon name="checkmark-done-outline"></ion-icon>Review & Approval
                    </a>
                    <a class="nav-link" href="../manager/timesheet_reports.php">
                        <ion-icon name="document-text-outline"></ion-icon>Timesheet Reports
                    </a>
                </nav>
            </div>
            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Leave Management</h6>
                <nav class="nav flex-column">
                    <a class="nav-link" href="../manager/leave_requests.php">
                        <ion-icon name="calendar-outline"></ion-icon>Leave Requests
                    </a>
                    <a class="nav-link" href="../manager/leave_balance.php">
                        <ion-icon name="wallet-outline"></ion-icon>Leave Balance
                    </a>
                    <a class="nav-link" href="../manager/leave_history.php">
                        <ion-icon name="time-outline"></ion-icon>Leave History
                 <a class="nav-link" href="../manager/leave_calendar.php"><ion-icon name="calendar-outline"></ion-icon>Leave Calendar</a>

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

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <!-- Top Bar -->
        <div class="topbar">
            <span class="dashboard-title">HR Manager Dashboard</span>
            <div class="profile">
                <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
                <div class="profile-info">
                    <strong><?= htmlspecialchars($fullname) ?></strong><br>
                    <small>HR Manager</small>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainDashboardContent" <?php if ($showTermsModal) echo 'class="blurred-bg"'; ?>>
            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stats-card pending">
                    <div class="stat-icon">
                        <ion-icon name="hourglass-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3 id="pending-ts">0</h3>
                        <p>Pending Timesheets</p>
                    </div>
                </div>
                <div class="stats-card pending">
                    <div class="stat-icon">
                        <ion-icon name="calendar-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3 id="pending-leave">0</h3>
                        <p>Pending Leave Requests</p>
                    </div>
                </div>
                <div class="stats-card danger">
                    <div class="stat-icon">
                        <ion-icon name="alert-circle-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3 id="overdue-approvals">0</h3>
                        <p>Overdue Approvals</p>
                    </div>
                </div>
                <div class="stats-card info">
                    <div class="stat-icon">
                        <ion-icon name="people-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3 id="on-leave">0</h3>
                        <p>Employees on Leave</p>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="dashboard-row">
                <div class="dashboard-col" style="flex: 1.5;">
                    <h5>
                        <ion-icon name="bar-chart-outline"></ion-icon>
                        Monthly Approvals Trend
                    </h5>
                    <div class="chart-container">
                        <canvas id="approvalChart"></canvas>
                    </div>
                </div>
                <div class="dashboard-col">
                    <h5>
                        <ion-icon name="pie-chart-outline"></ion-icon>
                        Leave Status Distribution
                    </h5>
                    <div class="chart-container">
                        <canvas id="leaveChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Key Metrics -->
            <div class="dashboard-row">
                <div class="dashboard-col">
                    <h5>
                        <ion-icon name="stats-chart-outline"></ion-icon>
                        Key Performance Indicators
                    </h5>
                    <div class="metric-item">
                        <span class="metric-label">Approval Rate</span>
                        <span class="metric-value" id="approval-rate">0%</span>
                    </div>
                    <div class="metric-item">
                        <span class="metric-label">Avg. Approval Time</span>
                        <span class="metric-value" id="avg-approval-time">0<small style="font-size: 0.6em;">d</small></span>
                    </div>
                    <div class="metric-item">
                        <span class="metric-label">Total Timesheets</span>
                        <span class="metric-value" id="total-timesheets">0</span>
                    </div>
                    <div class="metric-item">
                        <span class="metric-label">Total Leave Requests</span>
                        <span class="metric-value" id="total-leave-requests">0</span>
                    </div>
                </div>

                <div class="dashboard-col">
                    <h5>
                        <ion-icon name="leaf-outline"></ion-icon>
                        Top Leave Types
                    </h5>
                    <div id="leave-types-list">
                        <p class="text-muted">Loading...</p>
                    </div>
                </div>

                <div class="dashboard-col">
                    <h5>
                        <ion-icon name="people-outline"></ion-icon>
                        Top Employees by Leave Usage
                    </h5>
                    <div id="employee-utilization-list">
                        <p class="text-muted">Loading...</p>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="dashboard-col">
                <h5>
                    <ion-icon name="time-outline"></ion-icon>
                    Recent Activity
                </h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>User</th>
                                <th>Activity</th>
                                <th>Module</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="recent-activity-table">
                            <tr>
                                <td colspan="5" class="text-center text-muted">Loading activities...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Terms Modal -->
        <?php if ($showTermsModal): ?>
            <div class="modal-backdrop-blur"></div>
            <div class="modal fade show" id="termsModal" tabindex="-1" style="display:block;" aria-modal="true" role="dialog">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <form method="post" class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <ion-icon name="document-text-outline"></ion-icon>
                                Terms and Conditions - HR Manager
                            </h5>
                        </div>
                        <div class="modal-body" style="max-height: 65vh; overflow-y: auto;">
                            <h6>Welcome to the HR Management module of ViaHale TNVS HR3.</h6>
                            <p>By accessing this system, you agree to:</p>
                            <ul>
                                <li><strong>Data Confidentiality:</strong> Handle all employee information with strict confidentiality in accordance with local privacy laws.</li>
                                <li><strong>Fair Approval:</strong> Review timesheets and leave requests fairly and impartially based on company policies.</li>
                                <li><strong>Compliance:</strong> Ensure all approvals comply with labor standards, company policies, and applicable regulations.</li>
                                <li><strong>Audit Trail:</strong> Maintain accurate records of all approvals and rejections for compliance audits.</li>
                                <li><strong>Professional Conduct:</strong> Use the system responsibly and report any irregularities to the HR Director.</li>
                                <li><strong>System Integrity:</strong> Do not attempt to bypass, modify, or exploit system security measures.</li>
                            </ul>
                            <hr>
                            <p class="text-muted small">Last updated: <?= date('Y-m-d') ?></p>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" name="accept_terms" class="btn btn-primary w-100">I Accept and Continue</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let approvalChart, leaveChart;

// Load metrics
async function loadMetrics() {
    try {
        const response = await fetch('?action=metrics');
        const data = await response.json();

        // Update stat cards
        document.getElementById('pending-ts').textContent = data.pending_timesheets;
        document.getElementById('pending-leave').textContent = data.pending_leave;
        document.getElementById('overdue-approvals').textContent = data.overdue_approvals;
        document.getElementById('on-leave').textContent = data.employees_on_leave;
        document.getElementById('approval-rate').textContent = data.approval_rate + '%';
        document.getElementById('avg-approval-time').innerHTML = data.avg_approval_time + '<small style="font-size: 0.6em;">d</small>';

        // Calculate totals
        const totalTs = Object.values(data.timesheet_status_breakdown).reduce((a, b) => a + b, 0);
        const totalLeave = Object.values(data.leave_status_breakdown).reduce((a, b) => a + b, 0);
        document.getElementById('total-timesheets').textContent = totalTs;
        document.getElementById('total-leave-requests').textContent = totalLeave;

        // Monthly Approvals Chart
        if (approvalChart) approvalChart.destroy();
        const approvalCtx = document.getElementById('approvalChart').getContext('2d');
        approvalChart = new Chart(approvalCtx, {
            type: 'line',
            data: {
                labels: data.monthly_approvals.labels,
                datasets: [
                    {
                        label: 'Approved',
                        data: data.monthly_approvals.approved,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Submitted',
                        data: data.monthly_approvals.submitted,
                        borderColor: '#9A66ff',
                        backgroundColor: 'rgba(154, 102, 255, 0.1)',
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { usePointStyle: true, padding: 15 }
                    }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });

        // Leave Status Pie Chart
        if (leaveChart) leaveChart.destroy();
        const leaveCtx = document.getElementById('leaveChart').getContext('2d');
        leaveChart = new Chart(leaveCtx, {
            type: 'doughnut',
            data: {
                labels: Object.keys(data.leave_status_breakdown),
                datasets: [{
                    data: Object.values(data.leave_status_breakdown),
                    backgroundColor: ['#d1fae5', '#fef3c7', '#fee2e2'],
                    borderColor: ['#065f46', '#92400e', '#991b1b']
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

        // Top Leave Types
        let leaveTypesHtml = '';
        if (data.top_leave_types.length > 0) {
            data.top_leave_types.forEach(item => {
                leaveTypesHtml += `
                    <div class="metric-item">
                        <span class="metric-label">${item.leave_type}</span>
                        <span class="metric-value" style="font-size: 1.2rem;">${item.count}</span>
                    </div>
                `;
            });
        } else {
            leaveTypesHtml = '<p class="text-muted">No leave types found</p>';
        }
        document.getElementById('leave-types-list').innerHTML = leaveTypesHtml;

        // Employee Utilization
        let empUtilHtml = '';
        if (data.employee_utilization.length > 0) {
            data.employee_utilization.forEach(emp => {
                empUtilHtml += `
                    <div class="metric-item">
                        <div>
                            <div class="metric-label">${emp.fullname}</div>
                            <small class="text-muted">${emp.department}</small>
                        </div>
                        <span class="metric-value" style="font-size: 1.2rem;">${emp.approved_leaves}</span>
                    </div>
                `;
            });
        } else {
            empUtilHtml = '<p class="text-muted">No employee data found</p>';
        }
        document.getElementById('employee-utilization-list').innerHTML = empUtilHtml;

        // Recent Activity
        let activityHtml = '';
        if (data.recent_activity.length > 0) {
            data.recent_activity.forEach(activity => {
                let statusBadgeClass = activity.status === 'approved' ? 'approved' : activity.status;
                activityHtml += `
                    <tr>
                        <td><small>${new Date(activity.date).toLocaleDateString()}</small></td>
                        <td>${activity.user || 'System'}</td>
                        <td>${activity.activity}</td>
                        <td><small>${activity.module}</small></td>
                        <td><span class="status-badge ${statusBadgeClass}">${activity.status}</span></td>
                    </tr>
                `;
            });
        } else {
            activityHtml = '<tr><td colspan="5" class="text-center text-muted">No recent activities</td></tr>';
        }
        document.getElementById('recent-activity-table').innerHTML = activityHtml;

    } catch (error) {
        console.error('Error loading metrics:', error);
    }
}

// Load metrics on page load and refresh every 30 seconds
document.addEventListener('DOMContentLoaded', () => {
    loadMetrics();
    setInterval(loadMetrics, 30000);
});

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