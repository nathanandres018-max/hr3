<?php
session_start();
include_once("../connection.php");

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

// === ANTI-BYPASS: Role enforcement — only 'Regular' (employee) role allowed ===
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Regular') {
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

$fullname = $_SESSION['fullname'] ?? 'Employee';
$role = $_SESSION['role'] ?? 'employee';

// Terms acceptance logic
if (!isset($_SESSION['terms_accepted']) || !$_SESSION['terms_accepted']) {
    $showTermsModal = true;
} else {
    $showTermsModal = false;
}

// Handle terms acceptance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_terms'])) {
    $_SESSION['terms_accepted'] = true;
    header("Location: employee_dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Employee Dashboard - ViaHale TNVS HR3</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    * { transition: all 0.3s ease; box-sizing: border-box; }
    html, body { height: 100%; }
    body {
      font-family: 'QuickSand','Poppins',Arial,sans-serif;
      background: linear-gradient(135deg, #f5f7fa 0%, #fafbfc 100%);
      color: #22223b; font-size: 16px; margin: 0; padding: 0;
    }
    .wrapper { display: flex; min-height: 100vh; }

    /* === SIDEBAR === */
    .sidebar {
      background: linear-gradient(180deg, #181818ff 0%, #1a1a2e 100%);
      color: #fff; width: 220px; position: fixed; left: 0; top: 0;
      height: 100vh; z-index: 1040; overflow-y: auto;
      padding: 1rem 0.3rem; box-shadow: 2px 0 15px rgba(0,0,0,0.1);
    }
    .sidebar::-webkit-scrollbar { width: 6px; }
    .sidebar::-webkit-scrollbar-track { background: transparent; }
    .sidebar::-webkit-scrollbar-thumb { background: #9A66ff; border-radius: 3px; }
    .sidebar a, .sidebar button {
      color: #bfc7d1; background: none; border: none; font-size: 0.95rem;
      padding: 0.45rem 0.7rem; border-radius: 8px; display: flex;
      align-items: center; gap: 0.7rem; margin-bottom: 0.1rem;
      width: 100%; text-align: left; white-space: nowrap; cursor: pointer;
      transition: background 0.2s, color 0.2s, padding-left 0.2s;
    }
    .sidebar a.active, .sidebar a:hover, .sidebar button.active, .sidebar button:hover {
      background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
      color: #fff; padding-left: 1rem;
      box-shadow: 0 2px 8px rgba(154, 102, 255, 0.3);
    }
    .sidebar hr { border-top: 1px solid #232a43; margin: 0.7rem 0; }
    .sidebar h6 { font-size: 0.75rem; font-weight: 700; letter-spacing: 1px; color: #9A66ff; }
    .sidebar .nav-link ion-icon { font-size: 1.2rem; }

    /* === LAYOUT === */
    .content-wrapper { flex: 1; margin-left: 220px; display: flex; flex-direction: column; }
    .topbar {
      padding: 1.5rem 2rem; background: #fff; border-bottom: 2px solid #f0f0f0;
      box-shadow: 0 2px 8px rgba(140,140,200,0.05);
      display: flex; align-items: center; justify-content: space-between; gap: 2rem;
    }
    .topbar h3 {
      font-size: 1.8rem; font-weight: 800; margin: 0; color: #22223b;
      display: flex; align-items: center; gap: 0.8rem;
    }
    .topbar h3 ion-icon { font-size: 2rem; color: #9A66ff; }
    .topbar .profile { display: flex; align-items: center; gap: 1.2rem; }
    .topbar .profile-img {
      width: 45px; height: 45px; border-radius: 50%;
      object-fit: cover; border: 3px solid #9A66ff;
    }
    .topbar .profile-info { line-height: 1.1; }
    .topbar .profile-info strong { font-size: 1.08rem; font-weight: 600; color: #22223b; }
    .topbar .profile-info small { color: #9A66ff; font-size: 0.93rem; font-weight: 500; }
    .main-content { flex: 1; overflow-y: auto; padding: 2rem; }

    /* === STATS CARDS === */
    .stats-cards { display: flex; gap: 1.5rem; margin-bottom: 2rem; flex-wrap: wrap; }
    .stats-card {
      background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
      border-radius: 18px; box-shadow: 0 4px 15px rgba(140,140,200,0.08);
      flex: 1; padding: 1.5rem 1.2rem; text-align: center; min-width: 170px;
      display: flex; flex-direction: column; align-items: center; gap: 0.5rem;
      border: 1px solid #f0f0f0; transition: transform 0.2s, box-shadow 0.2s;
    }
    .stats-card:hover { transform: translateY(-4px); box-shadow: 0 8px 25px rgba(140,140,200,0.15); }
    .stats-card .icon {
      background: linear-gradient(135deg, #ede9fe 0%, #e0d4ff 100%);
      color: #4311a5; border-radius: 50%; width: 52px; height: 52px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.8rem; margin-bottom: 0.5rem;
    }
    .stats-card .label { font-size: 0.95rem; color: #6c757d; margin-bottom: 0.2rem; font-weight: 500; }
    .stats-card .value { font-size: 1.7rem; font-weight: 800; color: #22223b; }

    /* === DASHBOARD CARDS === */
    .dashboard-row { display: flex; gap: 1.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
    .dashboard-col {
      background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
      border-radius: 18px; box-shadow: 0 4px 15px rgba(140,140,200,0.08);
      padding: 1.5rem 1.2rem; flex: 1; min-width: 320px; margin-bottom: 0;
      display: flex; flex-direction: column; gap: 1rem; border: 1px solid #f0f0f0;
    }
    .dashboard-col h5 {
      font-size: 1.13rem; font-weight: 700; margin-bottom: 0.5rem; color: #22223b;
      display: flex; align-items: center; gap: 0.6rem;
    }
    .dashboard-col h5 ion-icon { color: #9A66ff; font-size: 1.3rem; }

    /* === TABLE === */
    .table { font-size: 0.95rem; color: #22223b; background: #fff; border-radius: 12px; overflow: hidden; }
    .table thead th {
      background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
      color: #fff; font-weight: 600; font-size: 0.9rem; border: none;
      text-align: center; padding: 0.8rem 0.6rem;
    }
    .table td { text-align: center; vertical-align: middle; border-color: #f0f0f0; padding: 0.7rem 0.6rem; }
    .table tbody tr:hover { background: #f8f9ff; }

    /* === STATUS BADGES === */
    .status-badge {
      display: inline-block; padding: 0.3rem 0.8rem; border-radius: 20px;
      font-size: 0.82rem; font-weight: 700;
    }
    .status-badge.approved, .status-badge.success { background: #dcfce7; color: #15803d; }
    .status-badge.pending { background: #fef3c7; color: #92400e; }
    .status-badge.rejected, .status-badge.danger { background: #fee2e2; color: #7f1d1d; }

    /* === BLURRED BACKGROUND FOR MODAL === */
    .blurred-bg {
      filter: blur(12px) brightness(0.9);
      pointer-events: none !important;
      user-select: none !important;
    }

    /* === MODAL === */
    .modal-content {
      border-radius: 18px; box-shadow: 0 6px 32px rgba(70,57,130,0.20);
      border: 1px solid #e0e7ff; background: #fff;
    }
    .modal-header {
      background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
      color: #fff; border-bottom: none; border-radius: 18px 18px 0 0; padding: 1.2rem 1.5rem;
    }
    .modal-title { font-size: 1.23rem; font-weight: 700; }
    .modal-body {
      background: #fafbfc; padding: 1.7rem 1.5rem;
      border-radius: 0 0 18px 18px; font-size: 1.02rem; color: #22223b;
      max-height: 65vh; overflow-y: auto;
    }
    .modal-footer { border-top: none; padding: 1.2rem; }
    .modal-backdrop-blur {
      position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
      background: rgba(33,30,70,0.24); z-index: 1050;
      backdrop-filter: blur(16px);
    }
    .btn-accept {
      background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
      color: white; border: none; border-radius: 12px;
      padding: 0.8rem 2rem; font-weight: 700; font-size: 1.05rem;
      transition: transform 0.2s, box-shadow 0.2s; width: 100%;
    }
    .btn-accept:hover { transform: translateY(-2px); color: white; box-shadow: 0 6px 20px rgba(154,102,255,0.3); }

    /* === MOBILE HAMBURGER BUTTON === */
    .mobile-menu-btn {
      display: none; position: fixed; top: 1rem; left: 1rem; z-index: 1060;
      background: linear-gradient(135deg, #9A66ff 0%, #4311a5 100%); color: #fff;
      border: none; border-radius: 12px; width: 44px; height: 44px;
      font-size: 1.5rem; cursor: pointer; align-items: center; justify-content: center;
      box-shadow: 0 4px 15px rgba(154,102,255,0.4); transition: all 0.3s ease;
    }
    .mobile-menu-btn:hover { transform: scale(1.05); box-shadow: 0 6px 20px rgba(154,102,255,0.5); }
    .mobile-menu-btn ion-icon { font-size: 1.4rem; }

    /* === SIDEBAR OVERLAY === */
    .sidebar-overlay {
      display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
      background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 1035;
      opacity: 0; transition: opacity 0.3s ease;
    }
    .sidebar-overlay.show { display: block; opacity: 1; }

    /* === SIDEBAR CLOSE BUTTON (mobile) === */
    .sidebar-close-btn {
      display: none; position: absolute; top: 0.8rem; right: 0.8rem;
      background: rgba(255,255,255,0.15); color: #fff; border: none; border-radius: 8px;
      width: 32px; height: 32px; font-size: 1.2rem; cursor: pointer;
      align-items: center; justify-content: center; z-index: 10; transition: background 0.2s;
    }
    .sidebar-close-btn:hover { background: rgba(255,255,255,0.25); }

    /* === RESPONSIVE === */
    @media (max-width: 1200px) { .sidebar { width: 180px; } .content-wrapper { margin-left: 180px; } .main-content { padding: 1.5rem 1rem; } }
    @media (max-width: 900px) {
      .mobile-menu-btn { display: flex; }
      .sidebar-close-btn { display: flex; }
      .sidebar {
        left: -280px; width: 260px;
        transition: left 0.35s cubic-bezier(0.4,0,0.2,1), box-shadow 0.35s ease;
        box-shadow: none;
      }
      .sidebar.show { left: 0; box-shadow: 4px 0 25px rgba(0,0,0,0.3); }
      .content-wrapper { margin-left: 0; }
      .main-content { padding: 1rem; }
      .topbar { flex-direction: column; align-items: flex-start; gap: 1rem; padding-left: 4.5rem; }
      .stats-cards { gap: 1rem; }
      .stats-card { min-width: 140px; padding: 1.2rem 1rem; }
    }
    @media (max-width: 700px) {
      .topbar h3 { font-size: 1.4rem; }
      .stats-cards { flex-direction: column; }
      .stats-card { min-width: 100%; }
      .dashboard-row { flex-direction: column; }
      .dashboard-col { min-width: 100%; }
      .main-content { padding: 0.8rem; }
    }
    @media (max-width: 500px) {
      .sidebar { width: 85vw; left: -85vw; }
      .sidebar.show { left: 0; }
      .main-content { padding: 0.5rem; }
      .topbar h3 { font-size: 1.2rem; } .topbar { padding: 1rem 0.8rem; padding-left: 4rem; }
      .stats-card .value { font-size: 1.4rem; }
      .stats-card .icon { width: 44px; height: 44px; font-size: 1.5rem; }
      .dashboard-col { padding: 1rem; border-radius: 14px; }
    }
    @media (min-width: 1400px) { .sidebar { width: 260px; padding: 2rem 1rem; } .content-wrapper { margin-left: 260px; } .main-content { padding: 2.5rem 2.5rem; } }
  </style>
</head>
<body>
<div class="wrapper">
  <!-- Mobile Hamburger Button -->
  <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">
    <ion-icon name="menu-outline"></ion-icon>
  </button>

  <!-- Sidebar Overlay -->
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <!-- Sidebar -->
  <div class="sidebar d-flex flex-column justify-content-between shadow-sm border-end" id="sidebar">
    <button class="sidebar-close-btn" id="sidebarCloseBtn" aria-label="Close menu">
      <ion-icon name="close-outline"></ion-icon>
    </button>
    <div>
      <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
        <img src="../assets/images/image.png" class="img-fluid" style="height:55px" alt="Logo">
      </div>
      <div class="mb-4">
        <nav class="nav flex-column">
          <a class="nav-link active" href="../employee/employee_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
        </nav>
      </div>
      <div class="mb-4">
        <h6 class="text-uppercase px-2 mb-2">Time & Attendance</h6>
        <nav class="nav flex-column">
          <a class="nav-link" href="../employee/attendance_with_liveness.php"><ion-icon name="camera-outline"></ion-icon>Clock In/Out</a>
          <a class="nav-link" href="../employee/attendance_logs.php"><ion-icon name="list-outline"></ion-icon>My Attendance Logs</a>
        </nav>
      </div>
      <div class="mb-4">
        <h6 class="text-uppercase px-2 mb-2">Leave Management</h6>
        <nav class="nav flex-column">
          <a class="nav-link" href="../employee/leave_request.php"><ion-icon name="calendar-outline"></ion-icon>Request Leave</a>
          <a class="nav-link" href="../employee/leave_history.php"><ion-icon name="calendar-outline"></ion-icon>Leave History</a>
        </nav>
      </div>
      <div class="mb-4">
        <h6 class="text-uppercase px-2 mb-2">Claims & Reimbursement</h6>
        <nav class="nav flex-column">
          <a class="nav-link" href="../employee/claim_submissions.php"><ion-icon name="create-outline"></ion-icon>File a Claim</a>
          <a class="nav-link" href="../employee/policy_checker.php"><ion-icon name="shield-checkmark-outline"></ion-icon>Policy Checker</a>
        </nav>
      </div>
    </div>
    <div class="p-3 border-top mb-2">
      <a class="nav-link text-danger" href="../logout.php"><ion-icon name="log-out-outline"></ion-icon>Logout</a>
    </div>
  </div>

  <!-- Main Content Wrapper -->
  <div class="content-wrapper">
    <!-- Top Bar -->
    <div class="topbar">
      <div>
        <h3><ion-icon name="home-outline"></ion-icon> Employee Dashboard</h3>
        <small class="text-muted">Welcome back, <?= htmlspecialchars($fullname) ?>!</small>
      </div>
      <div class="profile">
        <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
        <div class="profile-info">
          <strong><?= htmlspecialchars($fullname) ?></strong><br>
          <small><?= htmlspecialchars(ucfirst($role)) ?></small>
        </div>
      </div>
    </div>

    <!-- Main Content -->
    <div class="main-content <?php if ($showTermsModal) echo 'blurred-bg'; ?>" id="mainDashboardContent">

      <!-- Stats Cards -->
      <div class="stats-cards">
        <div class="stats-card">
          <div class="icon"><ion-icon name="timer-outline"></ion-icon></div>
          <div class="label">Attendance (This Month)</div>
          <div class="value">21/22</div>
        </div>
        <div class="stats-card">
          <div class="icon"><ion-icon name="calendar-outline"></ion-icon></div>
          <div class="label">Leave Balance</div>
          <div class="value">5</div>
        </div>
        <div class="stats-card">
          <div class="icon"><ion-icon name="document-text-outline"></ion-icon></div>
          <div class="label">Pending Timesheets</div>
          <div class="value">1</div>
        </div>
        <div class="stats-card">
          <div class="icon"><ion-icon name="cash-outline"></ion-icon></div>
          <div class="label">Pending Claims</div>
          <div class="value">2</div>
        </div>
      </div>

      <!-- Charts Row -->
      <div class="dashboard-row">
        <div class="dashboard-col" style="flex:1.7">
          <h5><ion-icon name="bar-chart-outline"></ion-icon> Attendance Overview</h5>
          <div style="height:260px;"><canvas id="barChart"></canvas></div>
        </div>
        <div class="dashboard-col" style="flex:1">
          <h5><ion-icon name="pie-chart-outline"></ion-icon> Request Status</h5>
          <div style="height:260px;"><canvas id="pieChart"></canvas></div>
        </div>
      </div>

      <!-- Recent Activity -->
      <div class="dashboard-col" style="margin-bottom: 1.5rem;">
        <h5><ion-icon name="time-outline"></ion-icon> Recent Activity</h5>
        <div class="table-responsive">
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Date</th>
                <th>Activity</th>
                <th>Module</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>2025-08-29</td>
                <td>Clocked In</td>
                <td>Time & Attendance</td>
                <td><span class="status-badge success">Success</span></td>
              </tr>
              <tr>
                <td>2025-08-29</td>
                <td>Submitted Leave Request</td>
                <td>Leave Management</td>
                <td><span class="status-badge pending">Pending</span></td>
              </tr>
              <tr>
                <td>2025-08-28</td>
                <td>Submitted Timesheet</td>
                <td>Timesheet Management</td>
                <td><span class="status-badge success">Success</span></td>
              </tr>
              <tr>
                <td>2025-08-28</td>
                <td>Filed Expense Claim</td>
                <td>Claims & Reimbursement</td>
                <td><span class="status-badge pending">Pending</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>

<?php if ($showTermsModal): ?>
  <div class="modal-backdrop-blur"></div>
  <!-- Terms & Conditions Modal -->
  <div class="modal fade show" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" style="display:block;z-index:1060;" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <form method="post" class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="termsModalLabel">Terms and Conditions</h5>
        </div>
        <div class="modal-body">
          <h6>Welcome to the ViaHale HR System. By accessing and using this system, you agree to comply with and be bound by the following Terms and Conditions. Please read them carefully.</h6>
          <hr>
          <strong>1. Compliance With Philippine Laws</strong>
          <ul>
            <li><em>Labor Code of the Philippines (PD 442)</em>: Governing working hours, attendance, rest periods, leaves, timesheet management, and related employment standards.</li>
            <li><em>Special Leave Laws</em>: Including RA 9710 (Magna Carta of Women), RA 9262 (Anti-Violence Against Women and Children), RA 8187 (Paternity Leave Act), RA 11210 (Expanded Maternity Leave Law), and RA 8972 (Solo Parents' Welfare Act).</li>
            <li><em>Data Privacy Act of 2012 (RA 10173)</em>: Protecting all personal information you provide and ensuring its confidentiality, integrity, and availability.</li>
            <li><em>BIR Regulations</em>: Governing claims and reimbursements, requiring valid proof and documentation for all transactions.</li>
            <li><em>Other relevant national and local labor and employment regulations</em>.</li>
          </ul>
          <strong>2. User Responsibilities</strong>
          <ul>
            <li>You agree to provide accurate, complete, and current information in all system modules, including attendance logs, leave requests, timesheets, and claims.</li>
            <li>You are responsible for the security of your account credentials and all activities conducted under your account.</li>
            <li>Misrepresentation, falsification of data, or unauthorized use of the system may result in disciplinary action or legal consequences.</li>
          </ul>
          <strong>3. System Usage</strong>
          <ul>
            <li>The system is to be used solely for authorized HR processes such as timekeeping, leave management, shift scheduling, timesheet submission, and claims/reimbursements.</li>
            <li>All actions in the system are logged and may be subject to audit and review by authorized personnel.</li>
          </ul>
          <strong>4. Data Privacy and Security</strong>
          <ul>
            <li>All personal and employment data collected and processed through this system will be handled in accordance with the Data Privacy Act of 2012.</li>
            <li>Your information will only be used for legitimate HR, payroll, and compliance purposes.</li>
            <li>The company implements reasonable and appropriate security measures to protect your data from unauthorized access, alteration, or disclosure.</li>
          </ul>
          <strong>5. Attendance, Leaves, and Timesheet</strong>
          <ul>
            <li>Attendance, leaves, and timesheet entries must be true and accurate. Any errors should be reported promptly to HR or your immediate supervisor.</li>
            <li>Leave approvals, balances, and entitlements are governed by company policy and the applicable laws mentioned above.</li>
            <li>Unauthorized absences or submission of false entries may result in corrective action.</li>
          </ul>
          <strong>6. Claims and Reimbursement</strong>
          <ul>
            <li>All claims and reimbursement requests must comply with company policy and BIR regulations. Valid receipts and supporting documents are required for processing.</li>
            <li>The system may employ automated checks (including AI) to verify compliance with reimbursement policies. Non-compliant claims may be denied or flagged for review.</li>
          </ul>
          <strong>7. Modifications to the Terms</strong>
          <ul>
            <li>The company reserves the right to update or amend these Terms and Conditions at any time. Notice of changes will be provided through the system or official communication channels.</li>
          </ul>
          <strong>8. Acceptance</strong>
          <ul>
            <li>By using this system, you acknowledge that you have read, understood, and agreed to these Terms and Conditions.</li>
            <li>If you do not agree with any part of these Terms, please discontinue use of the system and contact your HR administrator for assistance.</li>
          </ul>
          <hr>
          <div class="mb-2"><small>For questions or concerns regarding these Terms and Conditions, please contact the HR Department.</small></div>
        </div>
        <div class="modal-footer">
          <button type="submit" name="accept_terms" class="btn-accept">I Accept</button>
        </div>
      </form>
    </div>
  </div>
  <script>
    document.body.classList.add('modal-open');
    document.body.style.overflow = 'hidden';
  </script>
<?php endif; ?>

<script>
// ===== CHART.JS (Dummy Data) =====
if (!<?= json_encode($showTermsModal) ?>) {
  var barCtx = document.getElementById('barChart').getContext('2d');
  new Chart(barCtx, {
    type: 'bar',
    data: {
      labels: ['Present', 'Absent', 'Late', 'On Leave'],
      datasets: [{ label: 'Days', data: [21, 1, 2, 1], backgroundColor: ['#9A66ff', '#ef4444', '#f59e0b', '#0284c7'], borderRadius: 8 }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { stepSize: 5 } }, x: { grid: { display: false } } }
    }
  });

  var pieCtx = document.getElementById('pieChart').getContext('2d');
  new Chart(pieCtx, {
    type: 'doughnut',
    data: {
      labels: ['Approved 70%', 'Pending 20%', 'Rejected 10%'],
      datasets: [{ data: [70, 20, 10], backgroundColor: ['#9A66ff', '#f59e0b', '#ef4444'], borderWidth: 0 }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '60%',
      plugins: { legend: { position: 'bottom', labels: { padding: 15, font: { size: 12, weight: 600 } } } },
      animation: { animateScale: true }
    }
  });
}

// ===== MOBILE SIDEBAR TOGGLE =====
(function() {
  var menuBtn = document.getElementById('mobileMenuBtn');
  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('sidebarOverlay');
  var closeBtn = document.getElementById('sidebarCloseBtn');
  function openSidebar() { if(sidebar) sidebar.classList.add('show'); if(overlay) overlay.classList.add('show'); document.body.style.overflow='hidden'; }
  function closeSidebar() { if(sidebar) sidebar.classList.remove('show'); if(overlay) overlay.classList.remove('show'); document.body.style.overflow=''; }
  if(menuBtn) menuBtn.addEventListener('click', openSidebar);
  if(overlay) overlay.addEventListener('click', closeSidebar);
  if(closeBtn) closeBtn.addEventListener('click', closeSidebar);
  if(sidebar) { sidebar.querySelectorAll('a.nav-link').forEach(function(link) { link.addEventListener('click', closeSidebar); }); }
  document.addEventListener('keydown', function(e) { if(e.key === 'Escape') closeSidebar(); });
  var touchStartX = 0;
  if(sidebar) {
    sidebar.addEventListener('touchstart', function(e) { touchStartX = e.touches[0].clientX; }, {passive:true});
    sidebar.addEventListener('touchend', function(e) { var diff = e.changedTouches[0].clientX - touchStartX; if(diff < -60) closeSidebar(); }, {passive:true});
  }
})();

// ===== SESSION INACTIVITY TIMEOUT (15 minutes, warn at 13 min) =====
(function() {
  var SESSION_TIMEOUT = 15 * 60 * 1000;
  var WARN_BEFORE    = 2 * 60 * 1000;
  var idleTimer, warnTimer;
  var warned = false;
  function resetTimers() {
    warned = false; clearTimeout(idleTimer); clearTimeout(warnTimer); hideWarning();
    warnTimer = setTimeout(showWarning, SESSION_TIMEOUT - WARN_BEFORE);
    idleTimer = setTimeout(logoutNow, SESSION_TIMEOUT);
  }
  function showWarning() { if (warned) return; warned = true; var el = document.getElementById('sessionTimeoutWarning'); if (el) el.style.display = 'flex'; }
  function hideWarning() { var el = document.getElementById('sessionTimeoutWarning'); if (el) el.style.display = 'none'; }
  function logoutNow() { window.location.href = '../login.php?timeout=1'; }
  var banner = document.createElement('div'); banner.id = 'sessionTimeoutWarning';
  banner.style.cssText = 'display:none;position:fixed;top:0;left:0;width:100%;z-index:9999;background:linear-gradient(90deg,#f59e0b,#d97706);color:#fff;padding:0.9rem 1.5rem;align-items:center;justify-content:center;gap:1rem;font-weight:600;font-size:0.97rem;box-shadow:0 4px 16px rgba(0,0,0,0.15);';
  banner.innerHTML = '<ion-icon name="alert-circle-outline" style="font-size:1.4rem;"></ion-icon><span>Your session will expire in <strong>2 minutes</strong> due to inactivity.</span><button onclick="this.parentElement.style.display=\'none\'" style="background:rgba(255,255,255,0.2);border:none;color:#fff;border-radius:8px;padding:0.4rem 1rem;font-weight:700;cursor:pointer;">Dismiss</button>';
  document.body.appendChild(banner);
  ['mousemove','mousedown','keydown','touchstart','scroll','click'].forEach(function(evt) { document.addEventListener(evt, resetTimers, {passive:true}); });
  resetTimers();
})();
</script>
</body>
</html>
