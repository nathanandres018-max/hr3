<?php
session_start();
require_once("../includes/db.php");

$fullname = $_SESSION['fullname'] ?? 'Schedule Officer';
$role = $_SESSION['role'] ?? 'schedule_officer';
$userid = $_SESSION['employee_id'] ?? 0;

// Handle new or update availability
$msg = "";
$msg_type = "info";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['employee_id'], $_POST['avail_dates'])) {
    $employee_id = intval($_POST['employee_id']);
    $dates = $_POST['avail_dates'];
    $notes = $_POST['avail_notes'] ?? [];

    // Validate employee exists
    $stmt = $pdo->prepare("SELECT id, fullname FROM employees WHERE id = ?");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        $msg = "Invalid employee selected.";
        $msg_type = "danger";
    } else if (empty($dates)) {
        $msg = "Please select at least one available date.";
        $msg_type = "warning";
    } else {
        try {
            // Validate all dates are in the future (at least today)
            $today = date('Y-m-d');
            $invalid_dates = [];
            foreach ($dates as $date) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $invalid_dates[] = $date;
                } else if (strtotime($date) < strtotime($today)) {
                    $invalid_dates[] = $date . " (past date)";
                }
            }

            if (!empty($invalid_dates)) {
                $msg = "Invalid dates detected: " . implode(", ", $invalid_dates);
                $msg_type = "danger";
            } else {
                // Remove existing availabilities for this employee for the selected dates
                $placeholders = implode(',', array_fill(0, count($dates), '?'));
                $delParams = array_merge([$employee_id], $dates);
                $pdo->prepare("DELETE FROM employee_availability WHERE employee_id = ? AND available_date IN ($placeholders)")->execute($delParams);

                // Insert new ones
                $inserted_count = 0;
                foreach ($dates as $date) {
                    $note = trim($notes[$date] ?? '');
                    $stmt = $pdo->prepare("INSERT INTO employee_availability (employee_id, available_date, note, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW())");
                    
                    if ($stmt->execute([$employee_id, $date, $note, $userid])) {
                        $inserted_count++;

                        // Send notification to the employee
                        try {
                            $message = "Your availability for " . date('M d, Y (l)', strtotime($date)) . " has been updated.";
                            if (!empty($note)) {
                                $message .= " Note: " . substr($note, 0, 50) . (strlen($note) > 50 ? "..." : "");
                            }
                            $notif_stmt = $pdo->prepare("INSERT INTO notifications (employee_id, title, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
                            $notif_stmt->execute([
                                $employee_id,
                                "Availability Update",
                                $message
                            ]);
                        } catch (Exception $e) {
                            error_log("Notification error: " . $e->getMessage());
                        }
                    }
                }

                $msg = "✅ Availability updated successfully! {$inserted_count} date(s) saved for " . htmlspecialchars($employee['fullname']) . ".";
                $msg_type = "success";
            }
        } catch (Exception $e) {
            $msg = "Error updating availability: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Handle swap request creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_swap') {
    $requester_id = intval($_POST['requester_id']);
    $requester_shift_id = intval($_POST['requester_shift_id']);
    $target_employee_id = intval($_POST['target_employee_id']);
    $target_shift_id = intval($_POST['target_shift_id']);
    $swap_reason = trim($_POST['swap_reason'] ?? '');

    if (!$requester_id || !$requester_shift_id || !$target_employee_id || !$target_shift_id) {
        $msg = "All swap fields are required.";
        $msg_type = "danger";
    } else if (strlen($swap_reason) < 10) {
        $msg = "Please provide a detailed reason (at least 10 characters).";
        $msg_type = "warning";
    } else {
        try {
            // Verify requester shift exists
            $stmt = $pdo->prepare("SELECT id FROM shifts WHERE id = ? AND employee_id = ?");
            $stmt->execute([$requester_shift_id, $requester_id]);
            if (!$stmt->fetch()) {
                $msg = "Invalid requester shift.";
                $msg_type = "danger";
            } else {
                // Verify target shift exists
                $stmt = $pdo->prepare("SELECT id FROM shifts WHERE id = ? AND employee_id = ?");
                $stmt->execute([$target_shift_id, $target_employee_id]);
                if (!$stmt->fetch()) {
                    $msg = "Invalid target shift.";
                    $msg_type = "danger";
                } else {
                    // Check for duplicate pending requests
                    $stmt = $pdo->prepare("SELECT id FROM swap_requests WHERE requester_id = ? AND requester_shift_id = ? AND status = 'pending'");
                    $stmt->execute([$requester_id, $requester_shift_id]);
                    if ($stmt->fetch()) {
                        $msg = "A pending swap request already exists for this shift.";
                        $msg_type = "warning";
                    } else {
                        // Insert swap request
                        $stmt = $pdo->prepare("INSERT INTO swap_requests (requester_id, requester_shift_id, target_employee_id, target_shift_id, reason, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
                        
                        if ($stmt->execute([$requester_id, $requester_shift_id, $target_employee_id, $target_shift_id, $swap_reason])) {
                            $msg = "✅ Shift swap request created successfully! The target employee and scheduler have been notified.";
                            $msg_type = "success";

                            // Send notifications
                            try {
                                $stmt = $pdo->prepare("SELECT fullname FROM employees WHERE id = ?");
                                $stmt->execute([$requester_id]);
                                $requester = $stmt->fetch(PDO::FETCH_ASSOC);
                                $stmt->execute([$target_employee_id]);
                                $target_emp = $stmt->fetch(PDO::FETCH_ASSOC);

                                // Notify target employee
                                $notif_stmt = $pdo->prepare("INSERT INTO notifications (employee_id, title, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
                                $notif_stmt->execute([
                                    $target_employee_id,
                                    "Shift Swap Request",
                                    htmlspecialchars($requester['fullname']) . " has requested to swap shifts with you."
                                ]);
                            } catch (Exception $e) {
                                error_log("Notification error: " . $e->getMessage());
                            }
                        } else {
                            $msg = "Failed to create swap request.";
                            $msg_type = "danger";
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $msg = "Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Fetch employees (active only)
$stmt = $pdo->prepare("SELECT id, fullname, role, status, employee_id FROM employees WHERE status='Active' ORDER BY fullname ASC");
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all availability (next 2 weeks only)
$todayObj = new DateTime();
$start = $todayObj->format('Y-m-d');
$endObj = clone $todayObj;
$endObj->modify('+13 days');
$end = $endObj->format('Y-m-d');

$stmt = $pdo->prepare("SELECT * FROM employee_availability WHERE available_date BETWEEN ? AND ? ORDER BY available_date ASC, employee_id ASC");
$stmt->execute([$start, $end]);
$all_availability = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build employee -> available_dates map and notes
$emp_avail = [];
$emp_notes = [];
$emp_lastupd = [];
foreach ($all_availability as $row) {
    $emp_avail[$row['employee_id']][] = $row['available_date'];
    $emp_notes[$row['employee_id']][$row['available_date']] = $row['note'];
    $emp_lastupd[$row['employee_id']][$row['available_date']] = [
        'updated_by' => $row['updated_by'],
        'updated_at' => $row['updated_at']
    ];
}

// For calendar grid (next 2 weeks)
$today = new DateTime();
$dates = [];
for ($i = 0; $i < 14; $i++) {
    $dates[] = $today->format('Y-m-d');
    $today->modify('+1 day');
}

// Fetch user list for updated_by display
$usernames = [];
$stmt = $pdo->query("SELECT id, fullname FROM employees");
foreach ($stmt as $row) {
    $usernames[$row['id']] = $row['fullname'];
}

// Function to check if employee is available on a specific date
function isEmployeeAvailable($employee_id, $date, $emp_avail) {
    return isset($emp_avail[$employee_id]) && in_array($date, $emp_avail[$employee_id]);
}

// Function to get available employees for a specific date
function getAvailableEmployees($date, $emp_avail, $employees) {
    $available = [];
    foreach ($employees as $emp) {
        if (isEmployeeAvailable($emp['id'], $date, $emp_avail)) {
            $available[] = $emp;
        }
    }
    return $available;
}

// Fetch availability summary stats
$total_records = count($all_availability);
$unique_employees = count($emp_avail);
$available_slots = [];
foreach ($dates as $date) {
    $available_slots[$date] = count(getAvailableEmployees($date, $emp_avail, $employees));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee Availability & Swap - ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        body { font-family: 'QuickSand','Poppins',Arial,sans-serif; background: #fafbfc; color: #22223b; font-size: 16px; }
        .sidebar { background: #181818ff; color: #fff; min-height: 100vh; width: 220px; position: fixed; left: 0; top: 0; z-index: 1040; overflow-y: auto; padding: 1rem 0.3rem; border-right: 1px solid #232a43; }
        .sidebar::-webkit-scrollbar { display: none; }
        .sidebar a, .sidebar button { color: #bfc7d1; background: none; border: none; font-size: 0.95rem; padding: 0.45rem 0.7rem; border-radius: 8px; display: flex; align-items: center; gap: 0.7rem; margin-bottom: 0.1rem; transition: background 0.2s, color 0.2s; width: 100%; text-align: left; white-space: nowrap; }
        .sidebar a.active, .sidebar a:hover, .sidebar button.active, .sidebar button:hover { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: #fff; }
        .sidebar hr { border-top: 1px solid #232a43; margin: 0.7rem 0; }
        .sidebar .nav-link ion-icon { font-size: 1.2rem; margin-right: 0.3rem; }
        .topbar { padding: 0.7rem 1.2rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; background: #fff; border-bottom: 1px solid #e0e7ff; }
        .topbar .profile { display: flex; align-items: center; gap: 1.2rem; }
        .topbar .profile-img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; margin-right: 0.7rem; border: 2px solid #e0e7ff; }
        .topbar .profile-info strong { font-size: 1.08rem; font-weight: 600; color: #22223b; display: block; }
        .topbar .profile-info small { color: #6c757d; font-size: 0.93rem; display: block; }
        .dashboard-title { font-family: 'QuickSand','Poppins',Arial,sans-serif; font-size: 1.7rem; font-weight: 700; margin: 0; color: #22223b; }
        .main-content { margin-left: 220px; padding: 2rem; }
        .breadcrumbs { color: #9A66ff; font-size: 0.98rem; text-align: right; margin-bottom: 1rem; }
        .card { border-radius: 12px; border: 1px solid #e0e7ff; box-shadow: 0 2px 8px rgba(140,140,200,0.07); margin-bottom: 2rem; }
        .card-header { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: #fff; border-radius: 12px 12px 0 0; padding: 1.5rem; border: none; font-weight: 700; display: flex; align-items: center; gap: 0.7rem; }
        .card-body { padding: 1.5rem; }
        .alert { border-radius: 10px; border-left: 4px solid; padding: 1.2rem; display: flex; gap: 0.8rem; align-items: flex-start; margin-bottom: 1.5rem; }
        .alert ion-icon { font-size: 1.3rem; flex-shrink: 0; margin-top: 0.2rem; }
        .alert-success { background: #d1fae5; border-left-color: #10b981; color: #065f46; }
        .alert-danger { background: #fee2e2; border-left-color: #ef4444; color: #991b1b; }
        .alert-warning { background: #fef3c7; border-left-color: #f59e0b; color: #92400e; }
        .alert-info { background: #dbeafe; border-left-color: #3b82f6; color: #1e40af; }
        .calendar-table th, .calendar-table td { text-align: center; vertical-align: middle; padding: 0.5rem; font-size: 14px; }
        .calendar-table th { background: #f3f4f6; font-weight: 600; color: #4b5563; border: none; }
        .calendar-table td.available { background: #d1fae5; color: #065f46; font-weight: 600; }
        .calendar-table td.unavailable { background: #fde2e2; color: #b91c1c; }
        .calendar-table td input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .calendar-table .emp-label { min-width: 160px; text-align: left; font-weight: 600; color: #22223b; }
        .calendar-table td.today { border: 2px solid #9A66ff !important; background: #f3f0ff; }
        .calendar-table td .note-icon { font-size: 1.2em; color: #9A66ff; cursor: pointer; margin-left: 4px; vertical-align: middle; }
        .calendar-table td .note-pop { display: none; position: absolute; background: #fff; border: 1px solid #e0e7ff; box-shadow: 0 4px 12px rgba(100,100,180,0.15); border-radius: 8px; z-index: 99; padding: 10px; font-size: 0.9em; min-width: 220px; max-width: 280px; word-wrap: break-word; }
        .calendar-table td:hover .note-pop { display: block; }
        .form-label { font-weight: 600; color: #4311a5; margin-bottom: 0.5rem; }
        .form-select, .form-control { border-radius: 8px; border: 1px solid #d0d7e2; padding: 0.7rem 1rem; font-size: 0.95rem; }
        .form-select:focus, .form-control:focus { border-color: #9A66ff; box-shadow: 0 0 0 0.2rem rgba(154, 102, 255, 0.15); }
        .btn-success { background: linear-gradient(90deg, #10b981 0%, #059669 100%); border: none; border-radius: 8px; padding: 0.7rem 1.5rem; font-weight: 600; }
        .btn-success:hover { background: linear-gradient(90deg, #059669 0%, #047857 100%); }
        .btn-primary { background: linear-gradient(90deg, #667eea 0%, #764ba2 100%); border: none; border-radius: 8px; padding: 0.7rem 1.5rem; font-weight: 600; }
        .btn-primary:hover { background: linear-gradient(90deg, #5568d3 0%, #6a3a8f 100%); }
        .badge-legend { background: #e5e7eb; color: #222; font-size: 13px; border-radius: 6px; margin-right: 8px; padding: 0.4rem 0.8rem; display: inline-block; }
        .badge-legend.available { background: #d1fae5; color: #065f46; }
        .badge-legend.unavailable { background: #fde2e2; color: #b91c1c; }
        .note-field { font-size: 13px; width: 100px; border-radius: 6px; border: 1px solid #d0d7e2; padding: 0.4rem; }
        .stats-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 12px; padding: 1.5rem; margin-bottom: 1rem; }
        .stats-card h6 { font-size: 0.9rem; opacity: 0.9; margin-bottom: 0.5rem; }
        .stats-card .stat-value { font-size: 2rem; font-weight: 700; }
        .no-availability { background: #f9fafb; border: 2px dashed #d1d5db; border-radius: 8px; padding: 2rem; text-align: center; color: #6c757d; }
        .available-employee-card { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 1rem; margin-bottom: 0.8rem; cursor: pointer; transition: all 0.3s; }
        .available-employee-card:hover { background: #dcfce7; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2); }
        .available-employee-card.selected { background: #86efac; border-color: #10b981; }
        .available-employee-card h6 { margin: 0; color: #065f46; font-weight: 600; }
        .available-employee-card small { color: #4b7c59; }
        .modal-content { border-radius: 12px; border: none; }
        .modal-header { background: linear-gradient(90deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 12px 12px 0 0; border: none; }
        .modal-header .btn-close { filter: brightness(0) invert(1); }
        .swap-modal-body { max-height: 70vh; overflow-y: auto; }
        .shift-details { background: #f3f0ff; border-left: 4px solid #9A66ff; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        @media (max-width: 1200px) { .main-content { padding: 1rem; margin-left: 180px; } .sidebar { width: 180px; } }
        @media (max-width: 900px) { .sidebar { left: -220px; } .sidebar.show { left: 0; } .main-content { margin-left: 0; padding: 1rem 0.5rem; } }
        @media (max-width: 600px) { .dashboard-title { font-size: 1.3rem; } .calendar-table th, .calendar-table td { font-size: 12px; padding: 0.3rem; } .note-field { width: 70px; } }
    </style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">
    <!-- Sidebar -->
    <div class="sidenav col-auto p-0">
      <div class="sidebar d-flex flex-column justify-content-between shadow-sm">
        <div>
          <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
            <img src="../assets/images/image.png" class="img-fluid me-2" style="height: 55px;" alt="Logo">
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Dashboard</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="../scheduler/schedule_officer_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Employee Management</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="../scheduler/employee_management.php"><ion-icon name="people-outline"></ion-icon>Employee List</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Shift & Schedule</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="../scheduler/shift_scheduling.php"><ion-icon name="calendar-outline"></ion-icon>Shift Scheduling</a>
              <a class="nav-link" href="../scheduler/shift_swap_requests.php"><ion-icon name="swap-horizontal-outline"></ion-icon>Swap Requests</a>
              <a class="nav-link active" href="../scheduler/employee_availability.php"><ion-icon name="people-outline"></ion-icon>Availability</a>
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
      <!-- Topbar -->
      <div class="topbar">
        <h1 class="dashboard-title"><ion-icon name="people-outline"></ion-icon> Employee Availability & Shift Swap</h1>
        <div class="profile">
          <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
          <div class="profile-info">
            <strong><?= htmlspecialchars($fullname) ?></strong>
            <small><?= htmlspecialchars(ucfirst($role)) ?></small>
          </div>
        </div>
      </div>

      <div class="breadcrumbs">
        Dashboard &gt; Shift & Schedule Management &gt; <strong>Employee Availability</strong>
      </div>

      <!-- Messages -->
      <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?>">
          <ion-icon name="<?= $msg_type === 'success' ? 'checkmark-circle-outline' : ($msg_type === 'danger' ? 'alert-circle-outline' : 'information-circle-outline') ?>"></ion-icon>
          <div><?= htmlspecialchars($msg) ?></div>
        </div>
      <?php endif; ?>

      <!-- Tab Navigation -->
      <ul class="nav nav-tabs mb-4" id="availabilityTabs" role="tablist" style="border-bottom: 2px solid #e0e7ff;">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="manage-tab" data-bs-toggle="tab" data-bs-target="#manage-content" type="button" role="tab" aria-controls="manage-content" aria-selected="true">
            <ion-icon name="pencil-outline"></ion-icon> Manage Availability
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="view-tab" data-bs-toggle="tab" data-bs-target="#view-content" type="button" role="tab" aria-controls="view-content" aria-selected="false">
            <ion-icon name="eye-outline"></ion-icon> View & Request Swaps
          </button>
        </li>
      </ul>

      <!-- Tab Content -->
      <div class="tab-content" id="availabilityTabContent">

        <!-- Tab 1: Manage Availability -->
        <div class="tab-pane fade show active" id="manage-content" role="tabpanel" aria-labelledby="manage-tab">
          <!-- Update Availability Form -->
          <div class="card">
            <div class="card-header">
              <ion-icon name="pencil-outline"></ion-icon> Update Employee Availability
            </div>
            <div class="card-body">
              <form method="post">
                <div class="row align-items-end g-3 mb-4">
                  <div class="col-md-4">
                    <label class="form-label">Select Employee <span style="color: #dc3545;">*</span></label>
                    <select class="form-select" name="employee_id" id="employeeSelect" required onchange="showAvailabilityGrid()">
                      <option value="">-- Choose Employee --</option>
                      <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['fullname']) ?> (<?= htmlspecialchars($emp['role']) ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-8">
                    <p class="text-muted mb-0"><ion-icon name="information-circle-outline"></ion-icon> Mark available days and add optional notes for the next 2 weeks</p>
                  </div>
                </div>

                <div id="availabilityGrid" style="margin-bottom: 1.5rem;">
                  <div class="no-availability">
                    <ion-icon name="person-outline" style="font-size: 2rem; opacity: 0.5;"></ion-icon>
                    <p>Select an employee to edit their availability</p>
                  </div>
                </div>

                <div class="d-flex gap-2">
                  <button type="submit" class="btn btn-success" id="submitBtn" disabled>
                    <ion-icon name="save-outline"></ion-icon> Save Availability
                  </button>
                  <button type="button" class="btn btn-outline-secondary" id="clearBtn" onclick="clearSelection()" style="display: none;">
                    <ion-icon name="close-outline"></ion-icon> Clear
                  </button>
                </div>
              </form>

              <div class="mt-3">
                <strong>Legend:</strong>
                <span class="badge-legend available"><ion-icon name="checkmark-circle-outline"></ion-icon> Available</span>
                <span class="badge-legend unavailable"><ion-icon name="close-circle-outline"></ion-icon> Unavailable</span>
                <span class="badge-legend" style="border: 2px solid #9A66ff; background: #fff;"><ion-icon name="calendar-outline"></ion-icon> Today</span>
                <span class="badge-legend" style="background: #e0e7ff; color: #4311a5;"><ion-icon name="information-circle-outline"></ion-icon> With Notes</span>
              </div>
            </div>
          </div>

          <!-- Availability Overview Table -->
          <div class="card">
            <div class="card-header">
              <ion-icon name="grid-outline"></ion-icon> Availability Overview (Next 2 Weeks)
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table calendar-table table-bordered align-middle">
                  <thead>
                    <tr>
                      <th class="emp-label">Employee</th>
                      <?php foreach ($dates as $date): ?>
                        <th<?= $date == date('Y-m-d') ? ' class="today"' : '' ?>>
                          <?= date('M d', strtotime($date)) ?><br>
                          <span class="text-muted" style="font-size: 0.85rem;"><?= substr(date('l', strtotime($date)), 0, 3) ?></span>
                        </th>
                      <?php endforeach; ?>
                      <th style="min-width: 100px;">Available Days</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($employees as $emp): ?>
                      <tr>
                        <td class="emp-label">
                          <strong><?= htmlspecialchars($emp['fullname']) ?></strong><br>
                          <small style="color: #6c757d;"><?= htmlspecialchars($emp['role']) ?></small>
                        </td>
                        <?php 
                          $count = 0; 
                          foreach ($dates as $date): 
                            $avail = isEmployeeAvailable($emp['id'], $date, $emp_avail);
                            $note = $avail && isset($emp_notes[$emp['id']][$date]) ? $emp_notes[$emp['id']][$date] : '';
                            $upd = $avail && isset($emp_lastupd[$emp['id']][$date]) ? $emp_lastupd[$emp['id']][$date] : null;
                            if ($avail) $count++;
                        ?>
                          <td class="<?= $date == date('Y-m-d') ? 'today' : '' ?> <?= $avail ? 'available' : 'unavailable' ?>" style="position: relative;">
                            <?php if ($avail): ?>
                              <ion-icon name="checkmark-circle-outline"></ion-icon>
                              <?php if ($note): ?>
                                <span class="note-icon" title="View note" tabindex="0">
                                  <ion-icon name="information-circle-outline"></ion-icon>
                                  <span class="note-pop">
                                    <strong>Note:</strong><br>
                                    <?= htmlspecialchars($note) ?><br><br>
                                    <small><strong>Last Updated:</strong><br>
                                    By: <?= isset($upd['updated_by']) && isset($usernames[$upd['updated_by']]) ? htmlspecialchars($usernames[$upd['updated_by']]) : 'System' ?><br>
                                    <span class="text-muted"><?= htmlspecialchars($upd['updated_at'] ?? '') ?></span></small>
                                  </span>
                                </span>
                              <?php endif; ?>
                            <?php else: ?>
                              <ion-icon name="close-circle-outline"></ion-icon>
                            <?php endif; ?>
                          </td>
                        <?php endforeach; ?>
                        <td style="font-weight: 600; color: #10b981;">
                          <span class="badge bg-success"><?= $count ?>/14</span>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Tab 2: View & Request Swaps -->
        <div class="tab-pane fade" id="view-content" role="tabpanel" aria-labelledby="view-tab">
          <div class="card">
            <div class="card-header">
              <ion-icon name="swap-horizontal-outline"></ion-icon> Request Shift Swap with Available Employee
            </div>
            <div class="card-body">
              <form id="swapForm" method="post">
                <input type="hidden" name="action" value="create_swap">

                <div class="row g-3 mb-4">
                  <div class="col-md-6">
                    <label class="form-label">Requester (Employee wanting to swap) <span style="color: #dc3545;">*</span></label>
                    <select class="form-select" name="requester_id" id="requesterId" required onchange="loadRequesterShifts()">
                      <option value="">-- Select Employee --</option>
                      <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['fullname']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Their Shift to Swap <span style="color: #dc3545;">*</span></label>
                    <select class="form-select" name="requester_shift_id" id="requesterShiftId" required onchange="loadAvailableEmployees()">
                      <option value="">-- Select Shift --</option>
                    </select>
                  </div>
                </div>

                <div id="shiftDetails" class="shift-details" style="display: none;">
                  <strong>Shift Date:</strong> <span id="shiftDate"></span><br>
                  <strong>Shift Type:</strong> <span id="shiftType"></span><br>
                  <strong>Time:</strong> <span id="shiftTime"></span>
                </div>

                <div id="availableEmployeesContainer" style="display: none; margin-bottom: 2rem;">
                  <h6 class="mb-3">Select Target Employee (Available on this date)</h6>
                  <div id="availableEmployeesList"></div>
                </div>

                <div id="selectedEmployeeInfo" style="display: none; margin-bottom: 2rem;">
                  <div class="shift-details">
                    <strong>Target Employee:</strong> <span id="selectedEmpName"></span><br>
                    <strong>Available Shift:</strong> <span id="selectedShiftInfo"></span><br>
                    <input type="hidden" name="target_employee_id" id="targetEmployeeId">
                    <input type="hidden" name="target_shift_id" id="targetShiftId">
                  </div>
                </div>

                <div class="form-group mb-4">
                  <label class="form-label">Reason for Swap <span style="color: #dc3545;">*</span></label>
                  <textarea class="form-control" name="swap_reason" rows="4" placeholder="Provide detailed reason for the shift swap request..." required minlength="10" maxlength="500"></textarea>
                  <small class="text-muted">Minimum 10 characters required</small>
                </div>

                <div class="d-flex gap-2">
                  <button type="submit" class="btn btn-primary" id="submitSwapBtn" disabled>
                    <ion-icon name="send-outline"></ion-icon> Submit Swap Request
                  </button>
                  <button type="button" class="btn btn-outline-secondary" onclick="resetSwapForm()">
                    <ion-icon name="close-outline"></ion-icon> Reset
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

      </div>

    </div>
  </div>
</div>

<!-- Bootstrap Modal for Swap Details (if needed) -->
<div class="modal fade" id="swapDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirm Shift Swap</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body swap-modal-body">
        <p>Swap request details will appear here.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary">Confirm Swap</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const allDates = <?= json_encode($dates) ?>;
const employees = <?= json_encode($employees) ?>;
const empAvail = <?= json_encode($emp_avail) ?>;
const empNotes = <?= json_encode($emp_notes) ?>;
const today = "<?= date('Y-m-d') ?>";

// Fetch shifts for requester
async function loadRequesterShifts() {
    let requesterId = document.getElementById('requesterId').value;
    let shiftSelect = document.getElementById('requesterShiftId');
    
    if (!requesterId) {
        shiftSelect.innerHTML = '<option value="">-- Select Shift --</option>';
        document.getElementById('shiftDetails').style.display = 'none';
        return;
    }

    try {
        let response = await fetch(`../scheduler/get_employee_shifts.php?employee_id=${requesterId}`);
        let shifts = await response.json();
        
        shiftSelect.innerHTML = '<option value="">-- Select Shift --</option>';
        shifts.forEach(shift => {
            let option = document.createElement('option');
            option.value = shift.id;
            option.textContent = `${shift.shift_type} - ${shift.shift_date}`;
            option.setAttribute('data-date', shift.shift_date);
            option.setAttribute('data-type', shift.shift_type);
            option.setAttribute('data-start', shift.shift_start);
            option.setAttribute('data-end', shift.shift_end);
            shiftSelect.appendChild(option);
        });
    } catch (error) {
        console.error('Error loading shifts:', error);
        alert('Error loading shifts');
    }
}

// Load available employees for the shift date
function loadAvailableEmployees() {
    let shiftSelect = document.getElementById('requesterShiftId');
    let selectedOption = shiftSelect.options[shiftSelect.selectedIndex];
    
    if (!selectedOption.value) {
        document.getElementById('shiftDetails').style.display = 'none';
        document.getElementById('availableEmployeesContainer').style.display = 'none';
        return;
    }

    let shiftDate = selectedOption.getAttribute('data-date');
    let shiftType = selectedOption.getAttribute('data-type');
    let shiftStart = selectedOption.getAttribute('data-start');
    let shiftEnd = selectedOption.getAttribute('data-end');

    // Show shift details
    document.getElementById('shiftDate').textContent = shiftDate;
    document.getElementById('shiftType').textContent = shiftType;
    document.getElementById('shiftTime').textContent = shiftStart && shiftEnd ? `${shiftStart} - ${shiftEnd}` : 'Not set';
    document.getElementById('shiftDetails').style.display = 'block';

    // Find available employees for this date
    let availableEmps = [];
    employees.forEach(emp => {
        if (empAvail[emp.id] && empAvail[emp.id].includes(shiftDate)) {
            availableEmps.push(emp);
        }
    });

    let container = document.getElementById('availableEmployeesList');
    if (availableEmps.length === 0) {
        container.innerHTML = '<div class="alert alert-warning"><ion-icon name="warning-outline"></ion-icon> No employees available on ' + shiftDate + '</div>';
        document.getElementById('availableEmployeesContainer').style.display = 'block';
        document.getElementById('selectedEmployeeInfo').style.display = 'none';
    } else {
        let html = '';
        availableEmps.forEach(emp => {
            html += `
                <div class="available-employee-card" onclick="selectTargetEmployee(${emp.id}, '${emp.fullname}', '${shiftDate}')">
                    <h6>${emp.fullname}</h6>
                    <small>${emp.role}</small>
                </div>
            `;
        });
        container.innerHTML = html;
        document.getElementById('availableEmployeesContainer').style.display = 'block';
    }

    document.getElementById('selectedEmployeeInfo').style.display = 'none';
    document.getElementById('submitSwapBtn').disabled = true;
}

// Select target employee
async function selectTargetEmployee(empId, empName, shiftDate) {
    // Highlight selected card
    document.querySelectorAll('.available-employee-card').forEach(card => {
        card.classList.remove('selected');
    });
    event.target.closest('.available-employee-card').classList.add('selected');

    document.getElementById('selectedEmpName').textContent = empName;
    document.getElementById('targetEmployeeId').value = empId;

    // Get shifts for target employee on this date
    try {
        let response = await fetch(`../scheduler/get_employee_shifts_by_date.php?employee_id=${empId}&date=${shiftDate}`);
        let shifts = await response.json();
        
        if (shifts.length === 0) {
            document.getElementById('selectedShiftInfo').textContent = 'No shifts found for this date';
            document.getElementById('targetShiftId').value = '';
            document.getElementById('submitSwapBtn').disabled = true;
        } else {
            let shift = shifts[0];
            document.getElementById('selectedShiftInfo').textContent = `${shift.shift_type} (${shift.shift_start} - ${shift.shift_end})`;
            document.getElementById('targetShiftId').value = shift.id;
            document.getElementById('submitSwapBtn').disabled = false;
        }
    } catch (error) {
        console.error('Error loading target shifts:', error);
    }

    document.getElementById('selectedEmployeeInfo').style.display = 'block';
}

// Availability grid functions (from previous code)
function showAvailabilityGrid() {
    let empId = document.getElementById('employeeSelect').value;
    let grid = document.getElementById('availabilityGrid');
    let submitBtn = document.getElementById('submitBtn');
    let clearBtn = document.getElementById('clearBtn');

    if (!empId) {
        grid.innerHTML = '<div class="no-availability"><ion-icon name="person-outline" style="font-size: 2rem; opacity: 0.5;"></ion-icon><p>Select an employee to edit their availability</p></div>';
        submitBtn.disabled = true;
        clearBtn.style.display = 'none';
        return;
    }

    submitBtn.disabled = false;
    clearBtn.style.display = 'inline-block';

    let checkedDates = empAvail[empId] ?? [];
    let notes = empNotes[empId] ?? {};
    let html = '<div class="table-responsive"><table class="table table-bordered calendar-table"><thead><tr><th class="emp-label">Mark Availability</th>';
    
    allDates.forEach(date => {
        let cls = date === today ? 'today' : '';
        let dayName = new Date(date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short' });
        html += `<th class="${cls}">${date}<br><span class="text-muted">${dayName}</span></th>`;
    });
    html += '</tr></thead><tbody>';
    
    html += '<tr><td class="emp-label"><strong>Available?</strong></td>';
    allDates.forEach(date => {
        let checked = checkedDates.includes(date) ? 'checked' : '';
        let cls = date === today ? 'today' : '';
        html += `<td class="${cls}"><input type="checkbox" name="avail_dates[]" value="${date}" ${checked} onchange="validateSelection()"></td>`;
    });
    html += '</tr>';

    html += '<tr><td class="emp-label"><strong>Note</strong></td>';
    allDates.forEach(date => {
        let noteVal = notes[date] ?? '';
        let cls = date === today ? 'today' : '';
        html += `<td class="${cls}"><input class="note-field" type="text" name="avail_notes[${date}]" value="${noteVal}" placeholder="(optional)" maxlength="100" title="Add a note for this date"></td>`;
    });
    html += '</tr></tbody></table></div>';

    grid.innerHTML = html;
}

function validateSelection() {
    let checkboxes = document.querySelectorAll('input[name="avail_dates[]"]:checked');
    let submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = checkboxes.length === 0;
}

function clearSelection() {
    document.getElementById('employeeSelect').value = '';
    document.getElementById('availabilityGrid').innerHTML = '<div class="no-availability"><ion-icon name="person-outline" style="font-size: 2rem; opacity: 0.5;"></ion-icon><p>Select an employee to edit their availability</p></div>';
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('clearBtn').style.display = 'none';
}

function resetSwapForm() {
    document.getElementById('swapForm').reset();
    document.getElementById('shiftDetails').style.display = 'none';
    document.getElementById('availableEmployeesContainer').style.display = 'none';
    document.getElementById('selectedEmployeeInfo').style.display = 'none';
    document.getElementById('submitSwapBtn').disabled = true;
}

window.addEventListener('load', function() {
    if (document.getElementById('employeeSelect')?.value) {
        showAvailabilityGrid();
    }
});
</script>

</body>
</html>