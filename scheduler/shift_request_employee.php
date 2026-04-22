<?php
session_start();
require_once("../includes/db.php");

/*
 Employee Shift Swap Request page
 - Uses table `swap_requests`
 - Allows the logged-in employee to:
   * select one of their scheduled shifts
   * pick a date they'd like to swap with
   * optionally choose a target employee or a specific target shift on that date
   * provide a reason and submit a pending request
   * view and cancel their own pending requests
 - Provides a small AJAX endpoint to list shifts on a chosen date (for populating target shifts)
*/

// Resolve current user (prefer session user_id)
$session_user_id = $_SESSION['user_id'] ?? null;
$fullname = $_SESSION['fullname'] ?? 'Employee';
$role = $_SESSION['role'] ?? 'employee';

// Try to resolve user id if session doesn't include it (best-effort)
if (!$session_user_id && !empty($fullname)) {
    $stmt = $pdo->prepare("SELECT id FROM employees WHERE fullname = ? LIMIT 1");
    $stmt->execute([$fullname]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    $session_user_id = $r['id'] ?? null;
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

// AJAX endpoint: return shifts on date (used by client-side)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['shifts_on']) && $_GET['shifts_on']) {
    header('Content-Type: application/json; charset=utf-8');
    $date = $_GET['shifts_on'];
    $stmt = $pdo->prepare("SELECT s.id, s.employee_id, s.shift_type, e.fullname FROM shifts s JOIN employees e ON s.employee_id = e.id WHERE s.shift_date = ? ORDER BY e.fullname ASC");
    $stmt->execute([$date]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Helpers
function get_user_shifts(PDO $pdo, $userId) {
    $stmt = $pdo->prepare("SELECT id, shift_date, shift_type FROM shifts WHERE employee_id = ? ORDER BY shift_date ASC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function get_active_employees(PDO $pdo, $excludeId = null) {
    if ($excludeId) {
        $stmt = $pdo->prepare("SELECT id, fullname, department FROM employees WHERE status='Active' AND id != ? ORDER BY fullname ASC");
        $stmt->execute([$excludeId]);
    } else {
        $stmt = $pdo->prepare("SELECT id, fullname, department FROM employees WHERE status='Active' ORDER BY fullname ASC");
        $stmt->execute();
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function get_user_requests(PDO $pdo, $userId) {
    $stmt = $pdo->prepare("SELECT r.*, s.shift_date, s.shift_type FROM swap_requests r LEFT JOIN shifts s ON r.requester_shift_id = s.id WHERE r.requester_id = ? ORDER BY r.created_at DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle submission of new swap request
$messages = [];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_swap_request'])) {
    // Basic auth check
    if (!$session_user_id) {
        $errors[] = "Unable to identify you — please re-login.";
    } else {
        $shift_id = intval($_POST['shift_id'] ?? 0);
        $swap_with_date = trim($_POST['swap_with_date'] ?? '');
        $target_employee_id = intval($_POST['target_employee_id'] ?? 0) ?: null;
        $target_shift_id = intval($_POST['target_shift_id'] ?? 0) ?: null;
        $reason = trim($_POST['reason'] ?? '');

        // Validation
        if (!$shift_id) $errors[] = "Please select the shift you want to swap.";
        if (!$swap_with_date) $errors[] = "Please select the date you'd like to swap with.";
        if (!$reason) $errors[] = "Please provide a reason for your request.";

        // Verify selected shift belongs to user
        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT 1 FROM shifts WHERE id = ? AND employee_id = ? LIMIT 1");
            $stmt->execute([$shift_id, $session_user_id]);
            if (!$stmt->fetch()) $errors[] = "Selected shift not found or does not belong to you.";
        }

        // Verify optional targets
        if ($target_employee_id) {
            $stmt = $pdo->prepare("SELECT 1 FROM employees WHERE id = ? AND status='Active' LIMIT 1");
            $stmt->execute([$target_employee_id]);
            if (!$stmt->fetch()) $errors[] = "Selected target employee is not valid.";
        }
        if ($target_shift_id) {
            $stmt = $pdo->prepare("SELECT shift_date FROM shifts WHERE id = ? LIMIT 1");
            $stmt->execute([$target_shift_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $errors[] = "Selected target shift not found.";
            } elseif ($row['shift_date'] !== $swap_with_date) {
                $errors[] = "Selected target shift does not match the chosen swap date.";
            }
        }

        // Prevent duplicate pending requests for same shift
        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT 1 FROM swap_requests WHERE requester_id = ? AND requester_shift_id = ? AND status = 'Pending' LIMIT 1");
            $stmt->execute([$session_user_id, $shift_id]);
            if ($stmt->fetch()) $errors[] = "You already have a pending request for this shift.";
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("INSERT INTO swap_requests (requester_id, requester_shift_id, swap_with_date, target_employee_id, target_shift_id, reason, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
            $stmt->execute([$session_user_id, $shift_id, $swap_with_date, $target_employee_id, $target_shift_id, $reason]);
            $messages[] = "Your swap request has been submitted and is now pending review by the scheduler.";
        }
    }
}

// Cancel own pending request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_request'])) {
    $req_id = intval($_POST['request_id'] ?? 0);
    if (!$session_user_id) {
        $errors[] = "Unable to identify you.";
    } elseif (!$req_id) {
        $errors[] = "Invalid request id.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM swap_requests WHERE id = ? AND requester_id = ? LIMIT 1");
        $stmt->execute([$req_id, $session_user_id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            $errors[] = "Request not found.";
        } elseif ($req['status'] !== 'Pending') {
            $errors[] = "Only pending requests can be cancelled.";
        } else {
            $stmt = $pdo->prepare("UPDATE swap_requests SET status='Cancelled', reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?");
            $stmt->execute([$fullname, 'Cancelled by requester', $req_id]);
            $messages[] = "Your request has been cancelled.";
        }
    }
}

// Load UI data
$user_shifts = $session_user_id ? get_user_shifts($pdo, $session_user_id) : [];
$active_employees = get_active_employees($pdo, $session_user_id);
$user_requests = $session_user_id ? get_user_requests($pdo, $session_user_id) : [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Request Shift Swap — ViaHale TNVS HR3</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <style>
    /* Sidebar markup and behavior remain consistent with other pages */
    body { font-family: 'QuickSand','Poppins',Arial,sans-serif; background:#fafbfc; color:#22223b; }
    .sidebar { background:#181818; color:#fff; min-height:100vh; width:220px; position:fixed; left:0; top:0; padding:1rem 0.3rem; }
    .sidebar a, .sidebar .nav-link { color:#bfc7d1; padding:0.45rem 0.7rem; display:flex; align-items:center; gap:0.7rem; margin-bottom:0.1rem; text-decoration:none; }
    .sidebar a.active, .sidebar a:hover { background:linear-gradient(90deg,#9A66ff 0%,#4311a5 100%); color:#fff; border-radius:8px; }
    .main-content { margin-left:220px; padding:2rem; }
    .card { border-radius:10px; box-shadow:0 2px 8px rgba(140,140,200,0.06); }
    .small-muted { color:#6b7280; font-size:0.9rem; }
    .badge-status { border-radius:8px; padding:.35rem .65rem; }
    .badge-pending { background:#fff3cd; color:#856404; }
    .badge-approved { background:#d1fae5; color:#065f46; }
    .badge-rejected { background:#fee2e2; color:#7f1d1d; }
  </style>
</head>
<body>
  <!-- Sidebar (kept identical to other scheduler pages) -->
  <div class="sidebar">
    <div class="d-flex justify-content-center align-items-center mb-4 mt-2">
      <img src="../assets/images/image.png" style="height:48px" alt="Logo">
    </div>
    <nav class="nav flex-column">
      <a class="nav-link" href="../scheduler/schedule_officer_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
      <a class="nav-link" href="../scheduler/shift_scheduling.php"><ion-icon name="calendar-outline"></ion-icon>Shift Scheduling</a>
      <a class="nav-link" href="../scheduler/edit_update_schedules.php"><ion-icon name="pencil-outline"></ion-icon>Edit/Update Schedules</a>
      <a class="nav-link active" href="../scheduler/shift_swap_requests.php"><ion-icon name="swap-horizontal-outline"></ion-icon>Shift Swap Requests</a>
      <a class="nav-link" href="../scheduler/employee_availability.php"><ion-icon name="people-outline"></ion-icon>Employee Availability</a>
      <a class="nav-link" href="../scheduler/schedule_logs.php"><ion-icon name="reader-outline"></ion-icon>Schedule Logs</a>
    </nav>
    <div class="mt-4">
      <a class="nav-link text-danger" href="../logout.php"><ion-icon name="log-out-outline"></ion-icon>Logout</a>
    </div>
  </div>

  <div class="main-content">
    <div class="d-flex justify-content-between align-items-start mb-3">
      <div>
        <h2>Request Shift Swap</h2>
        <div class="small-muted">Submit a request to swap one of your scheduled shifts.</div>
      </div>
      <div class="text-end">
        <strong><?= htmlspecialchars($fullname) ?></strong><br>
        <small class="small-muted"><?= htmlspecialchars(ucfirst($role)) ?></small>
      </div>
    </div>

    <?php foreach ($messages as $m): ?>
      <div class="alert alert-success"><?= htmlspecialchars($m) ?></div>
    <?php endforeach; ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card p-3">
          <h5 class="mb-3">New Request</h5>
          <form method="post" id="swapForm">
            <input type="hidden" name="submit_swap_request" value="1">
            <div class="mb-3">
              <label class="form-label">Your scheduled shift</label>
              <select name="shift_id" class="form-select" required>
                <option value="">-- select your shift --</option>
                <?php foreach($user_shifts as $s): ?>
                  <option value="<?= intval($s['id']) ?>"><?= htmlspecialchars($s['shift_date']) ?> — <?= htmlspecialchars($s['shift_type']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (empty($user_shifts)): ?><div class="small-muted mt-1">You have no scheduled shifts.</div><?php endif; ?>
            </div>

            <div class="mb-3">
              <label class="form-label">Desired swap date</label>
              <input type="date" name="swap_with_date" id="swap_with_date" class="form-control" required>
              <div class="small-muted">Pick the date you'd like to swap into (this helps find target shifts).</div>
            </div>

            <div class="mb-3">
              <label class="form-label">Optional — preferred employee</label>
              <select name="target_employee_id" id="target_employee_id" class="form-select">
                <option value="">(no preference)</option>
                <?php foreach($active_employees as $emp): ?>
                  <option value="<?= intval($emp['id']) ?>"><?= htmlspecialchars($emp['fullname']) ?><?= $emp['department'] ? " — ".htmlspecialchars($emp['department']) : "" ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label">Optional — specific shift (populates after selecting date)</label>
              <select name="target_shift_id" id="target_shift_id" class="form-select">
                <option value="">(choose a date to load shifts)</option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label">Reason</label>
              <textarea name="reason" class="form-control" rows="4" required></textarea>
            </div>

            <div class="d-flex gap-2">
              <button class="btn btn-primary" type="submit">Submit request</button>
              <button type="reset" class="btn btn-outline-secondary">Reset</button>
            </div>
          </form>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card p-3">
          <h5 class="mb-3">Your requests</h5>
          <?php if (empty($user_requests)): ?>
            <div class="small-muted">You have no requests yet.</div>
          <?php else: ?>
            <div class="table-responsive" style="max-height:520px;overflow:auto;">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>Shift</th>
                    <th>Swap date</th>
                    <th>Status</th>
                    <th>Requested</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($user_requests as $r): ?>
                    <tr>
                      <td><?= htmlspecialchars($r['shift_date']) ?><br><small class="small-muted"><?= htmlspecialchars($r['shift_type']) ?></small></td>
                      <td><?= htmlspecialchars($r['swap_with_date']) ?></td>
                      <td>
                        <?php if ($r['status'] === 'Pending'): ?><span class="badge-status badge-pending">Pending</span>
                        <?php elseif ($r['status'] === 'Approved'): ?><span class="badge-status badge-approved">Approved</span>
                        <?php elseif ($r['status'] === 'Rejected'): ?><span class="badge-status badge-rejected">Rejected</span>
                        <?php else: ?><span class="small-muted"><?= htmlspecialchars($r['status']) ?></span><?php endif; ?>
                      </td>
                      <td><?= htmlspecialchars(date('Y-m-d', strtotime($r['created_at']))) ?></td>
                      <td>
                        <?php if ($r['status'] === 'Pending'): ?>
                          <form method="post" onsubmit="return confirm('Cancel this pending request?');">
                            <input type="hidden" name="request_id" value="<?= intval($r['id']) ?>">
                            <button name="cancel_request" class="btn btn-sm btn-outline-danger">Cancel</button>
                          </form>
                        <?php else: ?>
                          <button class="btn btn-sm btn-outline-secondary" disabled>—</button>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const dateInput = document.getElementById('swap_with_date');
  const targetShift = document.getElementById('target_shift_id');

  function clearTargetShifts(){
    targetShift.innerHTML = '<option value="">(choose a date to load shifts)</option>';
  }

  if (dateInput) {
    dateInput.addEventListener('change', function(){
      clearTargetShifts();
      const d = this.value;
      if (!d) return;
      fetch(window.location.pathname + '?shifts_on=' + encodeURIComponent(d), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
          if (!Array.isArray(data)) return;
          data.forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.fullname + ' — ' + s.shift_type;
            targetShift.appendChild(opt);
          });
        })
        .catch(err => console.error('Failed to fetch shifts:', err));
    });
  }
});
</script>
</body>
</html>