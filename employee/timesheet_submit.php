<?php
session_start();
require_once("../includes/db.php");
require_once("../includes/attendance_functions.php");


$fullname = $_SESSION['fullname'] ?? 'Employee';
$role = $_SESSION['role'] ?? 'employee';


// Payroll/cutoff settings
define('PAYDAY_DAY', 15);

// Only show periods/cutoffs with attendance logs
function getPeriodsWithAttendance($employeeId, $paydayDay = 15) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT date FROM attendance WHERE employee_id = ? ORDER BY date ASC");
    $stmt->execute([$employeeId]);
    $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $periods = [];
    foreach ($dates as $date) {
        $year = date('Y', strtotime($date));
        $month = date('m', strtotime($date));
        $day = date('d', strtotime($date));
        // 1st cutoff
        if ($day <= $paydayDay) {
            $from = "$year-$month-01";
            $to = "$year-$month-" . str_pad($paydayDay, 2, '0', STR_PAD_LEFT);
            $label = date('F Y', strtotime($from)) . " (1st Cutoff)";
        } else {
            $from = "$year-$month-" . str_pad(($paydayDay+1), 2, '0', STR_PAD_LEFT);
            $to = date('Y-m-t', strtotime($date));
            $label = date('F Y', strtotime($from)) . " (2nd Cutoff)";
        }
        $key = $from . '|' . $to;
        if (!isset($periods[$key])) {
            $periods[$key] = [
                'label' => $label,
                'from' => $from,
                'to' => $to
            ];
        }
    }
    // return as indexed array
    return array_values($periods);
}

// Fetch only periods with employee's attendance


// Decide selected period from GET or default to most recent
if (isset($_GET['from']) && isset($_GET['to'])) {
    $selected_period = ['from' => $_GET['from'], 'to' => $_GET['to']];
} elseif (!empty($periods)) {
    // Default: last (most recent) period with logs
    $last = end($periods);
    $selected_period = ['from' => $last['from'], 'to' => $last['to']];
} else {
    $selected_period = ['from' => '', 'to' => ''];
}

$from = $selected_period['from'];
$to = $selected_period['to'];
$logs = ($from && $to) ? viewAttendanceLogsCutoff($employeeId, $from, $to) : [];

// Check if already submitted for this period
if ($from && $to) {
    $stmt = $pdo->prepare("SELECT id, status, submitted_at, notes FROM timesheets WHERE employee_id = ? AND period_from = ? AND period_to = ?");
    $stmt->execute([$employeeId, $from, $to]);
    $timesheet = $stmt->fetch(PDO::FETCH_ASSOC);
    $already_submitted = $timesheet ? true : false;
    $status = $timesheet['status'] ?? null;
    $submitted_at = $timesheet['submitted_at'] ?? null;
    $notes = $timesheet['notes'] ?? null;
} else {
    $already_submitted = false;
    $status = null;
    $submitted_at = null;
    $notes = null;
}

// Handle submission
$submitted_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_timesheet'])) {
    $sel_from = $_POST['sel_from'] ?? $from;
    $sel_to = $_POST['sel_to'] ?? $to;
    $notes = $_POST['timesheet_notes'] ?? '';
    // Check if already submitted
    $stmt = $pdo->prepare("SELECT id FROM timesheets WHERE employee_id = ? AND period_from = ? AND period_to = ?");
    $stmt->execute([$employeeId, $sel_from, $sel_to]);
    $ts_check = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ts_check) {
        // Insert timesheet
        $stmt = $pdo->prepare("INSERT INTO timesheets (employee_id, period_from, period_to, status, submitted_at, notes) VALUES (?, ?, ?, 'pending', NOW(), ?)");
        $stmt->execute([$employeeId, $sel_from, $sel_to, $notes]);
        $_SESSION['last_timesheet_submit'] = date('Y-m-d H:i:s');
        $submitted_message = "Attendance logs from $sel_from to $sel_to have been submitted for HR review!";
        header("Location: timesheet_submit.php?from=$sel_from&to=$sel_to&success=1");
        exit;
    } else {
        $submitted_message = "You have already submitted a timesheet for this period.";
    }
}

