<?php
session_start();
require_once("../includes/db.php");

$fullname = $_SESSION['fullname'] ?? 'Employee';
$role = $_SESSION['role'] ?? 'employee';


// Fetch assigned shifts for the logged-in employee
$stmt = $pdo->prepare("SELECT id, shift_date, shift_type FROM shifts WHERE employee_id = ? ORDER BY shift_date ASC");

$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle shift swap request submission (AJAX or direct POST)
$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_swap'])) {
    $shift_id = intval($_POST['shift_id']);
    $swap_with_date = $_POST['swap_with_date'];
    $reason = trim($_POST['reason']);
    if ($shift_id && $swap_with_date && $reason) {
        $stmt = $pdo->prepare("INSERT INTO shift_swap_requests (employee_id, shift_id, swap_with_date, reason, status) VALUES (?, ?, ?, ?, 'Pending')");
        $stmt->execute([$employee_id, $shift_id, $swap_with_date, $reason]);
        $msg = "Shift swap request submitted!";
        // If AJAX, respond with JSON
        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => true, 'msg' => $msg]);
            exit;
        }
    } else {
        $msg = "All fields are required for swap request.";
        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => false, 'msg' => $msg]);
            exit;
        }
    }
}

// Fetch previous swap requests
$stmt = $pdo->prepare("SELECT swap_with_date, reason, status, created_at FROM shift_swap_requests WHERE employee_id = ? ORDER BY created_at DESC");

