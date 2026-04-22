<?php
session_start();
require_once("../includes/db.php"); // expects $pdo (PDO) configured and connected

$fullname = $_SESSION['fullname'] ?? 'Schedule Officer';
$display_role = $_SESSION['role'] ?? '';

// Ensure PDO exists
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo "Server configuration error: PDO \$pdo not available.";
    exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------------------- Single source shift times ----------------------
$shift_times = [
    'Morning'   => ['start' => '08:00', 'end' => '16:00'],
    'Afternoon' => ['start' => '16:00', 'end' => '00:00'],
    'Night'     => ['start' => '00:00', 'end' => '08:00']
];

// ---------------------- Helpers ----------------------
function normalize_role(string $r): string {
    $k = trim($r);
    $k = strtolower($k);
    $k = preg_replace('/[^a-z0-9]+/', '_', $k);
    $k = trim($k, '_');
    return $k ?: '';
}

function actor_can_edit_schedules(string $role_norm): bool {
    if ($role_norm === '') return false;
    $allowed_exact = ['admin', 'schedule_officer', 'hr_manager', 'hr', 'scheduler', 'scheduling_officer'];
    if (in_array($role_norm, $allowed_exact, true)) return true;
    $keywords = ['admin','schedule','scheduler','hr','scheduling'];
    foreach ($keywords as $kw) {
        if (strpos($role_norm, $kw) !== false) return true;
    }
    if (!empty($_SESSION['is_admin']) && $_SESSION['is_admin']) return true;
    if (!empty($_SESSION['permissions']) && is_array($_SESSION['permissions']) && in_array('manage_schedules', $_SESSION['permissions'], true)) return true;
    return false;
}

function shifts_have_time_cols(PDO $pdo): bool {
    $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'shifts'
              AND COLUMN_NAME IN ('shift_start','shift_end')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $count = (int)$stmt->fetchColumn();
    return $count >= 1;
}

function normalize_time_for_db(?string $t): ?string {
    if ($t === null) return null;
    $t = trim($t);
    if ($t === '') return null;
    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $t)) {
        $parts = explode(':', $t);
        $hh = str_pad((int)$parts[0], 2, '0', STR_PAD_LEFT);
        $mm = str_pad((int)$parts[1], 2, '0', STR_PAD_LEFT);
        $ss = isset($parts[2]) ? str_pad((int)$parts[2], 2, '0', STR_PAD_LEFT) : '00';
        if ((int)$hh < 0 || (int)$hh > 23 || (int)$mm > 59 || (int)$ss > 59) return null;
        return "$hh:$mm:$ss";
    }
    $ts = strtotime($t);
    if ($ts === false) return null;
    return date('H:i:s', $ts);
}

function log_schedule_action(PDO $pdo, int $employee_id, string $action, string $status,
                             string $performed_by, ?string $department = null, array $details = []): void {
    $stmt = $pdo->prepare("INSERT INTO schedule_logs (employee_id, action, action_time, status, performed_by, department, details) VALUES (?, ?, NOW(), ?, ?, ?, ?)");
    $stmt->execute([
        $employee_id,
        $action,
        $status,
        $performed_by,
        $department,
        json_encode($details, JSON_UNESCAPED_UNICODE)
    ]);
}

// ---------------------- Environment / permissions ----------------------
$role_norm = normalize_role($display_role);
$can_edit = actor_can_edit_schedules($role_norm);
$shifts_have_times = shifts_have_time_cols($pdo);

