<?php
// filepath: admin/attendance_logs.php
// Attendance Logs UI — unified layout with sidebar/topbar with enhanced modern design
// FIXED: Responsive sortable headers

declare(strict_types=1);
session_start();
require_once(__DIR__ . '/../connection.php');

// Session timeout handling (10 minutes)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 600)) {
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

// Fetch enrolled employees for the Employee filter (face_enrolled = 1)
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
  <title>Attendance Logs — HR3</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <style>
    * { transition: all 0.3s ease; }
    table th, table td, table tr, thead, tbody { transition: none !important; }
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
      align-items: center;
      gap: 1rem;
      flex-wrap: wrap;
    }

    .filter-group label {
      font-weight: 600;
      color: #22223b;
      margin: 0;
      white-space: nowrap;
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

    .btn-secondary {
      background: #6b7280;
      color: white;
    }

    .btn-secondary:hover {
      background: #4b5563;
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

    .btn-outline-dark {
      border: 1.5px solid #22223b;
      color: #22223b;
      background: transparent;
    }

    .btn-outline-dark:hover {
      background: #22223b;
      color: white;
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

    .summary {
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

    /* RESPONSIVE TABLE STYLES */
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
      border-collapse: separate;
      border-spacing: 0;
    }

    .table th {
      color: #6c757d;
      font-weight: 700;
      border: none;
      border-bottom: 2px solid #e0e0ef;
      background: #f9f9fc !important;
      padding: 1.2rem 1rem;
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      position: sticky;
      top: 0;
      z-index: 10;
      white-space: nowrap;
    }

    .table td {
      border-bottom: 1px solid #e8e8f0;
      padding: 1rem;
      vertical-align: middle;
    }

    .table tbody tr {
      transition: all 0.2s ease;
    }

    .table tbody tr:hover {
      background: #f8f9ff;
    }

    /* FIXED SORTABLE HEADER STYLES */
    th.sortable {
      cursor: pointer;
      user-select: none;
      position: relative;
      padding-right: 1.8rem !important;
    }

    th.sortable:hover {
      background: #e8e8f0 !important;
      color: #22223b;
    }

    th.sortable::after {
      content: ' ↕';
      font-size: 0.75rem;
      opacity: 0.4;
      position: absolute;
      right: 0.5rem;
      top: 50%;
      transform: translateY(-50%);
      margin-left: 0.3rem;
    }

    /* Better icon alignment */
    th ion-icon {
      font-size: 0.95rem;
      margin-right: 0.3rem;
      vertical-align: middle;
    }

    /* Clickable employee name in table */
    .emp-clickable {
      cursor: pointer;
      color: #4311a5;
      border-bottom: 1px dashed transparent;
      padding-bottom: 1px;
      transition: all 0.15s ease;
    }

    .emp-clickable:hover {
      color: #9A66ff;
      border-bottom-color: #9A66ff;
      text-shadow: 0 0 0.5px #9A66ff;
    }

    .emp-clickable:active {
      color: #360090;
    }

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

    .pagination-controls {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .page-info {
      font-weight: 600;
      color: #22223b;
    }

    .message-area {
      margin-bottom: 1rem;
    }

    .alert {
      border-radius: 12px;
      border: none;
      border-left: 4px solid;
      padding: 1.2rem;
      font-size: 0.95rem;
    }

    .alert-info {
      background: #dbeafe;
      color: #0c4a6e;
      border-left-color: #0284c7;
    }

    .alert-danger {
      background: #fee2e2;
      color: #7f1d1d;
      border-left-color: #ef4444;
    }

    .notes-section {
      background: #f0f9ff;
      border-left: 4px solid #0284c7;
      padding: 1.2rem;
      border-radius: 8px;
      margin-top: 1.5rem;
    }

    .notes-section h6 {
      color: #0c4a6e;
      font-weight: 700;
      margin-bottom: 0.8rem;
      display: flex;
      align-items: center;
      gap: 0.6rem;
    }

    .notes-section ul {
      margin: 0;
      padding-left: 1.5rem;
      color: #0c4a6e;
      font-size: 0.95rem;
    }

    .notes-section li {
      margin-bottom: 0.6rem;
    }

    /* RESPONSIVE ADJUSTMENTS FOR SORTABLE HEADERS */
    @media (max-width: 1024px) {
      .table {
        font-size: 0.85rem;
      }

      .table th, .table td {
        padding: 0.75rem;
      }

      .table th {
        font-size: 0.75rem;
      }

      th.sortable {
        padding-right: 1.5rem !important;
      }

      th.sortable::after {
        font-size: 0.65rem;
        right: 0.3rem;
      }

      th ion-icon {
        font-size: 0.85rem;
      }
    }

    @media (max-width: 768px) {
      .table {
        font-size: 0.8rem;
      }

      .table th, .table td {
        padding: 0.7rem 0.5rem;
        font-size: 0.8rem;
        word-break: break-word;
      }

      .table th {
        font-size: 0.7rem;
      }

      th.sortable {
        padding-right: 1.3rem !important;
      }

      th.sortable::after {
        font-size: 0.6rem;
        right: 0.2rem;
      }

      th ion-icon {
        font-size: 0.8rem;
        margin-right: 0.2rem;
      }
    }

    @media (max-width: 700px) {
      .table {
        font-size: 0.75rem;
      }

      .table th {
        font-size: 0.65rem;
        padding: 0.6rem 0.4rem;
      }

      .table td {
        padding: 0.6rem 0.4rem;
        font-size: 0.75rem;
      }

      th.sortable {
        padding-right: 1.2rem !important;
      }

      th.sortable::after {
        font-size: 0.55rem;
        right: 0.15rem;
      }

      th ion-icon {
        font-size: 0.7rem;
        margin-right: 0.15rem;
      }

      /* Hide less important columns on small screens */
      .table th:nth-child(8),
      .table td:nth-child(8),
      .table th:nth-child(9),
      .table td:nth-child(9) {
        display: none;
      }

      .table-wrap { max-height: 40vh; }
    }

    @media (max-width: 500px) {
      .sidebar { width: 100%; left: -100%; }
      .sidebar.show { left: 0; }
      .main-content { padding: 0.8rem 0.5rem; }
      .dashboard-title { font-size: 1.2rem; }
      .topbar { padding: 1rem 0.8rem; }
      
      .table-wrap { max-height: 35vh; }
      
      .filters-section {
        padding: 0.8rem;
      }

      .table th {
        font-size: 0.6rem;
        padding: 0.5rem 0.3rem;
      }

      .table td {
        padding: 0.5rem 0.3rem;
        font-size: 0.7rem;
      }

      th.sortable {
        padding-right: 1.1rem !important;
      }

      th.sortable::after {
        font-size: 0.5rem;
        right: 0.1rem;
      }

      th ion-icon {
        font-size: 0.65rem;
        margin-right: 0.1rem;
      }

      /* Hide more columns on very small screens */
      .table th:nth-child(3),
      .table td:nth-child(3),
      .table th:nth-child(7),
      .table td:nth-child(7),
      .table th:nth-child(8),
      .table td:nth-child(8),
      .table th:nth-child(9),
      .table td:nth-child(9),
      .table th:nth-child(10),
      .table td:nth-child(10) {
        display: none;
      }

      .pagination-controls {
        flex-direction: column;
        width: 100%;
        gap: 0.5rem;
      }

      .pagination-controls button {
        width: 100%;
      }

      .summary-card {
        padding: 0.8rem;
      }

      .summary-card label {
        font-size: 0.75rem;
      }

      .summary-card .value {
        font-size: 1.4rem;
      }
    }

    @media (max-width: 1200px) {
      .sidebar { width: 180px; }
      .content-wrapper { margin-left: 180px; }
      .main-content { padding: 1.5rem 1rem; }
      .table-wrap { max-height: 50vh; }
    }

    @media (max-width: 900px) {
      .sidebar { left: -220px; width: 220px; }
      .sidebar.show { left: 0; }
      .content-wrapper { margin-left: 0; }
      .main-content { padding: 1rem; }
      .topbar { padding: 1rem 1.5rem; flex-direction: column; gap: 1rem; align-items: flex-start; }
      .filter-group { flex-direction: column; }
      .filter-group > * { width: 100%; }
      .summary { grid-template-columns: repeat(2, 1fr); }
      .table-wrap { max-height: 45vh; }
    }

    @media (min-width: 1400px) {
      .sidebar { width: 260px; padding: 2rem 1rem; }
      .content-wrapper { margin-left: 260px; }
      .main-content { padding: 2.5rem 2.5rem; }
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
          <a class="nav-link active" href="attendance_logs.php"><ion-icon name="list-outline"></ion-icon>Attendance Logs</a>
          <a class="nav-link" href="attendance_stats.php"><ion-icon name="stats-chart-outline"></ion-icon>Attendance Statistics</a>
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
      <h1 class="dashboard-title">Attendance Logs</h1>
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
              <div style="display: flex; align-items: center; gap: 1rem; flex: 1; min-width: 280px;">
                <label for="empSelect" style="margin-bottom: 0;">
                  <ion-icon name="person-outline"></ion-icon> Employee
                </label>
                <select id="empSelect" class="form-select" style="flex: 1;">
                  <option value="">— All enrolled employees —</option>
                  <?php foreach ($enrolledEmployees as $emp):
                    $profile = !empty($emp['profile_photo']) ? htmlspecialchars($emp['profile_photo']) : '../assets/images/default-profile.png';
                  ?>
                    <option value="<?= htmlspecialchars($emp['employee_id']) ?>" data-profile="<?= $profile ?>">
                      <?= htmlspecialchars($emp['fullname'] . ' (' . $emp['employee_id'] . ')') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div style="display: flex; align-items: center; gap: 1rem; flex: 1; min-width: 250px;">
                <label for="monthPicker" style="margin-bottom: 0;">
                  <ion-icon name="calendar-outline"></ion-icon> Month
                </label>
                <input id="monthPicker" type="month" class="form-control" style="flex: 1;" />
              </div>

              <div style="display: flex; gap: 0.8rem;">
                <button id="btnFilter" class="btn btn-primary btn-sm">
                  <ion-icon name="search-outline"></ion-icon> Filter
                </button>
                <button id="btnClear" class="btn btn-outline-secondary btn-sm">
                  <ion-icon name="refresh-outline"></ion-icon> Clear
                </button>
                <button id="btnRefresh" class="btn btn-secondary btn-sm">
                  <ion-icon name="reload-outline"></ion-icon> Refresh
                </button>
              </div>
            </div>

            <div style="display: flex; gap: 1rem; align-items: center; margin-top: 1rem; flex-wrap: wrap; justify-content: space-between;">
              <div style="display: flex; align-items: center; gap: 0.8rem;">
                <label for="perPage" style="margin: 0; white-space: nowrap;">Items per page:</label>
                <select id="perPage" class="form-select" style="width: 100px;">
                  <option value="10">10</option>
                  <option value="25" selected>25</option>
                  <option value="50">50</option>
                  <option value="100">100</option>
                </select>
              </div>

              <div style="display: flex; gap: 0.8rem;">
                <button id="btnExportCsv" class="btn btn-success btn-sm">
                  <ion-icon name="download-outline"></ion-icon> CSV
                </button>
                <button id="btnExportPdf" class="btn btn-outline-dark btn-sm">
                  <ion-icon name="document-outline"></ion-icon> PDF
                </button>
              </div>
            </div>
          </div>

          <!-- Summary Cards -->
          <div class="summary">
            <div class="summary-card">
              <label>Working Days</label>
              <div class="value" id="sumDays">—</div>
            </div>
            <div class="summary-card">
              <label>Late Entries</label>
              <div class="value" id="sumLate">—</div>
            </div>
            <div class="summary-card">
              <label>Early Time Outs</label>
              <div class="value" id="sumEarly">—</div>
            </div>
            <div class="summary-card">
              <label>Missing Time Outs</label>
              <div class="value" id="sumMissing">—</div>
            </div>
            <div class="summary-card">
              <label>Total Hours</label>
              <div class="value" id="sumHours">—</div>
            </div>
          </div>

          <!-- Message Area -->
          <div id="messageArea" aria-live="polite" aria-atomic="true" class="message-area"></div>

          <!-- Responsive Table Wrapper -->
          <div class="table-responsive-mobile">
            <div class="table-wrap">
              <table class="table table-striped" id="logsTable">
                <thead class="table-light">
                  <tr>
                    <th class="sortable" data-sort="date">
                      <ion-icon name="calendar-outline"></ion-icon>Date
                    </th>
                    <th class="sortable" data-sort="fullname">
                      <ion-icon name="person-outline"></ion-icon>Employee
                    </th>
                    <th>ID</th>
                    <th class="sortable" data-sort="time_in">
                      <ion-icon name="time-outline"></ion-icon>In
                    </th>
                    <th class="sortable" data-sort="time_out">
                      <ion-icon name="time-outline"></ion-icon>Out
                    </th>
                    <th class="sortable" data-sort="status">Status</th>
                    <th>Method</th>
                    <th>IP In</th>
                    <th>IP Out</th>
                    <th>Shift</th>
                    <th>Notes</th>
                  </tr>
                </thead>
                <tbody id="logsTbody">
                  <tr><td colspan="11" class="text-muted text-center py-4">Use the filters above and click Filter to load logs.</td></tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Pagination -->
          <div class="d-flex justify-content-between align-items-center mt-3" style="flex-wrap: wrap; gap: 1rem;">
            <div class="pagination-controls">
              <button id="btnPrev" class="btn btn-outline-secondary btn-sm">
                <ion-icon name="chevron-back-outline"></ion-icon> Prev
              </button>
              <span id="pageInfo" class="page-info">Page 1 / 1</span>
              <button id="btnNext" class="btn btn-outline-secondary btn-sm">
                Next <ion-icon name="chevron-forward-outline"></ion-icon>
              </button>
            </div>
            <div class="small text-muted">Click column headers to sort</div>
          </div>
        </div>
      </div>

      <!-- Notes Section -->
      <div class="notes-section">
        <h6>
          <ion-icon name="information-circle-outline"></ion-icon> Legend & Notes
        </h6>
        <ul>
          <li><strong>Late Entry (Orange):</strong> Employee clocked in after scheduled shift start time.</li>
          <li><strong>Early Time Out (Pink):</strong> Employee clocked out before scheduled shift end time.</li>
          <li><strong>Missing Time Out (Yellow):</strong> Employee clocked in but did not clock out on that day.</li>
          <li><strong>Mobile View:</strong> On small screens, IP addresses and Employee ID columns are hidden to improve readability.</li>
        </ul>
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

const empSelect = document.getElementById('empSelect');
const monthPicker = document.getElementById('monthPicker');
const btnFilter = document.getElementById('btnFilter');
const btnClear = document.getElementById('btnClear');
const btnRefresh = document.getElementById('btnRefresh');
const btnExportCsv = document.getElementById('btnExportCsv');
const btnExportPdf = document.getElementById('btnExportPdf');
const perPageEl = document.getElementById('perPage');
const logsTbody = document.getElementById('logsTbody');
const messageArea = document.getElementById('messageArea');
const sumDays = document.getElementById('sumDays');
const sumLate = document.getElementById('sumLate');
const sumEarly = document.getElementById('sumEarly');
const sumMissing = document.getElementById('sumMissing');
const sumHours = document.getElementById('sumHours');
const btnPrev = document.getElementById('btnPrev');
const btnNext = document.getElementById('btnNext');
const pageInfo = document.getElementById('pageInfo');

let currentRows = [], page = 1, perPage = parseInt(perPageEl.value,10) || 25, totalPages = 1, totalRows = 0;
let sortBy = 'date', sortDir = 'desc';

function setMessage(html, type='info') {
  messageArea.innerHTML = `<div class="alert alert-${type} small mb-0"><ion-icon name="${type === 'danger' ? 'alert-circle-outline' : 'information-circle-outline'}"></ion-icon> ${html}</div>`;
  setTimeout(()=> { messageArea.innerHTML = ''; }, 6000);
}

async function fetchLogs(resetPage = false) {
  if (resetPage) page = 1;
  perPage = parseInt(perPageEl.value,10) || 25;
  const emp = empSelect.value;
  const month = monthPicker.value;
  const params = new URLSearchParams();
  if (emp) params.append('emp', emp);
  if (month) params.append('month', month);
  params.append('per_page', String(perPage));
  params.append('page', String(page));
  params.append('sort_by', sortBy);
  params.append('sort_dir', sortDir);

  try {
    const resp = await fetch('attendance_logs_fetch.php?' + params.toString(), { credentials: 'same-origin' });
    if (!resp.ok) {
      const text = await resp.text();
      setMessage(`Server error (${resp.status}): ${text}`, 'danger');
      return;
    }
    const data = await resp.json();
    if (!data || !Array.isArray(data.rows)) { setMessage('Invalid server response', 'danger'); return; }
    renderRows(data.rows);
    totalRows = data.total_rows || 0;
    totalPages = Math.max(1, Math.ceil(totalRows / perPage));
    pageInfo.textContent = `Page ${page} / ${totalPages} (${totalRows} rows)`;
    btnPrev.disabled = page <= 1;
    btnNext.disabled = page >= totalPages;
    sumDays.textContent = data.summary.total_working_days ?? '0';
    sumLate.textContent = data.summary.total_late_entries ?? '0';
    sumEarly.textContent = data.summary.total_early_timeouts ?? '0';
    sumMissing.textContent = data.summary.total_missing_timeouts ?? '0';
    sumHours.textContent = (Math.round(((data.summary.total_work_seconds ?? 0)/3600) * 100) / 100).toFixed(2) + ' h';
  } catch (err) {
    console.error(err);
    setMessage('Network/server error: ' + (err.message || err), 'danger');
  }
}

function renderRows(rows) {
  currentRows = rows;
  logsTbody.innerHTML = '';
  if (!rows.length) {
    logsTbody.innerHTML = '<tr><td colspan="11" class="text-muted text-center py-4">No attendance records found for this month.</td></tr>';
    return;
  }
  for (const r of rows) {
    const tr = document.createElement('tr');
    const late = r.is_late === true;
    const early = r.is_early === true;
    const missing = r.is_missing_out === true;
    if (missing) tr.classList.add('irregular-missing');
    if (late) tr.classList.add('irregular-late');
    if (early) tr.classList.add('irregular-early');
    const assigned = r.assigned_shift_type ? (r.assigned_shift_type + (r.shift_start || r.shift_end ? ' ' + (r.shift_start || '') + (r.shift_start ? '–' : '') + (r.shift_end || '') : '')) : '';
    const notes = missing ? '⚠️ Missing Time Out' : (late ? '⏱️ Late In' : (early ? '⏰ Early Out' : ''));
    tr.innerHTML = `
      <td>${escapeHtml(r.date || '')}</td>
      <td><strong class="emp-clickable" data-empid="${escapeHtml(r.emp_code || r.employee_id || '')}" title="Click to filter by this employee">${escapeHtml(r.fullname_display || '')}</strong></td>
      <td>${escapeHtml(r.emp_code || r.employee_id || '')}</td>
      <td>${escapeHtml(r.time_in || '')}</td>
      <td>${escapeHtml(r.time_out || '')}</td>
      <td><span style="background: #ede9fe; color: #4311a5; padding: 0.3rem 0.6rem; border-radius: 4px; font-weight: 600; font-size: 0.8rem;">${escapeHtml(r.status || '')}</span></td>
      <td>${escapeHtml(r.method || '')}</td>
      <td>${escapeHtml(r.ip_in || '')}</td>
      <td>${escapeHtml(r.ip_out || '')}</td>
      <td>${escapeHtml(assigned)}</td>
      <td>${escapeHtml(notes)}</td>
    `;
    logsTbody.appendChild(tr);
  }
}

function escapeHtml(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

// Click employee name to filter by that employee
logsTbody.addEventListener('click', function(e) {
  const el = e.target.closest('.emp-clickable');
  if (!el) return;
  const empId = el.getAttribute('data-empid');
  if (!empId) return;
  // Find matching option in dropdown
  const options = empSelect.options;
  let found = false;
  for (let i = 0; i < options.length; i++) {
    if (options[i].value === empId) {
      empSelect.value = empId;
      found = true;
      break;
    }
  }
  if (found) {
    fetchLogs(true);
    setMessage(`Filtered by employee: <strong>${el.textContent}</strong>`, 'info');
  }
});

document.querySelectorAll('th.sortable').forEach(th => {
  th.addEventListener('click', () => {
    const s = th.getAttribute('data-sort') || 'date';
    if (sortBy === s) sortDir = (sortDir === 'asc') ? 'desc' : 'asc';
    else { sortBy = s; sortDir = 'asc'; }
    fetchLogs(true);
  });
});

btnFilter.addEventListener('click', ()=>fetchLogs(true));
btnRefresh.addEventListener('click', ()=>fetchLogs());
btnClear.addEventListener('click', ()=>{
  empSelect.value = ''; 
  monthPicker.value = ''; 
  page = 1; 
  currentRows = [];
  logsTbody.innerHTML = '<tr><td colspan="11" class="text-muted text-center py-4">Use the filters above and click Filter to load logs.</td></tr>';
  sumDays.textContent = sumLate.textContent = sumEarly.textContent = sumMissing.textContent = sumHours.textContent = '—';
  pageInfo.textContent = 'Page 1 / 1';
});

btnPrev.addEventListener('click', ()=>{ if (page>1) { page--; fetchLogs(); }});
btnNext.addEventListener('click', ()=>{ if (page<totalPages) { page++; fetchLogs(); }});
perPageEl.addEventListener('change', ()=>{ page = 1; fetchLogs(true); });

btnExportCsv.addEventListener('click', ()=>{
  const emp = empSelect.value;
  const month = monthPicker.value;
  const params = new URLSearchParams();
  if (emp) params.append('emp', emp);
  if (month) params.append('month', month);
  window.location = 'attendance_logs_export.php?format=csv&' + params.toString();
});

btnExportPdf.addEventListener('click', ()=>{
  const emp = empSelect.value;
  const month = monthPicker.value;
  const params = new URLSearchParams();
  if (emp) params.append('emp', emp);
  if (month) params.append('month', month);
  window.open('attendance_logs_export.php?format=pdf&' + params.toString(), '_blank');
});

(function init() {
  const now = new Date();
  const mm = String(now.getMonth() + 1).padStart(2,'0');
  const yyyy = now.getFullYear();
  monthPicker.value = `${yyyy}-${mm}`;
  fetchLogs(true);
})();
</script>

</body>
</html>