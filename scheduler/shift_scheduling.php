<?php
session_start();
require_once("../includes/db.php");

$fullname = $_SESSION['fullname'] ?? 'Schedule Officer';
$display_role = $_SESSION['role'] ?? 'Schedule Officer';

function normalize_role(string $r): string {
    $k = trim($r);
    $k = strtolower($k);
    $k = preg_replace('/[^a-z0-9]+/', '_', $k);
    $k = trim($k, '_');
    return $k ?: 'employee';
}
$role = normalize_role($display_role);

$today = date('Y-m-d');

$departments = ['HR', 'LOGISTICS', 'CORE', 'FINANCIAL'];
$selected_department = $_GET['department'] ?? '';

$debug_mode = (isset($_GET['debug_role']) && $_GET['debug_role'] == '1');

function get_employee_by_id($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function actor_can_assign_shifts(string $role_norm): bool {
    if (!empty($_SESSION['is_admin']) && $_SESSION['is_admin']) return true;
    if (!empty($_SESSION['permissions']) && is_array($_SESSION['permissions']) && in_array('manage_schedules', $_SESSION['permissions'], true)) return true;

    $allowed_exact = ['admin', 'schedule_officer', 'hr_manager', 'scheduler', 'scheduling_officer', 'scheduling_admin'];
    if (in_array($role_norm, $allowed_exact, true)) return true;

    $raw = strtolower(trim($_SESSION['role'] ?? ''));
    $keywords = ['admin','schedule','scheduler','hr','officer','hr3'];
    foreach ($keywords as $kw) {
        if ($kw !== '' && (strpos($role_norm, $kw) !== false || strpos($raw, $kw) !== false)) return true;
    }

    return false;
}

function shifts_have_time_cols(PDO $pdo): bool {
    $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'shifts'
              AND COLUMN_NAME IN ('shift_start','shift_end')";
    $stmt = $pdo->query($sql);
    if (!$stmt) return false;
    $count = (int)$stmt->fetchColumn();
    return $count >= 1;
}

function shift_exists(PDO $pdo, int $employee_id, string $date): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM shifts WHERE employee_id = ? AND shift_date = ? LIMIT 1");
    $stmt->execute([$employee_id, $date]);
    return (bool) $stmt->fetchColumn();
}

