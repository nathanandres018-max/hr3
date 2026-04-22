<?php
session_start();
require_once("../includes/db.php");

$fullname = $_SESSION['fullname'] ?? 'Employee';
$role = $_SESSION['role'] ?? 'employee';

// Fetch employee info (make sure columns exist in your employees table)
$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");

$emp = $stmt->fetch(PDO::FETCH_ASSOC);

// The leave types, now matches your leave_requests.php file:
$leave_types = [
    'Vacation'  => 10,
    'Sick'      => 12,
    'Emergency' => 5,
    'Unpaid'    => 0 // Unpaid leave has no quota, just display as info
];

// Calculate used leaves per type (Vacation, Sick, Emergency, Unpaid)
$used_leaves = [];
foreach ($leave_types as $type => $quota) {
    $stmt = $pdo->prepare("SELECT date_from, date_to FROM leave_requests WHERE employee_id = ? AND leave_type = ? AND status = 'approved' AND YEAR(date_from) = YEAR(CURDATE())");
  
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $days = 0;
    foreach ($rows as $row) {
        $diff = (strtotime($row['date_to']) - strtotime($row['date_from'])) / 86400 + 1;
        $days += $diff > 0 ? $diff : 1;
    }
    $used_leaves[$type] = $days;
}

// Get pending requests count and total requests for progress bar
$stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND status = 'pending'");

$pending_count = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = ?");

$total_requests = (int)$stmt->fetchColumn();

