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

$month = $_GET['month'] ?? date('Y-m');
$start_month = "$month-01";
$end_month = date('Y-m-t', strtotime($start_month));
$first_day_of_week = date('N', strtotime($start_month)); // 1 (Mon) ... 7 (Sun)
$days_in_month = date('t', strtotime($start_month));
$month_label = date('F Y', strtotime($start_month));

// Fetch all leaves in month
$stmt = $pdo->prepare("
    SELECT l.*, e.fullname, e.username, e.id as empid, e.employee_id
    FROM leave_requests l
    JOIN employees e ON l.employee_id = e.id
    WHERE l.date_from <= ? AND l.date_to >= ?
    AND (l.status='approved' OR l.status='pending')
    ORDER BY l.date_from
");
$stmt->execute([$end_month, $start_month]);
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Map leaves to each date
$leaves_by_date = [];
for ($d = 1; $d <= $days_in_month; $d++) {
    $date = date('Y-m-d', strtotime("$month-$d"));
    $leaves_by_date[$date] = [];
}
foreach ($leaves as $l) {
    $from = strtotime($l['date_from']);
    $to = strtotime($l['date_to']);
    for ($d = $from; $d <= $to; $d += 86400) {
        $date = date('Y-m-d', $d);
        if (isset($leaves_by_date[$date])) {
            $leaves_by_date[$date][] = $l;
        }
    }
}

// Calculate statistics
$total_leaves_month = count($leaves);
$total_approved = 0;
$total_pending = 0;
$total_days = 0;

foreach ($leaves as $l) {
    if ($l['status'] === 'approved') $total_approved++;
    elseif ($l['status'] === 'pending') $total_pending++;
    
    $days = (strtotime($l['date_to']) - strtotime($l['date_from'])) / 86400 + 1;
    $total_days += $days > 0 ? $days : 1;
}

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

$current_year = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leave Calendar - HR Manager | ViaHale TNVS HR3</title>
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
            flex-direction: column;
            gap: 0.8rem;
        }

        .stat-card.total { border-left: 5px solid #667eea; }
        .stat-card.approved { border-left: 5px solid #10b981; }
        .stat-card.pending { border-left: 5px solid #f59e0b; }
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

        .stat-card.total .stat-value { color: #667eea; }
        .stat-card.approved .stat-value { color: #10b981; }
        .stat-card.pending .stat-value { color: #f59e0b; }
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

        .month-selector { 
            background: #f8f9ff; 
            border: 1px solid #e0e7ff; 
            border-radius: 12px; 
            padding: 1.5rem; 
            margin-bottom: 2rem;
        }

        .month-selector form { 
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

        .calendar-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 2rem; 
            flex-wrap: wrap; 
            gap: 1.5rem;
        }

        .calendar-header h3 { 
            font-size: 1.8rem; 
            font-weight: 800; 
            color: #22223b; 
            margin: 0; 
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .calendar-nav-buttons { 
            display: flex; 
            gap: 0.8rem; 
        }

        .calendar-nav-btn { 
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%); 
            color: white; 
            border: none; 
            border-radius: 8px; 
            padding: 0.7rem 1.3rem; 
            font-weight: 600; 
            cursor: pointer; 
            transition: all 0.2s; 
            text-decoration: none; 
            display: inline-flex; 
            align-items: center; 
            gap: 0.5rem; 
            font-size: 0.95rem;
        }

        .calendar-nav-btn:hover { 
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            color: white;
        }

        .calendar-grid { 
            display: grid; 
            grid-template-columns: repeat(7, 1fr); 
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .calendar-weekday { 
            text-align: center; 
            font-weight: 700; 
            color: #fff; 
            background: linear-gradient(135deg, #9A66ff 0%, #4311a5 100%);
            border-radius: 10px; 
            padding: 1rem 0.5rem; 
            font-size: 0.95rem; 
            text-transform: uppercase; 
            letter-spacing: 0.5px;
            box-shadow: 0 2px 8px rgba(154, 102, 255, 0.2);
        }

        .calendar-day { 
            min-height: 110px; 
            background: linear-gradient(135deg, #f8f9ff 0%, #fff 100%);
            border-radius: 12px; 
            box-shadow: 0 2px 8px rgba(140,140,200,0.08); 
            text-align: left; 
            position: relative; 
            cursor: pointer; 
            padding: 12px; 
            transition: all 0.2s; 
            border: 2px solid transparent;
        }

        .calendar-day:hover { 
            background: linear-gradient(135deg, #e6e6f7 0%, #f0f0ff 100%);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(154, 102, 255, 0.2);
            border-color: #9A66ff;
        }

        .calendar-day.today { 
            border: 2px solid #9A66ff; 
            background: linear-gradient(135deg, #f3f0ff 0%, #faf8ff 100%);
            box-shadow: 0 4px 12px rgba(154, 102, 255, 0.15);
        }

        .calendar-day.today .day-num { 
            color: #9A66ff;
            font-weight: 800;
        }

        .calendar-day.disabled { 
            background: #f5f5f5; 
            color: #d1d5db; 
            cursor: default; 
            pointer-events: none;
        }

        .calendar-day .day-num { 
            font-weight: 800; 
            display: block; 
            margin-bottom: 8px; 
            color: #4311a5; 
            font-size: 1.2rem;
        }

        .calendar-day .leave-badge { 
            display: block; 
            font-size: 0.75rem; 
            margin: 3px 0; 
            border-radius: 6px; 
            padding: 4px 6px; 
            background: #fee2e2; 
            color: #b91c1c; 
            font-weight: 600; 
            overflow: hidden; 
            text-overflow: ellipsis; 
            white-space: nowrap; 
            line-height: 1.2;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .calendar-day .leave-badge.approved { 
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46; 
            border: 1px solid #6ee7b7;
        }

        .calendar-day .leave-badge.pending { 
            background: linear-gradient(135deg, #fef3c7 0%, #fcd34d 100%);
            color: #92400e;
            border: 1px solid #fbbf24;
        }

        .calendar-day .leave-badge.conflict { 
            background: linear-gradient(135deg, #fca5a5 0%, #f87171 100%);
            color: #fff; 
            border: 1px solid #ef4444;
            font-weight: 700; 
            animation: pulse 2s infinite;
        }

        @keyframes pulse { 
            0%, 100% { opacity: 1; } 
            50% { opacity: 0.85; } 
        }

        .modal-content { 
            border-radius: 18px; 
            border: 1px solid #e0e7ff; 
            box-shadow: 0 10px 40px rgba(70, 57, 130, 0.15);
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

        .btn-close { 
            filter: brightness(1.8);
        }

        .leave-detail { 
            background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
            border-left: 4px solid #9A66ff; 
            padding: 1.3rem; 
            border-radius: 10px; 
            margin-bottom: 1.2rem; 
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(140,140,200,0.08);
        }

        .leave-detail:hover { 
            transform: translateX(4px);
            box-shadow: 0 4px 12px rgba(154, 102, 255, 0.2);
        }

        .leave-detail-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-start; 
            margin-bottom: 1rem; 
            flex-wrap: wrap; 
            gap: 0.8rem;
        }

        .leave-detail-title { 
            font-weight: 700; 
            color: #22223b; 
            font-size: 1.1rem; 
            margin-bottom: 0.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .leave-detail-employee { 
            font-size: 0.9rem; 
            color: #6c757d;
        }

        .leave-detail-dates { 
            font-size: 0.95rem; 
            margin: 0.8rem 0; 
            color: #22223b;
            font-weight: 500;
        }

        .leave-detail-reason { 
            font-size: 0.9rem; 
            color: #6c757d; 
            margin-top: 0.5rem;
            padding: 0.8rem;
            background: #f0f9ff;
            border-radius: 6px;
            border-left: 3px solid #0284c7;
        }

        .conflict-warning { 
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border-left: 4px solid #ef4444; 
            padding: 1.1rem; 
            border-radius: 10px; 
            margin-top: 1rem; 
            font-size: 0.95rem;
        }

        .conflict-warning strong { 
            color: #b91c1c;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.6rem;
        }

        .conflict-list { 
            margin: 0; 
            padding: 0 0 0 1.5rem;
        }

        .conflict-list li { 
            margin: 0.5rem 0; 
            color: #7f1d1d; 
            font-size: 0.9rem;
        }

        .badge.bg-success { 
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%) !important;
            color: #065f46;
        }

        .badge.bg-warning { 
            background: linear-gradient(135deg, #fef3c7 0%, #fcd34d 100%) !important;
            color: #92400e;
        }

        .legend-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); 
            gap: 1.5rem;
        }

        .legend-item { 
            display: flex; 
            align-items: center; 
            gap: 1rem;
            padding: 1rem;
            background: #f8f9ff;
            border-radius: 10px;
            border: 1px solid #e0e7ff;
            transition: all 0.2s;
        }

        .legend-item:hover { 
            background: #f0f0ff;
            transform: translateY(-2px);
        }

        .legend-badge { 
            padding: 0.5rem 0.9rem; 
            border-radius: 8px; 
            font-weight: 600; 
            font-size: 0.85rem; 
            white-space: nowrap;
        }

        .tip-box { 
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-left: 4px solid #0284c7;
            border-radius: 10px;
            padding: 1.2rem;
            margin-top: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .tip-box ion-icon { 
            font-size: 1.5rem;
            color: #0284c7;
            flex-shrink: 0;
        }

        .tip-box p { 
            margin: 0; 
            color: #0c4a6e; 
            font-size: 0.95rem;
            font-weight: 500;
        }

        @media (max-width: 1200px) { 
            .sidebar { width: 180px; } 
            .content-wrapper { margin-left: 180px; } 
            .main-content { padding: 1.5rem 1rem; } 
            .stats-container { grid-template-columns: repeat(2, 1fr); }
            .calendar-grid { gap: 0.8rem; }
            .calendar-day { min-height: 90px; padding: 10px; }
        }

        @media (max-width: 900px) { 
            .sidebar { left: -220px; width: 220px; } 
            .sidebar.show { left: 0; } 
            .content-wrapper { margin-left: 0; } 
            .main-content { padding: 1rem; } 
            .topbar { padding: 1rem 1.5rem; } 
            .stats-container { grid-template-columns: repeat(2, 1fr); }
            .calendar-header { flex-direction: column; align-items: flex-start; }
            .calendar-grid { gap: 0.6rem; }
        }

        @media (max-width: 700px) { 
            .dashboard-title { font-size: 1.4rem; } 
            .main-content { padding: 1rem 0.8rem; } 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; }
            .stats-container { grid-template-columns: 1fr; }
            .topbar { flex-direction: column; gap: 1rem; justify-content: flex-start; }
            .calendar-grid { grid-template-columns: repeat(7, 1fr); gap: 0.5rem; }
            .calendar-day { min-height: 80px; padding: 8px; font-size: 0.85rem; }
            .calendar-day .day-num { font-size: 1rem; margin-bottom: 4px; }
            .calendar-day .leave-badge { font-size: 0.7rem; padding: 2px 4px; }
            .legend-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 500px) { 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; } 
            .main-content { padding: 0.8rem 0.5rem; }
            .card-body { padding: 1rem 0.8rem; }
            .dashboard-title { font-size: 1.2rem; }
            .stat-card { padding: 1rem; }
            .calendar-weekday { padding: 0.6rem 0.3rem; font-size: 0.8rem; }
            .calendar-day { min-height: 70px; padding: 6px; }
            .calendar-day .day-num { font-size: 0.9rem; }
            .calendar-nav-buttons { width: 100%; flex-direction: column; }
            .calendar-nav-btn { width: 100%; justify-content: center; }
        }

        @media (min-width: 1400px) { 
            .sidebar { width: 260px; padding: 2rem 1rem; } 
            .content-wrapper { margin-left: 260px; } 
            .main-content { padding: 2.5rem 2.5rem; } 
            .stats-container { grid-template-columns: repeat(4, 1fr); }
            .calendar-grid { gap: 1.2rem; }
            .calendar-day { min-height: 130px; }
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
                    <a class="nav-link" href="../manager/leave_history.php"><ion-icon name="time-outline"></ion-icon>Leave History</a>
                    <a class="nav-link active" href="../manager/leave_calendar.php"><ion-icon name="calendar-outline"></ion-icon>Leave Calendar</a>
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
                <ion-icon name="calendar-number-outline"></ion-icon> Leave Calendar
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
                <div class="stat-card total">
                    <div class="stat-label">Total Leave Requests</div>
                    <div class="stat-value"><?= $total_leaves_month ?></div>
                    <small style="color: #6c757d;">In <?= date('F Y', strtotime($month . '-01')) ?></small>
                </div>
                <div class="stat-card approved">
                    <div class="stat-label">Approved Requests</div>
                    <div class="stat-value"><?= $total_approved ?></div>
                    <small style="color: #6c757d;">Confirmed leaves</small>
                </div>
                <div class="stat-card pending">
                    <div class="stat-label">Pending Requests</div>
                    <div class="stat-value"><?= $total_pending ?></div>
                    <small style="color: #6c757d;">Awaiting approval</small>
                </div>
                <div class="stat-card days">
                    <div class="stat-label">Total Days</div>
                    <div class="stat-value"><?= $total_days ?></div>
                    <small style="color: #6c757d;">Leave days in month</small>
                </div>
            </div>

            <!-- Month Selector -->
            <div class="month-selector">
                <form method="get">
                    <label style="font-weight: 600; display: flex; align-items: center; gap: 0.5rem; margin: 0; white-space: nowrap;">
                        <ion-icon name="calendar-outline"></ion-icon> Select Month:
                    </label>
                    <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($month) ?>" title="Select month and year" style="flex: 1; min-width: 180px;">
                    <button type="submit" class="btn btn-primary">
                        <ion-icon name="arrow-forward-outline"></ion-icon> Go
                    </button>
                </form>
            </div>

            <!-- Calendar Card -->
            <div class="card">
                <div class="card-body">
                    <div class="calendar-header">
                        <h3>
                            <ion-icon name="calendar-outline"></ion-icon>
                            <?= htmlspecialchars($month_label) ?>
                        </h3>
                        <div class="calendar-nav-buttons">
                            <a href="?month=<?= date('Y-m', strtotime('-1 month', strtotime($start_month))) ?>" class="calendar-nav-btn" title="Previous month">
                                <ion-icon name="chevron-back-outline"></ion-icon> Previous
                            </a>
                            <a href="?month=<?= date('Y-m', strtotime('+1 month', strtotime($start_month))) ?>" class="calendar-nav-btn" title="Next month">
                                Next <ion-icon name="chevron-forward-outline"></ion-icon>
                            </a>
                        </div>
                    </div>

                    <div class="calendar-grid">
                        <?php
                        $weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                        foreach ($weekdays as $wd) {
                            echo '<div class="calendar-weekday">' . $wd . '</div>';
                        }

                        // Fill empty slots before first day
                        for ($i = 1; $i < $first_day_of_week; $i++) {
                            echo '<div class="calendar-day disabled"></div>';
                        }

                        // Render each day
                        for ($d = 1; $d <= $days_in_month; $d++) {
                            $date = date('Y-m-d', strtotime("$month-$d"));
                            $today_class = ($date == date('Y-m-d')) ? 'today' : '';
                            $has_leaves = count($leaves_by_date[$date]) > 0;
                            $modal_id = 'modal_' . str_replace('-', '', $date);

                            echo '<div class="calendar-day ' . ($has_leaves ? 'calendar-has-leave' : '') . ' ' . $today_class . '" '
                                . ($has_leaves ? 'data-bs-toggle="modal" data-bs-target="#' . $modal_id . '"' : '')
                                . '>';
                            echo '<span class="day-num">' . $d . '</span>';

                            // Show summary badges with conflict detection
                            if ($has_leaves) {
                                foreach ($leaves_by_date[$date] as $lv) {
                                    $overlapping = hasOverlappingLeaves($pdo, $lv['employee_id'], $lv['date_from'], $lv['date_to'], $lv['id']);
                                    $has_conflict = !empty($overlapping);
                                    $conflict_class = $has_conflict ? 'conflict' : '';

                                    $status_class = htmlspecialchars($lv['status']);
                                    echo '<span class="leave-badge ' . $status_class . ' ' . $conflict_class . '" title="' . htmlspecialchars($lv['fullname']) . ' - ' . htmlspecialchars($lv['leave_type']) . '">';
                                    if ($has_conflict) {
                                        echo '⚠️ ';
                                    }
                                    echo htmlspecialchars(substr($lv['fullname'], 0, 10));
                                    echo '</span>';
                                }
                            }
                            echo '</div>';

                            // Modal for this day
                            if ($has_leaves) {
                                ?>
                                <div class="modal fade" id="<?= $modal_id ?>" tabindex="-1" aria-labelledby="<?= $modal_id ?>Label" aria-hidden="true">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="<?= $modal_id ?>Label">
                                                    <ion-icon name="calendar-outline"></ion-icon> Leave Requests — <?= date('F j, Y (l)', strtotime($date)) ?>
                                                </h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <?php foreach ($leaves_by_date[$date] as $lv): ?>
                                                    <?php 
                                                        $overlapping = hasOverlappingLeaves($pdo, $lv['employee_id'], $lv['date_from'], $lv['date_to'], $lv['id']);
                                                        $has_conflict = !empty($overlapping);
                                                    ?>
                                                    <div class="leave-detail">
                                                        <div class="leave-detail-header">
                                                            <div class="leave-detail-info">
                                                                <div class="leave-detail-title">
                                                                    <ion-icon name="document-text-outline"></ion-icon> <?= htmlspecialchars($lv['leave_type']) ?>
                                                                </div>
                                                                <div class="leave-detail-employee">
                                                                    <?= htmlspecialchars($lv['fullname']) ?>
                                                                    <span style="color: #667eea; font-weight: 600; margin-left: 0.5rem;">
                                                                        (<?= htmlspecialchars($lv['employee_id'] ?? 'N/A') ?>)
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            <span class="badge bg-<?= $lv['status'] === 'approved' ? 'success' : 'warning' ?>" style="white-space: nowrap; padding: 0.5rem 0.9rem; font-size: 0.85rem;">
                                                                <ion-icon name="<?= $lv['status'] === 'approved' ? 'checkmark-circle-outline' : 'time-outline' ?>" style="margin-right: 0.3rem;"></ion-icon> <?= ucfirst($lv['status']) ?>
                                                            </span>
                                                        </div>

                                                        <div class="leave-detail-dates">
                                                            <ion-icon name="calendar-outline" style="margin-right: 0.4rem;"></ion-icon>
                                                            <strong>Period:</strong> <?= formatDateRange($lv['date_from'], $lv['date_to']) ?>
                                                        </div>

                                                        <?php if (!empty($lv['reason'])): ?>
                                                            <div class="leave-detail-reason">
                                                                <strong><ion-icon name="document-text-outline" style="margin-right: 0.4rem;"></ion-icon>Reason:</strong> <?= htmlspecialchars($lv['reason']) ?>
                                                            </div>
                                                        <?php endif; ?>

                                                        <?php if ($has_conflict): ?>
                                                            <div class="conflict-warning" role="status" aria-live="polite">
                                                                <strong>
                                                                    <ion-icon name="alert-circle-outline"></ion-icon>
                                                                    Overlapping Leave(s) Detected!
                                                                </strong>
                                                                <ul class="conflict-list">
                                                                    <?php foreach ($overlapping as $overlap): ?>
                                                                        <li>
                                                                            <strong><?= htmlspecialchars($overlap['leave_type']) ?></strong>
                                                                            (<?= formatDateRange($overlap['date_from'], $overlap['date_to']) ?>)
                                                                            <span class="badge bg-<?= $overlap['status'] === 'approved' ? 'success' : 'warning' ?>" style="margin-left: 0.5rem;">
                                                                                <?= ucfirst($overlap['status']) ?>
                                                                            </span>
                                                                        </li>
                                                                    <?php endforeach; ?>
                                                                </ul>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php
                            }
                        }

                        // Fill empty slots after last day
                        $remain = (($first_day_of_week - 1 + $days_in_month) % 7);
                        if ($remain > 0) {
                            for ($i = 1; $i <= (7 - $remain); $i++) {
                                echo '<div class="calendar-day disabled"></div>';
                            }
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- Legend & Information -->
            <div class="card">
                <div class="card-header">
                    <ion-icon name="information-circle-outline"></ion-icon> Calendar Legend & Usage Guide
                </div>
                <div class="card-body">
                    <div class="legend-grid">
                        <div class="legend-item">
                            <span class="legend-badge" style="background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #065f46;">Approved</span>
                            <span style="color: #6c757d; font-size: 0.95rem;">Approved leave request</span>
                        </div>
                        <div class="legend-item">
                            <span class="legend-badge" style="background: linear-gradient(135deg, #fef3c7 0%, #fcd34d 100%); color: #92400e;">Pending</span>
                            <span style="color: #6c757d; font-size: 0.95rem;">Pending leave request</span>
                        </div>
                        <div class="legend-item">
                            <span class="legend-badge" style="background: linear-gradient(135deg, #fca5a5 0%, #f87171 100%); color: #fff;">⚠️ Conflict</span>
                            <span style="color: #6c757d; font-size: 0.95rem;">Overlapping leaves detected</span>
                        </div>
                    </div>

                    <div class="tip-box">
                        <ion-icon name="bulb-outline"></ion-icon>
                        <div>
                            <p><strong>How to use this calendar:</strong></p>
                            <p style="margin-top: 0.5rem;">Click on any date with leave requests to view detailed information. Dates highlighted with a conflict indicator (⚠️) show overlapping leave requests that may need attention or review. Navigate between months using the Previous/Next buttons or select a specific month using the month selector above.</p>
                        </div>
                    </div>
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