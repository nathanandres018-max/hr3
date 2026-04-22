<?php
session_start();
require_once("../includes/db.php");

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3600)) {
    session_unset();
    session_destroy();
    header("Location:../login.php");
    exit();
}
$_SESSION['last_activity'] = time();
$fullname = $_SESSION['fullname'] ?? 'Schedule Officer';

// Departments and filters
$departments = ['ALL', 'HR', 'LOGISTICS', 'CORE', 'FINANCIAL'];
$selected_department = $_GET['department'] ?? 'ALL';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$employee = $_GET['employee'] ?? '';
$shift_type = $_GET['shift_type'] ?? '';

// For employee dropdown (active only, filtered by department if needed)
$emp_params = [];
$emp_sql = "SELECT id, employee_id, fullname, department FROM employees WHERE status = 'Active'";
if ($selected_department && $selected_department !== 'ALL') {
    $emp_sql .= " AND department = ?";
    $emp_params[] = $selected_department;
}
$emp_sql .= " ORDER BY fullname";
$stmt = $pdo->prepare($emp_sql);
$stmt->execute($emp_params);
$employee_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// For shift type dropdown
$shift_types = ['All', 'Morning', 'Afternoon', 'Night'];

// Build report query
$where = [];
$params = [];
if ($selected_department && $selected_department !== 'ALL') {
    $where[] = "e.department = ?";
    $params[] = $selected_department;
}
if ($employee) {
    $where[] = "s.employee_id = ?";
    $params[] = $employee;
}
if ($shift_type && $shift_type != 'All') {
    $where[] = "s.shift_type = ?";
    $params[] = $shift_type;
}
if ($date_from) {
    $where[] = "s.shift_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $where[] = "s.shift_date <= ?";
    $params[] = $date_to;
}

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Fetch report data
$report_sql = "SELECT s.shift_date, s.shift_type, e.fullname, e.department, e.role, e.status
    FROM shifts s
    JOIN employees e ON s.employee_id = e.id
    $where_sql
    ORDER BY s.shift_date DESC, e.fullname ASC";
