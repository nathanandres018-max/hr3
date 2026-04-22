<?php
session_start();
require_once("../includes/db.php");


$fullname = $_SESSION['fullname'] ?? 'Employee';
$employee_id = $_SESSION['id'] ?? null;

// Ensure swap_requests table exists
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

$msg = "";
$msg_type = "";

// Handle swap request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_swap_request'])) {
    $requester_shift_id = intval($_POST['requester_shift_id'] ?? 0);
    $swap_with_date = trim($_POST['swap_with_date'] ?? '');
    $target_employee_id = intval($_POST['target_employee_id'] ?? 0);
    $target_shift_id = intval($_POST['target_shift_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if (!$requester_shift_id || !$swap_with_date || empty($reason)) {
        $msg = "All fields are required.";
        $msg_type = "danger";
    } else if (strlen($reason) < 10) {
        $msg = "Please provide a detailed reason (at least 10 characters).";
        $msg_type = "warning";
    } else {
        try {
            // Verify the shift belongs to the employee
            $stmt = $pdo->prepare("SELECT * FROM shifts WHERE id = ? AND employee_id = ?");
            $stmt->execute([$requester_shift_id, $employee_id]);
            $shift = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$shift) {
                $msg = "Invalid shift selected.";
                $msg_type = "danger";
            } else if ($shift['shift_date'] < date('Y-m-d')) {
                $msg = "Cannot request swap for past dates.";
                $msg_type = "danger";
            } else {
                // Check for duplicate pending requests
                $stmt = $pdo->prepare("SELECT id FROM swap_requests WHERE requester_id = ? AND requester_shift_id = ? AND status = 'Pending'");
                $stmt->execute([$employee_id, $requester_shift_id]);
                if ($stmt->fetch()) {
                    $msg = "You already have a pending swap request for this shift.";
                    $msg_type = "warning";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO swap_requests (requester_id, requester_shift_id, swap_with_date, target_employee_id, target_shift_id, reason) VALUES (?, ?, ?, ?, ?, ?)");
                    $result = $stmt->execute([
                        $employee_id,
                        $requester_shift_id,
                        $swap_with_date,
                        ($target_employee_id > 0 ? $target_employee_id : null),
                        ($target_shift_id > 0 ? $target_shift_id : null),
                        $reason
                    ]);

                    if ($result) {
                        $msg = "✅ Shift swap request submitted successfully! Awaiting scheduler review.";
                        $msg_type = "success";
                        $_POST = [];
                    } else {
                        $msg = "Failed to submit request. Please try again.";
                        $msg_type = "danger";
                    }
                }
            }
        } catch (Exception $e) {
            $msg = "Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Handle request cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_request'])) {
    $request_id = intval($_POST['request_id'] ?? 0);
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM swap_requests WHERE id = ? AND requester_id = ? AND status = 'Pending'");
        $stmt->execute([$request_id, $employee_id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            $msg = "Request not found or cannot be cancelled.";
            $msg_type = "danger";
        } else {
            $stmt = $pdo->prepare("UPDATE swap_requests SET status = 'Cancelled' WHERE id = ?");
            if ($stmt->execute([$request_id])) {
                $msg = "Request cancelled successfully.";
                $msg_type = "success";
            }
        }
    } catch (Exception $e) {
        $msg = "Error: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// Get employee's shifts
$stmt = $pdo->prepare("
    SELECT s.id, s.shift_date, s.shift_type, s.shift_start, s.shift_end
    FROM shifts s
    WHERE s.employee_id = ? AND s.shift_date >= CURDATE()
    ORDER BY s.shift_date ASC
");
$stmt->execute([$employee_id]);
$my_shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all other employees' shifts for swap options
$stmt = $pdo->prepare("
    SELECT s.id, s.employee_id, s.shift_date, s.shift_type, s.shift_start, s.shift_end, e.fullname
    FROM shifts s
    JOIN employees e ON s.employee_id = e.id
    WHERE s.employee_id != ? AND s.shift_date >= CURDATE()
    ORDER BY s.shift_date ASC, e.fullname ASC
");
$stmt->execute([$employee_id]);
$other_shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get employee's swap request history
$stmt = $pdo->prepare("
    SELECT r.*, s.shift_date, s.shift_type, e.fullname as target_employee_name
    FROM swap_requests r
    JOIN shifts s ON r.requester_shift_id = s.id
    LEFT JOIN employees e ON r.target_employee_id = e.id
    WHERE r.requester_id = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$employee_id]);
$swap_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Request Shift Swap - ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        body { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; background: #fafbfc; color: #22223b; font-size: 16px; }
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
        .dashboard-title { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; font-size: 1.5rem; font-weight: 700; color: #22223b; margin: 0; }
        .main-content { margin-left: 220px; padding: 2rem; }
        .card { border-radius: 12px; border: 1px solid #e0e7ff; box-shadow: 0 2px 8px rgba(140,140,200,0.07); margin-bottom: 2rem; }
        .card-header { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: #fff; border-radius: 12px 12px 0 0; padding: 1.5rem; border: none; font-weight: 700; display: flex; align-items: center; gap: 0.7rem; }
        .card-body { padding: 1.5rem; }
        .form-label { font-weight: 600; color: #22223b; margin-bottom: 0.5rem; display: block; }
        .form-control, .form-select { border-radius: 8px; border: 1px solid #d0d7e2; padding: 0.7rem 1rem; font-size: 0.95rem; }
        .form-control:focus, .form-select:focus { border-color: #9A66ff; box-shadow: 0 0 0 0.2rem rgba(154, 102, 255, 0.15); }
        .btn-primary { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); border: none; border-radius: 8px; padding: 0.7rem 1.5rem; font-weight: 600; }
        .btn-primary:hover { background: linear-gradient(90deg, #8654e0 0%, #360090 100%); }
        .alert { border-radius: 10px; border-left: 4px solid; padding: 1.2rem; display: flex; gap: 0.8rem; align-items: flex-start; margin-bottom: 1.5rem; }
        .alert ion-icon { font-size: 1.3rem; flex-shrink: 0; margin-top: 0.2rem; }
        .alert-success { background: #d1fae5; border-left-color: #10b981; color: #065f46; }
        .alert-danger { background: #fee2e2; border-left-color: #ef4444; color: #991b1b; }
        .alert-warning { background: #fef3c7; border-left-color: #f59e0b; color: #92400e; }
        .badge-pending { background: #fff3cd; color: #856404; padding: 0.35rem 0.6rem; border-radius: 6px; font-weight: 600; }
        .badge-approved { background: #d1fae5; color: #065f46; padding: 0.35rem 0.6rem; border-radius: 6px; font-weight: 600; }
        .badge-rejected { background: #fee2e2; color: #991b1b; padding: 0.35rem 0.6rem; border-radius: 6px; font-weight: 600; }
        .badge-cancelled { background: #f3f4f6; color: #4b5563; padding: 0.35rem 0.6rem; border-radius: 6px; font-weight: 600; }
        .shift-info { background: #f3f0ff; border-left: 4px solid #9A66ff; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .shift-info strong { color: #4311a5; display: block; margin-bottom: 0.3rem; }
        .shift-info small { color: #6c757d; }
        .table { font-size: 0.95rem; }
        .table th { background: #f3f4f6; font-weight: 600; color: #4b5563; border: none; padding: 0.8rem; }
        .table td { border-color: #e5e7eb; padding: 0.8rem; }
        .table tr:hover { background: #f9fafb; }
        .status-badge { display: inline-block; font-size: 0.85rem; font-weight: 600; padding: 0.35rem 0.6rem; border-radius: 6px; }
        @media (max-width: 900px) { .main-content { margin-left: 0; padding: 1rem; } .sidebar { left: -220px; } .sidebar.show { left: 0; z-index: 1050; } }
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
            <h6 class="text-uppercase px-2 mb-2">Menu</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="employee_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
              <a class="nav-link" href="my_shifts.php"><ion-icon name="calendar-outline"></ion-icon>My Shifts</a>
              <a class="nav-link" href="leave_requests.php"><ion-icon name="checkbox-outline"></ion-icon>Leave Requests</a>
              <a class="nav-link active" href="shift_swap_request_employee.php"><ion-icon name="swap-horizontal-outline"></ion-icon>Request Shift Swap</a>
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
        <h1 class="dashboard-title">Request Shift Swap</h1>
        <div class="profile">
          <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
          <div class="profile-info">
            <strong><?= htmlspecialchars($fullname) ?></strong>
            <small>Employee</small>
          </div>
        </div>
      </div>

      <!-- Messages -->
      <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?>">
          <ion-icon name="<?= $msg_type === 'success' ? 'checkmark-circle-outline' : ($msg_type === 'danger' ? 'alert-circle-outline' : 'alert-outline') ?>"></ion-icon>
          <div><?= htmlspecialchars($msg) ?></div>
        </div>
      <?php endif; ?>

      <!-- Submit Request Form -->
      <div class="card">
        <div class="card-header">
          <ion-icon name="swap-horizontal-outline"></ion-icon>
          Submit Shift Swap Request
        </div>
        <div class="card-body">
          <?php if (empty($my_shifts)): ?>
            <div class="alert alert-info" style="margin-bottom: 0;">
              <ion-icon name="information-circle-outline"></ion-icon>
              <div>You have no upcoming shifts available for swap.</div>
            </div>
          <?php else: ?>
            <form method="post">
              <input type="hidden" name="submit_swap_request" value="1">
              
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label">Your Current Shift <span style="color: #dc3545;">*</span></label>
                  <select class="form-select" name="requester_shift_id" id="requesterShift" required onchange="updateShiftInfo()">
                    <option value="">-- Select Your Shift --</option>
                    <?php foreach ($my_shifts as $shift): ?>
                      <option value="<?= $shift['id'] ?>" data-date="<?= $shift['shift_date'] ?>" data-type="<?= $shift['shift_type'] ?>" data-start="<?= $shift['shift_start'] ?? '' ?>" data-end="<?= $shift['shift_end'] ?? '' ?>">
                        <?= htmlspecialchars($shift['shift_type']) ?> - <?= date('M d, Y', strtotime($shift['shift_date'])) ?> (<?= ucfirst(strtolower(date('l', strtotime($shift['shift_date'])))) ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <small class="text-muted">Select the shift you want to swap</small>
                </div>

                <div class="col-md-6 mb-3">
                  <label class="form-label">Preferred Swap Date <span style="color: #dc3545;">*</span></label>
                  <input type="date" class="form-control" name="swap_with_date" required min="<?= date('Y-m-d') ?>">
                  <small class="text-muted">Date you prefer to work instead</small>
                </div>
              </div>

              <div id="shiftInfo" style="display: none;">
                <div class="shift-info">
                  <strong>Your Shift Details:</strong>
                  <div><small><span id="shiftDateInfo"></span> | <span id="shiftTypeInfo"></span> | <span id="shiftTimeInfo"></span></small></div>
                </div>
              </div>

              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label">Swap With Employee (Optional)</label>
                  <select class="form-select" name="target_employee_id" id="targetEmployee" onchange="updateTargetShifts()">
                    <option value="">-- Any Employee --</option>
                    <?php 
                    $employees = [];
                    foreach ($other_shifts as $shift) {
                      if (!in_array($shift['employee_id'], array_column($employees, 'id'))) {
                        $employees[] = ['id' => $shift['employee_id'], 'name' => $shift['fullname']];
                      }
                    }
                    foreach ($employees as $emp): 
                    ?>
                      <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <small class="text-muted">Leave blank if open to anyone</small>
                </div>

                <div class="col-md-6 mb-3">
                  <label class="form-label">Their Shift (Optional)</label>
                  <select class="form-select" name="target_shift_id" id="targetShift">
                    <option value="">-- Select Shift --</option>
                    <?php foreach ($other_shifts as $shift): ?>
                      <option value="<?= $shift['id'] ?>" data-employee="<?= $shift['employee_id'] ?>">
                        <?= htmlspecialchars($shift['fullname']) ?> - <?= htmlspecialchars($shift['shift_type']) ?> - <?= date('M d, Y', strtotime($shift['shift_date'])) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <small class="text-muted">Leave blank if no specific shift in mind</small>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label">Reason for Swap <span style="color: #dc3545;">*</span></label>
                <textarea class="form-control" name="reason" rows="4" placeholder="Please explain why you need this shift swap..." required minlength="10"></textarea>
                <small class="text-muted">Minimum 10 characters required</small>
              </div>

              <button type="submit" class="btn btn-primary">
                <ion-icon name="send-outline"></ion-icon> Submit Request
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- Request History -->
      <div class="card">
        <div class="card-header">
          <ion-icon name="history-outline"></ion-icon>
          Your Swap Request History
        </div>
        <div class="card-body">
          <?php if (empty($swap_history)): ?>
            <p class="text-muted mb-0">No swap requests submitted yet.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover mb-0">
                <thead>
                  <tr>
                    <th>Your Shift</th>
                    <th>Desired Date</th>
                    <th>Target Employee</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($swap_history as $req): ?>
                    <tr>
                      <td>
                        <strong><?= htmlspecialchars($req['shift_type']) ?></strong><br>
                        <small class="text-muted"><?= date('M d, Y', strtotime($req['shift_date'])) ?></small>
                      </td>
                      <td><?= htmlspecialchars($req['swap_with_date'] ?? 'Open') ?></td>
                      <td><?= htmlspecialchars($req['target_employee_name'] ?? 'Any') ?></td>
                      <td>
                        <?php if ($req['status'] === 'Pending'): ?>
                          <span class="badge-pending">Pending</span>
                        <?php elseif ($req['status'] === 'Approved'): ?>
                          <span class="badge-approved">Approved</span>
                        <?php elseif ($req['status'] === 'Rejected'): ?>
                          <span class="badge-rejected">Rejected</span>
                        <?php else: ?>
                          <span class="badge-cancelled">Cancelled</span>
                        <?php endif; ?>
                      </td>
                      <td><small><?= date('M d, Y H:i', strtotime($req['created_at'])) ?></small></td>
                      <td>
                        <?php if ($req['status'] === 'Pending'): ?>
                          <form method="post" style="display: inline;">
                            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                            <button type="submit" name="cancel_request" class="btn btn-sm btn-outline-danger" onclick="return confirm('Cancel this request?')">
                              <ion-icon name="close-outline"></ion-icon> Cancel
                            </button>
                          </form>
                        <?php else: ?>
                          <small class="text-muted">No action</small>
                        <?php endif; ?>
                      </td>
                    </tr>
                    <?php if (!empty($req['review_note'])): ?>
                      <tr class="table-light">
                        <td colspan="6"><small><strong>Reviewer Note:</strong> <?= htmlspecialchars($req['review_note']) ?></small></td>
                      </tr>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateShiftInfo() {
  const select = document.getElementById('requesterShift');
  const option = select.options[select.selectedIndex];
  const infoDiv = document.getElementById('shiftInfo');
  
  if (option.value) {
    document.getElementById('shiftDateInfo').textContent = option.getAttribute('data-date');
    document.getElementById('shiftTypeInfo').textContent = option.getAttribute('data-type');
    const start = option.getAttribute('data-start');
    const end = option.getAttribute('data-end');
    document.getElementById('shiftTimeInfo').textContent = start && end ? `${start} - ${end}` : 'Time not set';
    infoDiv.style.display = 'block';
  } else {
    infoDiv.style.display = 'none';
  }
}

function updateTargetShifts() {
  const empSelect = document.getElementById('targetEmployee');
  const shiftSelect = document.getElementById('targetShift');
  const selectedEmpId = empSelect.value;
  
  // Reset and filter shifts
  Array.from(shiftSelect.options).forEach(opt => {
    if (opt.value === '') {
      opt.style.display = 'block';
    } else {
      opt.style.display = (selectedEmpId === '' || opt.getAttribute('data-employee') === selectedEmpId) ? 'block' : 'none';
    }
  });
  
  // Reset selection
  shiftSelect.value = '';
}
</script>
</body>
</html>