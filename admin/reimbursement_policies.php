<?php
/**
 * admin/reimbursement_policies.php
 * Admin view — Reimbursement Policies (NO AI Policy Verification Engine)
 */

declare(strict_types=1);
session_start();
require_once(__DIR__ . '/../connection.php');

// Session timeout (10 minutes)
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

// Fetch policies dynamically
$policies = [];
$has_description = false;

$col_check = mysqli_query($conn, "SHOW COLUMNS FROM reimbursement_policies");
if ($col_check) {
    $columns = [];
    while ($col = mysqli_fetch_assoc($col_check)) {
        $columns[] = $col['Field'];
    }
    if (in_array('description', $columns)) $has_description = true;

    $select_cols = ['id', 'category', 'limit_amount'];
    if ($has_description) $select_cols[] = 'description';
    if (in_array('created_at', $columns)) $select_cols[] = 'created_at';
    if (in_array('updated_at', $columns)) $select_cols[] = 'updated_at';

    $select = implode(',', $select_cols);
    $p_result = mysqli_query($conn, "SELECT $select FROM reimbursement_policies ORDER BY category ASC");
    if ($p_result) {
        while ($row = mysqli_fetch_assoc($p_result)) {
            $policies[] = $row;
        }
    }
}

// Fallback defaults
if (empty($policies)) {
    $policies = [
        ['id' => 0, 'category' => 'Travel',   'limit_amount' => 2000, 'updated_at' => null],
        ['id' => 0, 'category' => 'Meal',     'limit_amount' => 500,  'updated_at' => null],
        ['id' => 0, 'category' => 'Medical',  'limit_amount' => 15000,'updated_at' => null],
        ['id' => 0, 'category' => 'Supplies', 'limit_amount' => 2500, 'updated_at' => null],
        ['id' => 0, 'category' => 'Others',   'limit_amount' => 5000, 'updated_at' => null],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Reimbursement Policies — HR3 Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <style>
    * { transition: all 0.3s ease; }
    table th, table td, table tr, thead, tbody { transition: none !important; }
    html, body { height: 100%; }
    body { font-family: 'QuickSand','Poppins',Arial,sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #fafbfc 100%); color: #22223b; font-size: 16px; margin: 0; padding: 0; }

    .wrapper { display: flex; min-height: 100vh; }

    .sidebar { background: linear-gradient(180deg, #181818ff 0%, #1a1a2e 100%); color: #fff; width: 220px; position: fixed; left: 0; top: 0; height: 100vh; z-index: 1040; overflow-y: auto; padding: 1rem 0.3rem; box-shadow: 2px 0 15px rgba(0,0,0,0.1); }
    .sidebar::-webkit-scrollbar { width: 6px; }
    .sidebar::-webkit-scrollbar-track { background: transparent; }
    .sidebar::-webkit-scrollbar-thumb { background: #9A66ff; border-radius: 3px; }
    .sidebar a, .sidebar button { color: #bfc7d1; background: none; border: none; font-size: 0.95rem; padding: 0.45rem 0.7rem; border-radius: 8px; display: flex; align-items: center; gap: 0.7rem; margin-bottom: 0.1rem; width: 100%; text-align: left; white-space: nowrap; cursor: pointer; text-decoration: none; }
    .sidebar a.active, .sidebar a:hover, .sidebar button.active, .sidebar button:hover { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: #fff; padding-left: 1rem; box-shadow: 0 2px 8px rgba(154, 102, 255, 0.3); }
    .sidebar hr { border-top: 1px solid #232a43; margin: 0.7rem 0; }
    .sidebar h6 { font-size: 0.75rem; font-weight: 700; letter-spacing: 1px; color: #9A66ff; }
    .sidebar .nav-link ion-icon { font-size: 1.2rem; }

    .content-wrapper { flex: 1; margin-left: 220px; display: flex; flex-direction: column; }
    .topbar { padding: 1.5rem 2rem; background: #fff; border-bottom: 2px solid #f0f0f0; box-shadow: 0 2px 8px rgba(140,140,200,0.05); display: flex; align-items: center; justify-content: space-between; gap: 2rem; }
    .dashboard-title { font-family: 'QuickSand','Poppins',Arial,sans-serif; font-size: 2rem; font-weight: 800; margin: 0; color: #22223b; letter-spacing: -0.5px; }
    .topbar .profile { display: flex; align-items: center; gap: 1.2rem; }
    .topbar .profile-img { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 3px solid #9A66ff; }
    .topbar .profile-info { line-height: 1.1; }
    .topbar .profile-info strong { font-size: 1.08rem; font-weight: 600; color: #22223b; }
    .topbar .profile-info small { color: #9A66ff; font-size: 0.93rem; font-weight: 500; }

    .main-content { flex: 1; overflow-y: auto; padding: 2rem; }

    .card { border-radius: 18px; box-shadow: 0 4px 15px rgba(140,140,200,0.08); border: 1px solid #f0f0f0; background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%); margin-bottom: 1.5rem; }
    .card-header { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: white; border-radius: 18px 18px 0 0; padding: 1.5rem; border: none; font-weight: 700; display: flex; align-items: center; gap: 0.8rem; font-size: 1.15rem; }
    .card-body { padding: 1.5rem; }

    .table { font-size: 0.95rem; color: #22223b; margin-bottom: 0; border-collapse: separate; border-spacing: 0; }
    .table th { color: #6c757d; font-weight: 700; border: none; border-bottom: 2px solid #e0e0ef; background: #f9f9fc !important; padding: 1rem 0.8rem; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px; position: sticky; top: 0; z-index: 10; white-space: nowrap; }
    .table td { border-bottom: 1px solid #e8e8f0; padding: 0.9rem 0.8rem; vertical-align: middle; }
    .table tbody tr { transition: all 0.2s ease; }
    .table tbody tr:hover { background: #f8f9ff; }

    .category-badge { display: inline-block; padding: 0.3rem 0.7rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600; background: #f0f4ff; color: #9A66ff; }
    .amount-badge { display: inline-block; padding: 0.3rem 0.7rem; border-radius: 20px; font-size: 0.85rem; font-weight: 700; background: #d1fae5; color: #065f46; }

    .guideline-list { padding: 0; list-style: none; margin: 0; }
    .guideline-list li { padding: 0.8rem 0; border-bottom: 1px solid #e8e8f0; display: flex; align-items: flex-start; gap: 0.7rem; }
    .guideline-list li:last-child { border-bottom: none; }
    .guideline-list li ion-icon { color: #9A66ff; font-size: 1.1rem; margin-top: 2px; min-width: 20px; }

    .empty-state { text-align: center; padding: 3rem 2rem; color: #6c757d; }
    .empty-state ion-icon { font-size: 3rem; color: #d1d5db; margin-bottom: 1rem; display: block; }

    @media (max-width: 1200px) { .sidebar { width: 180px; } .content-wrapper { margin-left: 180px; } .main-content { padding: 1.5rem 1rem; } }
    @media (max-width: 900px) { .sidebar { left: -220px; width: 220px; } .sidebar.show { left: 0; } .content-wrapper { margin-left: 0; } .main-content { padding: 1rem; } .topbar { padding: 1rem 1.5rem; flex-direction: column; gap: 1rem; align-items: flex-start; } }
    @media (max-width: 500px) { .sidebar { width: 100%; left: -100%; } .sidebar.show { left: 0; } .main-content { padding: 0.8rem 0.5rem; } .dashboard-title { font-size: 1.2rem; } .topbar { padding: 1rem 0.8rem; } }
    @media (min-width: 1400px) { .sidebar { width: 260px; padding: 2rem 1rem; } .content-wrapper { margin-left: 260px; } .main-content { padding: 2.5rem 2.5rem; } }
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
          <a class="nav-link active" href="reimbursement_policies.php"><ion-icon name="settings-outline"></ion-icon>Reimbursement Policies</a>
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
    <div class="topbar">
      <h1 class="dashboard-title">Reimbursement Policies</h1>
      <div class="profile">
        <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
        <div class="profile-info">
          <strong><?= htmlspecialchars($fullname) ?></strong><br>
          <small><?= htmlspecialchars(ucfirst($role)) ?></small>
        </div>
      </div>
    </div>

    <div class="main-content">
      <!-- Active Policies -->
      <div class="card">
        <div class="card-header">
          <ion-icon name="settings-outline"></ion-icon> Active Reimbursement Policies
        </div>
        <div class="card-body">
          <?php if (empty($policies)): ?>
            <div class="empty-state">
              <ion-icon name="document-outline"></ion-icon>
              <h5>No Policies Found</h5>
              <p>There are currently no reimbursement policies configured.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table">
                <thead>
                  <tr>
                    <th>Category</th>
                    <th>Max Amount</th>
                    <?php if ($has_description): ?><th>Description</th><?php endif; ?>
                    <th>Last Updated</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($policies as $pol): ?>
                    <tr>
                      <td><span class="category-badge"><?= htmlspecialchars($pol['category'] ?? 'N/A') ?></span></td>
                      <td><span class="amount-badge">₱<?= number_format((float)($pol['limit_amount'] ?? 0), 2) ?></span></td>
                      <?php if ($has_description): ?>
                        <td><?= htmlspecialchars($pol['description'] ?? '') ?></td>
                      <?php endif; ?>
                      <td>
                        <?php if (!empty($pol['updated_at'])): ?>
                          <?= date('M d, Y h:i A', strtotime($pol['updated_at'])) ?>
                        <?php elseif (!empty($pol['created_at'])): ?>
                          <?= date('M d, Y h:i A', strtotime($pol['created_at'])) ?>
                        <?php else: ?>
                          <span style="color: #6c757d;">Default</span>
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

      <!-- Policy Guidelines -->
      <div class="card">
        <div class="card-header">
          <ion-icon name="book-outline"></ion-icon> Policy Guidelines
        </div>
        <div class="card-body">
          <ul class="guideline-list">
            <li>
              <ion-icon name="checkmark-circle-outline"></ion-icon>
              <span>All reimbursement claims must be submitted within <strong>30 days</strong> of the expense date.</span>
            </li>
            <li>
              <ion-icon name="checkmark-circle-outline"></ion-icon>
              <span>Original receipts or clear digital copies are <strong>required</strong> for all claims.</span>
            </li>
            <li>
              <ion-icon name="checkmark-circle-outline"></ion-icon>
              <span>Claims exceeding the category limit will be <strong>automatically flagged</strong> for additional review.</span>
            </li>
            <li>
              <ion-icon name="checkmark-circle-outline"></ion-icon>
              <span>Medical claims require a <strong>medical certificate</strong> or doctor's prescription.</span>
            </li>
            <li>
              <ion-icon name="checkmark-circle-outline"></ion-icon>
              <span>Travel claims must include <strong>itinerary details</strong> and purpose of travel.</span>
            </li>
            <li>
              <ion-icon name="checkmark-circle-outline"></ion-icon>
              <span>Meal reimbursements are limited to <strong>official business meals</strong> only.</span>
            </li>
            <li>
              <ion-icon name="checkmark-circle-outline"></ion-icon>
              <span>All policies are subject to periodic review and may be updated by the Benefits Officer.</span>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
