<?php
/**
 * admin/processed_claims.php
 * Admin view — Processed/approved claims with receipt links
 * Uses admin sidebar layout matching attendance_logs.php
 */

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

// Get filter parameters
$filter_category = $_GET['category'] ?? '';
$filter_from_date = $_GET['from_date'] ?? '';
$filter_to_date = $_GET['to_date'] ?? '';
$search_query = $_GET['search'] ?? '';

// Build where clauses
$where_clauses = ["c.status IN ('approved', 'processed')"];

if (!empty($filter_category)) {
    $filter_category_esc = mysqli_real_escape_string($conn, $filter_category);
    $where_clauses[] = "c.category = '$filter_category_esc'";
}
if (!empty($filter_from_date)) {
    $from_esc = mysqli_real_escape_string($conn, $filter_from_date);
    $where_clauses[] = "DATE(c.created_at) >= '$from_esc'";
}
if (!empty($filter_to_date)) {
    $to_esc = mysqli_real_escape_string($conn, $filter_to_date);
    $where_clauses[] = "DATE(c.created_at) <= '$to_esc'";
}
if (!empty($search_query)) {
    $search_esc = mysqli_real_escape_string($conn, $search_query);
    $where_clauses[] = "(c.vendor LIKE '%$search_esc%' OR e.fullname LIKE '%$search_esc%' OR c.id LIKE '%$search_esc%')";
}

$where = implode(' AND ', $where_clauses);

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM claims c LEFT JOIN employees e ON c.created_by = e.username WHERE $where";
$count_result = mysqli_query($conn, $count_sql);
$total_claims = mysqli_fetch_assoc($count_result)['total'] ?? 0;

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 25;
$offset = ($page - 1) * $per_page;
$total_pages = max(1, (int)ceil($total_claims / $per_page));

// Fetch processed claims
$sql = "SELECT c.id, c.amount, c.category, c.vendor, c.expense_date, c.description,
               c.created_by, c.status, c.receipt_path, c.created_at,
               c.reviewed_by, c.reviewed_at, c.review_notes,
               e.fullname as employee_name, e.employee_id
        FROM claims c
        LEFT JOIN employees e ON c.created_by = e.username
        WHERE $where
        ORDER BY c.created_at DESC
        LIMIT $offset, $per_page";

$result = mysqli_query($conn, $sql);
$processed_claims = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $processed_claims[] = $row;
    }
}

// Get categories
$cat_sql = "SELECT DISTINCT category FROM claims WHERE category IS NOT NULL AND category != '' AND status IN ('approved','processed') ORDER BY category ASC";
$cat_result = mysqli_query($conn, $cat_sql);
$categories = [];
while ($row = mysqli_fetch_assoc($cat_result)) {
    if (!empty($row['category'])) $categories[] = $row['category'];
}

// Total value
$val_sql = "SELECT COALESCE(SUM(c.amount),0) as total_val FROM claims c LEFT JOIN employees e ON c.created_by = e.username WHERE $where";
$val_result = mysqli_query($conn, $val_sql);
$total_value = mysqli_fetch_assoc($val_result)['total_val'] ?? 0;