$progress = ($total_requests > 0) ? round(($pending_count / $total_requests) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leave Balance - Employee | ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .topbar { padding: 0.7rem 1.2rem 0.7rem 1.2rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-top: 0 !important; }
        .topbar .profile { display: flex; align-items: center; gap: 1.2rem; }
        .topbar .profile-img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; margin-right: 0.7rem; border: 2px solid #e0e7ff; }
        .topbar .profile-info { line-height: 1.1; }
        .topbar .profile-info strong { font-size: 1.08rem; font-weight: 600; color: #22223b; }
        .topbar .profile-info small { color: #6c757d; font-size: 0.93rem; }
        .dashboard-title { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; font-size: 1.7rem; font-weight: 700; margin-bottom: 1.2rem; color: #22223b; }
        .breadcrumbs { color: #9A66ff; font-size: 0.98rem; text-align: right; }

        .info-card { background: #fff; border-radius: 16px; box-shadow: 0 2px 8px rgba(140, 140, 200, 0.07); border: 1px solid #f0f0f0; padding: 1.6rem 1.3rem; margin-bottom: 2rem; min-width: 220px; width: 260px; }
        .info-card strong { color: #4311a5; }
        .leave-cards { display: flex; gap: 2rem; justify-content: flex-start; flex-wrap: wrap; margin-bottom: 2.2rem; }
        .leave-card { background: #fff; border-radius: 16px; box-shadow: 0 2px 8px rgba(140,140,200,0.07); border: 1px solid #f0f0f0; padding: 1rem 1.1rem 1.5rem 1.1rem; min-width: 180px; width: 200px; text-align: center; }
        .leave-card canvas { display: block; margin: 0 auto 0.7rem auto; width: 90px !important; height: 90px !important; }
        .leave-card .label { font-size: 1.07rem; color: #22223b; margin-bottom: 0.15rem; font-weight: 600;}
        .leave-card .avail { font-size: 1.15rem; color: #4311a5; font-weight: 700; margin-bottom: 0.2rem; }
        .progress-container { margin: 2rem 0 1.2rem 0; padding-top: 0.3rem; }
        .progress-label { font-weight: 600; font-size: 1.1rem; color: #22223b; margin-bottom: 0.5rem; }
        .progress-bar { height: 18px; background: #e5e7eb; border-radius: 10px; overflow: hidden; }
        .progress-bar-inner { height: 100%; background: linear-gradient(90deg,#27ae60 0%, #b2f5ea 100%); border-radius: 10px; transition: width 0.6s; }
        .progress-percent { text-align: right; font-weight: 700; color: #27ae60; margin-top: 0.1rem; font-size: 1.04rem; }
        @media (max-width: 1000px) { .leave-cards { flex-direction: column; gap: 1.3rem; } }
        @media (max-width: 600px) { .leave-cards { flex-direction: column; gap: 1rem; } .info-card { width: 100%; min-width: 0; } }
        .dashboard-col { background: #fff; border-radius: 18px; box-shadow: 0 2px 8px rgba(140, 140, 200, 0.07); padding: 1.5rem 1.2rem; flex: 1; min-width: 0; max-width: 1000px; margin: 2rem auto 1rem auto; display: flex; flex-direction: column; gap: 1rem; border: 1px solid #f0f0f0; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">
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
              <a class="nav-link active" href="../employee/leave_balance.php"><ion-icon name="calendar-outline"></ion-icon>Leave Balance</a>
              <a class="nav-link" href="../employee/leave_history.php"><ion-icon name="calendar-outline"></ion-icon>Leave History</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Shift & Schedule</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="/employee/schedule.php"><ion-icon name="calendar-outline"></ion-icon>My Schedule</a>
              
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
    <div class="main-content col" style="margin-left:220px;">
      <div class="topbar">
        <span class="dashboard-title">Leave Balance</span>
        <div class="profile">
          <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
          <div class="profile-info">
            <strong><?= htmlspecialchars($fullname) ?></strong><br>
            <small><?= htmlspecialchars(ucfirst($role)) ?></small>
          </div>
        </div>
      </div>
      
      <div class="dashboard-col" style="display:flex; flex-direction:row; flex-wrap:wrap;">
        <div class="info-card me-4 mb-4">
          <div><strong>Employee ID:</strong> <?= htmlspecialchars($emp['employee_id'] ?? 'N/A') ?></div>
          <div><strong>Department:</strong> <?= htmlspecialchars($emp['department'] ?? 'N/A') ?></div>
          <div><strong>Birthday:</strong> <?= htmlspecialchars($emp['birthday'] ?? 'N/A') ?></div>
          <div><strong>Email:</strong> <?= htmlspecialchars($emp['email'] ?? 'N/A') ?></div>
          <div><strong>Contract Number:</strong> <?= htmlspecialchars($emp['contract_number'] ?? 'N/A') ?></div>
        </div>
        <div style="flex:1;">
          <div class="leave-cards">
            <?php foreach($leave_types as $type => $quota): ?>
              <div class="leave-card">
                <canvas id="leaveCircle<?= $type ?>"></canvas>
                <div class="label">Available</div>
                <?php if($type === 'Unpaid'): ?>
                  <div class="avail">Unlimited</div>
                <?php else: ?>
                  <div class="avail"><?= max(0, $quota - ($used_leaves[$type] ?? 0)) ?>/<?= $quota ?></div>
                <?php endif; ?>
                <div><?= $type ?> Leave</div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="progress-container">
            <div class="progress-label">Overall request Progress</div>
            <div class="progress-bar">
              <div class="progress-bar-inner" style="width:<?= $progress ?>%;"></div>
            </div>
            <div class="progress-percent"><?= $progress ?>%</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php foreach($leave_types as $type => $quota): 
  $used = $used_leaves[$type] ?? 0;
  $avail = $type === 'Unpaid' ? 0 : max(0, $quota - $used);
  // Pick colors
  $colorMap = [
    'Vacation'  => "'#36a2eb'",
    'Sick'      => "'#dc3545'",
    'Emergency' => "'#f39c12'",
    'Unpaid'    => "'#6c757d'"
  ];
  $showChart = ($type !== 'Unpaid');
?>
var ctx<?= $type ?> = document.getElementById('leaveCircle<?= $type ?>').getContext('2d');
<?php if($showChart): ?>
new Chart(ctx<?= $type ?>, {
    type: 'doughnut',
    data: {
      labels: ['Available','Used'],
      datasets: [{
        data: [<?= $avail ?>, <?= $used ?>],
        backgroundColor: [<?= $colorMap[$type] ?? "'#36a2eb'" ?>, '#e0e7ff'],
        borderWidth: 2
      }]
    },
    options: {
      cutout: '70%',
      plugins: {
        legend: {display: false},
        tooltip: {enabled: false}
      }
    }
});
<?php endif; ?>
<?php endforeach; ?>
</script>
</body>
</html>