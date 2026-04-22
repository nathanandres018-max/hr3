<?php
session_start();
require_once("../includes/db.php");
require_once("../includes/attendance_functions.php");


$fullname = $_SESSION['fullname'] ?? 'Employee';
$role = $_SESSION['role'] ?? 'employee';


// Filters
$search_status = $_GET['status'] ?? '';
$search_year = $_GET['year'] ?? date('Y');
$search_month = $_GET['month'] ?? '';

// Build WHERE for filters
$where = "employee_id = ?";

if ($search_status !== '' && in_array($search_status, ['pending', 'approved', 'rejected'])) {
    $where .= " AND status = ?";
    $params[] = $search_status;
}
if ($search_year !== '') {
    $where .= " AND YEAR(period_from) = ?";
    $params[] = $search_year;
}
if ($search_month !== '' && $search_month >= 1 && $search_month <= 12) {
    $where .= " AND MONTH(period_from) = ?";
    $params[] = str_pad($search_month, 2, '0', STR_PAD_LEFT);
}

// Fetch timesheets
$stmt = $pdo->prepare("SELECT * FROM timesheets WHERE $where ORDER BY period_from DESC, submitted_at DESC");

$timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter dropdown (years)
$year_stmt = $pdo->prepare("SELECT DISTINCT YEAR(period_from) AS year FROM timesheets WHERE employee_id = ? ORDER BY year DESC");

$years = $year_stmt->fetchAll(PDO::FETCH_COLUMN);