function insert_shift(PDO $pdo, int $employee_id, string $date, string $shift_type, ?string $shift_start = null, ?string $shift_end = null): int {
    if (shifts_have_time_cols($pdo)) {
        $stmt = $pdo->prepare("INSERT INTO shifts (employee_id, shift_date, shift_type, shift_start, shift_end, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$employee_id, $date, $shift_type, $shift_start, $shift_end]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO shifts (employee_id, shift_date, shift_type, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$employee_id, $date, $shift_type]);
    }
    return (int) $pdo->lastInsertId();
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

function assign_shifts_v2(PDO $pdo, string $performerName, string $actorRole, int $employee_id,
                          string $shift_type, string $start_date, ?string $end_date = null,
                          ?array $days = null, bool $dryRun = false, ?string $shift_start = null, ?string $shift_end = null): array
{
    $result = [
        'success' => false,
        'assigned' => 0,
        'preview' => [],
        'errors' => [],
        'details' => []
    ];

    if (!actor_can_assign_shifts($actorRole)) {
        $result['errors'][] = 'permission_denied';
        return $result;
    }

    $today = (new DateTime())->format('Y-m-d');
    $s = DateTime::createFromFormat('Y-m-d', $start_date);
    if (!$s) {
        $result['errors'][] = 'invalid_start_date';
        return $result;
    }

    if ($end_date) {
        $e = DateTime::createFromFormat('Y-m-d', $end_date);
        if (!$e) {
            $result['errors'][] = 'invalid_end_date';
            return $result;
        }
        if ($e < $s) {
            $result['errors'][] = 'end_before_start';
            return $result;
        }
    } else {
        $e = clone $s;
    }

    if ($e < new DateTime($today)) {
        $result['errors'][] = 'range_entirely_in_past';
        return $result;
    }

    if ($end_date && (empty($days) || !is_array($days))) {
        $days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
    }

    $stmt = $pdo->prepare("SELECT id, fullname, department, status FROM employees WHERE id = ? LIMIT 1");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) {
        $result['errors'][] = 'employee_not_found';
        return $result;
    }

    $cursor = clone $s;
    $plan = [];
    while ($cursor <= $e) {
        $dateStr = $cursor->format('Y-m-d');
        $weekday = strtolower($cursor->format('l'));

        if ($dateStr < $today) {
            $plan[$dateStr] = 'skipped_past';
        } elseif ($end_date && !in_array($weekday, $days, true)) {
            $plan[$dateStr] = 'weekday_not_selected';
        } elseif (shift_exists($pdo, $employee_id, $dateStr)) {
            $plan[$dateStr] = 'exists';
        } else {
            $plan[$dateStr] = 'will_create';
        }
        $cursor->modify('+1 day');
    }

    $result['preview'] = $plan;

    if ($dryRun) {
        $result['success'] = true;
        return $result;
    }

    try {
        $pdo->beginTransaction();
        $assigned = 0;
        $created = [];
        foreach ($plan as $dateStr => $action) {
            if ($action !== 'will_create') continue;
            try {
                $newId = insert_shift($pdo, $employee_id, $dateStr, $shift_type, $shift_start, $shift_end);
                $assigned++;
                $created[] = ['shift_id' => $newId, 'shift_date' => $dateStr, 'shift_type' => $shift_type, 'shift_start' => $shift_start, 'shift_end' => $shift_end];
            } catch (PDOException $ex) {
                $plan[$dateStr] = 'insert_failed';
                $result['preview'] = $plan;
            }
        }
        $pdo->commit();

        $actionLabel = ($end_date ? 'Created Recurring Shift' : 'Created Shift');
        $status = ($assigned > 0) ? 'Success' : 'No Changes';
        log_schedule_action(
            $pdo,
            $employee_id,
            $actionLabel,
            $status,
            $performerName,
            $employee['department'] ?? null,
            array_merge(['created' => $created], ['preview' => $plan])
        );

        $result['success'] = true;
        $result['assigned'] = $assigned;
        $result['details'] = ['created' => $created];
        $result['preview'] = $plan;
        return $result;

    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $result['errors'][] = 'exception:' . $ex->getMessage();
        return $result;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_shift'])) {
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date = trim($_POST['end_date'] ?? '');
    $shift_type = trim($_POST['shift_type'] ?? '');
    $days = isset($_POST['days']) ? $_POST['days'] : [];

    $shift_start = trim($_POST['shift_start_bulk'] ?? '');
    $shift_end = trim($_POST['shift_end_bulk'] ?? '');

    header('Content-Type: application/json; charset=utf-8');
    if (!$employee_id || !$start_date || !$end_date || !$shift_type) {
        echo json_encode(['success' => false, 'errors' => ['missing_parameters']]);
        exit;
    }

    $preview = assign_shifts_v2($pdo, $fullname, $role, $employee_id, $shift_type, $start_date, $end_date, $days, true, $shift_start ?: null, $shift_end ?: null);

    if (!empty($shift_start) || !empty($shift_end)) {
        $preview['shift_times'] = ['start' => $shift_start ?: null, 'end' => $shift_end ?: null];
    } else {
        $shiftTimes = [
            'Morning' => ['start' => '08:00', 'end' => '16:00'],
            'Afternoon' => ['start' => '16:00', 'end' => '00:00'],
            'Night' => ['start' => '00:00', 'end' => '08:00']
        ];
        $preview['shift_times'] = $shiftTimes[$shift_type] ?? null;
    }

    echo json_encode($preview);
    exit;
}

$msg = "";

$can_assign = actor_can_assign_shifts($role);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_shift_bulk'])) {
    if (!$can_assign) {
        $msg = "Error: permission_denied";
    } else {
        $employee_id = intval($_POST['employee_id']);
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $shift_type = $_POST['shift_type'] ?? '';
        $days = isset($_POST['days']) ? $_POST['days'] : [];
        $shift_start = trim($_POST['shift_start_bulk'] ?? '') ?: null;
        $shift_end = trim($_POST['shift_end_bulk'] ?? '') ?: null;

        $result = assign_shifts_v2($pdo, $fullname, $role, $employee_id, $shift_type, $start_date, $end_date, $days, false, $shift_start, $shift_end);

        if (!empty($result['errors'])) {
            $msg = "Error: " . implode(", ", $result['errors']);
        } else {
            $assigned = intval($result['assigned']);
            $counts = array_count_values($result['preview']);
            $skipped = isset($counts['skipped_past']) ? $counts['skipped_past'] : 0;
            $weekday_skips = isset($counts['weekday_not_selected']) ? $counts['weekday_not_selected'] : 0;
            $exists = isset($counts['exists']) ? $counts['exists'] : 0;
            $insert_failed = isset($counts['insert_failed']) ? $counts['insert_failed'] : 0;

            $pieces = [];
            $pieces[] = "$assigned shifts assigned";
            if ($skipped) $pieces[] = "$skipped past-date(s) skipped";
            if ($weekday_skips) $pieces[] = "$weekday_skips skipped by weekday filter";
            if ($exists) $pieces[] = "$exists already scheduled";
            if ($insert_failed) $pieces[] = "$insert_failed failed due to concurrent inserts";
            $msg = implode("; ", $pieces) . ".";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_shift'])) {
    if (!$can_assign) {
        $msg = "Error: permission_denied";
    } else {
        $employee_id = intval($_POST['employee_id']);
        $shift_date = $_POST['shift_date'] ?? '';
        $shift_type = $_POST['shift_type'] ?? '';
        $shift_start = trim($_POST['shift_start_single'] ?? '') ?: null;
        $shift_end = trim($_POST['shift_end_single'] ?? '') ?: null;

        $result = assign_shifts_v2($pdo, $fullname, $role, $employee_id, $shift_type, $shift_date, null, null, false, $shift_start, $shift_end);

        if (!empty($result['errors'])) {
            $msg = "Error: " . implode(", ", $result['errors']);
        } else {
            if ($result['assigned'] > 0) {
                $msg = "Shift assigned!";
            } else {
                $counts = array_count_values($result['preview']);
                if (isset($counts['exists']) && $counts['exists'] > 0) {
                    $msg = "Employee already has a shift for that date.";
                } elseif (isset($counts['skipped_past']) && $counts['skipped_past'] > 0) {
                    $msg = "Cannot assign to past dates.";
                } else {
                    $msg = "No changes made.";
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_shift'])) {
    $shift_id = intval($_POST['shift_id']);
    $stmt = $pdo->prepare("SELECT s.*, e.department FROM shifts s JOIN employees e ON s.employee_id = e.id WHERE s.id=?");
    $stmt->execute([$shift_id]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("DELETE FROM shifts WHERE id=?");
    $stmt->execute([$shift_id]);
    if ($shift) {
        $stmt = $pdo->prepare("INSERT INTO schedule_logs (employee_id, action, action_time, status, performed_by, department, details) VALUES (?, ?, NOW(), ?, ?, ?, ?)");
        $stmt->execute([
            $shift['employee_id'],
            'Deleted Shift',
            'Success',
            $fullname,
            $shift['department'],
            json_encode(['shift_date' => $shift['shift_date'], 'shift_type' => $shift['shift_type']])
        ]);
    }
    header("Location: shift_scheduling.php");
    exit;
}

$emp_q = "SELECT id, employee_id, fullname, role, status, department FROM employees";
$params = [];
if ($selected_department && in_array($selected_department, $departments)) {
    $emp_q .= " WHERE department = ?";
    $params[] = $selected_department;
}
$emp_q .= " ORDER BY fullname ASC";
$stmt = $pdo->prepare($emp_q);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$shifts_have_times = shifts_have_time_cols($pdo);

if ($shifts_have_times) {
    $stmt = $pdo->prepare("SELECT s.id, s.employee_id, e.fullname, e.role, e.status, e.department, s.shift_date, s.shift_type, s.shift_start, s.shift_end
        FROM shifts s 
        JOIN employees e ON s.employee_id = e.id
        ORDER BY s.shift_date DESC, e.fullname ASC");
} else {
    $stmt = $pdo->prepare("SELECT s.id, s.employee_id, e.fullname, e.role, e.status, e.department, s.shift_date, s.shift_type
        FROM shifts s 
        JOIN employees e ON s.employee_id = e.id
        ORDER BY s.shift_date DESC, e.fullname ASC");
}
$stmt->execute();
$shift_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shift Scheduling - ViaHale TNVS HR3</title>
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

        .card-body { padding: 2rem; }

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

        .btn-outline-primary { 
            border: 2px solid #9A66ff; 
            color: #9A66ff;
            border-radius: 8px;
        }

        .btn-outline-primary:hover { 
            background: #9A66ff; 
            color: white;
        }

        .btn-success { 
            background: #10b981; 
            border: none; 
            border-radius: 8px; 
            padding: 0.65rem 1.5rem; 
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

        .form-control, .form-select { 
            border-radius: 8px; 
            border: 1px solid #e0e7ff; 
            padding: 0.7rem 1rem;
        }

        .form-control:focus, .form-select:focus { 
            border-color: #9A66ff; 
            box-shadow: 0 0 0 0.2rem rgba(154, 102, 255, 0.15);
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

        .shift-date-badge { 
            display: inline-block; 
            margin-right: 6px; 
            padding: 6px 12px; 
            border-radius: 12px; 
            font-weight: 600;
            font-size: 0.9rem;
        }

        .shift-time-label { 
            font-size: 0.95rem; 
            color: #6c757d; 
            display: block; 
            margin-top: 0.25rem;
        }

        .weekdays-checkboxes label { margin-right: 14px; }

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

        .modal-title { font-size: 1.13rem; font-weight: 700; }
        .modal-body { background: #fafbfc; padding: 1.7rem 1.5rem; }
        .modal-footer { background: #fafbfc; border-top: 1px solid #e0e7ff; padding: 1rem 1.5rem; }

        .btn-close { filter: brightness(1.8); }

        .table-responsive { border-radius: 12px; overflow: hidden; }

        .badge { padding: 0.5rem 0.85rem; border-radius: 20px; font-weight: 600; }

        .filter-section { 
            background: #f8f9ff; 
            border: 1px solid #e0e7ff; 
            border-radius: 12px; 
            padding: 1.5rem; 
            margin-bottom: 2rem;
        }

        .filter-section form { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }

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
            .filter-section form { flex-direction: column; }
            .filter-section form > * { width: 100%; }
        }

        /* Search input in card header */
        #empSearchInput::placeholder { color: rgba(255,255,255,0.6); }
        #empSearchInput:focus { background: rgba(255,255,255,0.25); border-color: #fff; color: #fff; box-shadow: 0 0 0 0.2rem rgba(255,255,255,0.15); }

        /* Pagination styles */
        .pagination .page-link {
            color: #9A66ff;
            border: 1px solid #e0e7ff;
            font-weight: 600;
            font-size: 0.88rem;
        }
        .pagination .page-item.active .page-link {
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
            border-color: #9A66ff;
            color: #fff;
        }
        .pagination .page-link:hover {
            background: #f0e6ff;
            color: #4311a5;
            border-color: #9A66ff;
        }
        .pagination .page-item.disabled .page-link {
            color: #bfc7d1;
            background: transparent;
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
                    <a class="nav-link active" href="../scheduler/shift_scheduling.php"><ion-icon name="calendar-outline"></ion-icon>Shift Scheduling</a>
                    <a class="nav-link" href="../scheduler/edit_update_schedules.php"><ion-icon name="pencil-outline"></ion-icon>Edit/Update Schedules</a>
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

    <!-- Main Content Wrapper -->
    <div class="content-wrapper">
        <!-- Top Bar -->
        <div class="topbar">
            <span class="dashboard-title">Shift Scheduling</span>
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

            <!-- Department Filter -->
            <div class="filter-section">
                <form method="get" action="">
                    <label for="department" style="font-weight: 600; margin: 0; white-space: nowrap;">
                        <ion-icon name="funnel-outline" style="margin-right: 0.4rem;"></ion-icon>Filter by Department:
                    </label>
                    <select class="form-select" style="width: auto; min-width: 200px;" name="department" id="department" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept ?>" <?= ($selected_department == $dept) ? 'selected' : '' ?>><?= $dept ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($selected_department): ?>
                        <a href="shift_scheduling.php" class="btn btn-link p-0"><ion-icon name="close-outline"></ion-icon> Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($msg): ?>
                <div class="alert alert-<?= strpos($msg, 'Error') ? 'danger' : (strpos($msg, 'successfully') ? 'success' : 'info') ?>">
                    <ion-icon name="<?= strpos($msg, 'Error') ? 'alert-circle-outline' : 'checkmark-circle-outline' ?>" style="margin-right: 0.5rem;"></ion-icon>
                    <?= htmlspecialchars($msg) ?>
                </div>
            <?php endif; ?>

            <?php if (!$can_assign): ?>
                <div class="alert alert-warning">
                    <ion-icon name="alert-circle-outline" style="margin-right: 0.5rem;"></ion-icon>
                    You do not have permission to assign shifts. Contact your administrator.
                    <?php if ($debug_mode): ?>
                        <div class="small text-muted mt-1">Debug — session role: <?= htmlspecialchars($_SESSION['role'] ?? '') ?> | normalized: <?= htmlspecialchars($role) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <ion-icon name="calendar-outline"></ion-icon> Single Shift Assignment
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        <ion-icon name="information-circle-outline" style="font-size: 0.9rem;"></ion-icon>
                        Use this to assign one shift to an employee on a specific future date. The system blocks past dates and prevents duplicate assignments.
                    </p>
                    <form method="post" class="row g-3 align-items-end">
                        <input type="hidden" name="assign_shift" value="1">
                        <div class="col-md-3">
                            <label for="single_employee" class="form-label">Employee <span class="text-danger">*</span></label>
                            <select class="form-select" id="single_employee" name="employee_id" required <?= $can_assign ? '' : 'disabled' ?>>
                                <option value="">Select employee</option>
                                <?php foreach ($employees as $emp): ?>
                                    <?php if ($emp['status'] == 'Active'): ?>
                                        <option value="<?= htmlspecialchars($emp['id']) ?>">
                                            <?= htmlspecialchars($emp['fullname']) ?> (<?= htmlspecialchars($emp['role']) ?><?= $emp['department'] ? ", " . htmlspecialchars($emp['department']) : "" ?>)
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="shift_date" class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="shift_date" name="shift_date" required min="<?= $today ?>" <?= $can_assign ? '' : 'disabled' ?>>
                        </div>
                        <div class="col-md-3">
                            <label for="shift_type_single" class="form-label">Shift Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="shift_type_single" name="shift_type" required <?= $can_assign ? '' : 'disabled' ?>>
                                <option value="">Choose shift type</option>
                                <option value="Morning" data-start="08:00" data-end="16:00">Morning — 08:00–16:00</option>
                                <option value="Afternoon" data-start="16:00" data-end="00:00">Afternoon — 16:00–00:00</option>
                                <option value="Night" data-start="00:00" data-end="08:00">Night — 00:00–08:00</option>
                            </select>
                            <span id="shift_time_single" class="shift-time-label" aria-live="polite"></span>
                            <input type="hidden" name="shift_start_single" id="shift_start_single" value="">
                            <input type="hidden" name="shift_end_single" id="shift_end_single" value="">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100" <?= $can_assign ? '' : 'disabled' ?>>
                                <ion-icon name="checkmark-outline"></ion-icon> Assign
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <ion-icon name="repeat-outline"></ion-icon> Recurring Shift Pattern
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        <ion-icon name="information-circle-outline" style="font-size: 0.9rem;"></ion-icon>
                        Create a repeating schedule between a start and end date. Select the weekdays to apply. The system will skip past dates and avoid duplicates.
                    </p>
                    <form method="post" class="row g-3 align-items-end" id="bulkForm">
                        <input type="hidden" name="assign_shift_bulk" value="1">
                        <div class="col-md-2">
                            <label for="bulk_employee" class="form-label">Employee <span class="text-danger">*</span></label>
                            <select class="form-select" id="bulk_employee" name="employee_id" required <?= $can_assign ? '' : 'disabled' ?>>
                                <option value="">Select employee</option>
                                <?php foreach ($employees as $emp): ?>
                                    <?php if ($emp['status'] == 'Active'): ?>
                                        <option value="<?= htmlspecialchars($emp['id']) ?>">
                                            <?= htmlspecialchars($emp['fullname']) ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="start_date" name="start_date" required min="<?= $today ?>" <?= $can_assign ? '' : 'disabled' ?>>
                        </div>
                        <div class="col-md-2">
                            <label for="end_date" class="form-label">End Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="end_date" name="end_date" required min="<?= $today ?>" <?= $can_assign ? '' : 'disabled' ?>>
                        </div>
                        <div class="col-md-2">
                            <label for="shift_type_bulk" class="form-label">Shift Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="shift_type_bulk" name="shift_type" required <?= $can_assign ? '' : 'disabled' ?>>
                                <option value="">Choose shift type</option>
                                <option value="Morning" data-start="08:00" data-end="16:00">Morning — 08:00–16:00</option>
                                <option value="Afternoon" data-start="16:00" data-end="00:00">Afternoon — 16:00–00:00</option>
                                <option value="Night" data-start="00:00" data-end="08:00">Night — 00:00–08:00</option>
                            </select>
                            <span id="shift_time_bulk" class="shift-time-label" aria-live="polite"></span>
                            <input type="hidden" name="shift_start_bulk" id="shift_start_bulk" value="">
                            <input type="hidden" name="shift_end_bulk" id="shift_end_bulk" value="">
                        </div>
                        <div class="col-md-2">
                            <div role="group" aria-label="Weekday selection">
                                <label class="form-label">Weekdays <span class="text-danger">*</span></label>
                                <div class="weekdays-checkboxes" style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                                    <?php
                                    $daysOfWeek = ["Monday" => "monday", "Tuesday" => "tuesday", "Wednesday" => "wednesday", "Thursday" => "thursday", "Friday" => "friday", "Saturday" => "saturday", "Sunday" => "sunday"];
                                    foreach ($daysOfWeek as $label => $value): ?>
                                        <label class="form-check-label" style="margin: 0; white-space: nowrap;">
                                            <input type="checkbox" class="form-check-input" name="days[]" value="<?= $value ?>" <?= $can_assign ? '' : 'disabled' ?>> <?= substr($label, 0, 3) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div style="display: flex; gap: 0.5rem;">
                                <button type="button" id="previewBtn" class="btn btn-outline-primary w-100" <?= $can_assign ? '' : 'disabled' ?>>
                                    <ion-icon name="eye-outline"></ion-icon> Preview
                                </button>
                                <button type="submit" class="btn btn-success w-100" <?= $can_assign ? '' : 'disabled' ?>>
                                    <ion-icon name="checkmark-outline"></ion-icon> Create
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:0.8rem;">
                    <span><ion-icon name="people-outline"></ion-icon> Employee Shift Overview</span>
                    <div class="d-flex align-items-center" style="gap:0.8rem;">
                        <div class="position-relative">
                            <ion-icon name="search-outline" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9A66ff;font-size:1rem;"></ion-icon>
                            <input type="text" id="empSearchInput" class="form-control form-control-sm" placeholder="Search employees..." style="padding-left:32px;border-radius:8px;font-size:0.85rem;min-width:200px;background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);color:#fff;" />
                        </div>
                        <label for="empPerPage" class="mb-0" style="font-size:0.85rem;font-weight:500;white-space:nowrap;">Rows per page:</label>
                        <select id="empPerPage" class="form-select form-select-sm" style="width:auto;border-radius:8px;font-size:0.85rem;">
                            <option value="5">5</option>
                            <option value="10" selected>10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="empShiftTable">
                            <thead>
                                <tr>
                                    <th>Employee Name</th>
                                    <th>Role</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th style="text-align: center;">View Schedule</th>
                                </tr>
                            </thead>
                            <tbody id="empShiftTableBody">
                                <?php foreach ($employees as $emp): ?>
                                    <tr class="emp-row <?= $emp['status'] == 'Inactive' ? 'table-secondary' : '' ?>">
                                        <td><strong><?= htmlspecialchars($emp['fullname']) ?></strong></td>
                                        <td><?= htmlspecialchars($emp['role']) ?></td>
                                        <td><?= htmlspecialchars($emp['department'] ?? '') ?></td>
                                        <td>
                                            <?php if ($emp['status'] == 'Inactive'): ?>
                                                <span class="badge bg-secondary"><ion-icon name="close-circle-outline"></ion-icon> Inactive</span>
                                            <?php else: ?>
                                                <span class="badge bg-success"><ion-icon name="checkmark-circle-outline"></ion-icon> Active</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#schedModal<?= $emp['id'] ?>">
                                                <ion-icon name="calendar-outline"></ion-icon> View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination Controls -->
                    <div id="empPaginationWrapper" class="d-flex justify-content-between align-items-center flex-wrap px-3 py-3" style="gap:0.8rem;border-top:1px solid #f0f0f0;">
                        <span id="empPaginationInfo" style="font-size:0.9rem;color:#6c757d;"></span>
                        <nav aria-label="Employee table pagination">
                            <ul class="pagination pagination-sm mb-0" id="empPaginationNav" style="gap:4px;"></ul>
                        </nav>
                    </div>
                </div>
            </div>

            <!-- Preview Modal -->
            <div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="previewModalLabel"><ion-icon name="eye-outline"></ion-icon>Schedule Preview</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div id="previewSummary" class="mb-3"></div>
                            <div id="previewTableContainer"></div>
                            <div id="previewErrors" class="mt-2 text-danger"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button id="applyFromPreviewBtn" type="button" class="btn btn-success" <?= $can_assign ? '' : 'disabled' ?>>
                                <ion-icon name="checkmark-outline"></ion-icon> Create Schedule
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MODALS FOR EMPLOYEE SCHEDULES -->
            <?php foreach ($employees as $emp): ?>
                <div class="modal fade" id="schedModal<?= $emp['id'] ?>" tabindex="-1" aria-labelledby="schedModalLabel<?= $emp['id'] ?>" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="schedModalLabel<?= $emp['id'] ?>">
                                    <ion-icon name="calendar-outline"></ion-icon>
                                    <?= htmlspecialchars($emp['fullname']) ?> — Schedule
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <?php if (isset($emp_shifts[$emp['id']]) && count($emp_shifts[$emp['id']]) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Day</th>
                                                    <th>Shift Type (Hours)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($emp_shifts[$emp['id']] as $shift):
                                                    $shiftDate = $shift['shift_date'];
                                                    $isPast = ($shiftDate < $today);
                                                    $dateBadgeClass = $isPast ? 'bg-light text-dark border' : 'bg-primary text-white';
                                                    $dayBadgeClass = $isPast ? 'bg-secondary text-white' : 'bg-info text-white';
                                                    $displayStart = $shift['shift_start'] ?? null;
                                                    $displayEnd = $shift['shift_end'] ?? null;
                                                    if (!$displayStart && !$displayEnd) {
                                                        $times = ['Morning' => '08:00–16:00', 'Afternoon' => '16:00–00:00', 'Night' => '00:00–08:00'];
                                                        $displayText = isset($times[$shift['shift_type']]) ? "({$times[$shift['shift_type']]})" : "";
                                                    } else {
                                                        $displayText = '(' . ($displayStart ?? '??') . '–' . ($displayEnd ?? '??') . ')';
                                                    }
                                                ?>
                                                    <tr class="<?= $isPast ? 'table-secondary' : '' ?>">
                                                        <td>
                                                            <span class="shift-date-badge <?= $dateBadgeClass ?>" title="Shift Date"><?= htmlspecialchars($shiftDate) ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="shift-date-badge <?= $dayBadgeClass ?>" title="Day of Week"><?= ucfirst(date('l', strtotime($shiftDate))) ?></span>
                                                        </td>
                                                        <td><?= htmlspecialchars($shift['shift_type']) . ' ' . $displayText ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info mb-0">
                                        <ion-icon name="information-circle-outline"></ion-icon>
                                        <span>No shifts scheduled for this employee.</span>
                                    </div>
                                <?php endif; ?>
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
    // ── Employee Shift Overview Pagination + Search ──
    (function() {
        const tbody = document.getElementById('empShiftTableBody');
        const perPageSel = document.getElementById('empPerPage');
        const searchInput = document.getElementById('empSearchInput');
        const info = document.getElementById('empPaginationInfo');
        const nav = document.getElementById('empPaginationNav');
        const allRows = Array.from(tbody.querySelectorAll('tr.emp-row'));
        let filteredRows = allRows;
        let currentPage = 1;

        function applySearch() {
            const term = searchInput.value.trim().toLowerCase();
            if (!term) {
                filteredRows = allRows;
            } else {
                filteredRows = allRows.filter(row => {
                    const text = row.textContent.toLowerCase();
                    return text.includes(term);
                });
            }
            // Hide all first, render() will show matching ones
            allRows.forEach(r => r.style.display = 'none');
            currentPage = 1;
            render();
        }

        function render() {
            const perPage = parseInt(perPageSel.value, 10);
            const total = filteredRows.length;
            const totalPages = Math.max(1, Math.ceil(total / perPage));
            if (currentPage > totalPages) currentPage = totalPages;

            // Hide all rows, then show only matching page
            allRows.forEach(r => r.style.display = 'none');
            filteredRows.forEach((row, i) => {
                const start = (currentPage - 1) * perPage;
                const end = start + perPage;
                row.style.display = (i >= start && i < end) ? '' : 'none';
            });

            // Info text
            const from = total === 0 ? 0 : (currentPage - 1) * perPage + 1;
            const to = Math.min(currentPage * perPage, total);
            info.textContent = `Showing ${from}–${to} of ${total} employees`;

            // Build pagination buttons
            nav.innerHTML = '';

            function addBtn(label, page, disabled, active, ariaLabel) {
                const li = document.createElement('li');
                li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
                const a = document.createElement('a');
                a.className = 'page-link';
                a.href = '#';
                a.innerHTML = label;
                if (ariaLabel) a.setAttribute('aria-label', ariaLabel);
                a.style.borderRadius = '6px';
                a.style.minWidth = '36px';
                a.style.textAlign = 'center';
                if (active) {
                    a.style.background = 'linear-gradient(90deg, #9A66ff 0%, #4311a5 100%)';
                    a.style.borderColor = '#9A66ff';
                    a.style.color = '#fff';
                }
                if (!disabled && !active) {
                    a.addEventListener('click', function(e) {
                        e.preventDefault();
                        currentPage = page;
                        render();
                    });
                } else {
                    a.addEventListener('click', function(e) { e.preventDefault(); });
                }
                li.appendChild(a);
                nav.appendChild(li);
            }

            // Previous
            addBtn('&laquo;', currentPage - 1, currentPage === 1, false, 'Previous');

            // Page numbers with ellipsis
            const maxVisible = 5;
            let startPage, endPage;
            if (totalPages <= maxVisible) {
                startPage = 1;
                endPage = totalPages;
            } else {
                const half = Math.floor(maxVisible / 2);
                startPage = Math.max(1, currentPage - half);
                endPage = startPage + maxVisible - 1;
                if (endPage > totalPages) {
                    endPage = totalPages;
                    startPage = endPage - maxVisible + 1;
                }
            }

            if (startPage > 1) {
                addBtn('1', 1, false, false);
                if (startPage > 2) {
                    const li = document.createElement('li');
                    li.className = 'page-item disabled';
                    li.innerHTML = '<span class="page-link" style="border-radius:6px;">&hellip;</span>';
                    nav.appendChild(li);
                }
            }

            for (let p = startPage; p <= endPage; p++) {
                addBtn(p, p, false, p === currentPage);
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    const li = document.createElement('li');
                    li.className = 'page-item disabled';
                    li.innerHTML = '<span class="page-link" style="border-radius:6px;">&hellip;</span>';
                    nav.appendChild(li);
                }
                addBtn(totalPages, totalPages, false, false);
            }

            // Next
            addBtn('&raquo;', currentPage + 1, currentPage === totalPages, false, 'Next');
        }

        perPageSel.addEventListener('change', function() {
            currentPage = 1;
            render();
        });

        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(applySearch, 250);
        });

        render();
    })();
</script>
<script>
    document.getElementById('shift_type_single').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const start = selectedOption.getAttribute('data-start');
        const end = selectedOption.getAttribute('data-end');
        document.getElementById('shift_time_single').textContent = start && end ? `(${start}–${end})` : '';
        document.getElementById('shift_start_single').value = start || '';
        document.getElementById('shift_end_single').value = end || '';
    });

    document.getElementById('shift_type_bulk').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const start = selectedOption.getAttribute('data-start');
        const end = selectedOption.getAttribute('data-end');
        document.getElementById('shift_time_bulk').textContent = start && end ? `(${start}–${end})` : '';
        document.getElementById('shift_start_bulk').value = start || '';
        document.getElementById('shift_end_bulk').value = end || '';
    });

    document.getElementById('previewBtn').addEventListener('click', function() {
        const employeeId = document.getElementById('bulk_employee').value;
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        const shiftType = document.getElementById('shift_type_bulk').value;
        const days = Array.from(document.querySelectorAll('input[name="days[]"]:checked')).map(el => el.value);

        if (!employeeId || !startDate || !endDate || !shiftType) {
            alert('Please fill in all required fields');
            return;
        }

        fetch('shift_scheduling.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `preview_shift=1&employee_id=${employeeId}&start_date=${startDate}&end_date=${endDate}&shift_type=${shiftType}&days[]=${days.join('&days[]=')}&shift_start_bulk=${document.getElementById('shift_start_bulk').value}&shift_end_bulk=${document.getElementById('shift_end_bulk').value}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const preview = data.preview;
                const counts = {};
                for