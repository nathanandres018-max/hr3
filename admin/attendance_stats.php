<?php
// filepath: admin/attendance_stats.php
// Attendance Statistics UI — unified sidebar/topbar layout with enhanced modern design
// FIXED: Chart boxes stay in place without moving

declare(strict_types=1);
session_start();
require_once(__DIR__ . '/../connection.php');

// Session timeout handling
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 300)) {
    session_unset();
    session_destroy();
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
    header("Location: ../login.php?timeout=1");
    exit();
}
$_SESSION['last_activity'] = time();

$fullname = $_SESSION['fullname'] ?? 'Administrator';
$role = $_SESSION['role'] ?? 'admin';

// fetch enrolled employees for filter
$enrolledEmployees = [];
if (isset($conn) && ($conn instanceof mysqli)) {
    $sql = "SELECT employee_id, fullname, profile_photo FROM employees WHERE face_enrolled = 1 ORDER BY fullname ASC";
    if ($res = $conn->query($sql)) {
        while ($r = $res->fetch_assoc()) $enrolledEmployees[] = $r;
        $res->free();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Attendance Statistics — HR3</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
  <style>
    * { transition: all 0.3s ease; }
    html, body { height: 100%; }
    body { 
      font-family: 'QuickSand','Poppins',Arial,sans-serif; 
      background: linear-gradient(135deg, #f5f7fa 0%, #fafbfc 100%);
      color: #22223b; 
      font-size: 16px;
      margin: 0;
      padding: 0;
    }

    .wrapper { display: flex; min-height: 100vh; }

    .sidebar { 
      background: linear-gradient(180deg, #181818ff 0%, #1a1a2e 100%);
      color: #fff; 
      width: 220px; 
      position: fixed; 
      left: 0;
      top: 0;
      height: 100vh; 
      z-index: 1040;
      overflow-y: auto; 
      padding: 1rem 0.3rem; 
      box-shadow: 2px 0 15px rgba(0,0,0,0.1);
    }

    .sidebar::-webkit-scrollbar { width: 6px; }
    .sidebar::-webkit-scrollbar-track { background: transparent; }
    .sidebar::-webkit-scrollbar-thumb { background: #9A66ff; border-radius: 3px; }

    .sidebar a, .sidebar button { 
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
      width: 100%; 
      text-align: left; 
      white-space: nowrap; 
      cursor: pointer;
    }

    .sidebar a.active, .sidebar a:hover, .sidebar button.active, .sidebar button:hover { 
      background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
      color: #fff; 
      padding-left: 1rem;
      box-shadow: 0 2px 8px rgba(154, 102, 255, 0.3);
    }

    .sidebar hr { border-top: 1px solid #232a43; margin: 0.7rem 0; }
    .sidebar h6 { font-size: 0.75rem; font-weight: 700; letter-spacing: 1px; color: #9A66ff; }
    .sidebar .nav-link ion-icon { font-size: 1.2rem; }

    .content-wrapper { 
      flex: 1; 
      margin-left: 220px; 
      display: flex; 
      flex-direction: column;
    }

    .topbar { 
      padding: 1.5rem 2rem; 
      background: #fff; 
      border-bottom: 2px solid #f0f0f0; 
      box-shadow: 0 2px 8px rgba(140,140,200,0.05); 
      display: flex; 
      align-items: center; 
      justify-content: space-between; 
      gap: 2rem;
    }

    .dashboard-title { 
      font-family: 'QuickSand','Poppins',Arial,sans-serif;
      font-size: 2rem; 
      font-weight: 800; 
      margin: 0; 
      color: #22223b;
      letter-spacing: -0.5px;
    }

    .topbar .profile { 
      display: flex; 
      align-items: center; 
      gap: 1.2rem;
    }

    .topbar .profile-img { 
      width: 45px; 
      height: 45px; 
      border-radius: 50%; 
      object-fit: cover; 
      border: 3px solid #9A66ff;
    }

    .topbar .profile-info { line-height: 1.1; }
    .topbar .profile-info strong { font-size: 1.08rem; font-weight: 600; color: #22223b; }
    .topbar .profile-info small { color: #9A66ff; font-size: 0.93rem; font-weight: 500; }

    .main-content { 
      flex: 1; 
      overflow-y: auto; 
      padding: 2rem;
    }

    .card { 
      border-radius: 18px; 
      box-shadow: 0 4px 15px rgba(140,140,200,0.08); 
      border: 1px solid #f0f0f0;
      background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
    }

    .card-header { 
      background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
      color: white; 
      border-radius: 18px 18px 0 0; 
      padding: 1.5rem; 
      border: none; 
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 0.8rem;
      font-size: 1.15rem;
    }

    .card-body { padding: 1.5rem; }

    .filters-section {
      background: #f8f9ff;
      border: 1px solid #e0e7ff;
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 1.5rem;
    }

    .filter-group {
      display: flex;
      align-items: flex-end;
      gap: 1rem;
      flex-wrap: wrap;
    }

    .filter-item {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    .filter-item label {
      font-weight: 600;
      color: #22223b;
      margin: 0;
      font-size: 0.9rem;
    }

    .form-select, .form-control {
      border-radius: 8px;
      border: 1px solid #e0e7ff;
      padding: 0.65rem 0.9rem;
      background: #fff;
      color: #22223b;
      font-size: 0.95rem;
    }

    .form-select:focus, .form-control:focus {
      border-color: #9A66ff;
      box-shadow: 0 0 0 0.2rem rgba(154, 102, 255, 0.15);
      outline: none;
    }

    .btn {
      border: none;
      border-radius: 8px;
      font-weight: 600;
      transition: all 0.2s ease;
      padding: 0.65rem 1.2rem;
      font-size: 0.95rem;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }

    .btn-primary {
      background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
      color: white;
    }

    .btn-primary:hover {
      background: linear-gradient(90deg, #8654e0 0%, #360090 100%);
      transform: translateY(-2px);
      color: white;
    }

    .btn-outline-secondary {
      border: 1.5px solid #9A66ff;
      color: #9A66ff;
      background: transparent;
    }

    .btn-outline-secondary:hover {
      background: #9A66ff;
      color: white;
      transform: translateY(-2px);
    }

    .btn-success {
      background: #10b981;
      color: white;
    }

    .btn-success:hover {
      background: #059669;
      transform: translateY(-2px);
    }

    .btn-sm {
      padding: 0.5rem 1rem;
      font-size: 0.85rem;
    }

    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none !important;
    }

    .summary-cards { 
      display: grid; 
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 1rem; 
      margin-bottom: 1.5rem;
    }

    .summary-card {
      background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
      border: 1px solid #e0e7ff;
      border-left: 5px solid #9A66ff;
      border-radius: 10px;
      padding: 1.2rem;
      text-align: center;
    }

    .summary-card label {
      font-size: 0.85rem;
      color: #6c757d;
      font-weight: 600;
      margin-bottom: 0.5rem;
      display: block;
    }

    .summary-card .value {
      font-size: 1.8rem;
      font-weight: 800;
      color: #22223b;
    }

    /* FIXED CHART BOXES - NO MOVEMENT */
    .chart-wrap { 
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 1.5rem; 
      margin-bottom: 1.5rem;
    }

    .chart-box { 
      background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
      padding: 1.5rem; 
      border-radius: 12px; 
      box-shadow: 0 4px 15px rgba(140,140,200,0.08);
      border: 1px solid #f0f0f0;
      display: flex;
      flex-direction: column;
      height: 400px;
    }

    .chart-box h6 {
      font-weight: 700;
      color: #22223b;
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 0.6rem;
      font-size: 1rem;
      flex-shrink: 0;
    }

    .chart-box-container {
      flex: 1;
      position: relative;
      min-height: 320px;
      overflow: hidden;
    }

    .chart-box canvas {
      width: 100% !important;
      height: 100% !important;
      display: block;
    }

    .table-wrap {
      max-height: 56vh;
      overflow: auto;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(140,140,200,0.05);
    }

    .table { 
      font-size: 0.95rem; 
      color: #22223b; 
      margin-bottom: 0;
    }

    .table th { 
      color: #6c757d; 
      font-weight: 700; 
      border: none; 
      background: #f9f9fc; 
      padding: 1.2rem 1rem;
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      position: sticky;
      top: 0;
      z-index: 10;
    }

    .table td { 
      border-bottom: 1px solid #e8e8f0; 
      padding: 1rem; 
      vertical-align: middle;
    }

    .table tbody tr { transition: all 0.2s ease; }
    .table tbody tr:hover { background: #f8f9ff; }

    .irregular-late {
      background: linear-gradient(135deg, #fff4e5 0%, #fffbf0 100%);
      border-left: 3px solid #f59e0b;
    }

    .irregular-early {
      background: linear-gradient(135deg, #fff4f6 0%, #fffbf0 100%);
      border-left: 3px solid #ec4899;
    }

    .irregular-missing {
      background: linear-gradient(135deg, #fff9db 0%, #fffbf0 100%);
      border-left: 3px solid #eab308;
    }

    /* RESPONSIVE WITHOUT MOVING */
    @media (max-width: 1200px) {
      .sidebar { width: 180px; }
      .content-wrapper { margin-left: 180px; }
      .main-content { padding: 1.5rem 1rem; }
      
      .chart-wrap { 
        grid-template-columns: repeat(2, 1fr);
        gap: 1.2rem;
      }

      .chart-box { 
        height: 380px;
      }

      .chart-box-container {
        min-height: 300px;
      }
    }

    @media (max-width: 900px) {
      .sidebar { left: -220px; width: 220px; }
      .sidebar.show { left: 0; }
      .content-wrapper { margin-left: 0; }
      .main-content { padding: 1rem; }
      .topbar { padding: 1rem 1.5rem; flex-direction: column; gap: 1rem; align-items: flex-start; }
      .filter-group { flex-direction: column; }
      .filter-group > * { width: 100%; }
      .summary-cards { grid-template-columns: repeat(2, 1fr); }
      
      .chart-wrap { 
        grid-template-columns: 1fr;
        gap: 1.2rem;
      }

      .chart-box { 
        height: 360px;
      }

      .chart-box h6 {
        font-size: 0.95rem;
      }

      .chart-box-container {
        min-height: 280px;
      }
    }

    @media (max-width: 700px) {
      .dashboard-title { font-size: 1.4rem; }
      .main-content { padding: 1rem 0.8rem; }
      .sidebar { width: 100%; left: -100%; }
      .sidebar.show { left: 0; }
      .summary-cards { grid-template-columns: 1fr; }
      
      .chart-wrap { 
        grid-template-columns: 1fr;
        gap: 1rem;
      }

      .chart-box { 
        height: 340px;
        padding: 1.2rem;
      }

      .chart-box h6 {
        font-size: 0.9rem;
        margin-bottom: 0.8rem;
      }

      .chart-box-container {
        min-height: 260px;
      }

      .table { font-size: 0.85rem; }
      .table th, .table td { padding: 0.7rem 0.5rem; }
    }

    @media (max-width: 500px) {
      .sidebar { width: 100%; left: -100%; }
      .sidebar.show { left: 0; }
      .main-content { padding: 0.8rem 0.5rem; }
      .dashboard-title { font-size: 1.2rem; }
      .topbar { padding: 1rem 0.8rem; }
      
      .chart-wrap { 
        grid-template-columns: 1fr;
        gap: 0.8rem;
      }

      .chart-box { 
        height: 320px;
        padding: 1rem;
      }

      .chart-box h6 {
        font-size: 0.85rem;
        margin-bottom: 0.6rem;
      }

      .chart-box-container {
        min-height: 240px;
      }

      .btn { padding: 0.5rem 0.8rem; font-size: 0.8rem; }
      .btn-sm { padding: 0.4rem 0.7rem; font-size: 0.75rem; }
      .table th, .table td { padding: 0.5rem 0.3rem; font-size: 0.75rem; }
      .summary-card { padding: 0.8rem; }
      .summary-card label { font-size: 0.75rem; }
      .summary-card .value { font-size: 1.4rem; }
    }

    @media (min-width: 1400px) {
      .sidebar { width: 260px; padding: 2rem 1rem; }
      .content-wrapper { margin-left: 260px; }
      .main-content { padding: 2.5rem 2.5rem; }
      
      .chart-wrap { 
        grid-template-columns: repeat(2, 1fr);
        gap: 2rem;
      }

      .chart-box { 
        height: 420px;
      }

      .chart-box-container {
        min-height: 340px;
      }
    }
  </style>
</head>
<body>
<div class="wrapper">
  <!-- Sidebar -->
  <div class="sidebar d-flex flex-column justify-content-between shadow-sm border-end">
    <div>
      <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
        <img src="../assets/images/image.png" class="img-fluid" style="height:55px" alt="Logo">
      </div>
      <div class="mb-4">
        <nav class="nav flex-column">
          <a class="nav-link" href="../admin/admin_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
        </nav>
      </div>
      <div class="mb-4">
        <h6 class="text-uppercase px-2 mb-2">Time & Attendance</h6>
        <nav class="nav flex-column">
          <a class="nav-link" href="attendance_logs.php"><ion-icon name="list-outline"></ion-icon>Attendance Logs</a>
          <a class="nav-link active" href="attendance_stats.php"><ion-icon name="stats-chart-outline"></ion-icon>Attendance Statistics</a>
          <a class="nav-link" href="face_enrollment.php"><ion-icon name="camera-outline"></ion-icon>Face Enrollment</a>
        </nav>
      </div>

      <div class="mb-4">
        <h6 class="text-uppercase px-2 mb-2">Employee Management</h6>
        <nav class="nav flex-column">
          <a class="nav-link" href="add_employee.php"><ion-icon name="person-add-outline"></ion-icon>Add Employee</a>
          <a class="nav-link" href="employee_management.php"><ion-icon name="people-outline"></ion-icon>Employee List</a>
        </nav>
      </div>

      <div class="mb-4">
        <h6 class="text-uppercase px-2 mb-2">Leave Management</h6>
        <nav class="nav flex-column">
          <a class="nav-link" href="leave_requests.php"><ion-icon name="calendar-outline"></ion-icon>Leave Requests</a>
        </nav>
      </div>

      <div class="mb-4">
        <h6 class="text-uppercase px-2 mb-2">Timesheet Management</h6>
        <nav class="nav flex-column">
          <a class="nav-link" href="timesheet_reports.php"><ion-icon name="document-text-outline"></ion-icon>Timesheet Reports</a>
        </nav>
      </div>

      <div class="mb-4">
        <h6 class="text-uppercase px-2 mb-2">Schedule Management</h6>
        <nav class="nav flex-column">
          <a class="nav-link" href="schedule_logs.php"><ion-icon name="reader-outline"></ion-icon>Schedule Logs</a>
          <a class="nav-link" href="schedule_reports.php"><ion-icon name="document-text-outline"></ion-icon>Schedule Reports</a>
        </nav>
      </div>
      <div class="mb-4">
        <h6 class="text-uppercase px-2 mb-2">Claims & Reimbursement</h6>
        <nav class="nav flex-column">
          <a class="nav-link" href="processed_claims.php"><ion-icon name="checkmark-done-outline"></ion-icon>Processed Claims</a>
          <a class="nav-link" href="reimbursement_policies.php"><ion-icon name="settings-outline"></ion-icon>Reimbursement Policies</a>
          <a class="nav-link" href="audit_reports.php"><ion-icon name="document-text-outline"></ion-icon>Audit & Reports</a>
        </nav>
      </div>
    </div>
    <div class="p-3 border-top mb-2">
      <a class="nav-link text-danger" href="../logout.php"><ion-icon name="log-out-outline"></ion-icon>Logout</a>
    </div>
  </div>

  <!-- Main Content -->
  <div class="content-wrapper">
    <!-- Top Bar -->
    <div class="topbar">
      <h1 class="dashboard-title">Attendance Statistics</h1>
      <div class="profile">
        <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
        <div class="profile-info">
          <strong><?= htmlspecialchars($fullname) ?></strong><br>
          <small><?= htmlspecialchars(ucfirst($role)) ?></small>
        </div>
      </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
      <!-- Filters Card -->
      <div class="card mb-3">
        <div class="card-body">
          <div class="filters-section">
            <div class="filter-group">
              <div class="filter-item">
                <label for="modeSelect">
                  <ion-icon name="settings-outline"></ion-icon> Mode
                </label>
                <select id="modeSelect" class="form-select">
                  <option value="month" selected>Month</option>
                  <option value="range">Date Range</option>
                </select>
              </div>

              <div class="filter-item month-controls">
                <label for="monthPicker">
                  <ion-icon name="calendar-outline"></ion-icon> Month
                </label>
                <input id="monthPicker" type="month" class="form-control" />
              </div>

              <div class="filter-item range-controls" style="display:none;">
                <label for="dateFrom">
                  <ion-icon name="calendar-outline"></ion-icon> From
                </label>
                <input id="dateFrom" type="date" class="form-control" />
              </div>

              <div class="filter-item range-controls" style="display:none;">
                <label for="dateTo">
                  <ion-icon name="calendar-outline"></ion-icon> To
                </label>
                <input id="dateTo" type="date" class="form-control" />
              </div>

              <div class="filter-item" style="flex: 1; min-width: 250px;">
                <label for="empSelect">
                  <ion-icon name="person-outline"></ion-icon> Employee
                </label>
                <select id="empSelect" class="form-select">
                  <option value="">— All enrolled employees —</option>
                  <?php foreach ($enrolledEmployees as $emp):
                    $profile = !empty($emp['profile_photo']) ? htmlspecialchars($emp['profile_photo']) : '../assets/images/default-profile.png';
                  ?>
                    <option value="<?= htmlspecialchars($emp['employee_id']) ?>" data-profile="<?= $profile ?>"><?= htmlspecialchars($emp['fullname'] . ' (' . $emp['employee_id'] . ')') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="filter-item">
                <button id="btnApply" class="btn btn-primary btn-sm">
                  <ion-icon name="search-outline"></ion-icon> Apply
                </button>
              </div>

              <div class="filter-item">
                <button id="btnClear" class="btn btn-outline-secondary btn-sm">
                  <ion-icon name="refresh-outline"></ion-icon> Clear
                </button>
              </div>

              <div class="filter-item ms-auto">
                <button id="btnExportCsv" class="btn btn-success btn-sm">
                  <ion-icon name="download-outline"></ion-icon> Export CSV
                </button>
              </div>
            </div>
          </div>

          <!-- Summary Cards -->
          <div class="summary-cards">
            <div class="summary-card">
              <label>Enrolled Employees</label>
              <div class="value" id="statEnrolled">—</div>
            </div>
            <div class="summary-card">
              <label>Working Days</label>
              <div class="value" id="statDays">—</div>
            </div>
            <div class="summary-card">
              <label>Total Hours</label>
              <div class="value" id="statHours">—</div>
            </div>
            <div class="summary-card">
              <label>Total Late</label>
              <div class="value" id="statLate">—</div>
            </div>
            <div class="summary-card">
              <label>Early Outs</label>
              <div class="value" id="statEarly">—</div>
            </div>
            <div class="summary-card">
              <label>Missing Outs</label>
              <div class="value" id="statMissing">—</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Charts -->
      <div class="chart-wrap mb-3">
        <div class="chart-box">
          <h6>
            <ion-icon name="trending-up-outline"></ion-icon> Hours Worked (per day)
          </h6>
          <div class="chart-box-container">
            <canvas id="hoursChart"></canvas>
          </div>
        </div>
        <div class="chart-box">
          <h6>
            <ion-icon name="bar-chart-outline"></ion-icon> Top 10 Employees — Total Hours
          </h6>
          <div class="chart-box-container">
            <canvas id="topHoursChart"></canvas>
          </div>
        </div>
      </div>

      <!-- Employee Summary Table -->
      <div class="card">
        <div class="card-header">
          <ion-icon name="people-outline"></ion-icon> Per-Employee Summary
        </div>
        <div class="card-body p-0">
          <div class="table-wrap">
            <table class="table table-striped" id="empTable">
              <thead class="table-light">
                <tr>
                  <th>Name</th>
                  <th>Emp ID</th>
                  <th>Days Present</th>
                  <th>Total Hours</th>
                  <th>Avg Hours/Day</th>
                  <th>Late</th>
                  <th>Early Outs</th>
                  <th>Missing Outs</th>
                </tr>
              </thead>
              <tbody id="empTbody">
                <tr><td colspan="8" class="text-center text-muted py-4">Apply filters to load statistics.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function updateClock(){ 
  const now = new Date(); 
  const el = document.getElementById('liveClock');
  if (el) el.textContent = now.toLocaleTimeString(); 
}
setInterval(updateClock, 1000); 
updateClock();

const modeSelect = document.getElementById('modeSelect');
const monthPicker = document.getElementById('monthPicker');
const dateFrom = document.getElementById('dateFrom');
const dateTo = document.getElementById('dateTo');
const empSelect = document.getElementById('empSelect');
const btnApply = document.getElementById('btnApply');
const btnClear = document.getElementById('btnClear');
const btnExportCsv = document.getElementById('btnExportCsv');

const statEnrolled = document.getElementById('statEnrolled');
const statDays = document.getElementById('statDays');
const statHours = document.getElementById('statHours');
const statLate = document.getElementById('statLate');
const statEarly = document.getElementById('statEarly');
const statMissing = document.getElementById('statMissing');
const empTbody = document.getElementById('empTbody');

let hoursChart = null;
let topHoursChart = null;

function showMode(mode) {
  document.querySelectorAll('.month-controls').forEach(el => el.style.display = mode === 'month' ? '' : 'none');
  document.querySelectorAll('.range-controls').forEach(el => el.style.display = mode === 'range' ? '' : 'none');
}
modeSelect.addEventListener('change', ()=> showMode(modeSelect.value));
showMode(modeSelect.value);

(function initDate() {
  const now = new Date();
  monthPicker.value = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}`;
})();

function formatHours(sec) {
  if (!sec) return '0.00 h';
  return (Math.round((sec/3600) * 100) / 100).toFixed(2) + ' h';
}

function escapeHtml(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function loadStats() {
  const mode = modeSelect.value;
  const params = new URLSearchParams();
  if (empSelect.value) params.append('emp', empSelect.value);
  if (mode === 'month' && monthPicker.value) params.append('month', monthPicker.value);
  if (mode === 'range') {
    if (dateFrom.value) params.append('from', dateFrom.value);
    if (dateTo.value) params.append('to', dateTo.value);
  }

  try {
    const resp = await fetch('attendance_stats_fetch.php?' + params.toString(), { credentials: 'same-origin' });
    if (!resp.ok) { 
      const t = await resp.text(); 
      alert('Server error: ' + t); 
      return; 
    }
    const data = await resp.json();
    
    // summary cards
    statEnrolled.textContent = data.summary.enrolled_employees ?? '0';
    statDays.textContent = data.summary.total_working_days ?? '0';
    statHours.textContent = formatHours(data.summary.total_work_seconds ?? 0);
    statLate.textContent = data.summary.total_late_entries ?? '0';
    statEarly.textContent = data.summary.total_early_timeouts ?? '0';
    statMissing.textContent = data.summary.total_missing_timeouts ?? '0';

    // per-day hours chart
    renderHoursChart(data.day_labels || [], (data.day_hours_seconds || []).map(s => (s/3600)));

    // top employees chart
    renderTopHoursChart(data.top_employees || []);

    // table rows
    empTbody.innerHTML = '';
    if (!Array.isArray(data.employees) || data.employees.length === 0) {
      empTbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No employee data for this period.</td></tr>';
    } else {
      for (const e of data.employees) {
        const tr = document.createElement('tr');
        if (e.missing_count && e.missing_count > 0) tr.classList.add('irregular-missing');
        if (e.late_count && e.late_count > 0) tr.classList.add('irregular-late');
        if (e.early_count && e.early_count > 0) tr.classList.add('irregular-early');
        tr.innerHTML = `
          <td><strong>${escapeHtml(e.fullname)}</strong></td>
          <td>${escapeHtml(e.employee_id)}</td>
          <td>${escapeHtml(e.days_present ?? 0)}</td>
          <td>${escapeHtml((e.total_work_seconds ? (Math.round((e.total_work_seconds/3600)*100)/100).toFixed(2) + ' h' : '0.00 h'))}</td>
          <td>${escapeHtml((e.avg_work_seconds ? (Math.round((e.avg_work_seconds/3600)*100)/100).toFixed(2) + ' h' : '0.00 h'))}</td>
          <td>${escapeHtml(e.late_count ?? 0)}</td>
          <td>${escapeHtml(e.early_count ?? 0)}</td>
          <td>${escapeHtml(e.missing_count ?? 0)}</td>
        `;
        empTbody.appendChild(tr);
      }
    }
  } catch (err) {
    console.error(err);
    alert('Network error: ' + err.message);
  }
}

function renderHoursChart(labels, hours) {
  const ctx = document.getElementById('hoursChart').getContext('2d');
  if (hoursChart) hoursChart.destroy();
  hoursChart = new Chart(ctx, {
    type: 'line',
    data: { 
      labels: labels, 
      datasets: [{ 
        label: 'Hours', 
        data: hours, 
        borderColor: '#9A66ff', 
        backgroundColor: 'rgba(154, 102, 255, 0.12)', 
        fill: true,
        tension: 0.4,
        borderWidth: 2
      }] 
    },
    options: { 
      responsive: true,
      maintainAspectRatio: false,
      scales: { y: { beginAtZero: true } },
      plugins: {
        legend: { labels: { font: { size: 12, weight: '600' } } }
      }
    }
  });
}

function renderTopHoursChart(topEmployees) {
  const ctx = document.getElementById('topHoursChart').getContext('2d');
  if (topHoursChart) topHoursChart.destroy();
  const labels = topEmployees.map(e => e.fullname + ' (' + e.employee_id + ')');
  const data = topEmployees.map(e => Math.round((e.total_work_seconds/3600)*100)/100);
  topHoursChart = new Chart(ctx, {
    type: 'bar',
    data: { 
      labels: labels, 
      datasets: [{ 
        label: 'Total Hours', 
        data: data, 
        backgroundColor: '#10b981',
        borderRadius: 6,
        borderSkipped: false
      }] 
    },
    options: { 
      indexAxis: 'y', 
      responsive: true,
      maintainAspectRatio: false,
      scales: { x: { beginAtZero: true } },
      plugins: {
        legend: { labels: { font: { size: 12, weight: '600' } } }
      }
    }
  });
}

btnApply.addEventListener('click', ()=> loadStats());
btnClear.addEventListener('click', ()=>{
  modeSelect.value = 'month'; 
  showMode('month');
  monthPicker.value = '';
  dateFrom.value = ''; 
  dateTo.value = ''; 
  empSelect.value = '';
  statEnrolled.textContent = statDays.textContent = statHours.textContent = statLate.textContent = statEarly.textContent = statMissing.textContent = '—';
  empTbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">Apply filters to load statistics.</td></tr>';
});

btnExportCsv.addEventListener('click', ()=>{
  const params = new URLSearchParams();
  if (empSelect.value) params.append('emp', empSelect.value);
  if (modeSelect.value === 'month' && monthPicker.value) params.append('month', monthPicker.value);
  if (modeSelect.value === 'range') {
    if (dateFrom.value) params.append('from', dateFrom.value);
    if (dateTo.value) params.append('to', dateTo.value);
  }
  window.location = 'attendance_stats_export.php?format=csv&' + params.toString();
});

(function init() {
  const now = new Date();
  monthPicker.value = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}`;
  loadStats();
})();
</script>

</body>
</html>