$swap_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Schedule - ViaHale TNVS HR3</title>
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
        .dashboard-title { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; font-size: 1.7rem; font-weight: 700; margin-bottom: 1.2rem; color: #22223b; }
        .modal-lg { max-width: 500px; }
        .table th, .table td { vertical-align: middle; }
        .swap-btn { padding: 2px 14px; }
        .profile { display: flex; align-items: center; gap: 1.2rem; float: right; }
        .profile-img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; margin-right: 0.7rem; border: 2px solid #e0e7ff; }
        .profile-info { line-height: 1.1; }
        .profile-info strong { font-size: 1.08rem; font-weight: 600; color: #22223b; }
        .profile-info small { color: #6c757d; font-size: 0.93rem; }
        /* Enhanced Modal Styling */
        .modal-content {
            border-radius: 16px;
            box-shadow: 0 6px 32px rgba(140, 140, 200, 0.18);
            border: 1px solid #e0e7ff;
            background: #fff;
            padding-bottom: 0.5rem;
        }
        .modal-header {
            border-bottom: none;
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
            color: #fff;
            border-top-left-radius: 16px;
            border-top-right-radius: 16px;
            padding-bottom: 0.7rem;
        }
        .modal-title {
            font-family: 'QuickSand', 'Poppins', Arial, sans-serif;
            font-size: 1.19rem;
            font-weight: 700;
            letter-spacing: 0.03em;
        }
        .modal-body {
            background: #f6f8fc;
            padding-top: 1.2rem;
            padding-bottom: 1.2rem;
        }
        .modal-footer {
            border-top: none;
            background: #fff;
            border-bottom-left-radius: 16px;
            border-bottom-right-radius: 16px;
            padding-top: 0.7rem;
        }
        .modal-label {
            font-weight: 600;
            color: #4311a5;
        }
        .form-control, .form-select, textarea {
            border-radius: 8px;
            border: 1.5px solid #e0e7ff;
            font-size: 1.05rem;
            margin-bottom: 0.5rem;
        }
        .btn-success, .btn-warning, .btn-secondary {
            font-weight: 600;
            border-radius: 8px;
            letter-spacing: 0.01em;
        }
        .swap-icon {
            font-size: 2.3rem;
            color: #9A66ff;
            margin-bottom: 0.7rem;
        }
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
      <div class="sidebar d-flex flex-column justify-content-between shadow-sm border-end" id="sidebarMenu">
        <div>
          <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
            <img src="../assets/images/image.png" class="img-fluid me-2" style="height: 55px;" alt="Logo">
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase mb-2">Dashboard</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="../employee/employee_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
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
              <a class="nav-link active" href="../employee/schedule.php"><ion-icon name="calendar-outline"></ion-icon>My Schedule</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Timesheet Management</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="/employee/timesheet_submit.php"><ion-icon name="document-text-outline"></ion-icon>Submit Timesheet</a>
              <a class="nav-link" href="/employee/timesheets.php"><ion-icon name="document-text-outline"></ion-icon>My Timesheets</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Claims & Reimbursement</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="/employee/claim_file.php"><ion-icon name="create-outline"></ion-icon>File a Claim</a>
              <a class="nav-link" href="/employee/claims.php"><ion-icon name="cash-outline"></ion-icon>My Claims</a>
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
        <div class="dashboard-title">
            My Assigned Schedule
            <div class="profile">
                <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
                <div class="profile-info">
                    <strong><?= htmlspecialchars($fullname) ?></strong><br>
                    <small><?= htmlspecialchars(ucfirst($role)) ?></small>
                </div>
            </div>
        </div>
        <?php if ($msg): ?>
            <div class="alert alert-info" id="swapAlert"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>
        <div class="mb-4">
            <h5 class="mb-3">Upcoming Shifts</h5>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Day</th>
                        <th>Shift Type</th>
                        <th>Request Swap</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules as $sched): ?>
                    <tr>
                        <td><?= htmlspecialchars($sched['shift_date']) ?></td>
                        <td><?= ucfirst(strtolower(date('l', strtotime($sched['shift_date']))) ) ?></td>
                        <td><?= htmlspecialchars($sched['shift_type']) ?></td>
                        <td>
                            <button class="btn btn-warning swap-btn" data-bs-toggle="modal" data-bs-target="#swapModal<?= $sched['id'] ?>">
                                <ion-icon name="swap-horizontal-outline"></ion-icon> Request Swap
                            </button>
                        </td>
                    </tr>
                    <!-- Enhanced Shift Swap Modal -->
                    <div class="modal fade" id="swapModal<?= $sched['id'] ?>" tabindex="-1" aria-labelledby="swapLabel<?= $sched['id'] ?>" aria-hidden="true">
                      <div class="modal-dialog modal-lg">
                        <form class="modal-content swap-form" method="post" onsubmit="return submitSwapRequest(event, <?= $sched['id'] ?>)">
                          <div class="modal-header">
                            <span class="swap-icon"><ion-icon name="swap-horizontal-outline"></ion-icon></span>
                            <h5 class="modal-title" id="swapLabel<?= $sched['id'] ?>">Request Shift Swap</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                          </div>
                          <div class="modal-body">
                            <input type="hidden" name="request_swap" value="1">
                            <input type="hidden" name="shift_id" value="<?= $sched['id'] ?>">
                            <div class="mb-3">
                                <label class="modal-label">Your Current Shift</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($sched['shift_date']) ?> (<?= ucfirst(strtolower(date('l', strtotime($sched['shift_date']))) ) ?>), <?= htmlspecialchars($sched['shift_type']) ?>" readonly style="background:#ede9fe;">
                            </div>
                            <div class="mb-3">
                                <label class="modal-label">Swap With Date</label>
                                <input type="date" class="form-control" name="swap_with_date" required>
                            </div>
                            <div class="mb-3">
                                <label class="modal-label">Reason</label>
                                <textarea name="reason" class="form-control" rows="3" required placeholder="Explain why you need this swap..."></textarea>
                            </div>
                            <div class="mb-2">
                                <i class="text-muted" style="font-size:0.95em;">Note: Once submitted, your request will be reviewed by management.</i>
                            </div>
                          </div>
                          <div class="modal-footer">
                            <button type="submit" class="btn btn-success">Submit Request</button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                          </div>
                        </form>
                      </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if(empty($schedules)): ?>
                    <tr><td colspan="4">No assigned shifts yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="mb-4">
            <h5>My Shift Swap Requests</h5>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Swap With Date</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Requested On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($swap_requests as $req): ?>
                    <tr>
                        <td><?= htmlspecialchars($req['swap_with_date']) ?></td>
                        <td><?= htmlspecialchars($req['reason']) ?></td>
                        <td>
                            <?php if($req['status']=='Pending'): ?>
                                <span class="badge bg-warning text-dark">Pending</span>
                            <?php elseif($req['status']=='Approved'): ?>
                                <span class="badge bg-success">Approved</span>
                            <?php elseif($req['status']=='Rejected'): ?>
                                <span class="badge bg-danger">Rejected</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars(date("Y-m-d H:i", strtotime($req['created_at']))) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($swap_requests)): ?>
                    <tr><td colspan="4">No shift swap requests yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function submitSwapRequest(e, shiftId) {
    e.preventDefault();
    var modal = document.getElementById('swapModal'+shiftId);
    var form = modal.querySelector('form');
    var formData = new FormData(form);
    formData.append('ajax', '1');
    fetch('schedule.php', {
        method: 'POST',
        body: formData
    }).then(res => res.json())
    .then(data => {
        if (data.success) {
            form.reset();
            var modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) modalInstance.hide();
            document.getElementById('swapAlert').innerHTML = data.msg;
            document.getElementById('swapAlert').style.display = 'block';
            setTimeout(function(){ location.reload(); }, 1200);
        } else {
            alert(data.msg);
        }
    });
    return false;
}
</script>
</body>
</html>