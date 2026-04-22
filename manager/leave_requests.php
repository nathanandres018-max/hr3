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

// Use session values
$fullname = $_SESSION['fullname'] ?? 'HR Manager';
$role = $_SESSION['role'] ?? 'HR Manager';

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['leave_id'])) {
    $leave_id = intval($_POST['leave_id']);
    $action = $_POST['action'] === 'approve' ? 'approved' : 'rejected';
    $notes = $_POST['notes'] ?? '';

    $stmt = $pdo->prepare("UPDATE leave_requests SET status = ?, reviewed_at = NOW(), reviewer_id = ? WHERE id = ?");
    $success = $stmt->execute([$action, 1, $leave_id]); // reviewer_id = 1 (current HR manager)

    if ($success) {
        $message = "Leave request " . $action . " successfully.";
        $message_type = "success";
    } else {
        $message = "Failed to update leave request.";
        $message_type = "danger";
    }
}

// Build filters
$where_clauses = [];
$params = [];

if (!empty($_GET['employee'])) {
    $where_clauses[] = "e.fullname LIKE ?";
    $params[] = '%' . $_GET['employee'] . '%';
}

if (!empty($_GET['type'])) {
    $where_clauses[] = "lr.leave_type = ?";
    $params[] = $_GET['type'];
}

if (!empty($_GET['status'])) {
    $where_clauses[] = "lr.status = ?";
    $params[] = $_GET['status'];
}

$where = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);

// Fetch leave requests from database
$query = "SELECT lr.*, e.fullname, e.employee_id, e.department 
          FROM leave_requests lr
          LEFT JOIN employees e ON lr.employee_id = e.id
          $where
          ORDER BY lr.requested_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique leave types for filter dropdown
$stmt = $pdo->query("SELECT DISTINCT leave_type FROM leave_requests WHERE leave_type IS NOT NULL ORDER BY leave_type");
$leave_types = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get statistics
$stmt = $pdo->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
    FROM leave_requests");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

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