// Attendance summary for selected period
$present = $absent = $late = $onleave = 0;
foreach ($logs as $log) {
    if ($log['status'] == 'Present') $present++;
    elseif ($log['status'] == 'Absent') $absent++;
    elseif ($log['status'] == 'Late') $late++;
    elseif ($log['status'] == 'On Leave') $onleave++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Submit Timesheet - ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        body { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; background: #fafbfc; color: #22223b; font-size: 16px; }
        .sidebar { background: #181818ff; color: #fff; min-height: 100vh; border: none; width: 220px; position: fixed; left: 0; top: 0; z-index: 1040; transition: left 0.3s; overflow-y: auto; padding: 1rem 0.3rem 1rem 0.3rem; scrollbar-width: none; height: 100vh; -ms-overflow-style: none; }
        .sidebar::-webkit-scrollbar { display: none; width: 0px; background: transparent; }
        .sidebar a, .sidebar button { color: #bfc7d1; background: none; border: none; font-size: 0.95rem; padding: 0.45rem 0.7rem; border-radius: 8px; display: flex; align-items: center; gap: 0.7rem; margin-bottom: 0.1rem; transition: background 0.2s, color 0.2s; width: 100%; text-align: left; white-space: nowrap; }
        .sidebar a.active, .sidebar a:hover, .sidebar button.active, .sidebar button:hover { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: #fff; }
        .sidebar hr { border-top: 1px solid #232a43; margin: 0.7rem 0; }
        .sidebar .nav-link ion-icon { font-size: 1.2rem; margin-right: 0.3rem; }
        .main-content { margin-left: 220px; padding: 2rem 2rem 2rem 2rem; }
        .topbar { padding: 0.7rem 1.2rem 0.7rem 1.2rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-top: 0 !important; }
        .topbar .profile { display: flex; align-items: center; gap: 1.2rem; }
        .topbar .profile-img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; margin-right: 0.7rem; border: 2px solid #e0e7ff; }
        .topbar .profile-info { line-height: 1.1; }
        .topbar .profile-info strong { font-size: 1.08rem; font-weight: 600; color: #22223b; }
        .topbar .profile-info small { color: #6c757d; font-size: 0.93rem; }
        .dashboard-title { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; font-size: 1.7rem; font-weight: 700; margin-bottom: 1.2rem; color: #22223b; }
        .stat-box { border-radius: 12px; background: #fff; box-shadow: 0 2px 8px rgba(140, 140, 200, 0.07); margin-bottom: 1.3rem; padding: 1.2rem 1rem; display: flex; gap: 1.5rem; }
        .stat-item { flex: 1; text-align: center; }
        .stat-item .stat-label { font-size: 1em; color: #4311a5; font-weight: 500; }
        .stat-item .stat-value { font-size: 1.4em; font-weight: 700; color: #22223b; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-approved { background: #dbeafe; color: #2563eb; }
        .badge-rejected { background: #fee2e2; color: #b91c1c; }
        .modal-content { border-radius: 16px; box-shadow: 0 6px 32px rgba(140, 140, 200, 0.18); border: 1px solid #e0e7ff;}
        .modal-header { border-bottom: none; background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: #fff; border-top-left-radius: 16px; border-top-right-radius: 16px; padding-bottom: 0.7rem;}
        .modal-title { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; font-size: 1.19rem; font-weight: 700; letter-spacing: 0.03em;}
        .modal-body { background: #f6f8fc; padding-top: 1.2rem; padding-bottom: 1.2rem;}
        .modal-footer { border-top: none; background: #fff; border-bottom-left-radius: 16px; border-bottom-right-radius: 16px; padding-top: 0.7rem;}
        .table th, .table td { vertical-align: middle; }
        .table th { background: #f6f8fc; }
        @media (max-width: 1200px) { .main-content { padding: 1rem 0.3rem; margin-left: 180px;} .sidebar { width: 180px; } }
        @media (max-width: 900px) { .main-content { margin-left: 0; padding: 1rem 0.5rem;} .sidebar { left: -220px; width: 180px; } .sidebar.show { left: 0; } }
        @media (max-width: 700px) { .dashboard-title { font-size: 1.1rem; } .main-content { padding: 0.7rem 0.2rem;} .sidebar { width: 100vw; left: -100vw; } .sidebar.show { left: 0; } }
        @media (max-width: 500px) { .main-content { padding: 0.1rem 0.01rem;} .sidebar { width: 100vw; left: -100vw; } }
        @media (min-width: 1400px) { .sidebar { width: 260px; } .main-content { margin-left: 260px; padding: 2rem 2rem;} }
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
              <a class="nav-link" href="/employee/employee_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Time & Attendance</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="../employee/attendance.php"><ion-icon name="timer-outline"></ion-icon>Clock In / Out</a>
              <a class="nav-link" href="../employee/attendance_logs.php"><ion-icon name="list-outline"></ion-icon>My Attendance Logs</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Leave Management</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="../employee/leave_requests.php"><ion-icon name="calendar-outline"></ion-icon>Request Leave</a>
              <a class="nav-link" href="../employee/leave_balance.php"><ion-icon name="calendar-outline"></ion-icon>Leave Balance</a>
              <a class="nav-link" href="../employee/leave_history.php"><ion-icon name="calendar-outline"></ion-icon>Leave History</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Shift & Schedule</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="../employee/schedule.php"><ion-icon name="calendar-outline"></ion-icon>My Schedule</a>
              <a class="nav-link" href="../employee/shift_swap.php"><ion-icon name="swap-horizontal-outline"></ion-icon>Request Shift Swap</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Timesheet Management</h6>
            <nav class="nav flex-column">
              <a class="nav-link active" href="../employee/timesheet_submit.php"><ion-icon name="document-text-outline"></ion-icon>Submit Timesheet</a>
              <a class="nav-link" href="../employee/timesheets.php"><ion-icon name="document-text-outline"></ion-icon>My Timesheets</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Claims & Reimbursement</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="../employee/claim_file.php"><ion-icon name="create-outline"></ion-icon>File a Claim</a>
              <a class="nav-link" href="../employee/claims.php"><ion-icon name="cash-outline"></ion-icon>My Claims</a>
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
            <span class="dashboard-title">Submit Timesheet</span>
            <div class="profile">
                <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
                <div class="profile-info">
                    <strong><?= htmlspecialchars($fullname) ?></strong><br>
                    <small><?= htmlspecialchars(ucfirst($role)) ?></small>
                </div>
            </div>
        </div>
        <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
            <div class="alert alert-success">Timesheet has been submitted for this period!</div>
        <?php endif; ?>
        <?php if (!empty($submitted_message)): ?>
            <div class="alert alert-info"><?= htmlspecialchars($submitted_message) ?></div>
        <?php endif; ?>

        <div class="mb-4">
            <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#periodModal">
                <ion-icon name="calendar-outline"></ion-icon> Select Cutoff/Month
            </button>
            <?php if ($from && $to): ?>
            <h5 class="mb-3">
                Attendance Logs for 
                <span class="text-primary"><?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?></span>
            </h5>
            <div class="stat-box mb-2">
                <div class="stat-item">
                    <div class="stat-label">Present</div>
                    <div class="stat-value"><?= $present ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Absent</div>
                    <div class="stat-value"><?= $absent ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Late</div>
                    <div class="stat-value"><?= $late ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">On Leave</div>
                    <div class="stat-value"><?= $onleave ?></div>
                </div>
            </div>
            <table class="table table-bordered attendance-table bg-white">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Status</th>
                        <th>IP In</th>
                        <th>IP Out</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($logs) > 0): ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= htmlspecialchars($log['date']) ?></td>
                            <td><?= htmlspecialchars($log['time_in']) ?></td>
                            <td><?= htmlspecialchars($log['time_out']) ?></td>
                            <td><?= htmlspecialchars($log['status']) ?></td>
                            <td><?= htmlspecialchars($log['ip_in'] ?? '') ?></td>
                            <td><?= htmlspecialchars($log['ip_out'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6">No attendance records for this period.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="alert alert-warning">No attendance logs found for any period.</div>
            <?php endif; ?>
        </div>
        <div class="mb-4">
            <?php if ($from && $to): ?>
                <?php if ($already_submitted): ?>
                    <div class="alert alert-info">
                        <strong>You have already submitted your timesheet for this cutoff period.</strong><br>
                        <strong>Status:</strong>
                        <?php if ($status == 'pending'): ?>
                            <span class="badge badge-pending">Pending HR Review</span>
                        <?php elseif ($status == 'approved'): ?>
                            <span class="badge badge-approved">Approved</span>
                        <?php elseif ($status == 'rejected'): ?>
                            <span class="badge badge-rejected">Rejected</span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><?= htmlspecialchars(ucfirst($status)) ?></span>
                        <?php endif; ?>
                        <?php if ($submitted_at): ?>
                            <br><span class="text-muted" style="font-size:90%;">Submitted on: <?= htmlspecialchars($submitted_at) ?></span>
                        <?php endif; ?>
                        <?php if ($notes): ?>
                            <br><span class="text-muted" style="font-size:90%;">Notes: <?= htmlspecialchars($notes) ?></span>
                        <?php endif; ?>
                    </div>
                <?php elseif (count($logs) > 0): ?>
                    <form method="post">
                        <input type="hidden" name="sel_from" value="<?= htmlspecialchars($from) ?>">
                        <input type="hidden" name="sel_to" value="<?= htmlspecialchars($to) ?>">
                        <textarea name="timesheet_notes" class="form-control mb-3" placeholder="Add notes or remarks for this timesheet (optional)"></textarea>
                        <button type="submit" name="submit_timesheet" class="btn btn-success">
                            <ion-icon name="cloud-upload-outline"></ion-icon> Submit Timesheet for HR Review
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if (!empty($_SESSION['last_timesheet_submit'])): ?>
            <div class="text-muted mt-3" style="font-size:90%;">
                Last submitted: <?= htmlspecialchars($_SESSION['last_timesheet_submit']) ?>
            </div>
        <?php endif; ?>
        <div class="mt-4">
            <a href="/employee/timesheets.php" class="btn btn-outline-primary">&larr; View My Timesheets</a>
        </div>
    </div>
  </div>
</div>

<!-- Modal for period selection -->
<div class="modal fade" id="periodModal" tabindex="-1" aria-labelledby="periodModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="get" action="timesheet_submit.php">
        <div class="modal-header">
          <h5 class="modal-title" id="periodModalLabel">Select Cutoff/Month</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if (empty($periods)): ?>
            <div class="alert alert-warning mb-0">No attendance periods available.</div>
          <?php else: ?>
          <select class="form-select" name="period" id="period-select" onchange="updatePeriodFields(this)">
            <?php foreach ($periods as $p): ?>
                <option value="<?= htmlspecialchars($p['from']) ?>|<?= htmlspecialchars($p['to']) ?>"
                <?= $p['from'] == $from && $p['to'] == $to ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['label']) ?> (<?= htmlspecialchars($p['from']) ?> to <?= htmlspecialchars($p['to']) ?>)
                </option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="from" id="modal-from" value="<?= htmlspecialchars($from) ?>">
          <input type="hidden" name="to" id="modal-to" value="<?= htmlspecialchars($to) ?>">
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary" <?= empty($periods) ? 'disabled' : '' ?>>View Period</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updatePeriodFields(sel) {
    let val = sel.value.split('|');
    document.getElementById('modal-from').value = val[0];
    document.getElementById('modal-to').value = val[1];
}
</script>
</body>
</html>