// ---------------------- POST: edit a single shift row ----------------------
$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_shift'])) {
    if (!$can_edit) {
        $msg = "Error: permission_denied";
    } else {
        $shift_id = intval($_POST['shift_id'] ?? 0);

        // Distinguish "not present" from "present but empty"
        $provided_date = array_key_exists('shift_date', $_POST) ? trim($_POST['shift_date']) : null;
        $provided_type = array_key_exists('shift_type', $_POST) ? trim($_POST['shift_type']) : null;
        $provided_start_raw = array_key_exists('shift_start', $_POST) ? trim($_POST['shift_start']) : null;
        $provided_end_raw   = array_key_exists('shift_end', $_POST) ? trim($_POST['shift_end']) : null;

        if ($shift_id <= 0) {
            $msg = "Invalid shift id.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT s.*, e.department FROM shifts s LEFT JOIN employees e ON s.employee_id = e.id WHERE s.id = ? LIMIT 1");
                $stmt->execute([$shift_id]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    $msg = "Shift not found.";
                } else {
                    // Final values: provided (even empty) overrides; otherwise keep existing
                    $final_date = ($provided_date !== null) ? $provided_date : $existing['shift_date'];
                    $final_type = ($provided_type !== null) ? $provided_type : $existing['shift_type'];

                    // Basic validation
                    if ($final_date === '' || DateTime::createFromFormat('Y-m-d', $final_date) === false) {
                        $msg = "Invalid date. Use YYYY-MM-DD.";
                    }
                    if ($final_type === '' || $final_type === null) {
                        $msg = $msg ?: "Shift type is required.";
                    }

                    // Times handling
                    $final_start = $existing['shift_start'] ?? null;
                    $final_end   = $existing['shift_end'] ?? null;
                    if ($shifts_have_times) {
                        if ($provided_start_raw !== null) {
                            if ($provided_start_raw === '') {
                                $final_start = null; // cleared by user
                            } else {
                                $norm = normalize_time_for_db($provided_start_raw);
                                if ($norm === null) $msg = $msg ?: "Invalid start time format.";
                                else $final_start = $norm;
                            }
                        }
                        if ($provided_end_raw !== null) {
                            if ($provided_end_raw === '') {
                                $final_end = null;
                            } else {
                                $norm = normalize_time_for_db($provided_end_raw);
                                if ($norm === null) $msg = $msg ?: "Invalid end time format.";
                                else $final_end = $norm;
                            }
                        }
                    } else {
                        $final_start = null;
                        $final_end = null;
                    }

                    // Prevent moving to past if not admin
                    $is_admin = ($role_norm === 'admin' || strpos($role_norm, 'admin') !== false);
                    if ($msg === "") {
                        $today = (new DateTime())->format('Y-m-d');
                        if ($final_date < $today && !$is_admin) {
                            $msg = "Cannot move shift to a past date.";
                        }
                    }

                    if ($msg === "") {
                        $pdo->beginTransaction();

                        if ($shifts_have_times) {
                            $upd = $pdo->prepare("UPDATE shifts SET shift_date = ?, shift_type = ?, shift_start = ?, shift_end = ? WHERE id = ?");
                            $start_param = $final_start ?: null;
                            $end_param = $final_end ?: null;
                            $upd->execute([$final_date, $final_type, $start_param, $end_param, $shift_id]);
                        } else {
                            $upd = $pdo->prepare("UPDATE shifts SET shift_date = ?, shift_type = ? WHERE id = ?");
                            $upd->execute([$final_date, $final_type, $shift_id]);
                        }

                        // Audit log
                        $details = [
                            'before' => [
                                'shift_date' => $existing['shift_date'] ?? null,
                                'shift_type' => $existing['shift_type'] ?? null,
                                'shift_start' => $existing['shift_start'] ?? null,
                                'shift_end' => $existing['shift_end'] ?? null
                            ],
                            'after' => [
                                'shift_date' => $final_date,
                                'shift_type' => $final_type,
                                'shift_start' => $final_start,
                                'shift_end' => $final_end
                            ]
                        ];
                        log_schedule_action($pdo, (int)$existing['employee_id'], 'Edit Shift', 'Success', $fullname, $existing['department'] ?? null, $details);

                        // Best-effort: insert into shift_history if table exists (non-fatal)
                        try {
                            $check = $pdo->query("SHOW TABLES LIKE 'shift_history'")->fetchColumn();
                            if ($check) {
                                $ins = $pdo->prepare("INSERT INTO shift_history (shift_id, employee_id, changed_by, action, before_json, after_json) VALUES (?, ?, ?, ?, ?, ?)");
                                $ins->execute([
                                    $shift_id,
                                    $existing['employee_id'] ?? null,
                                    $fullname,
                                    'edit',
                                    json_encode($details['before'], JSON_UNESCAPED_UNICODE),
                                    json_encode($details['after'], JSON_UNESCAPED_UNICODE)
                                ]);
                            }
                        } catch (Exception $ex) {
                            // ignore history errors
                        }

                        $pdo->commit();

                        $_SESSION['edit_update_msg'] = "Shift updated successfully!";
                        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
                        exit();
                    }
                }
            } catch (Exception $ex) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                $msg = "Error updating shift: " . $ex->getMessage();
            }
        }
    }
}