function build_page_url_admin($pg) {
    $params = [];
    if (!empty($_GET['search'])) $params[] = 'search=' . urlencode($_GET['search']);
    if (!empty($_GET['category'])) $params[] = 'category=' . urlencode($_GET['category']);
    if (!empty($_GET['from_date'])) $params[] = 'from_date=' . urlencode($_GET['from_date']);
    if (!empty($_GET['to_date'])) $params[] = 'to_date=' . urlencode($_GET['to_date']);
    $params[] = 'page=' . $pg;
    return implode('&', $params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Processed Claims — HR3 Admin</title>
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

    .card { border-radius: 18px; box-shadow: 0 4px 15px rgba(140,140,200,0.08); border: 1px solid #f0f0f0; background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%); }
    .card-header { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: white; border-radius: 18px 18px 0 0; padding: 1.5rem; border: none; font-weight: 700; display: flex; align-items: center; gap: 0.8rem; font-size: 1.15rem; }
    .card-body { padding: 1.5rem; }

    .stats-section { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .stat-card { background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%); border: 1px solid #e0e7ff; border-left: 5px solid #9A66ff; border-radius: 10px; padding: 1.2rem; text-align: center; }
    .stat-card label { font-size: 0.85rem; color: #6c757d; font-weight: 600; margin-bottom: 0.5rem; display: block; }
    .stat-card .value { font-size: 1.8rem; font-weight: 800; color: #22223b; }

    .filters-section { background: #f8f9ff; border: 1px solid #e0e7ff; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; }
    .filter-group { display: flex; align-items: flex-end; gap: 1rem; flex-wrap: wrap; }
    .filter-group > div { display: flex; flex-direction: column; gap: 0.3rem; }
    .filter-group label { font-weight: 600; color: #22223b; margin: 0; white-space: nowrap; font-size: 0.9rem; }
    .form-select, .form-control { border-radius: 8px; border: 1px solid #e0e7ff; padding: 0.65rem 0.9rem; background: #fff; color: #22223b; font-size: 0.95rem; }
    .form-select:focus, .form-control:focus { border-color: #9A66ff; box-shadow: 0 0 0 0.2rem rgba(154, 102, 255, 0.15); outline: none; }

    .btn { border: none; border-radius: 8px; font-weight: 600; transition: all 0.2s ease; padding: 0.65rem 1.2rem; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; }
    .btn-primary { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: white; }
    .btn-primary:hover { background: linear-gradient(90deg, #8654e0 0%, #360090 100%); transform: translateY(-2px); color: white; }
    .btn-outline-secondary { border: 1.5px solid #9A66ff; color: #9A66ff; background: transparent; }
    .btn-outline-secondary:hover { background: #9A66ff; color: white; transform: translateY(-2px); }
    .btn-secondary { background: #6b7280; color: white; }
    .btn-secondary:hover { background: #4b5563; transform: translateY(-2px); }
    .btn-sm { padding: 0.5rem 1rem; font-size: 0.85rem; }
    .btn-success { background: #10b981; color: white; }
    .btn-success:hover { background: #059669; transform: translateY(-2px); }
    .btn-outline-dark { background: none; border: 1.5px solid #6c757d; color: #6c757d; }
    .btn-outline-dark:hover { background: #6c757d; color: white; transform: translateY(-2px); }

    .table-wrap { max-height: 56vh; overflow: auto; border-radius: 12px; box-shadow: 0 2px 8px rgba(140,140,200,0.05); }
    .table { font-size: 0.95rem; color: #22223b; margin-bottom: 0; border-collapse: separate; border-spacing: 0; }
    .table th { color: #6c757d; font-weight: 700; border: none; border-bottom: 2px solid #e0e0ef; background: #f9f9fc !important; padding: 1rem 0.8rem; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px; position: sticky; top: 0; z-index: 10; white-space: nowrap; }
    .table td { border-bottom: 1px solid #e8e8f0; padding: 0.9rem 0.8rem; vertical-align: middle; }
    .table tbody tr { transition: all 0.2s ease; }
    .table tbody tr:hover { background: #f8f9ff; }

    .claim-id { font-weight: 700; color: #9A66ff; }
    .category-badge { display: inline-block; padding: 0.3rem 0.7rem; border-radius: 20px; font-size: 0.82rem; font-weight: 600; background: #f0f4ff; color: #9A66ff; }
    .amount { font-weight: 700; color: #10b981; }
    .status-badge { display: inline-block; padding: 0.3rem 0.7rem; border-radius: 20px; font-size: 0.82rem; font-weight: 600; }
    .status-approved { background: #d1fae5; color: #065f46; }
    .status-processed { background: #dbeafe; color: #1e40af; }

    .pagination-controls { display: flex; align-items: center; gap: 0.5rem; justify-content: center; margin-top: 1.5rem; flex-wrap: wrap; }
    .pagination-controls a, .pagination-controls span { padding: 0.5rem 0.8rem; border-radius: 6px; border: 1px solid #d1d5db; text-decoration: none; color: #22223b; cursor: pointer; transition: all 0.2s ease; font-size: 0.9rem; }
    .pagination-controls a:hover { background: #9A66ff; color: white; border-color: #9A66ff; }
    .pagination-controls .current { background: #9A66ff; color: white; border-color: #9A66ff; }

    .empty-state { text-align: center; padding: 3rem 2rem; color: #6c757d; }
    .empty-state ion-icon { font-size: 3rem; color: #d1d5db; margin-bottom: 1rem; display: block; }

    @media (max-width: 1200px) { .sidebar { width: 180px; } .content-wrapper { margin-left: 180px; } .main-content { padding: 1.5rem 1rem; } }
    @media (max-width: 900px) { .sidebar { left: -220px; width: 220px; } .sidebar.show { left: 0; } .content-wrapper { margin-left: 0; } .main-content { padding: 1rem; } .topbar { padding: 1rem 1.5rem; flex-direction: column; gap: 1rem; align-items: flex-start; } .filter-group { flex-direction: column; } .filter-group > * { width: 100%; } .stats-section { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 500px) { .sidebar { width: 100%; left: -100%; } .sidebar.show { left: 0; } .main-content { padding: 0.8rem 0.5rem; } .dashboard-title { font-size: 1.2rem; } .topbar { padding: 1rem 0.8rem; } .stats-section { grid-template-columns: 1fr; } }
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
          <a class="nav-link active" href="processed_claims.php"><ion-icon name="checkmark-done-outline"></ion-icon>Processed Claims</a>
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
    <div class="topbar">
      <h1 class="dashboard-title">Processed Claims</h1>
      <div class="profile">
        <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
        <div class="profile-info">
          <strong><?= htmlspecialchars($fullname) ?></strong><br>
          <small><?= htmlspecialchars(ucfirst($role)) ?></small>
        </div>
      </div>
    </div>

    <div class="main-content">
      <!-- Stats -->
      <div class="stats-section">
        <div class="stat-card">
          <label>Total Processed</label>
          <div class="value"><?= $total_claims ?></div>
        </div>
        <div class="stat-card">
          <label>This Page</label>
          <div class="value"><?= count($processed_claims) ?></div>
        </div>
        <div class="stat-card">
          <label>Total Value</label>
          <div class="value">₱<?= number_format((float)$total_value, 2) ?></div>
        </div>
      </div>

      <!-- Filters -->
      <div class="card mb-3">
        <div class="card-header">
          <ion-icon name="funnel-outline"></ion-icon> Filter Claims
        </div>
        <div class="card-body">
          <form method="GET" class="filters-section">
            <div class="filter-group">
              <div style="flex:1; min-width:180px;">
                <label for="search">Search</label>
                <input type="text" id="search" name="search" class="form-control" placeholder="ID, Vendor, Employee..." value="<?= htmlspecialchars($search_query) ?>">
              </div>
              <div style="min-width:160px;">
                <label for="category">Category</label>
                <select id="category" name="category" class="form-select">
                  <option value="">All Categories</option>
                  <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= $filter_category === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="min-width:150px;">
                <label for="from_date">From Date</label>
                <input type="date" id="from_date" name="from_date" class="form-control" value="<?= htmlspecialchars($filter_from_date) ?>">
              </div>
              <div style="min-width:150px;">
                <label for="to_date">To Date</label>
                <input type="date" id="to_date" name="to_date" class="form-control" value="<?= htmlspecialchars($filter_to_date) ?>">
              </div>
              <div style="display:flex; gap:0.5rem; align-self:flex-end;">
                <button type="submit" class="btn btn-primary btn-sm"><ion-icon name="search-outline"></ion-icon> Filter</button>
                <a href="processed_claims.php" class="btn btn-secondary btn-sm"><ion-icon name="refresh-outline"></ion-icon> Reset</a>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Claims Table -->
      <div class="card">
        <div class="card-header">
          <ion-icon name="list-outline"></ion-icon> Processed Claims List
        </div>
        <div class="card-body">
          <?php if (empty($processed_claims)): ?>
            <div class="empty-state">
              <ion-icon name="document-outline"></ion-icon>
              <h5>No Processed Claims</h5>
              <p>No approved or processed claims found matching your filters.</p>
            </div>
          <?php else: ?>
            <div class="table-wrap">
              <table class="table" id="claimsTable">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Employee</th>
                    <th>Category</th>
                    <th>Vendor</th>
                    <th>Amount</th>
                    <th>Expense Date</th>
                    <th>Status</th>
                    <th>Processed</th>
                    <th>Receipt</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($processed_claims as $claim): ?>
                    <tr>
                      <td><span class="claim-id">#<?= htmlspecialchars((string)$claim['id']) ?></span></td>
                      <td>
                        <div><strong><?= htmlspecialchars($claim['employee_name'] ?? 'Unknown') ?></strong></div>
                        <small style="color: #6c757d;"><?= htmlspecialchars($claim['employee_id'] ?? 'N/A') ?></small>
                      </td>
                      <td><span class="category-badge"><?= htmlspecialchars($claim['category'] ?? 'N/A') ?></span></td>
                      <td><?= htmlspecialchars($claim['vendor'] ?? 'Not specified') ?></td>
                      <td><span class="amount">₱<?= number_format((float)($claim['amount'] ?? 0), 2) ?></span></td>
                      <td><?= htmlspecialchars($claim['expense_date'] ?? 'N/A') ?></td>
                      <td>
                        <span class="status-badge status-<?= strtolower($claim['status']) ?>">
                          <?= htmlspecialchars(ucfirst($claim['status'])) ?>
                        </span>
                      </td>
                      <td>
                        <?php if (!empty($claim['reviewed_at'])): ?>
                          <div style="font-size:0.85rem;"><?= date('M d, Y', strtotime($claim['reviewed_at'])) ?></div>
                          <small style="color: #6c757d;">by <?= htmlspecialchars($claim['reviewed_by'] ?? 'N/A') ?></small>
                        <?php else: ?>
                          <small style="color: #6c757d;">N/A</small>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (!empty($claim['receipt_path'])): ?>
                          <a class="btn btn-sm btn-outline-dark" href="<?= htmlspecialchars($claim['receipt_path']) ?>" target="_blank" rel="noopener">
                            <ion-icon name="download-outline"></ion-icon> View
                          </a>
                        <?php else: ?>
                          <small style="color: #6c757d;">No receipt</small>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
              <div class="pagination-controls">
                <?php if ($page > 1): ?>
                  <a href="?<?= build_page_url_admin(1) ?>">First</a>
                  <a href="?<?= build_page_url_admin($page - 1) ?>">Prev</a>
                <?php endif; ?>
                <?php
                  $start = max(1, $page - 2);
                  $end = min($total_pages, $page + 2);
                  for ($p = $start; $p <= $end; $p++):
                ?>
                  <?php if ($p === $page): ?>
                    <span class="current"><?= $p ?></span>
                  <?php else: ?>
                    <a href="?<?= build_page_url_admin($p) ?>"><?= $p ?></a>
                  <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                  <a href="?<?= build_page_url_admin($page + 1) ?>">Next</a>
                  <a href="?<?= build_page_url_admin($total_pages) ?>">Last</a>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <div style="margin-top: 1rem;">
              <button class="btn btn-success btn-sm" onclick="exportCSV()"><ion-icon name="download-outline"></ion-icon> Export CSV</button>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function exportCSV() {
  let csv = 'ID,Employee,Category,Vendor,Amount,Expense Date,Status,Processed Date,Processed By\n';
  document.querySelectorAll('#claimsTable tbody tr').forEach(row => {
    const cells = row.querySelectorAll('td');
    const vals = Array.from(cells).slice(0, 8).map(c => '"' + (c.textContent || '').trim().replace(/"/g, '""') + '"');
    csv += vals.join(',') + '\n';
  });
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = 'processed_claims_' + new Date().toISOString().split('T')[0] + '.csv';
  link.click();
}
</script>
</body>
</html>