// Function to format date range
function formatDateRange($date_from, $date_to) {
    return date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leave Requests - HR Manager | ViaHale TNVS HR3</title>
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

        .breadcrumbs { color: #9A66ff; font-size: 0.93rem; margin-bottom: 2rem; }

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
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover { 
            transform: translateY(-4px); 
            box-shadow: 0 6px 20px rgba(140,140,200,0.12); 
        }

        .stat-card.total { border-left: 5px solid #3b82f6; }
        .stat-card.pending { border-left: 5px solid #f59e0b; }
        .stat-card.approved { border-left: 5px solid #10b981; }
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
        .stat-card.pending .stat-icon { background: #fef3c7; color: #f59e0b; }
        .stat-card.approved .stat-icon { background: #d1fae5; color: #10b981; }
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

        .filter-section form { 
            display: flex; 
            gap: 1rem; 
            align-items: flex-end; 
            flex-wrap: wrap;
        }

        .form-group { margin-bottom: 0; }

        .form-label { 
            font-weight: 600; 
            margin-bottom: 0.5rem; 
            color: #22223b; 
            font-size: 0.9rem;
        }

        .form-control, .form-select { 
            border-radius: 8px; 
            border: 1px solid #e0e7ff; 
            padding: 0.7rem 1rem;
            background: #fff;
            font-size: 0.95rem;
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

        .btn-secondary { 
            background: #e5e7eb; 
            color: #22223b;
            border: none;
            border-radius: 8px;
            padding: 0.65rem 1.5rem;
            font-weight: 600;
        }

        .btn-secondary:hover { 
            background: #d1d5db;
            color: #22223b;
        }

        .btn-approve { 
            background: #10b981; 
            color: white; 
            border: none;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .btn-approve:hover { 
            background: #059669; 
            color: white;
        }

        .btn-reject { 
            background: #ef4444; 
            color: white; 
            border: none;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .btn-reject:hover { 
            background: #dc2626; 
            color: white;
        }

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
            font-size: 0.85rem;
            display: inline-block;
        }

        .status-badge.pending { background: #fef3c7; color: #92400e; }
        .status-badge.approved { background: #d1fae5; color: #065f46; }
        .status-badge.rejected { background: #fee2e2; color: #991b1b; }

        .conflict-info { 
            background: #fef2f2; 
            border-left: 4px solid #ef4444; 
            padding: 0.8rem; 
            border-radius: 8px; 
            margin-top: 0.75rem; 
            font-size: 0.9rem;
            color: #991b1b;
        }

        .conflict-info strong { 
            display: block; 
            margin-bottom: 0.5rem;
            font-weight: 700;
        }

        .conflict-info ul { 
            margin: 0.5rem 0 0 1.5rem; 
            padding: 0;
        }

        .conflict-info li { 
            margin-bottom: 0.3rem;
            list-style-type: none;
        }

        .conflict-info li:before {
            content: "• ";
            margin-right: 0.5rem;
        }

        .alert { 
            border-radius: 12px; 
            border: none; 
            border-left: 4px solid; 
            padding: 1rem 1.2rem; 
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.8rem;
        }

        .alert ion-icon { 
            font-size: 1.2rem; 
            flex-shrink: 0; 
            margin-top: 0.2rem;
        }

        .alert-success { 
            background: #d1fae5; 
            color: #065f46; 
            border-left-color: #10b981;
        }

        .alert-danger { 
            background: #fee2e2; 
            color: #991b1b; 
            border-left-color: #ef4444;
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

        .modal-header h5 { 
            font-family: 'QuickSand', 'Poppins', Arial, sans-serif; 
            font-size: 1.23rem; 
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.7rem;
        }

        .modal-body { 
            padding: 2rem; 
            background: #fafbfc;
        }

        .modal-body label { 
            font-weight: 600; 
            margin-bottom: 0.5rem; 
            color: #22223b;
            display: block;
            margin-top: 0.5rem;
        }

        .modal-body textarea { 
            border-radius: 8px; 
            border: 1px solid #e0e7ff;
        }

        .modal-body textarea:focus { 
            border-color: #9A66ff; 
            box-shadow: 0 0 0 0.2rem rgba(154, 102, 255, 0.15);
        }

        .modal-footer { 
            background: #fafbfc; 
            border-top: 1px solid #e0e7ff; 
            padding: 1.2rem 2rem; 
            border-radius: 0 0 18px 18px;
        }

        .btn-close { 
            color: #fff !important; 
            filter: brightness(1.8) grayscale(0.25); 
            opacity: 0.85; 
            transition: opacity 0.15s;
        }

        .btn-close:hover { opacity: 1; }

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
            .card-body { padding: 0; }
            .table th, .table td { padding: 0.8rem 0.5rem; font-size: 0.85rem; }
            .topbar { flex-direction: column; gap: 1rem; justify-content: flex-start; }
        }

        @media (max-width: 500px) { 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; } 
            .main-content { padding: 0.8rem 0.5rem; }
            .dashboard-title { font-size: 1.2rem; }
            .table th, .table td { padding: 0.6rem 0.3rem; font-size: 0.75rem; }
            .btn-approve, .btn-reject { padding: 0.4rem 0.6rem; font-size: 0.8rem; }
        }

        @media (min-width: 1400px) { 
            .sidebar { width: 260px; padding: 2rem 1rem; } 
            .content-wrapper { margin-left: 260px; } 
            .main-content { padding: 2.5rem 2.5rem; } 
            .stats-container { grid-template-columns: repeat(4, 1fr); }
        }

        .no-data-container {
            text-align: center;
            padding: 3rem 2rem;
            color: #6c757d;
        }

        .no-data-container ion-icon {
            font-size: 3rem;
            color: #d1d5db;
            display: block;
            margin-bottom: 1rem;
        }

        .no-data-container p {
            font-size: 1rem;
            margin: 0;
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
                    <a class="nav-link" href="../manager/manager_dashboard.php">
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
                    <a class="nav-link active" href="../manager/leave_requests.php">
                        <ion-icon name="calendar-outline"></ion-icon>Leave Requests
                    </a>
                    <a class="nav-link" href="../manager/leave_balance.php">
                        <ion-icon name="wallet-outline"></ion-icon>Leave Balance
                    </a>
                    <a class="nav-link" href="../manager/leave_history.php">
                        <ion-icon name="time-outline"></ion-icon>Leave History
                    </a>
                    <a class="nav-link" href="../manager/leave_calendar.php">
                        <ion-icon name="calendar-outline"></ion-icon>Leave Calendar
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
            <span class="dashboard-title">Leave Requests Management</span>
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
                <div class="stat-card total">
                    <div class="stat-icon">
                        <ion-icon name="calendar-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['total'] ?? 0 ?></h3>
                        <p>Total Requests</p>
                    </div>
                </div>
                <div class="stat-card pending">
                    <div class="stat-icon">
                        <ion-icon name="time-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['pending_count'] ?? 0 ?></h3>
                        <p>Pending Review</p>
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
            <?php if (isset($message)): ?>
                <div class="alert alert-<?= $message_type ?>">
                    <ion-icon name="<?= $message_type === 'success' ? 'checkmark-circle-outline' : 'alert-circle-outline' ?>"></ion-icon>
                    <div><?= htmlspecialchars($message) ?></div>
                </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="get" action="">
                    <div class="form-group" style="flex: 1; min-width: 200px;">
                        <label for="employee" class="form-label">
                            <ion-icon name="person-outline" style="margin-right: 0.3rem; font-size: 0.9rem;"></ion-icon>Employee Name
                        </label>
                        <input type="text" class="form-control" id="employee" name="employee" 
                               value="<?= htmlspecialchars($_GET['employee'] ?? '') ?>" 
                               placeholder="Search employee...">
                    </div>
                    <div class="form-group" style="flex: 0 1 180px;">
                        <label for="type" class="form-label">
                            <ion-icon name="bookmark-outline" style="margin-right: 0.3rem; font-size: 0.9rem;"></ion-icon>Leave Type
                        </label>
                        <select class="form-select" id="type" name="type">
                            <option value="">All Types</option>
                            <?php foreach($leave_types as $t): ?>
                                <option value="<?= htmlspecialchars($t) ?>" <?= ($_GET['type'] ?? '') === $t ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 0 1 150px;">
                        <label for="status" class="form-label">
                            <ion-icon name="flag-outline" style="margin-right: 0.3rem; font-size: 0.9rem;"></ion-icon>Status
                        </label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Status</option>
                            <option value="pending" <?= ($_GET['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="approved" <?= ($_GET['status'] ?? '') === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="rejected" <?= ($_GET['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <ion-icon name="search-outline"></ion-icon> Filter
                        </button>
                        <a href="leave_requests.php" class="btn btn-secondary">
                            <ion-icon name="refresh-outline"></ion-icon> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Leave Requests Table -->
            <div class="card">
                <div class="card-header">
                    <ion-icon name="calendar-outline"></ion-icon> All Leave Requests
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Leave Type</th>
                                    <th>From Date</th>
                                    <th>To Date</th>
                                    <th style="text-align: center;">Days</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                    <th>Requested</th>
                                    <th style="width: 180px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($requests)): ?>
                                    <?php foreach($requests as $request): ?>
                                        <?php 
                                            $overlapping = hasOverlappingLeaves($pdo, $request['employee_id'], $request['date_from'], $request['date_to'], $request['id']);
                                            $has_conflict = !empty($overlapping);
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($request['fullname'] ?? 'Unknown') ?></strong><br>
                                                <small style="color: #6c757d;">ID: <?= htmlspecialchars($request['employee_id'] ?? 'N/A') ?></small>
                                            </td>
                                            <td>
                                                <span class="badge" style="background: #e0e7ff; color: #4311a5;">
                                                    <?= htmlspecialchars($request['leave_type'] ?? 'N/A') ?>
                                                </span>
                                            </td>
                                            <td><?= date('M d, Y', strtotime($request['date_from'])) ?></td>
                                            <td><?= date('M d, Y', strtotime($request['date_to'])) ?></td>
                                            <td style="text-align: center;">
                                                <span class="badge bg-info">
                                                    <?= htmlspecialchars($request['days_requested'] ?? 0) ?>
                                                </span>
                                            </td>
                                            <td style="max-width: 200px;">
                                                <span title="<?= htmlspecialchars($request['reason'] ?? '') ?>">
                                                    <?= htmlspecialchars(substr($request['reason'] ?? '', 0, 25)) ?><?= strlen($request['reason'] ?? '') > 25 ? '...' : '' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php $rq_status = strtolower($request['status'] ?? 'pending'); ?>
                                                <span class="status-badge <?= $rq_status ?>">
                                                    <?= htmlspecialchars(ucfirst($rq_status)) ?>
                                                </span>
                                                <?php if ($has_conflict): ?>
                                                    <div class="conflict-info">
                                                        <strong>⚠️ Overlapping Leave(s)</strong>
                                                        <ul>
                                                            <?php foreach ($overlapping as $overlap): ?>
                                                                <li>
                                                                    <?= htmlspecialchars($overlap['leave_type']) ?> 
                                                                    <small>(<?= formatDateRange($overlap['date_from'], $overlap['date_to']) ?>)</small>
                                                                    <span class="badge bg-<?= $overlap['status'] === 'approved' ? 'success' : 'warning' ?>" style="font-size: 0.75rem; margin-left: 0.3rem;">
                                                                        <?= ucfirst($overlap['status']) ?>
                                                                    </span>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= date('M d, Y H:i', strtotime($request['requested_at'])) ?></td>
                                            <td>
                                                <?php if ($request['status'] === 'pending'): ?>
                                                    <button class="btn btn-sm btn-approve" data-bs-toggle="modal" data-bs-target="#approveModal" 
                                                            onclick="setLeaveData(<?= $request['id'] ?>, 'approve', '<?= htmlspecialchars($request['fullname'], ENT_QUOTES) ?>')">
                                                        <ion-icon name="checkmark-outline"></ion-icon> Approve
                                                    </button>
                                                    <button class="btn btn-sm btn-reject" data-bs-toggle="modal" data-bs-target="#rejectModal" 
                                                            onclick="setLeaveData(<?= $request['id'] ?>, 'reject', '<?= htmlspecialchars($request['fullname'], ENT_QUOTES) ?>')">
                                                        <ion-icon name="close-outline"></ion-icon> Reject
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted text-nowrap" style="font-size: 0.9rem;">
                                                        <ion-icon name="checkmark-circle-outline" style="margin-right: 0.3rem;"></ion-icon>No action
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9">
                                            <div class="no-data-container">
                                                <ion-icon name="document-outline"></ion-icon>
                                                <p><strong>No leave requests found</strong></p>
                                                <small style="color: #9ca3af;">Try adjusting your search filters</small>
                                            </div>
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

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="approveModalLabel">
                    <ion-icon name="checkmark-circle-outline"></ion-icon> Approve Leave Request
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" id="leaveId" name="leave_id">
                <input type="hidden" name="action" value="approve">
                <div class="modal-body">
                    <p>Are you sure you want to <strong>approve</strong> the leave request for <strong id="empName"></strong>?</p>
                    <div class="form-group mt-3">
                        <label for="notes" class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Add any approval notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <ion-icon name="close-outline"></ion-icon> Cancel
                    </button>
                    <button type="submit" class="btn btn-approve">
                        <ion-icon name="checkmark-outline"></ion-icon> Approve Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rejectModalLabel">
                    <ion-icon name="close-circle-outline"></ion-icon> Reject Leave Request
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" id="leaveId2" name="leave_id">
                <input type="hidden" name="action" value="reject">
                <div class="modal-body">
                    <p>Are you sure you want to <strong>reject</strong> the leave request for <strong id="empName2"></strong>?</p>
                    <div class="form-group mt-3">
                        <label for="notes2" class="form-label">Rejection Reason (Optional)</label>
                        <textarea class="form-control" id="notes2" name="notes" rows="3" placeholder="Please provide a reason for rejection..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <ion-icon name="close-outline"></ion-icon> Cancel
                    </button>
                    <button type="submit" class="btn btn-reject">
                        <ion-icon name="close-outline"></ion-icon> Reject Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function setLeaveData(leaveId, action, empName) {
        if (action === 'approve') {
            document.getElementById('leaveId').value = leaveId;
            document.getElementById('empName').textContent = empName;
            document.getElementById('notes').value = '';
        } else if (action === 'reject') {
            document.getElementById('leaveId2').value = leaveId;
            document.getElementById('empName2').textContent = empName;
            document.getElementById('notes2').value = '';
        }
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