// Load flash message
if (isset($_SESSION['edit_update_msg'])) {
    $msg = $_SESSION['edit_update_msg'];
    unset($_SESSION['edit_update_msg']);
}

// ---------------------- Load UI data ----------------------
$empStmt = $pdo->prepare("SELECT id, fullname, role, status FROM employees ORDER BY fullname ASC");
$empStmt->execute();
$employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);

if ($shifts_have_times) {
    $shiftStmt = $pdo->prepare("SELECT s.id, s.employee_id, e.fullname, e.role, e.status, s.shift_date, s.shift_type, s.shift_start, s.shift_end
        FROM shifts s
        JOIN employees e ON s.employee_id = e.id
        ORDER BY s.shift_date DESC, e.fullname ASC");
} else {
    $shiftStmt = $pdo->prepare("SELECT s.id, s.employee_id, e.fullname, e.role, e.status, s.shift_date, s.shift_type
        FROM shifts s
        JOIN employees e ON s.employee_id = e.id
        ORDER BY s.shift_date DESC, e.fullname ASC");
}
$shiftStmt->execute();
$shift_list = $shiftStmt->fetchAll(PDO::FETCH_ASSOC);

$emp_shifts = [];
foreach ($shift_list as $shift) {
    $emp_shifts[$shift['employee_id']][] = $shift;
}

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_employees,
    SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_count,
    SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) as inactive_count,
    (SELECT COUNT(*) FROM shifts) as total_shifts
    FROM employees";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit/Update Schedules - ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        * { transition: all 0.3s ease; }
        html, body { height: 100%; }
        body { font-family: 'QuickSand','Poppins',Arial,sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #fafbfc 100%); color: #22223b; font-size: 16px; margin: 0; padding: 0; }
        
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
        }

        .stat-card.active { border-left: 5px solid #22c55e; }
        .stat-card.inactive { border-left: 5px solid #ef4444; }
        .stat-card.total { border-left: 5px solid #3b82f6; }
        .stat-card.shifts { border-left: 5px solid #f59e0b; }

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

        .stat-card.active .stat-icon { background: #dcfce7; color: #22c55e; }
        .stat-card.inactive .stat-icon { background: #fee2e2; color: #ef4444; }
        .stat-card.total .stat-icon { background: #dbeafe; color: #3b82f6; }
        .stat-card.shifts .stat-icon { background: #fef3c7; color: #f59e0b; }

        .stat-text h3 { font-size: 2rem; font-weight: 800; margin: 0; color: #22223b; }
        .stat-text p { font-size: 0.9rem; color: #6c757d; margin: 0; }

        .content-card { 
            background: #fff; 
            border-radius: 18px; 
            box-shadow: 0 4px 15px rgba(140,140,200,0.08); 
            padding: 2rem 1.5rem; 
            margin-bottom: 2rem; 
            border: 1px solid #f0f0f0;
        }

        .content-card h5 { 
            font-size: 1.35rem; 
            font-weight: 700; 
            color: #22223b; 
            margin-bottom: 1.5rem; 
            display: flex; 
            align-items: center; 
            gap: 0.8rem;
        }

        .content-card h5::before { 
            content: ''; 
            width: 4px; 
            height: 28px; 
            background: linear-gradient(180deg, #9A66ff 0%, #4311a5 100%); 
            border-radius: 2px;
        }

        .table { font-size: 0.98rem; color: #22223b; margin: 0; }
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
            border: none; 
            background: transparent; 
            padding: 1.2rem 0.8rem; 
            vertical-align: middle; 
            border-bottom: 1px solid #f0f0f0;
        }
        .table tbody tr { transition: all 0.2s ease; }
        .table tbody tr:hover { background-color: #f8f9ff; }
        .table tbody tr:last-child td { border-bottom: none; }

        .inactive-row { opacity: 0.6; }

        .shift-time-small { font-size: 0.85rem; color: #6c757d; display: block; margin-top: 0.25rem; }

        .time-input { max-width: 110px; }

        .form-control, .form-select { 
            border-radius: 8px; 
            border: 1px solid #e0e7ff; 
            font-size: 0.95rem; 
            padding: 0.7rem 1rem;
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

        .btn-success { 
            background: #10b981; 
            border: none; 
            border-radius: 8px; 
            padding: 0.5rem 1rem; 
            font-weight: 600;
        }

        .btn-success:hover { background: #059669; }

        .btn-info { 
            background: #0ea5e9; 
            border: none; 
            border-radius: 8px; 
            padding: 0.5rem 1rem; 
            font-weight: 600;
        }

        .btn-info:hover { background: #0284c7; }

        .btn-secondary { background: #6b7280; border: none; }

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

        .alert-warning { 
            background: #fef3c7; 
            color: #92400e; 
            border-left-color: #f59e0b;
        }

        .alert-danger { 
            background: #fee2e2; 
            color: #991b1b; 
            border-left-color: #dc2626;
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

        .modal-title { font-size: 1.13rem; font-weight: 700; }
        .modal-body { padding: 1.5rem; background: #fafbfc; }
        .modal-footer { 
            background: #fafbfc; 
            border-top: 1px solid #e0e7ff; 
            padding: 1rem 1.5rem; 
            border-radius: 0 0 18px 18px;
        }

        .btn-close { filter: brightness(1.8); }

        .table-responsive { border-radius: 12px; overflow: hidden; }

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
            .content-card { padding: 1.2rem; } 
            .table th, .table td { padding: 0.8rem 0.5rem; font-size: 0.85rem; } 
            .topbar { flex-direction: column; gap: 1rem; justify-content: flex-start; }
        }

        @media (max-width: 500px) { 
            .sidebar { width: 100%; } 
            .main-content { padding: 0.8rem 0.5rem; } 
            .content-card { padding: 1rem 0.8rem; } 
            .dashboard-title { font-size: 1.2rem; } 
            .stat-card { padding: 1rem; gap: 1rem; } 
            .stat-icon { width: 50px; height: 50px; font-size: 1.3rem; } 
            .stat-text h3 { font-size: 1.5rem; } 
            .table th, .table td { padding: 0.4rem 0.2rem; font-size: 0.75rem; }
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
    <!-- Sidebar START -->
    <div class="sidebar d-flex flex-column justify-content-between shadow-sm border-end">
        <div>
            <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
                <img src="../assets/images/image.png" class="img-fluid me-2" style="height: 55px;" alt="Logo">
            </div>
            <div class="mb-4">
                <nav class="nav flex-column">
                    <a class="nav-link" href="../scheduler/schedule_officer_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
                </nav>
            </div>
            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Shift & Schedule Management</h6>
                <nav class="nav flex-column">
                    <a class="nav-link" href="../scheduler/shift_scheduling.php"><ion-icon name="calendar-outline"></ion-icon>Shift Scheduling</a>
                    <a class="nav-link active" href="../scheduler/edit_update_schedules.php"><ion-icon name="pencil-outline"></ion-icon>Edit/Update Schedules</a>
                    <a class="nav-link" href="../scheduler/schedule_logs.php"><ion-icon name="reader-outline"></ion-icon>Schedule Logs</a>
                    <a class="nav-link" href="../scheduler/schedule_reports.php"><ion-icon name="document-text-outline"></ion-icon>Schedule Reports</a>
                </nav>
            </div>
        </div>
        <div class="p-3 border-top mb-2">
            <a class="nav-link text-danger" href="../logout.php">
                <ion-icon name="log-out-outline"></ion-icon>Logout
            </a>
        </div>
    </div>
    <!-- Sidebar END -->

    <!-- Main Content Wrapper -->
    <div class="content-wrapper">
        <!-- Top Bar -->
        <div class="topbar">
            <span class="dashboard-title">Edit/Update Schedules</span>
            <div class="profile">
                <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
                <div class="profile-info">
                    <strong><?= htmlspecialchars($fullname) ?></strong><br>
                    <small>Schedule Officer</small>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Alert Messages -->
            <?php if ($msg): ?>
                <div class="alert alert-<?= strpos($msg, 'Error') ? 'danger' : (strpos($msg, 'success') ? 'success' : 'info') ?> alert-dismissible fade show" role="alert">
                    <ion-icon name="<?= strpos($msg, 'Error') ? 'alert-circle-outline' : 'checkmark-circle-outline' ?>" style="margin-right: 0.5rem;"></ion-icon>
                    <span><?= htmlspecialchars($msg) ?></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Permission Warning -->
            <?php if (!$can_edit): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <ion-icon name="alert-circle-outline" style="margin-right: 0.5rem;"></ion-icon>
                    <span>You do not have permission to edit schedules. Contact your administrator.</span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

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
                        <h3><?= $stats['active_count'] ?? 0 ?></h3>
                        <p>Active</p>
                    </div>
                </div>
                <div class="stat-card inactive">
                    <div class="stat-icon">
                        <ion-icon name="close-circle-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['inactive_count'] ?? 0 ?></h3>
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
            </div>

            <!-- Employee Shift List Card -->
            <div class="content-card">
                <h5>
                    <ion-icon name="calendar-outline"></ion-icon> Employee Shift Management
                </h5>
                <p class="text-muted small mb-3">
                    <ion-icon name="information-circle-outline" style="font-size: 0.9rem;"></ion-icon>
                    Click "View/Edit" to manage individual employee schedules. Past shifts are locked to prevent accidental modifications.
                </p>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 35%;">Employee Name</th>
                                <th style="width: 20%;">Role</th>
                                <th style="width: 20%;">Status</th>
                                <th style="width: 25%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $emp): ?>
                                <tr class="<?= $emp['status']=='Inactive' ? 'inactive-row' : '' ?>">
                                    <td>
                                        <strong><?= htmlspecialchars($emp['fullname']) ?></strong>
                                    </td>
                                    <td><?= htmlspecialchars($emp['role']) ?></td>
                                    <td>
                                        <?php if($emp['status']=='Inactive'): ?>
                                            <span class="badge bg-secondary"><ion-icon name="close-circle-outline"></ion-icon> Inactive</span>
                                        <?php else: ?>
                                            <span class="badge bg-success"><ion-icon name="checkmark-circle-outline"></ion-icon> Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#schedModal<?= $emp['id'] ?>">
                                            <ion-icon name="calendar-outline"></ion-icon> View/Edit
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (empty($employees)): ?>
                    <div class="alert alert-warning mb-0">
                        <ion-icon name="alert-circle-outline"></ion-icon>
                        <span>No employees found in the system.</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- MODALS FOR EMPLOYEE SCHEDULES -->
            <?php foreach ($employees as $emp): ?>
                <div class="modal fade" id="schedModal<?= $emp['id'] ?>" tabindex="-1" aria-labelledby="schedModalLabel<?= $emp['id'] ?>" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="schedModalLabel<?= $emp['id'] ?>">
                                    <ion-icon name="calendar-outline"></ion-icon>
                                    <?= htmlspecialchars($emp['fullname']) ?> — Shift Schedule
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <?php if (isset($emp_shifts[$emp['id']]) && count($emp_shifts[$emp['id']]) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th style="width: 18%;">Date</th>
                                                    <th style="width: 12%;">Day</th>
                                                    <th style="width: 25%;">Shift Type</th>
                                                    <?php if ($shifts_have_times): ?>
                                                        <th style="width: 12%;">Start</th>
                                                        <th style="width: 12%;">End</th>
                                                    <?php endif; ?>
                                                    <th style="width: 15%;">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($emp_shifts[$emp['id']] as $shift): 
                                                    $shiftDate = $shift['shift_date'];
                                                    $isPast = ($shiftDate < date('Y-m-d'));
                                                    $stype = $shift['shift_type'] ?? '';
                                                    $fallbackStart = isset($shift_times[$stype]) ? $shift_times[$stype]['start'] : '';
                                                    $fallbackEnd   = isset($shift_times[$stype]) ? $shift_times[$stype]['end'] : '';
                                                    $displayStart = $shift['shift_start'] ?? $fallbackStart;
                                                    $displayEnd =   $shift['shift_end']   ?? $fallbackEnd;
                                                    ?>
                                                    <tr>
                                                        <form method="post" class="row-edit-form" style="display: contents;">
                                                            <input type="hidden" name="edit_shift" value="1">
                                                            <input type="hidden" name="shift_id" value="<?= (int)$shift['id'] ?>">
                                                            
                                                            <td>
                                                                <input type="date" class="form-control form-control-sm" name="shift_date" value="<?= htmlspecialchars($shiftDate) ?>" required <?= $isPast ? 'readonly' : '' ?> min="<?= $today ?>">
                                                            </td>
                                                            
                                                            <td>
                                                                <span class="badge bg-light text-dark">
                                                                    <?= ucfirst(strtolower(date('l', strtotime($shiftDate)))) ?>
                                                                </span>
                                                            </td>
                                                            
                                                            <td>
                                                                <select class="form-select form-select-sm shift-type-select" name="shift_type" required <?= $isPast ? 'disabled' : '' ?>>
                                                                    <option value="Morning" data-start="08:00" data-end="16:00" <?= ($shift['shift_type']=='Morning')?'selected':'' ?>>Morning</option>
                                                                    <option value="Afternoon" data-start="16:00" data-end="00:00" <?= ($shift['shift_type']=='Afternoon')?'selected':'' ?>>Afternoon</option>
                                                                    <option value="Night" data-start="00:00" data-end="08:00" <?= ($shift['shift_type']=='Night')?'selected':'' ?>>Night</option>
                                                                </select>
                                                                <small class="text-muted d-block mt-1">
                                                                    <?= htmlspecialchars(($displayStart ?: '') . ($displayStart ? '–' : '') . ($displayEnd ?: '')) ?>
                                                                </small>
                                                            </td>

                                                            <?php if ($shifts_have_times): ?>
                                                                <td>
                                                                    <input type="time" name="shift_start" class="form-control form-control-sm time-input" value="<?= htmlspecialchars($shift['shift_start'] ?? '') ?>" <?= $isPast ? 'readonly' : '' ?>>
                                                                </td>
                                                                <td>
                                                                    <input type="time" name="shift_end" class="form-control form-control-sm time-input" value="<?= htmlspecialchars($shift['shift_end'] ?? '') ?>" <?= $isPast ? 'readonly' : '' ?>>
                                                                </td>
                                                            <?php else: ?>
                                                                <input type="hidden" name="shift_start" class="shift-start-input" value="">
                                                                <input type="hidden" name="shift_end" class="shift-end-input" value="">
                                                            <?php endif; ?>

                                                            <td>
                                                                <?php if ($isPast): ?>
                                                                    <button type="button" class="btn btn-secondary btn-sm" disabled title="This shift is in the past">
                                                                        <ion-icon name="lock-closed-outline"></ion-icon> Locked
                                                                    </button>
                                                                <?php else: ?>
                                                                    <button type="submit" class="btn btn-success btn-sm" <?= $can_edit ? '' : 'disabled title="Permission denied"' ?>>
                                                                        <ion-icon name="checkmark-outline"></ion-icon> Save
                                                                    </button>
                                                                <?php endif; ?>
                                                            </td>
                                                        </form>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info mb-0">
                                        <ion-icon name="information-circle-outline"></ion-icon>
                                        <span>No shifts scheduled for this employee yet.</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Client-side: populate time inputs when shift type changes
document.addEventListener('DOMContentLoaded', function(){
    const mapping = { 
        'Morning':['08:00','16:00'], 
        'Afternoon':['16:00','00:00'], 
        'Night':['00:00','08:00'] 
    };
    
    document.querySelectorAll('.row-edit-form').forEach(function(form){
        const sel = form.querySelector('.shift-type-select');
        const startInput = form.querySelector('input[name="shift_start"]');
        const endInput = form.querySelector('input[name="shift_end"]');
        
        if (!sel) return;
        
        sel.addEventListener('change', function(){
            const val = sel.value;
            if (mapping[val]) {
                if (startInput && !startInput.value && !startInput.readOnly) {
                    startInput.value = mapping[val][0];
                }
                if (endInput && !endInput.value && !endInput.readOnly) {
                    endInput.value = mapping[val][1];
                }
            }
        });
    });
});
</script>
</body>
</html>