$stmt = $pdo->prepare($report_sql);
$stmt->execute($params);
$report_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_schedules,
    SUM(CASE WHEN shift_type = 'Morning' THEN 1 ELSE 0 END) as morning_count,
    SUM(CASE WHEN shift_type = 'Afternoon' THEN 1 ELSE 0 END) as afternoon_count,
    SUM(CASE WHEN shift_type = 'Night' THEN 1 ELSE 0 END) as night_count
    FROM shifts";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Schedule Reports - ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        * { transition: all 0.3s ease; }
        html, body { height: 100%; }
        body { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #fafbfc 100%); color: #22223b; font-size: 16px; margin: 0; padding: 0; }
        .wrapper { display: flex; min-height: 100vh; }
        .sidebar { background: linear-gradient(180deg, #181818ff 0%, #1a1a2e 100%); color: #fff; width: 220px; position: fixed; left: 0; top: 0; height: 100vh; z-index: 1040; overflow-y: auto; padding: 1rem 0.3rem; box-shadow: 2px 0 15px rgba(0,0,0,0.1); }
        .sidebar::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
        .sidebar::-webkit-scrollbar-thumb { background: #9A66ff; border-radius: 3px; }
        .sidebar a, .sidebar button { color: #bfc7d1; background: none; border: none; font-size: 0.95rem; padding: 0.45rem 0.7rem; border-radius: 8px; display: flex; align-items: center; gap: 0.7rem; margin-bottom: 0.1rem; width: 100%; text-align: left; white-space: nowrap; cursor: pointer; }
        .sidebar a.active, .sidebar a:hover, .sidebar button.active, .sidebar button:hover { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: #fff; padding-left: 1rem; box-shadow: 0 2px 8px rgba(154, 102, 255, 0.3); }
        .sidebar hr { border-top: 1px solid #232a43; margin: 0.7rem 0; }
        .sidebar h6 { font-size: 0.75rem; font-weight: 700; letter-spacing: 1px; color: #9A66ff; }
        .sidebar .nav-link ion-icon { font-size: 1.2rem; }
        .content-wrapper { flex: 1; margin-left: 220px; display: flex; flex-direction: column; }
        .topbar { padding: 1.5rem 2rem; background: #fff; border-bottom: 2px solid #f0f0f0; box-shadow: 0 2px 8px rgba(140,140,200,0.05); display: flex; align-items: center; justify-content: space-between; gap: 2rem; }
        .topbar .profile { display: flex; align-items: center; gap: 1.2rem; }
        .topbar .profile-img { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 3px solid #9A66ff; }
        .topbar .profile-info { line-height: 1.1; }
        .topbar .profile-info strong { font-size: 1.08rem; font-weight: 600; color: #22223b; }
        .topbar .profile-info small { color: #9A66ff; font-size: 0.93rem; font-weight: 500; }
        .dashboard-title { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; font-size: 2rem; font-weight: 800; margin: 0; color: #22223b; letter-spacing: -0.5px; }
        .main-content { flex: 1; overflow-y: auto; padding: 2rem 2rem; }
        .breadcrumbs { color: #9A66ff; font-size: 0.93rem; margin-bottom: 2rem; }
        .stats-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%); border-radius: 15px; padding: 1.5rem; box-shadow: 0 4px 15px rgba(140,140,200,0.08); border: 1px solid #f0f0f0; display: flex; align-items: center; gap: 1.5rem; }
        .stat-card.morning { border-left: 5px solid #f59e0b; }
        .stat-card.afternoon { border-left: 5px solid #3b82f6; }
        .stat-card.night { border-left: 5px solid #8b5cf6; }
        .stat-card.total { border-left: 5px solid #22c55e; }
        .stat-icon { width: 60px; height: 60px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; flex-shrink: 0; }
        .stat-card.morning .stat-icon { background: #fef3c7; color: #f59e0b; }
        .stat-card.afternoon .stat-icon { background: #dbeafe; color: #3b82f6; }
        .stat-card.night .stat-icon { background: #ede9fe; color: #8b5cf6; }
        .stat-card.total .stat-icon { background: #dcfce7; color: #22c55e; }
        .stat-text h3 { font-size: 2rem; font-weight: 800; margin: 0; color: #22223b; }
        .stat-text p { font-size: 0.9rem; color: #6c757d; margin: 0; }
        .dashboard-col { background: #fff; border-radius: 18px; box-shadow: 0 4px 15px rgba(140,140,200,0.08); padding: 2rem 1.5rem; margin-bottom: 2rem; border: 1px solid #f0f0f0; }
        .dashboard-col h5 { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; font-size: 1.35rem; font-weight: 700; margin-bottom: 1.5rem; color: #22223b; display: flex; align-items: center; gap: 0.8rem; }
        .dashboard-col h5::before { content: ''; width: 4px; height: 28px; background: linear-gradient(180deg, #9A66ff 0%, #4311a5 100%); border-radius: 2px; }
        .filter-form { background: linear-gradient(135deg, #f8f9ff 0%, #f0e6ff 100%); padding: 2rem; border-radius: 12px; margin-bottom: 0; border: 1px solid #e0e7ff; }
        .filter-form .form-label { font-weight: 600; color: #22223b; font-size: 0.9rem; margin-bottom: 0.6rem; }
        .filter-form .form-select, .filter-form .form-control { border: 1px solid #e0e7ff; border-radius: 8px; padding: 0.7rem 1rem; font-size: 0.95rem; background: #fff; }
        .filter-form .form-select:focus, .filter-form .form-control:focus { border-color: #9A66ff; box-shadow: 0 0 0 0.2rem rgba(154, 102, 255, 0.15); background: #fff; }
        .filter-form .btn-primary { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); border: none; border-radius: 8px; padding: 0.7rem 2.5rem; font-weight: 600; margin-top: auto; height: fit-content; }
        .filter-form .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(154, 102, 255, 0.4); color: #fff; }
        .table { font-size: 0.98rem; color: #22223b; margin: 0; }
        .table th { color: #6c757d; font-weight: 700; border: none; background: transparent; font-size: 0.92rem; text-transform: uppercase; letter-spacing: 0.5px; padding: 1rem 0.8rem; }
        .table td { border: none; background: transparent; vertical-align: middle; padding: 1.2rem 0.8rem; border-bottom: 1px solid #f0f0f0; }
        .table tbody tr { transition: all 0.2s ease; }
        .table tbody tr:hover { background: #f8f9ff; }
        .table tbody tr:last-child td { border-bottom: none; }
        .status-badge { padding: 0.5rem 0.85rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.4rem; }
        .status-badge.active { background: #dcfce7; color: #22c55e; }
        .status-badge.inactive { background: #f3f4f6; color: #6b7280; }
        .shift-badge { background: #f0e6ff; padding: 0.4rem 0.9rem; border-radius: 6px; font-size: 0.9rem; color: #7c3aed; font-weight: 600; }
        .dept-badge { background: #e0f2fe; padding: 0.4rem 0.9rem; border-radius: 6px; font-size: 0.9rem; color: #0369a1; font-weight: 600; }
        .table-responsive { border-radius: 12px; overflow: hidden; }
        .alert-info { background: linear-gradient(135deg, #dbeafe 0%, #e0f2fe 100%); border: 1px solid #93c5fd; color: #1e40af; border-radius: 12px; }
        .export-btn { background: linear-gradient(90deg, #10b981 0%, #059669 100%); border: none; border-radius: 8px; padding: 0.7rem 1.8rem; font-weight: 600; color: #fff; display: inline-flex; align-items: center; gap: 0.5rem; margin-top: 1.5rem; }
        .export-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4); color: #fff; text-decoration: none; }

        @media (max-width: 1200px) { 
            .sidebar { width: 180px; } 
            .content-wrapper { margin-left: 180px; } 
            .main-content { padding: 1.5rem 1rem; } 
            .stats-container { grid-template-columns: repeat(2, 1fr); } 
        }
        @media (max-width: 900px) { 
            .sidebar { left: -220px; width: 220px; } 
            .sidebar.show { left: 0; } 
            .content-wrapper { margin-left: 0; } 
            .main-content { padding: 1rem; } 
            .topbar { padding: 1rem 1.5rem; } 
            .stats-container { grid-template-columns: repeat(2, 1fr); } 
            .filter-form { padding: 1.5rem; } 
            .filter-form .form-select, .filter-form .form-control { padding: 0.6rem 0.8rem; font-size: 0.9rem; } 
        }
        @media (max-width: 700px) { 
            .dashboard-title { font-size: 1.4rem; } 
            .main-content { padding: 1rem 0.8rem; } 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; } 
            .stats-container { grid-template-columns: 1fr; } 
            .filter-form { padding: 1.2rem; } 
            .filter-form .row { flex-direction: column; } 
            .filter-form .col-md-2, .filter-form .col-md-2-5 { width: 100% !important; max-width: 100% !important; } 
            .table { font-size: 0.85rem; } 
            .table th, .table td { padding: 0.8rem 0.5rem; } 
            .topbar { flex-direction: column; gap: 1rem; justify-content: flex-start; } 
        }
        @media (max-width: 500px) { 
            .sidebar { width: 100%; } 
            .main-content { padding: 0.8rem 0.5rem; } 
            .dashboard-col { padding: 1.2rem 0.8rem; } 
            .dashboard-title { font-size: 1.2rem; } 
            .stat-card { padding: 1rem; gap: 1rem; } 
            .stat-icon { width: 50px; height: 50px; font-size: 1.3rem; } 
            .stat-text h3 { font-size: 1.5rem; } 
            .filter-form { padding: 1rem; } 
        }
        @media (min-width: 1400px) { 
            .sidebar { width: 260px; padding: 2rem 1rem; } 
            .content-wrapper { margin-left: 260px; } 
            .main-content { padding: 2.5rem 2.5rem; } 
            .stats-container { grid-template-columns: repeat(4, 1fr); } 
        }
    </style>
</head>
<body>
<div class="wrapper">
    <!-- Sidebar -->
    <div class="sidebar d-flex flex-column justify-content-between shadow-sm border-end">
        <div>
            <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
                <img src="../assets/images/image.png" class="img-fluid me-2" style="height: 55px;" alt="Logo">
            </div>
            <div class="mb-4">
                <nav class="nav flex-column">
                    <a class="nav-link" href="../scheduler/schedule_officer_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
                </nav>
            </div>
            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Shift & Schedule Management</h6>
                <nav class="nav flex-column">
                    <a class="nav-link" href="../scheduler/shift_scheduling.php"><ion-icon name="calendar-outline"></ion-icon>Shift Scheduling</a>
                    <a class="nav-link" href="../scheduler/edit_update_schedules.php"><ion-icon name="pencil-outline"></ion-icon>Edit/Update Schedules</a>
                    <a class="nav-link" href="../scheduler/schedule_logs.php"><ion-icon name="reader-outline"></ion-icon>Schedule Logs</a>
                    <a class="nav-link active" href="../scheduler/schedule_reports.php"><ion-icon name="document-text-outline"></ion-icon>Schedule Reports</a>
                </nav>
            </div>
        </div>
        <div class="p-3 border-top mb-2">
            <a class="nav-link text-danger" href="../logout.php">
                <ion-icon name="log-out-outline"></ion-icon>Logout
            </a>
        </div>
    </div>

    <!-- Main Content Wrapper -->
    <div class="content-wrapper">
        <!-- Top Bar -->
        <div class="topbar">
            <span class="dashboard-title">Schedule Reports</span>
            <div class="profile">
                <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
                <div class="profile-info">
                    <strong><?= htmlspecialchars($fullname) ?></strong><br>
                    <small>Schedule Officer</small>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card total">
                    <div class="stat-icon">
                        <ion-icon name="layers-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['total_schedules'] ?? 0 ?></h3>
                        <p>Total Schedules</p>
                    </div>
                </div>
                <div class="stat-card morning">
                    <div class="stat-icon">
                        <ion-icon name="sunny-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['morning_count'] ?? 0 ?></h3>
                        <p>Morning Shifts</p>
                    </div>
                </div>
                <div class="stat-card afternoon">
                    <div class="stat-icon">
                        <ion-icon name="cloud-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['afternoon_count'] ?? 0 ?></h3>
                        <p>Afternoon Shifts</p>
                    </div>
                </div>
                <div class="stat-card night">
                    <div class="stat-icon">
                        <ion-icon name="moon-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['night_count'] ?? 0 ?></h3>
                        <p>Night Shifts</p>
                    </div>
                </div>
            </div>


            <!-- Filter Section -->
            <div class="dashboard-col">
                <h5><ion-icon name="funnel-outline"></ion-icon>Generate Schedule Report</h5>
                <form class="filter-form" method="get">
                    <div class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label">Department</label>
                            <select class="form-select" name="department" onchange="this.form.submit()">
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept ?>" <?= $selected_department===$dept?'selected':'' ?>><?= $dept ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Employee</label>
                            <select class="form-select" name="employee">
                                <option value="">All Employees</option>
                                <?php foreach ($employee_list as $emp): ?>
                                    <option value="<?= $emp['id'] ?>" <?= $employee == $emp['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['fullname']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Shift Type</label>
                            <select class="form-select" name="shift_type">
                                <?php foreach ($shift_types as $s): ?>
                                    <option value="<?= $s ?>" <?= $shift_type == $s ? "selected" : "" ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100"><ion-icon name="search-outline"></ion-icon>Search</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Results Section -->
            <div class="dashboard-col">
                <h5><ion-icon name="document-outline"></ion-icon>Report Results</h5>
                <?php if (!empty($report_rows)): ?>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Shift</th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Role</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report_rows as $row): ?>
                            <tr>
                                <td>
                                    <span style="font-weight: 600;"><?= htmlspecialchars(date('M d, Y', strtotime($row['shift_date']))) ?></span>
                                </td>
                                <td>
                                    <span class="shift-badge">
                                        <?= htmlspecialchars($row['shift_type']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($row['fullname']) ?></td>
                                <td><span class="dept-badge"><?= htmlspecialchars($row['department']) ?></span></td>
                                <td><?= htmlspecialchars($row['role']) ?></td>
                                <td>
                                    <?php if($row['status']=='Inactive'): ?>
                                        <span class="status-badge inactive"><ion-icon name="close-circle-outline" style="font-size: 0.85rem;"></ion-icon> Inactive</span>
                                    <?php else: ?>
                                        <span class="status-badge active"><ion-icon name="checkmark-circle-outline" style="font-size: 0.85rem;"></ion-icon> Active</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <form method="post" action="export_schedule_report.php" target="_blank">
                    <input type="hidden" name="department" value="<?= htmlspecialchars($selected_department) ?>">
                    <input type="hidden" name="employee" value="<?= htmlspecialchars($employee) ?>">
                    <input type="hidden" name="shift_type" value="<?= htmlspecialchars($shift_type) ?>">
                    <input type="hidden" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                    <input type="hidden" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                    <button class="export-btn" type="submit"><ion-icon name="download-outline"></ion-icon> Export as CSV</button>
                </form>
                <?php else: ?>
                    <div class="alert alert-info text-center mt-4">
                        <ion-icon name="search-outline" style="font-size: 1.5rem; margin-bottom: 0.5rem; display: block;"></ion-icon>
                        <p style="margin: 0.5rem 0 0 0;">No schedules found for the selected filters.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.querySelectorAll('.sidebar a').forEach(link => {
    link.addEventListener('click', function() {
      document.querySelectorAll('.sidebar a').forEach(a => a.classList.remove('active'));
      this.classList.add('active');
    });
  });
</script>
</body>
</html>