// Attendance logs for modals
function getAttendanceForPeriod($employeeId, $from, $to) {
    return viewAttendanceLogsCutoff($employeeId, $from, $to);
}
// HR notes for modals
function getHRNotes($pdo, $timesheetId) {
    $stmt = $pdo->prepare("SELECT hr_notes FROM timesheets WHERE id = ?");
    $stmt->execute([$timesheetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && isset($row['hr_notes']) ? $row['hr_notes'] : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Timesheets - ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        body { font-family: 'Quicksand', 'Poppins', Arial, sans-serif; background: #fafbfc; color: #22223b; font-size: 16px; }
        .sidebar { background: #181818ff; color: #fff; min-height: 100vh; border: none; width: 220px; position: fixed; left: 0; top: 0; z-index: 1040; transition: left 0.3s; overflow-y: auto; padding: 1rem 0.3rem 1rem 0.3rem; scrollbar-width: none; height: 100vh; -ms-overflow-style: none; }
        .sidebar::-webkit-scrollbar { display: none; width: 0px; background: transparent; }
        .sidebar a, .sidebar button { color: #bfc7d1; background: none; border: none; font-size: 0.95rem; padding: 0.45rem 0.7rem; border-radius: 8px; display: flex; align-items: center; gap: 0.7rem; margin-bottom: 0.1rem; transition: background 0.2s, color 0.2s; width: 100%; text-align: left; white-space: nowrap; }
        .sidebar a.active, .sidebar a:hover, .sidebar button.active, .sidebar button:hover { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: #fff; }
        .sidebar hr { border-top: 1px solid #232a43; margin: 0.7rem 0; }
        .sidebar .nav-link ion-icon { font-size: 1.2rem; margin-right: 0.3rem; }
        .main-content { margin-left: 220px; padding: 2rem 2rem 2rem 2rem; min-height: 100vh; background: #fafbfc; }
        .topbar { padding: 0.7rem 1.2rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem;}
        .topbar .profile { display: flex; align-items: center; gap: 1.2rem;}
        .topbar .profile-img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; margin-right: 0.7rem; border: 2px solid #e0e7ff;}
        .topbar .profile-info strong { font-size: 1.08rem; font-weight: 600; color: #22223b;}
        .topbar .profile-info small { color: #6c757d; font-size: 0.93rem;}
        .dashboard-title { font-family: 'Quicksand', 'Poppins', Arial, sans-serif; font-size: 1.7rem; font-weight: 700; margin-bottom: 1.2rem; color: #22223b;}
        .table th, .table td { vertical-align: middle; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-approved { background: #dbeafe; color: #2563eb; }
        .badge-rejected { background: #fee2e2; color: #b91c1c; }
        .badge-note { background: #9A66ff; color: #fff; }
        .modal-content { border-radius: 16px; box-shadow: 0 6px 32px rgba(140, 140, 200, 0.18);}
        .modal-header { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: #fff; border-top-left-radius: 16px; border-top-right-radius: 16px;}
        .modal-title { font-family: 'Quicksand', 'Poppins', Arial, sans-serif; font-size: 1.19rem; font-weight: 700; letter-spacing: 0.03em;}
        .modal-body { background: #f6f8fc; padding-top: 1.2rem; padding-bottom: 1.2rem;}
        .filter-bar { margin-bottom: 1.5rem; display: flex; gap: 0.7rem; flex-wrap: wrap; align-items: center;}
        .modal-footer { display: none !important; }
        .details-list { list-style: none; padding: 0; margin: 0 0 1rem 0;}
        .details-list li { margin-bottom: 0.2rem; font-size: 1.03rem;}
        .details-label { font-weight: 600; color: #4311a5;}
        .details-status { margin-left: 0.5em;}
        .details-notes { background: #fff; border-radius: 8px; padding: 0.5em 0.9em; font-size:0.97rem; color:#4311a5; margin-bottom: 1em; border: 1px solid #e0e7ff;}
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
              <a class="nav-link" href="../employee/schedule.php"><ion-icon name="calendar-outline"></ion-icon>My Schedule</a>
              <a class="nav-link" href="../employee/shift_swap.php"><ion-icon name="swap-horizontal-outline"></ion-icon>Request Shift Swap</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Timesheet Management</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="../employee/timesheet_submit.php"><ion-icon name="document-text-outline"></ion-icon>Submit Timesheet</a>
              <a class="nav-link active" href="../employee/timesheets.php"><ion-icon name="document-text-outline"></ion-icon>My Timesheets</a>
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
            <span class="dashboard-title">My Timesheets</span>
            <div class="profile">
                <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
                <div class="profile-info">
                    <strong><?= htmlspecialchars($fullname) ?></strong><br>
                    <small><?= htmlspecialchars(ucfirst($role)) ?></small>
                </div>
            </div>
        </div>
        <!-- Filter/Search Bar -->
        <form class="filter-bar" method="get" action="timesheets.php">
            <label>Status:
                <select name="status" class="form-select d-inline-block w-auto">
                    <option value="">All</option>
                    <option value="pending" <?= $search_status=='pending'?'selected':''; ?>>Pending</option>
                    <option value="approved" <?= $search_status=='approved'?'selected':''; ?>>Approved</option>
                    <option value="rejected" <?= $search_status=='rejected'?'selected':''; ?>>Rejected</option>
                </select>
            </label>
            <label>Year:
                <select name="year" class="form-select d-inline-block w-auto">
                    <?php foreach ($years as $year): ?>
                        <option value="<?= $year ?>" <?= $search_year==$year?'selected':''; ?>><?= $year ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Month:
                <select name="month" class="form-select d-inline-block w-auto">
                    <option value="">All</option>
                    <?php for($i=1; $i<=12; $i++): ?>
                        <option value="<?= $i ?>" <?= $search_month==$i?'selected':''; ?>><?= date('F', mktime(0,0,0,$i,1)); ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <button class="btn btn-primary" type="submit"><ion-icon name="search-outline"></ion-icon> Filter</button>
            <a href="timesheets.php" class="btn btn-outline-secondary ms-2">Reset</a>
        </form>
        <div class="mb-4">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Date Submitted</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th>Review</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($timesheets as $ts): ?>
                    <tr>
                        <td><?= htmlspecialchars($ts['period_from']) ?> to <?= htmlspecialchars($ts['period_to']) ?></td>
                        <td><?= htmlspecialchars($ts['submitted_at']) ?></td>
                        <td>
                            <?php if ($ts['status'] == 'pending'): ?>
                                <span class="badge badge-pending">Pending</span>
                            <?php elseif ($ts['status'] == 'approved'): ?>
                                <span class="badge badge-approved">Approved</span>
                            <?php elseif ($ts['status'] == 'rejected'): ?>
                                <span class="badge badge-rejected">Rejected</span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><?= htmlspecialchars(ucfirst($ts['status'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                          <?php if (!empty($ts['notes'])): ?>
                            <span class="badge badge-note" title="<?= htmlspecialchars($ts['notes']) ?>" style="cursor: pointer;">
                                <ion-icon name="chatbubble-ellipses-outline"></ion-icon>
                                <?= htmlspecialchars(mb_strimwidth($ts['notes'], 0, 20, '...')) ?>
                            </span>
                          <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#detailsModal<?= $ts['id'] ?>">
                                <ion-icon name="eye-outline"></ion-icon> View
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($timesheets)): ?>
                    <tr><td colspan="5">No timesheet submissions found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <!-- All modals are now outside the table, to avoid HTML errors -->
            <?php foreach ($timesheets as $ts): ?>
                <div class="modal fade" id="detailsModal<?= $ts['id'] ?>" tabindex="-1" aria-labelledby="detailsLabel<?= $ts['id'] ?>" aria-hidden="true">
                  <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title" id="detailsLabel<?= $ts['id'] ?>">
                            Attendance Logs for <?= htmlspecialchars($ts['period_from']) ?> to <?= htmlspecialchars($ts['period_to']) ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                      </div>
                      <div class="modal-body">
                        <ul class="details-list">
                            <li>
                                <span class="details-label">Status:</span>
                                <?php if ($ts['status'] == 'pending'): ?>
                                    <span class="badge badge-pending details-status">Pending</span>
                                <?php elseif ($ts['status'] == 'approved'): ?>
                                    <span class="badge badge-approved details-status">Approved</span>
                                <?php elseif ($ts['status'] == 'rejected'): ?>
                                    <span class="badge badge-rejected details-status">Rejected</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary details-status"><?= htmlspecialchars(ucfirst($ts['status'])) ?></span>
                                <?php endif; ?>
                            </li>
                            <li><span class="details-label">Submitted On:</span> <?= htmlspecialchars($ts['submitted_at']) ?></li>
                            <?php if (!empty($ts['notes'])): ?>
                            <li>
                              <span class="details-label">Your Notes:</span>
                              <div class="details-notes"><?= nl2br(htmlspecialchars($ts['notes'])) ?></div>
                            </li>
                            <?php endif; ?>
                            <?php
                            $hrNotes = getHRNotes($pdo, $ts['id']);
                            if ($hrNotes):
                            ?>
                            <li>
                              <span class="details-label">HR Notes:</span>
                              <div class="details-notes"><?= nl2br(htmlspecialchars($hrNotes)) ?></div>
                            </li>
                            <?php endif; ?>
                        </ul>
                        <div class="mb-2" style="font-weight:600;">Attendance Logs</div>
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
                          <?php
                          $attendance = getAttendanceForPeriod($employeeId, $ts['period_from'], $ts['period_to']);
                          if (count($attendance) > 0):
                              foreach ($attendance as $log):
                          ?>
                              <tr>
                                <td><?= htmlspecialchars($log['date']) ?></td>
                                <td><?= htmlspecialchars($log['time_in']) ?></td>
                                <td><?= htmlspecialchars($log['time_out']) ?></td>
                                <td><?= htmlspecialchars($log['status']) ?></td>
                                <td><?= htmlspecialchars($log['ip_in'] ?? '') ?></td>
                                <td><?= htmlspecialchars($log['ip_out'] ?? '') ?></td>
                              </tr>
                          <?php
                              endforeach;
                          else:
                          ?>
                            <tr><td colspan="6">No attendance records for this period.</td></tr>
                          <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>