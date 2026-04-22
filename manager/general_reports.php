<?php
session_start();
require_once("../includes/db.php");

// === ANTI-BYPASS: Prevent browser caching of protected pages ===
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Session timeout handling (15 minutes inactivity)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 900)) {
    session_unset();
    session_destroy();
    header("Location: ../login.php?timeout=1");
    exit();
}

// === ANTI-BYPASS: Require logged-in user ===
if (!isset($_SESSION['username']) || empty($_SESSION['username'])) {
    header("Location: ../login.php");
    exit();
}

// === ANTI-BYPASS: Role enforcement — only 'HR Manager' role allowed ===
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'HR Manager') {
    session_unset();
    session_destroy();
    header("Location: ../login.php?unauthorized=1");
    exit();
}

// === ANTI-BYPASS: Session fingerprint — bind session to browser user-agent ===
$currentFingerprint = md5($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
if (!isset($_SESSION['fingerprint'])) {
    $_SESSION['fingerprint'] = $currentFingerprint;
} elseif ($_SESSION['fingerprint'] !== $currentFingerprint) {
    session_unset();
    session_destroy();
    header("Location: ../login.php?unauthorized=1");
    exit();
}

// === ANTI-BYPASS: Generate CSRF token for forms ===
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$_SESSION['last_activity'] = time();
$fullname = $_SESSION['fullname'] ?? 'HR Manager';
$role = $_SESSION['role'] ?? 'HR Manager';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>General Reports - HR Manager | ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; background: #fafbfc; color: #22223b; font-size: 16px; }
        .sidebar { background: #181818ff; color: #fff; min-height: 100vh; width: 220px; position: fixed; left: 0; top: 0; z-index: 1040; padding: 1rem 0.3rem;}
        .sidebar a, .sidebar button { color: #bfc7d1; background: none; border: none; font-size: 0.95rem; padding: 0.45rem 0.7rem; border-radius: 8px; display: flex; align-items: center; gap: 0.7rem; margin-bottom: 0.1rem; transition: background 0.2s, color 0.2s; width: 100%; text-align: left;}
        .sidebar a.active, .sidebar a:hover { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: #fff;}
        .sidebar hr { border-top: 1px solid #232a43; margin: 0.7rem 0;}
        .sidebar .nav-link ion-icon { font-size: 1.2rem; margin-right: 0.3rem;}
        .main-content { margin-left: 220px; padding: 2rem;}
        .dashboard-title { font-size: 1.7rem; font-weight: 700; margin-bottom: 1.2rem; color: #22223b;}
        .dashboard-col { background: #fff; border-radius: 18px; box-shadow: 0 2px 8px rgba(140,140,200,0.07); padding: 1.5rem 1.2rem; margin-bottom: 1rem; border: 1px solid #f0f0f0;}
        .dashboard-col h5 { font-size: 1.13rem; font-weight: 600; margin-bottom: 1.1rem; color: #22223b;}
        .table { font-size: 0.98rem; color: #22223b;}
        .table th { color: #6c757d; font-weight: 600;}
        .table td { background: transparent;}
        .btn-primary { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); border: none; }
        .form-label { font-weight: 600; color: #4311a5; }
        .status-badge { padding: 3px 12px; border-radius: 12px; font-size: 0.85rem; font-weight: 600; display: inline-block;}
        .status-badge.success, .status-badge.approved { background: #dbeafe; color: #2563eb;}
        .status-badge.pending { background: #fff3cd; color: #856404;}
        .status-badge.danger, .status-badge.rejected { background: #fee2e2; color: #b91c1c;}
        .profile { display: flex; align-items: center; gap: 1.2rem; }
        .profile-img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 2px solid #e0e7ff; }
        .profile-info { line-height: 1.1; }
        .profile-info strong { font-size: 1.08rem; font-weight: 600; color: #22223b; }
        .profile-info small { color: #6c757d; font-size: 0.93rem; }
        .chart-container { min-height: 220px; height: 220px; }
        @media (max-width: 1200px) { .main-content { padding: 1rem; } .sidebar { width: 180px; } .main-content { margin-left: 180px; } }
        @media (max-width: 900px) { .sidebar { left: -220px; width: 180px; } .sidebar.show { left: 0; } .main-content { margin-left: 0; padding: 1rem; } }
        @media (max-width: 700px) { .dashboard-title { font-size: 1.1rem; } .main-content { padding: 0.7rem; } .sidebar { width: 100vw; left: -100vw; } .sidebar.show { left: 0; } }
        @media (max-width: 500px) { .sidebar { width: 100vw; left: -100vw; } .sidebar.show { left: 0; } .main-content { padding: 0.1rem; } }
        @media (min-width: 1400px) { .sidebar { width: 260px; } .main-content { margin-left: 260px; padding: 2rem; } }
    </style>
</head>
<body>
<div class="sidenav col-auto p-0">
  <div class="sidebar d-flex flex-column justify-content-between shadow-sm border-end">
    <div>
      <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
        <img src="../assets/images/image.png" class="img-fluid me-2" style="height:55px;" alt="Logo">
      </div>
      <div class="mb-4">
        <h6 class="text-uppercase mb-2">Dashboard</h6>
        <nav class="nav flex-column">
          <a class="nav-link" href="../manager/manager_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
        </nav>
      </div>
      <div class="mb-4">
        <h6 class="text-uppercase px-2 mb-2">Timesheet Management</h6>
        <nav class="nav flex-column">
          <a class="nav-link" href="../manager/timesheet_review.php"><ion-icon name="checkmark-done-outline"></ion-icon>Review & Approval</a>
          <a class="nav-link" href="../manager/timesheet_reports.php"><ion-icon name="document-text-outline"></ion-icon>Timesheet Reports</a>
        </nav>
      </div>
      <div class="mb-4">
        <h6 class="text-uppercase px-2 mb-2">Leave Management</h6>
        <nav class="nav flex-column">
          <a class="nav-link" href="../manager/leave_requests.php"><ion-icon name="calendar-outline"></ion-icon>Leave Requests</a>
          <a class="nav-link" href="../manager/leave_balance.php"><ion-icon name="calendar-outline"></ion-icon>Leave Balance</a>
          <a class="nav-link" href="../manager/leave_history.php"><ion-icon name="calendar-outline"></ion-icon>Leave History</a>
          <a class="nav-link" href="../manager/leave_calendar.php"><ion-icon name="calendar-outline"></ion-icon>Leave Calendar</a>
        </nav>
      </div>
      <div class="mb-4">
        <h6 class="text-uppercase px-2 mb-2">Policy & Reports</h6>
        <nav class="nav flex-column">
          <a class="nav-link active" href="general_reports.php"><ion-icon name="stats-chart-outline"></ion-icon>General Reports</a>
          <a class="nav-link" href="policy_reports.php"><ion-icon name="settings-outline"></ion-icon>Policy Management</a>
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
<div class="main-content">
  <div class="topbar d-flex justify-content-between align-items-center mb-3">
    <span class="dashboard-title">General Reports</span>
    <div class="profile">
      <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
      <div class="profile-info">
        <strong><?= htmlspecialchars($fullname) ?></strong><br>
        <small><?= htmlspecialchars(ucfirst($role)) ?></small>
      </div>
    </div>
  </div>
  <div class="dashboard-col">
    <h5>Generate HR Reports</h5>
    <form class="mb-4">
      <div class="row g-3">
        <div class="col-md-4">
          <label for="reportType" class="form-label">Report Type</label>
          <select id="reportType" class="form-select">
            <option value="">Select</option>
            <option>Leave Requests</option>
            <option>Attendance</option>
            <option>Claims</option>
            <option>Timesheets</option>
          </select>
        </div>
        <div class="col-md-4">
          <label for="reportDateFrom" class="form-label">Date From</label>
          <input type="date" id="reportDateFrom" class="form-control" placeholder="From">
        </div>
        <div class="col-md-4">
          <label for="reportDateTo" class="form-label">Date To</label>
          <input type="date" id="reportDateTo" class="form-control" placeholder="To">
        </div>
      </div>
      <button type="submit" class="btn btn-primary mt-4 w-100">Generate Report</button>
    </form>
    <div>
      <h6>Recent Reports</h6>
      <ul class="list-group mb-3">
        <li class="list-group-item">Leave Requests (Sep) <span class="badge bg-success ms-2">Downloaded</span></li>
        <li class="list-group-item">Attendance Summary <span class="badge bg-primary ms-2">Viewed</span></li>
        <li class="list-group-item">Claims Audit <span class="badge bg-warning ms-2">Pending</span></li>
      </ul>
      <div class="chart-container">
        <canvas id="reportChart"></canvas>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Chart.js demo for General Reports (improved version)
  document.addEventListener("DOMContentLoaded", function () {
    const ctx = document.getElementById('reportChart').getContext('2d');
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: ['Leave Requests', 'Attendance', 'Claims', 'Timesheets'],
        datasets: [{
          label: 'Records',
          data: [18, 32, 12, 27],
          backgroundColor: [
            '#9A66ff', '#66b3ff', '#ffc107', '#4311a5'
          ],
          borderRadius: 8,
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          title: {
            display: true,
            text: 'HR Records by Category'
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { stepSize: 5 }
          }
        }
      }
    });
  });
</script>
</body>
</html>