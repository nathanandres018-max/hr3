<?php
session_start();
require_once("../includes/db.php");

$fullname = $_SESSION['fullname'] ?? 'Schedule Officer';
$role = $_SESSION['role'] ?? 'schedule_officer';

// normalize role for RBAC checks (keeps behavior consistent with other pages)
function normalize_role(string $r): string {
    $k = trim($r);
    $k = strtolower($k);
    $k = preg_replace('/[^a-z0-9]+/', '_', $k);
    $k = trim($k, '_');
    return $k ?: 'employee';
}
$role_key = normalize_role($role);

// who can manage swap requests
function actor_can_manage_swaps(string $role_key): bool {
    $allowed = ['admin', 'schedule_officer', 'hr_manager'];
    return in_array(strtolower($role_key), $allowed, true);
}

// helpers
function get_employee(PDO $pdo, int $id) {
    $stmt = $pdo->prepare("SELECT id, fullname, role, status, department FROM employees WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function get_shift(PDO $pdo, int $id) {
    $stmt = $pdo->prepare("SELECT id, employee_id, shift_date, shift_type FROM shifts WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function log_schedule_action(PDO $pdo, int $employee_id, string $action, string $status,
                             string $performed_by, ?string $department = null, array $details = []) {
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

// Ensure swap_requests table exists (idempotent)
$pdo->exec("CREATE TABLE IF NOT EXISTS swap_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    requester_id INT UNSIGNED NOT NULL,
    requester_shift_id INT UNSIGNED NOT NULL,
    swap_with_date DATE DEFAULT NULL,
    target_employee_id INT UNSIGNED DEFAULT NULL,
    target_shift_id INT UNSIGNED DEFAULT NULL,
    reason TEXT DEFAULT NULL,
    status ENUM('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by VARCHAR(255) DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_note TEXT DEFAULT NULL,
    INDEX (requester_id),
    INDEX (requester_shift_id),
    INDEX (target_employee_id),
    INDEX (target_shift_id),
    INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Message for UI
$msg = "";

// Handle review actions (approve / reject) - integrated, transactional
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_swap_status'])) {
    $request_id = intval($_POST['request_id'] ?? 0);
    $new_status = trim($_POST['new_status'] ?? '');
    $review_note = trim($_POST['review_note'] ?? '');

    if (!actor_can_manage_swaps($role_key)) {
        $msg = "Error: permission_denied";
    } elseif (!$request_id || !in_array($new_status, ['Approved','Rejected'], true)) {
        $msg = "Invalid request or status.";
    } else {
        // Load request
        $stmt = $pdo->prepare("SELECT * FROM swap_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            $msg = "Request not found.";
        } elseif ($req['status'] !== 'Pending') {
            $msg = "Only pending requests can be updated.";
        } else {
            if ($new_status === 'Rejected') {
                // simple reject path
                $upd = $pdo->prepare("UPDATE swap_requests SET status='Rejected', reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?");
                $upd->execute([$fullname, $review_note ?: 'Rejected by scheduler', $request_id]);
                log_schedule_action($pdo, intval($req['requester_id']), "Swap Request Rejected", "Rejected", $fullname, null, ['request_id'=>$request_id, 'note'=>$review_note]);
                $msg = "Request rejected.";
            } else {
                // Approve flow: transactional, attempt swap or reassignment
                try {
                    $pdo->beginTransaction();

                    // lock request row
                    $stmt = $pdo->prepare("SELECT * FROM swap_requests WHERE id = ? FOR UPDATE");
                    $stmt->execute([$request_id]);
                    $req = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$req) throw new Exception("Request disappeared.");
                    if ($req['status'] !== 'Pending') throw new Exception("Request no longer pending.");

                    // lock requester shift
                    $stmt = $pdo->prepare("SELECT * FROM shifts WHERE id = ? FOR UPDATE");
                    $stmt->execute([intval($req['requester_shift_id'])]);
                    $shiftA = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$shiftA) throw new Exception("Requester's shift not found.");

                    // find/lock potential target shift
                    $shiftB = null;
                    if (!empty($req['target_shift_id'])) {
                        $stmt = $pdo->prepare("SELECT * FROM shifts WHERE id = ? FOR UPDATE");
                        $stmt->execute([intval($req['target_shift_id'])]);
                        $shiftB = $stmt->fetch(PDO::FETCH_ASSOC);
                    } elseif (!empty($req['target_employee_id'])) {
                        // attempt to find that employee's shift on same date
                        $stmt = $pdo->prepare("SELECT * FROM shifts WHERE employee_id = ? AND shift_date = ? LIMIT 1 FOR UPDATE");
                        $stmt->execute([intval($req['target_employee_id']), $shiftA['shift_date']]);
                        $shiftB = $stmt->fetch(PDO::FETCH_ASSOC);
                    }

                    if ($shiftB) {
                        // swap scenario
                        if (intval($shiftA['employee_id']) === intval($shiftB['employee_id'])) {
                            throw new Exception("Both shifts already belong to same employee.");
                        }

                        // Update shifts: swap employee_id
                        $u1 = $pdo->prepare("UPDATE shifts SET employee_id = ? WHERE id = ?");
                        $u1->execute([intval($shiftB['employee_id']), intval($shiftA['id'])]);
                        $u2 = $pdo->prepare("UPDATE shifts SET employee_id = ? WHERE id = ?");
                        $u2->execute([intval($shiftA['employee_id']), intval($shiftB['id'])]);

                        // update request status
                        $upd = $pdo->prepare("UPDATE swap_requests SET status='Approved', reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?");
                        $upd->execute([$fullname, $review_note ?: 'Approved by scheduler', $request_id]);

                        // logging
                        $empA = get_employee($pdo, intval($shiftA['employee_id']));
                        $empB = get_employee($pdo, intval($shiftB['employee_id']));
                        log_schedule_action($pdo, intval($empA['id']), "Swap Approved - shift swapped with {$empB['fullname']}", "Success", $fullname, $empA['department'] ?? null, ['request_id'=>$request_id]);
                        log_schedule_action($pdo, intval($empB['id']), "Swap Approved - shift swapped with {$empA['fullname']}", "Success", $fullname, $empB['department'] ?? null, ['request_id'=>$request_id]);

                        $pdo->commit();
                        $msg = "Swap request approved — shifts swapped.";
                    } else {
                        // no counterpart shift found - attempt reassignment to target employee if provided
                        if (!empty($req['target_employee_id'])) {
                            // check target employee has no shift on that date
                            $stmt = $pdo->prepare("SELECT id FROM shifts WHERE employee_id = ? AND shift_date = ? LIMIT 1");
                            $stmt->execute([intval($req['target_employee_id']), $shiftA['shift_date']]);
                            if ($stmt->fetch()) throw new Exception("Target employee already has a shift on that date; cannot reassign.");

                            // reassign shift to target employee
                            $u = $pdo->prepare("UPDATE shifts SET employee_id = ? WHERE id = ?");
                            $u->execute([intval($req['target_employee_id']), intval($shiftA['id'])]);

                            $upd = $pdo->prepare("UPDATE swap_requests SET status='Approved', reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?");
                            $upd->execute([$fullname, $review_note ?: 'Approved (reassigned to target)', $request_id]);

                            // logging
                            log_schedule_action($pdo, intval($req['target_employee_id']), "Swap Approved - assigned shift {$shiftA['id']}", "Success", $fullname, null, ['request_id'=>$request_id, 'shift_id'=>$shiftA['id']]);

                            $pdo->commit();
                            $msg = "Swap request approved — shift reassigned to target employee.";
                        } else {
                            throw new Exception("No target shift/employee specified; cannot perform swap.");
                        }
                    }
                } catch (Exception $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $msg = "Approve failed: " . $ex->getMessage();
                }
            }
        }
    }
}

// Listing: pull recent requests (use swap_requests table)
$stmt = $pdo->prepare(
    "SELECT r.*, e.fullname, e.role, s.shift_date, s.shift_type
     FROM swap_requests r
     JOIN employees e ON r.requester_id = e.id
     JOIN shifts s ON r.requester_shift_id = s.id
     ORDER BY r.created_at DESC
     LIMIT 500"
);
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If none found, keep $requests empty array (UI shows empty state)
if (!$requests) $requests = [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shift Swap Requests - ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        * {
            font-family: 'QuickSand', 'Poppins', Arial, sans-serif;
        }

        body {
            background: #fafbfc;
            color: #22223b;
            font-size: 16px;
        }

        .sidebar {
            background: #181818ff;
            color: #fff;
            min-height: 100vh;
            border: none;
            width: 220px;
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1040;
            transition: left 0.3s;
            overflow-y: auto;
            padding: 1rem 0.3rem 1rem 0.3rem;
            scrollbar-width: none;
            height: 100vh;
            -ms-overflow-style: none;
        }

        .sidebar::-webkit-scrollbar {
            display: none;
            width: 0px;
            background: transparent;
        }

        .sidebar a,
        .sidebar button {
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
            transition: background 0.2s, color 0.2s;
            width: 100%;
            text-align: left;
            white-space: nowrap;
        }

        .sidebar a.active,
        .sidebar a:hover,
        .sidebar button.active,
        .sidebar button:hover {
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
            color: #fff;
        }

        .sidebar hr {
            border-top: 1px solid #232a43;
            margin: 0.7rem 0;
        }

        .sidebar .nav-link ion-icon {
            font-size: 1.2rem;
            margin-right: 0.3rem;
        }

        .topbar {
            padding: 0.7rem 2rem 0.7rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-top: 0 !important;
        }

        .topbar .profile {
            display: flex;
            align-items: center;
            gap: 1.2rem;
        }

        .topbar .profile-img {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 0.7rem;
            border: 2px solid #e0e7ff;
        }

        .topbar .profile-info {
            line-height: 1.1;
        }

        .topbar .profile-info strong {
            font-size: 1.08rem;
            font-weight: 600;
            color: #22223b;
        }

        .topbar .profile-info small {
            color: #6c757d;
            font-size: 0.93rem;
        }

        .dashboard-title {
            font-family: 'QuickSand', 'Poppins', Arial, sans-serif;
            font-size: 1.7rem;
            font-weight: 700;
            margin-bottom: 0;
            color: #22223b;
        }

        .main-content {
            margin-left: 220px;
            padding: 2rem 0;
        }

        .dashboard-col {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 2px 8px rgba(140, 140, 200, 0.07);
            padding: 2rem;
            margin: 2rem 2rem 1.5rem 2rem;
            border: 1px solid #f0f0f0;
        }

        .card {
            border-radius: 18px;
            box-shadow: 0 2px 8px rgba(140, 140, 200, 0.07);
            border: 1px solid #f0f0f0;
            margin-bottom: 2rem;
            margin-left: 2rem;
            margin-right: 2rem;
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
            color: white;
            border-radius: 18px 18px 0 0;
            padding: 1.5rem;
            border: none;
            font-weight: 700;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.7rem;
        }

        .card-body {
            padding: 2rem;
        }

        .table {
            font-size: 0.95rem;
            color: #22223b;
            margin-bottom: 0;
        }

        .table th {
            color: #6c757d;
            font-weight: 700;
            border-bottom: 2px solid #e0e7ff;
            background: #f9f9fc;
            padding: 1.2rem 1rem;
        }

        .table td {
            border-bottom: 1px solid #e8e8f0;
            padding: 1.2rem 1rem;
            vertical-align: middle;
        }

        .table tbody tr {
            transition: all 0.3s;
        }

        .table tbody tr:hover {
            background: #f8f8fb;
        }

        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .badge-pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-approved {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-rejected {
            background: #f8d7da;
            color: #842029;
        }

        .table-responsive {
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(140, 140, 200, 0.05);
        }

        .modal-content {
            border-radius: 18px;
            border: 1px solid #e0e7ff;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
            color: white;
            border: none;
            border-radius: 18px 18px 0 0;
            padding: 1.5rem;
        }

        .modal-title {
            font-size: 1.4rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .btn-close-white {
            filter: brightness(1.8);
            opacity: 0.8;
        }

        .btn-close-white:hover {
            opacity: 1;
        }

        .modal-body {
            padding: 2rem;
            background: #fafbfc;
            max-height: 80vh;
            overflow-y: auto;
        }

        .btn-primary {
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
            border: none;
            border-radius: 8px;
            padding: 0.7rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-primary:hover {
            background: linear-gradient(90deg, #8654e0 0%, #360090 100%);
            transform: translateY(-2px);
        }

        .btn-success {
            background: #10b981;
            border: none;
            border-radius: 8px;
            padding: 0.7rem 1.5rem;
            font-weight: 600;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-danger {
            background: #ef4444;
            border: none;
            border-radius: 8px;
            padding: 0.7rem 1.5rem;
            font-weight: 600;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-secondary {
            background: #6c757d;
            border: none;
            border-radius: 8px;
        }

        .btn-secondary:hover {
            background: #5c636a;
        }

        .btn-outline-primary {
            border: 1px solid #667eea;
            color: #667eea;
            border-radius: 8px;
        }

        .btn-outline-primary:hover {
            background: #667eea;
            color: white;
        }

        .form-control,
        .form-select {
            border-radius: 8px;
            border: 1px solid #e0e7ff;
            padding: 0.7rem 1rem;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.1);
        }

        .alert {
            border-radius: 10px;
            border: none;
            border-left: 4px solid #667eea;
            margin: 2rem 2rem 1rem 2rem;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border-left-color: #f59e0b;
        }

        @media (max-width: 1200px) {
            .main-content {
                padding: 1rem 0;
            }

            .sidebar {
                width: 180px;
                padding: 1rem 0.3rem;
            }

            .main-content {
                margin-left: 180px;
            }

            .card,
            .dashboard-col {
                margin-left: 1rem;
                margin-right: 1rem;
            }

            .topbar {
                padding-left: 1rem;
                padding-right: 1rem;
            }
        }

        @media (max-width: 900px) {
            .sidebar {
                left: -220px;
                width: 180px;
                padding: 1rem 0.3rem;
            }

            .sidebar.show {
                left: 0;
            }

            .main-content {
                margin-left: 0;
                padding: 1rem 0.5rem 1rem 0.5rem;
            }

            .card,
            .dashboard-col {
                margin-left: 0.5rem;
                margin-right: 0.5rem;
            }

            .topbar {
                padding-left: 0.5rem;
                padding-right: 0.5rem;
            }
        }

        @media (max-width: 700px) {
            .dashboard-title {
                font-size: 1.3rem;
            }

            .main-content {
                padding: 0.7rem 0.2rem 0.7rem 0.2rem;
            }

            .sidebar {
                width: 100vw;
                left: -100vw;
                padding: 0.7rem 0.2rem;
            }

            .sidebar.show {
                left: 0;
            }

            .card,
            .dashboard-col {
                margin-left: 0.2rem;
                margin-right: 0.2rem;
                padding: 1.2rem;
            }

            .topbar {
                padding-left: 0.5rem;
                padding-right: 0.5rem;
                flex-direction: column;
                align-items: flex-start;
            }

            .topbar .profile {
                width: 100%;
                justify-content: space-between;
            }

            .card-header {
                padding: 1.2rem;
                font-size: 1rem;
            }

            .card-body {
                padding: 1.2rem;
            }

            .table th,
            .table td {
                padding: 0.8rem 0.5rem;
                font-size: 0.85rem;
            }

            .modal-body {
                padding: 1.5rem;
            }

            .modal-header {
                padding: 1.2rem;
            }

            .modal-title {
                font-size: 1.1rem;
            }
        }

        @media (max-width: 500px) {
            .sidebar {
                width: 100vw;
                left: -100vw;
                padding: 0.3rem 0.01rem;
            }

            .sidebar.show {
                left: 0;
            }

            .main-content {
                padding: 0.5rem 0;
            }

            .card,
            .dashboard-col {
                margin: 0.5rem 0.01rem;
                padding: 0.8rem;
                border-radius: 12px;
            }

            .card-header,
            .card-body {
                padding: 0.8rem;
            }

            .topbar {
                padding: 0.5rem 0.5rem;
            }

            .dashboard-title {
                font-size: 1.1rem;
            }

            .table th,
            .table td {
                padding: 0.6rem 0.3rem;
                font-size: 0.75rem;
            }

            .btn-primary,
            .btn-success,
            .btn-danger,
            .btn-outline-primary {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }

            .status-badge {
                padding: 0.4rem 0.8rem;
                font-size: 0.75rem;
            }

            .form-control,
            .form-select {
                padding: 0.5rem 0.8rem;
                font-size: 0.9rem;
            }
        }

        @media (min-width: 1400px) {
            .sidebar {
                width: 260px;
                padding: 2rem 1rem 2rem 1rem;
            }

            .main-content {
                margin-left: 260px;
                padding: 2rem 2rem 2rem 2rem;
            }
        }
    </style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">
    <!-- Sidebar -->
    <div class="sidenav col-auto p-0">
      <div class="sidebar d-flex flex-column justify-content-between shadow-sm border-end">
        <div>
          <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
            <img src="../assets/images/image.png" class="img-fluid me-2" style="height: 55px;" alt="Logo">
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase mb-2">Dashboard</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="../scheduler/schedule_officer_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Employee Management</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="../scheduler/add_employee.php"><ion-icon name="person-add-outline"></ion-icon>Add Employee</a>
              <a class="nav-link" href="../scheduler/employee_management.php"><ion-icon name="people-outline"></ion-icon>Employee List</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Shift & Schedule Management</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="../scheduler/shift_scheduling.php"><ion-icon name="calendar-outline"></ion-icon>Shift Scheduling</a>
              <a class="nav-link" href="../scheduler/edit_update_schedules.php"><ion-icon name="pencil-outline"></ion-icon>Edit/Update Schedules</a>
              <a class="nav-link active" href="../scheduler/shift_swap_requests.php"><ion-icon name="swap-horizontal-outline"></ion-icon>Shift Swap Requests</a>
              <a class="nav-link" href="../scheduler/employee_availability.php"><ion-icon name="people-outline"></ion-icon>Employee Availability</a>
              <a class="nav-link" href="../scheduler/schedule_logs.php"><ion-icon name="reader-outline"></ion-icon>Schedule Logs</a>
              <a class="nav-link" href="../scheduler/company_calendar.php"><ion-icon name="calendar-outline"></ion-icon>Company Calendar</a>
              <a class="nav-link" href="../scheduler/schedule_reports.php"><ion-icon name="document-text-outline"></ion-icon>Schedule Reports</a>
              <a class="nav-link" href="#"><ion-icon name="settings-outline"></ion-icon>Scheduling Rules/Policies</a>
            </nav>
          </div>
        </div>
        <div class="p-3 border-top mb-2">
          <a class="nav-link text-danger" href="../logout.php">
            <ion-icon name="log-out-outline"></ion-icon>Logout
          </a>
        </div>
      </div>
    </div>

    <!-- Main Content -->
    <div class="main-content col">
      <div class="topbar">
        <span class="dashboard-title"><ion-icon name="swap-horizontal-outline"></ion-icon> Shift Swap Requests</span>
        <div class="profile">
          <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
          <div class="profile-info">
            <strong><?= htmlspecialchars($fullname) ?></strong><br>
            <small><?= htmlspecialchars(ucfirst($role)) ?></small>
          </div>
        </div>
      </div>

      <?php if ($msg): ?>
        <div class="alert alert-info">
          <ion-icon name="information-circle-outline"></ion-icon>
          <div><?= htmlspecialchars($msg) ?></div>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header">
          <ion-icon name="list-outline"></ion-icon> Pending & Recent Shift Swap Requests
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th style="width: 20%;">Employee</th>
                  <th style="width: 18%;">Current Shift</th>
                  <th style="width: 15%;">Requested Date</th>
                  <th style="width: 20%;">Reason</th>
                  <th style="width: 12%;">Status</th>
                  <th style="width: 15%;">Requested On</th>
                  <th style="width: 20%;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($requests)): ?>
                  <tr>
                    <td colspan="7" class="text-center py-4 text-muted">
                      <ion-icon name="checkmark-circle-outline" style="font-size: 2rem; margin-bottom: 0.5rem; display: block;"></ion-icon>
                      No shift swap requests found.
                    </td>
                  </tr>
                <?php endif; ?>
                <?php foreach ($requests as $req): ?>
                  <tr>
                    <td>
                      <div style="font-weight: 700; color: #22223b;">
                        <?= htmlspecialchars($req['fullname']) ?>
                      </div>
                      <div style="font-size: 0.85rem; color: #6c757d; margin-top: 0.2rem;">
                        <?= htmlspecialchars(ucfirst($req['role'])) ?>
                      </div>
                    </td>
                    <td>
                      <div style="font-weight: 600; color: #4311a5;">
                        <?= htmlspecialchars($req['shift_date']) ?>
                      </div>
                      <div style="font-size: 0.85rem; color: #6c757d; margin-top: 0.2rem;">
                        <?= htmlspecialchars($req['shift_type']) ?> — <?= ucfirst(strtolower(date('l', strtotime($req['shift_date'])))) ?>
                      </div>
                    </td>
                    <td>
                      <div style="font-weight: 600;">
                        <?= htmlspecialchars($req['swap_with_date'] ?? 'Not specified') ?>
                      </div>
                    </td>
                    <td>
                      <div style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        <?= htmlspecialchars(substr($req['reason'] ?? '', 0, 50)) ?>
                      </div>
                    </td>
                    <td>
                      <?php if ($req['status'] === 'Pending'): ?>
                        <span class="status-badge badge-pending">
                          <ion-icon name="time-outline" style="margin-right: 0.3rem;"></ion-icon>
                          Pending
                        </span>
                      <?php elseif ($req['status'] === 'Approved'): ?>
                        <span class="status-badge badge-approved">
                          <ion-icon name="checkmark-circle-outline" style="margin-right: 0.3rem;"></ion-icon>
                          Approved
                        </span>
                      <?php else: ?>
                        <span class="status-badge badge-rejected">
                          <ion-icon name="close-circle-outline" style="margin-right: 0.3rem;"></ion-icon>
                          <?= htmlspecialchars($req['status']) ?>
                        </span>
                      <?php endif; ?>
                    </td>
                    <td style="font-size: 0.9rem; color: #6c757d;">
                      <?= htmlspecialchars(date("M d, Y H:i", strtotime($req['created_at']))) ?>
                    </td>
                    <td>
                      <?php if ($req['status'] === 'Pending'): ?>
                        <div style="display: flex; gap: 0.5rem;">
                          <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewModal<?= $req['id'] ?>">
                            <ion-icon name="eye-outline"></ion-icon> View
                          </button>
                          <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#reviewModal<?= $req['id'] ?>">
                            <ion-icon name="create-outline"></ion-icon> Review
                          </button>
                        </div>
                      <?php else: ?>
                        <span style="color: #6c757d; font-size: 0.9rem;">No action</span>
                      <?php endif; ?>
                    </td>
                  </tr>

                  <!-- View Modal -->
                  <div class="modal fade" id="viewModal<?= $req['id'] ?>" tabindex="-1" aria-labelledby="viewLabel<?= $req['id'] ?>" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                      <div class="modal-content">
                        <div class="modal-header">
                          <h5 class="modal-title" id="viewLabel<?= $req['id'] ?>">
                            <ion-icon name="document-outline"></ion-icon> Request Details
                          </h5>
                          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                            <div>
                              <div style="color: #6c757d; font-size: 0.9rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.3rem;">Employee</div>
                              <div style="font-weight: 700; color: #22223b; font-size: 1rem;">
                                <?= htmlspecialchars($req['fullname']) ?>
                              </div>
                              <div style="color: #6c757d; font-size: 0.85rem; margin-top: 0.3rem;">
                                <?= htmlspecialchars(ucfirst($req['role'])) ?>
                              </div>
                            </div>

                            <div>
                              <div style="color: #6c757d; font-size: 0.9rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.3rem;">Current Shift</div>
                              <div style="font-weight: 700; color: #22223b; font-size: 1rem;">
                                <?= htmlspecialchars($req['shift_date']) ?>
                              </div>
                              <div style="color: #6c757d; font-size: 0.85rem; margin-top: 0.3rem;">
                                <?= htmlspecialchars($req['shift_type']) ?>
                              </div>
                            </div>
                          </div>

                          <hr style="margin: 1.5rem 0; border-color: #e0e7ff;">

                          <div class="mb-3">
                            <div style="color: #6c757d; font-size: 0.9rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.5rem;">Requested Swap Date</div>
                            <div style="background: #f9f9fc; border-left: 4px solid #667eea; padding: 1rem; border-radius: 8px; font-weight: 600;">
                              <?= htmlspecialchars($req['swap_with_date'] ?? 'Not specified') ?>
                            </div>
                          </div>

                          <div class="mb-3">
                            <div style="color: #6c757d; font-size: 0.9rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.5rem;">Reason</div>
                            <div style="background: #fafbfc; border: 1px solid #e0e7ff; padding: 1rem; border-radius: 8px; color: #22223b; line-height: 1.6;">
                              <?= nl2br(htmlspecialchars($req['reason'] ?? 'No reason provided')) ?>
                            </div>
                          </div>

                          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div>
                              <div style="color: #6c757d; font-size: 0.9rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.3rem;">Status</div>
                              <div style="font-weight: 700; color: #22223b;">
                                <?= htmlspecialchars($req['status']) ?>
                              </div>
                            </div>

                            <div>
                              <div style="color: #6c757d; font-size: 0.9rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.3rem;">Submitted</div>
                              <div style="font-weight: 700; color: #22223b;">
                                <?= htmlspecialchars(date("M d, Y H:i", strtotime($req['created_at']))) ?>
                              </div>
                            </div>
                          </div>
                        </div>
                        <div class="modal-footer" style="border-top: 1px solid #e0e7ff; padding: 1.5rem;">
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Review Modal -->
                  <div class="modal fade" id="reviewModal<?= $req['id'] ?>" tabindex="-1" aria-labelledby="reviewLabel<?= $req['id'] ?>" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                      <form class="modal-content" method="post">
                        <div class="modal-header">
                          <h5 class="modal-title" id="reviewLabel<?= $req['id'] ?>">
                            <ion-icon name="create-outline"></ion-icon> Review Shift Swap Request
                          </h5>
                          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                          <input type="hidden" name="update_swap_status" value="1">
                          <input type="hidden" name="request_id" value="<?= intval($req['id']) ?>">

                          <div style="background: #ede9fe; border-left: 4px solid #667eea; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                            <div style="font-weight: 700; color: #4311a5; margin-bottom: 0.5rem;">
                              <?= htmlspecialchars($req['fullname']) ?> — <?= htmlspecialchars($req['shift_date']) ?> (<?= htmlspecialchars($req['shift_type']) ?>)
                            </div>
                            <div style="color: #5a4a7d; font-size: 0.9rem;">
                              Requesting swap for: <?= htmlspecialchars($req['swap_with_date'] ?? 'Not specified') ?>
                            </div>
                          </div>

                          <div class="mb-3">
                            <label style="font-weight: 600; margin-bottom: 0.5rem; display: block;">Request Reason</label>
                            <div style="background: #fafbfc; border: 1px solid #e0e7ff; padding: 1rem; border-radius: 8px; color: #22223b; line-height: 1.6; max-height: 120px; overflow-y: auto;">
                              <?= nl2br(htmlspecialchars($req['reason'] ?? 'No reason provided')) ?>
                            </div>
                          </div>

                          <div class="mb-3">
                            <label for="review_note<?= $req['id'] ?>" style="font-weight: 600; margin-bottom: 0.5rem; display: block;">
                              Your Review Note (Optional)
                            </label>
                            <textarea class="form-control" id="review_note<?= $req['id'] ?>" name="review_note" rows="3" placeholder="Add any notes for your decision..."></textarea>
                            <small style="color: #6c757d; margin-top: 0.3rem; display: block;">This note will be saved to the request record.</small>
                          </div>

                          <div class="alert alert-warning">
                            <ion-icon name="alert-circle-outline" style="margin-right: 0.5rem;"></ion-icon>
                            <strong>Important:</strong> Approving will modify the schedule. The system will attempt to swap shifts or reassign based on availability.
                          </div>
                        </div>
                        <div class="modal-footer" style="border-top: 1px solid #e0e7ff; padding: 1.5rem; display: flex; gap: 0.75rem;">
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                          <button type="submit" name="new_status" value="Rejected" class="btn btn-danger">
                            <ion-icon name="close-circle-outline" style="margin-right: 0.3rem;"></ion-icon>Reject
                          </button>
                          <button type="submit" name="new_status" value="Approved" class="btn btn-success">
                            <ion-icon name="checkmark-circle-outline" style="margin-right: 0.3rem;"></ion-icon>Approve
                          </button>
                        </div>
                      </form>
                    </div>
                  </div>

                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>