<?php
// filepath: admin/face_enrollment.php
// Face Enrollment page with:
// - Improved client-side guidance and quality checks
// - Duplicate-detection handling with server 409 response display
// - "Preview Replacement" modal and "Force Replace" flow (resend with force=true)
// - Robust model loader: checks manifest accessibility then loads face-api models
// - ENHANCED: Modern UI design with improved responsiveness
//
// Notes:
// - Ensure /models/* (manifests + shards) are present and readable by webserver.
// - enroll_save.php must support 'force' and return match details on 409 (see server patch).
// - Protect enroll_debug.log and schedule_logs storage.

include_once("../connection.php");
// Load encryption secret from absolute path per your environment (optional)
@require_once('/home/hr3.viahale.com/public_html/secret.php');

session_start();

// Session timeout handling (5 minutes)
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

// Fetch active employees (include face_enrolled & face_image data)
$employees = [];
$sql = "SELECT id, employee_id, fullname, profile_photo, status, face_enrolled, face_image FROM employees WHERE status = 'Active' ORDER BY fullname ASC";
if (isset($conn) && ($conn instanceof mysqli)) {
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        $result->free();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Face Enrollment — Admin Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <style>
    * { transition: all 0.3s ease; }
    html, body { height: 100%; }
    body { 
      font-family: 'QuickSand', 'Poppins', Arial, sans-serif; 
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

    .camera-box { 
      background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
      border-radius: 12px; 
      padding: 1.5rem; 
      box-shadow: 0 4px 15px rgba(140,140,200,0.08);
      border: 1px solid #f0f0f0;
    }

    video { 
      width: 100%; 
      height: auto; 
      border-radius: 8px; 
      background: #000; 
      max-height: 400px;
    }

    canvas { display: none; }

    .guidance { 
      font-weight: 600; 
      color: #9A66ff;
      padding: 0.8rem;
      background: #f3f0ff;
      border-left: 4px solid #9A66ff;
      border-radius: 6px;
      font-size: 0.95rem;
    }

    .sample-thumb { 
      width: 84px; 
      height: 84px; 
      object-fit: cover; 
      border-radius: 6px; 
      border: 2px solid #e0e7ff;
      box-shadow: 0 2px 8px rgba(140,140,200,0.08);
    }

    .status-badge { 
      padding: 0.4rem 0.8rem; 
      border-radius: 12px; 
      font-weight: 600;
      font-size: 0.85rem;
    }

    .status-badge.success { 
      background: #dbeafe; 
      color: #2563eb;
    }

    .status-badge.pending { 
      background: #fff3cd; 
      color: #856404;
    }

    .form-label {
      font-weight: 600;
      color: #22223b;
      margin-bottom: 0.5rem;
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

    .btn-outline-primary {
      border: 1.5px solid #9A66ff;
      color: #9A66ff;
      background: transparent;
    }

    .btn-outline-primary:hover {
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

    .btn-danger {
      background: #ef4444;
      color: white;
    }

    .btn-danger:hover {
      background: #dc2626;
      transform: translateY(-2px);
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

    .btn-outline-warning {
      border: 1.5px solid #f59e0b;
      color: #f59e0b;
      background: transparent;
    }

    .btn-outline-warning:hover {
      background: #f59e0b;
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

    .employee-preview {
      background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
      border: 1px solid #e0e7ff;
      border-radius: 10px;
      padding: 1rem;
    }

    .alert {
      border-radius: 12px;
      border: none;
      border-left: 4px solid;
      padding: 1rem;
      margin-bottom: 1rem;
      font-size: 0.95rem;
    }

    .alert-success {
      background: #dcfce7;
      color: #0b7a1b;
      border-left-color: #22c55e;
    }

    .alert-warning {
      background: #fef3c7;
      color: #92400e;
      border-left-color: #f59e0b;
    }

    .alert-danger {
      background: #fee2e2;
      color: #7f1d1d;
      border-left-color: #ef4444;
    }

    .muted-small { 
      font-size: 0.85rem; 
      color: #6c757d;
    }

    .sample-progress {
      font-weight: 600;
      color: #9A66ff;
      font-size: 0.9rem;
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
    }

    .table td {
      border-bottom: 1px solid #e8e8f0;
      padding: 1rem;
      vertical-align: middle;
    }

    .table tbody tr { transition: all 0.2s ease; }
    .table tbody tr:hover { background: #f8f9ff; }

    .modal-content { 
      border-radius: 18px; 
      border: 1px solid #e0e7ff; 
      box-shadow: 0 6px 32px rgba(70, 57, 130, 0.15);
    }

    .modal-header { 
      background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
      color: #fff; 
      border-bottom: none; 
      border-radius: 18px 18px 0 0; 
      padding: 1.5rem;
    }

    .modal-title { font-size: 1.13rem; font-weight: 700; }
    .modal-body { background: #fafbfc; padding: 1.7rem 1.5rem; }
    .modal-footer { background: #fafbfc; border-top: 1px solid #e0e7ff; padding: 1.2rem 1.5rem; }

    .btn-close { filter: brightness(1.8); }

    @media (max-width: 1200px) {
      .sidebar { width: 180px; }
      .content-wrapper { margin-left: 180px; }
      .main-content { padding: 1.5rem 1rem; }
    }

    @media (max-width: 900px) {
      .sidebar { left: -220px; width: 220px; }
      .sidebar.show { left: 0; }
      .content-wrapper { margin-left: 0; }
      .main-content { padding: 1rem; }
      .topbar { padding: 1rem 1.5rem; flex-direction: column; gap: 1rem; align-items: flex-start; }
    }

    @media (max-width: 700px) {
      .dashboard-title { font-size: 1.4rem; }
      .main-content { padding: 1rem 0.8rem; }
      .sidebar { width: 100%; left: -100%; }
      .sidebar.show { left: 0; }
      .camera-box { padding: 1rem; }
      video { max-height: 300px; }
      .btn { padding: 0.5rem 0.8rem; font-size: 0.8rem; }
      .btn-sm { padding: 0.4rem 0.7rem; font-size: 0.75rem; }
    }

    @media (max-width: 500px) {
      .sidebar { width: 100%; left: -100%; }
      .sidebar.show { left: 0; }
      .main-content { padding: 0.8rem 0.5rem; }
      .dashboard-title { font-size: 1.2rem; }
      .topbar { padding: 1rem 0.8rem; }
      .camera-box { padding: 0.8rem; }
      video { max-height: 250px; }
      .sample-thumb { width: 70px; height: 70px; }
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
          <a class="nav-link" href="attendance_logs.php"><ion-icon name="list-outline"></ion-icon>Attendance Logs</a>
          <a class="nav-link" href="attendance_stats.php"><ion-icon name="stats-chart-outline"></ion-icon>Attendance Statistics</a>
          <a class="nav-link active" href="face_enrollment.php"><ion-icon name="camera-outline"></ion-icon>Face Enrollment</a>
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
      <h1 class="dashboard-title">Face Enrollment</h1>
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
      <div class="row g-3">
        <!-- Camera Section -->
        <div class="col-lg-5">
          <div class="camera-box">
            <!-- Employee Selection -->
            <div class="mb-3">
              <label for="employeeSelect" class="form-label">
                <ion-icon name="person-outline"></ion-icon> Select Employee
              </label>
              <select id="employeeSelect" class="form-select">
                <option value="">-- Select employee --</option>
                <?php foreach ($employees as $emp):
                  $profile = !empty($emp['profile_photo']) ? htmlspecialchars($emp['profile_photo']) : '../assets/images/default-profile.png';
                  $enrolled = ((int)$emp['face_enrolled'] === 1) ? 1 : 0;
                  $face_image = !empty($emp['face_image']) ? htmlspecialchars($emp['face_image']) : '';
                ?>
                  <option value="<?= htmlspecialchars($emp['employee_id']) ?>"
                          data-profile="<?= $profile ?>"
                          data-enrolled="<?= $enrolled ?>"
                          data-face-image="<?= $face_image ?>">
                    <?= htmlspecialchars($emp['fullname'] . " (" . $emp['employee_id'] . ")") ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Employee Preview -->
            <div id="employeePreview" style="display:none" class="employee-preview mb-3">
              <div class="d-flex align-items-center gap-2">
                <img id="employeePhoto" src="../assets/images/default-profile.png" class="sample-thumb" alt="profile">
                <div style="flex: 1;">
                  <strong id="employeeName" style="display: block;"></strong>
                  <small id="employeeId" style="display: block;"></small>
                  <small id="enrollStatus" class="muted-small"></small>
                </div>
              </div>
            </div>

            <!-- Instructions -->
            <div class="alert alert-info mb-3">
              <strong>
                <ion-icon name="information-circle-outline"></ion-icon> Capture Instructions
              </strong>
              <p class="mb-0 mt-2">You will capture 3 samples: front, slight left, slight right. Keep the face centered and remove sunglasses if possible.</p>
            </div>

            <!-- Video Feed -->
            <div id="videoContainer" class="mb-3">
              <video id="video" autoplay muted playsinline></video>
              <canvas id="snapshotCanvas"></canvas>
            </div>

            <!-- Model Loading & Camera Controls -->
            <div class="mb-3">
              <div class="d-flex gap-2 flex-wrap">
                <button id="btnLoadModels" class="btn btn-outline-primary btn-sm">
                  <ion-icon name="download-outline"></ion-icon> Load Models
                </button>
                <button id="btnStart" class="btn btn-primary btn-sm" disabled>
                  <ion-icon name="camera-outline"></ion-icon> Start Camera
                </button>
                <button id="btnStop" class="btn btn-danger btn-sm" disabled>
                  <ion-icon name="close-outline"></ion-icon> Stop
                </button>
              </div>
            </div>

            <!-- Guidance Text -->
            <div class="guidance mb-3" id="guidanceText">
              <ion-icon name="bulb-outline"></ion-icon> Load models to begin
            </div>

            <!-- Sample Capture -->
            <div class="mb-3">
              <div class="d-flex gap-2 align-items-center flex-wrap">
                <button id="btnCaptureSample" class="btn btn-success btn-sm" disabled>
                  <ion-icon name="camera-outline"></ion-icon> Capture Sample
                </button>
                <div class="sample-progress" id="sampleProgress">0 / 3 samples</div>
                <div id="enrollSpinner" style="display:none" class="ms-auto text-muted small">
                  <span class="spinner-border spinner-border-sm me-2"></span>
                  Enrolling…
                </div>
              </div>
            </div>

            <!-- Sample Thumbnails -->
            <div id="sampleThumbs" class="mb-3 d-flex gap-2" style="min-height:90px; flex-wrap: wrap;"></div>

            <!-- Enroll Buttons -->
            <div class="d-flex gap-2 mb-3 flex-wrap">
              <button id="btnEnrollNow" class="btn btn-primary btn-sm" disabled>
                <ion-icon name="checkmark-circle-outline"></ion-icon> Enroll Now
              </button>
              <button id="btnPreviewReplace" class="btn btn-outline-warning btn-sm" disabled style="display:none;">
                <ion-icon name="eye-outline"></ion-icon> Preview Replacement
              </button>
            </div>

            <!-- Result Message -->
            <div id="resultMessage"></div>
          </div>
        </div>

        <!-- Info Section -->
        <div class="col-lg-7">
          <!-- Guidance Card -->
          <div class="card mb-3">
            <div class="card-header">
              <ion-icon name="information-circle-outline"></ion-icon> On-screen Guidance
            </div>
            <div class="card-body">
              <ul style="margin-bottom: 0;">
                <li><strong>Front:</strong> Look straight to camera, neutral expression.</li>
                <li><strong>Slight left:</strong> Turn head ~15° to the left.</li>
                <li><strong>Slight right:</strong> Turn head ~15° to the right.</li>
                <li><strong>Lighting:</strong> Good lighting and unobstructed face increases accuracy.</li>
                <li><strong>Quality:</strong> Ensure face is clearly visible and centered in frame.</li>
              </ul>
            </div>
          </div>

          <!-- Enrolled Status Card -->
          <div class="card">
            <div class="card-header">
              <ion-icon name="checkmark-circle-outline"></ion-icon> Enrollment Status
            </div>
            <div class="card-body p-0">
              <p class="text-muted small p-3 mb-0">After successful enrollment the system will store an encrypted template and example image(s).</p>
              <div class="table-responsive">
                <table class="table table-sm">
                  <thead class="table-light">
                    <tr>
                      <th>Name</th>
                      <th>ID</th>
                      <th>Enrolled</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                      $listSql = "SELECT fullname, employee_id, face_enrolled FROM employees ORDER BY fullname LIMIT 50";
                      if (isset($conn) && ($conn instanceof mysqli) && $res2 = $conn->query($listSql)) {
                        while ($row = $res2->fetch_assoc()) {
                          $en = ((int)$row['face_enrolled'] === 1) ? '<span class="status-badge success">Yes</span>' : '<span class="status-badge pending">No</span>';
                          echo "<tr><td>".htmlspecialchars($row['fullname'])."</td><td>".htmlspecialchars($row['employee_id'])."</td><td>{$en}</td></tr>";
                        }
                        $res2->free();
                      }
                    ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="previewModalLabel">
          <ion-icon name="eye-outline"></ion-icon> Preview Replacement
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <h6>Current Enrolled Image</h6>
            <div id="currentImageWrap">
              <img id="currentImage" src="../assets/images/default-profile.png" class="img-fluid" style="max-height:280px;object-fit:cover;border-radius:6px;" alt="Current">
            </div>
          </div>
          <div class="col-md-6">
            <h6>New Samples (Preview)</h6>
            <div id="previewSamples" class="d-flex gap-2" style="flex-wrap:wrap;"></div>
            <div class="mt-2">
              <small class="text-muted">If you confirm, the new samples will replace the existing template and image.</small>
            </div>
          </div>
        </div>
        <div class="mt-3" id="previewNotes"></div>
      </div>
      <div class="modal-footer">
        <button type="button" id="cancelPreviewBtn" class="btn btn-outline-secondary" data-bs-dismiss="modal">
          <ion-icon name="close-outline"></ion-icon> Cancel
        </button>
        <button type="button" id="confirmReplaceBtn" class="btn btn-warning">
          <ion-icon name="checkmark-circle-outline"></ion-icon> Replace Existing Sample
        </button>
      </div>
    </div>
  </div>
</div>

<!-- face-api.js + Bootstrap JS -->
<script src="https://unpkg.com/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const video = document.getElementById('video');
const canvas = document.getElementById('snapshotCanvas');
const btnLoadModels = document.getElementById('btnLoadModels');
const btnStart = document.getElementById('btnStart');
const btnStop = document.getElementById('btnStop');
const btnCaptureSample = document.getElementById('btnCaptureSample');
const guidanceText = document.getElementById('guidanceText');
const sampleProgress = document.getElementById('sampleProgress');
const sampleThumbs = document.getElementById('sampleThumbs');
const resultMessage = document.getElementById('resultMessage');
const select = document.getElementById('employeeSelect');
const employeePreview = document.getElementById('employeePreview');
const employeePhoto = document.getElementById('employeePhoto');
const employeeName = document.getElementById('employeeName');
const employeeIdEl = document.getElementById('employeeId');
const enrollSpinner = document.getElementById('enrollSpinner');
const btnEnrollNow = document.getElementById('btnEnrollNow');
const btnPreviewReplace = document.getElementById('btnPreviewReplace');

let stream = null;
let modelsLoaded = false;
let samples = [];
const REQUIRED_SAMPLES = 3;
const DETECTION_MIN_CONFIDENCE = 0.5;
const MIN_FACE_AREA_FRACTION = 0.03;

let currentEmployee = null;
let currentEmployeeEnrolled = false;
let currentEmployeeFaceImage = '';

const previewModalEl = document.getElementById('previewModal');
const previewModal = new bootstrap.Modal(previewModalEl);
const currentImage = document.getElementById('currentImage');
const previewSamples = document.getElementById('previewSamples');
const confirmReplaceBtn = document.getElementById('confirmReplaceBtn');

select.addEventListener('change', () => {
  const val = select.value;
  if (!val) {
    employeePreview.style.display = 'none';
    currentEmployee = null;
    currentEmployeeEnrolled = false;
    currentEmployeeFaceImage = '';
    btnEnrollNow.disabled = true;
    btnPreviewReplace.style.display = 'none';
    return;
  }
  const selected = select.options[select.selectedIndex];
  const prof = selected.getAttribute('data-profile');
  const enrolled = selected.getAttribute('data-enrolled') === '1';
  const faceImage = selected.getAttribute('data-face-image') || '';
  employeePhoto.src = faceImage ? faceImage : (prof ? prof : '../assets/images/default-profile.png');
  employeeName.textContent = selected.text;
  employeeIdEl.textContent = val;
  employeePreview.style.display = 'flex';
  currentEmployee = val;
  currentEmployeeEnrolled = enrolled;
  currentEmployeeFaceImage = faceImage;
  checkStartReady();
  btnEnrollNow.disabled = false;
  btnEnrollNow.textContent = enrolled ? '↻ Enroll New Sample' : '✓ Enroll Now';
  btnPreviewReplace.style.display = enrolled ? '' : 'none';
  btnPreviewReplace.disabled = true;
});

function checkStartReady(){
  btnStart.disabled = !modelsLoaded || !currentEmployee;
  btnCaptureSample.disabled = true;
}

async function checkModelFile(url) {
  try {
    const r = await fetch(url, { method: 'HEAD', cache: 'no-store' });
    return r.ok;
  } catch (err) {
    console.error('Model HEAD check failed for', url, err);
    return false;
  }
}

btnLoadModels.addEventListener('click', async () => {
  guidanceText.innerHTML = '<ion-icon name="timer-outline"></ion-icon> Checking model files availability...';
  btnLoadModels.disabled = true;

  const base = '/models';
  const manifests = [
    base + '/ssd_mobilenetv1_model-weights_manifest.json',
    base + '/face_landmark_68_model-weights_manifest.json',
    base + '/face_recognition_model-weights_manifest.json'
  ];

  const missing = [];
  for (const m of manifests) {
    const ok = await checkModelFile(m);
    if (!ok) missing.push(m);
  }

  if (missing.length > 0) {
    guidanceText.innerHTML = '<ion-icon name="alert-circle-outline"></ion-icon> Model files not accessible:<br><small style="color:#b91c1c; font-size: 0.85rem;">' + missing.join('<br>') + '</small><br><small>Ensure /models exists and files are readable.</small>';
    console.error('Missing models:', missing);
    btnLoadModels.disabled = false;
    return;
  }

  guidanceText.innerHTML = '<ion-icon name="download-outline"></ion-icon> Loading models (this may take a few seconds)...';
  try {
    await Promise.all([
      faceapi.nets.ssdMobilenetv1.loadFromUri('/models'),
      faceapi.nets.faceLandmark68Net.loadFromUri('/models'),
      faceapi.nets.faceRecognitionNet.loadFromUri('/models')
    ]);
    modelsLoaded = true;
    guidanceText.innerHTML = '<ion-icon name="checkmark-circle-outline"></ion-icon> Models loaded. Select employee and start camera.';
    btnLoadModels.disabled = true;
    checkStartReady();
  } catch (err) {
    guidanceText.innerHTML = '<ion-icon name="alert-circle-outline"></ion-icon> Failed to load models. Check console for details.';
    console.error('Model load failed:', err);
    btnLoadModels.disabled = false;
  }
});

btnStart.addEventListener('click', async () => {
  if (!modelsLoaded) { guidanceText.innerHTML = '<ion-icon name="alert-circle-outline"></ion-icon> Load models first.'; return; }
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
    video.srcObject = stream;
    btnStop.disabled = false;
    btnStart.disabled = true;
    btnCaptureSample.disabled = false;
    guidanceText.innerHTML = '<ion-icon name="camera-outline"></ion-icon> Camera started. Capture 3 samples: front, left, right.';
  } catch (err) {
    console.error(err);
    guidanceText.innerHTML = '<ion-icon name="alert-circle-outline"></ion-icon> Unable to access camera: ' + err.message;
  }
});

btnStop.addEventListener('click', () => {
  stopCamera();
  guidanceText.innerHTML = '<ion-icon name="pause-outline"></ion-icon> Camera stopped.';
});

function stopCamera(){
  if (stream) {
    stream.getTracks().forEach(t => t.stop());
    video.srcObject = null;
    stream = null;
  }
  btnStart.disabled = false;
  btnStop.disabled = true;
  btnCaptureSample.disabled = true;
}

function analyzeImageData(imgCanvas) {
  const ctx = imgCanvas.getContext('2d');
  const { width, height } = imgCanvas;
  const data = ctx.getImageData(0,0,width,height).data;
  let lumSum = 0;
  for (let i=0;i<data.length;i+=4){
    const r = data[i], g = data[i+1], b = data[i+2];
    const l = 0.2126*r + 0.7152*g + 0.0722*b;
    lumSum += l;
  }
  const mean = lumSum / (width*height);
  let variance = 0;
  for (let i=0;i<data.length;i+=4){
    const r = data[i], g = data[i+1], b = data[i+2];
    const l = 0.2126*r + 0.7152*g + 0.0722*b;
    variance += Math.pow(l - mean, 2);
  }
  variance = variance / (width*height);
  return { brightness: mean, contrast: variance };
}

btnCaptureSample.addEventListener('click', async () => {
  if (!currentEmployee) { alert('Select an employee first.'); return; }
  if (!stream) { alert('Start the camera first.'); return; }
  if (samples.length >= REQUIRED_SAMPLES) { guidanceText.innerHTML = '<ion-icon name="checkmark-circle-outline"></ion-icon> Already captured required samples.'; return; }
  guidanceText.innerHTML = '<ion-icon name="search-outline"></ion-icon> Detecting face...';

  canvas.width = video.videoWidth;
  canvas.height = video.videoHeight;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

  const detection = await faceapi.detectSingleFace(canvas).withFaceLandmarks().withFaceDescriptor();

  if (!detection) {
    guidanceText.innerHTML = '<ion-icon name="alert-circle-outline"></ion-icon> No face detected. Adjust lighting and position.';
    return;
  }

  const score = detection.detection.score || 0;
  if (score < DETECTION_MIN_CONFIDENCE) {
    guidanceText.innerHTML = '<ion-icon name="alert-circle-outline"></ion-icon> Low detection confidence. Move closer or improve lighting.';
    return;
  }

  const box = detection.detection.box;
  const faceArea = box.width * box.height;
  const frameArea = canvas.width * canvas.height;
  if (faceArea / frameArea < MIN_FACE_AREA_FRACTION) {
    guidanceText.innerHTML = '<ion-icon name="alert-circle-outline"></ion-icon> Face too small. Move closer.';
    return;
  }

  const analysis = analyzeImageData(canvas);
  if (analysis.brightness < 30) {
    guidanceText.innerHTML = '<ion-icon name="alert-circle-outline"></ion-icon> Image too dark. Improve lighting.';
    return;
  }
  if (analysis.contrast < 15) {
    guidanceText.innerHTML = '<ion-icon name="alert-circle-outline"></ion-icon> Image may be blurry. Try again.';
    return;
  }

  const descriptor = Array.from(detection.descriptor);
  const imageDataUrl = canvas.toDataURL('image/jpeg', 0.9);

  samples.push({ descriptor, image: imageDataUrl, meta: { score: score, brightness: analysis.brightness } });

  const img = document.createElement('img');
  img.src = imageDataUrl;
  img.className = 'sample-thumb';
  sampleThumbs.appendChild(img);

  sampleProgress.textContent = samples.length + ' / ' + REQUIRED_SAMPLES + ' samples';
  
  if (samples.length === 1) {
    guidanceText.innerHTML = '<ion-icon name="checkmark-circle-outline"></ion-icon> Sample captured. Now: slight left pose.';
  } else if (samples.length === 2) {
    guidanceText.innerHTML = '<ion-icon name="checkmark-circle-outline"></ion-icon> Sample captured. Now: slight right pose.';
  } else {
    guidanceText.innerHTML = '<ion-icon name="checkmark-circle-outline"></ion-icon> All samples captured! Ready to enroll.';
  }

  if (samples.length >= REQUIRED_SAMPLES) {
    btnEnrollNow.disabled = false;
    if (currentEmployeeEnrolled) {
      btnPreviewReplace.disabled = false;
    }
    btnCaptureSample.disabled = true;
  }
});

async function sendEnroll(payload) {
  btnEnrollNow.disabled = true;
  btnPreviewReplace.disabled = true;
  confirmReplaceBtn.disabled = true;
  enrollSpinner.style.display = 'inline';
  resultMessage.innerHTML = '';
  try {
    const resp = await fetch('enroll_save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      credentials: 'same-origin'
    });

    const text = await resp.text();
    let data;
    try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { success: false, error: 'Invalid JSON response', raw: text }; }

    if (resp.ok) {
      enrollSpinner.style.display = 'none';
      resultMessage.innerHTML = '<div class="alert alert-success small mb-0"><ion-icon name="checkmark-circle-outline"></ion-icon> ' + (data.action === 'update' ? 'Update' : 'Enrollment') + ' successful for ' + escapeHtml(data.employee || payload.employee_id) + '</div>';
      setTimeout(()=>location.reload(),900);
      return { ok: true, data };
    }

    if (resp.status === 409) {
      enrollSpinner.style.display = 'none';
      const matched = data.match || null;
      let html = '<div class="alert alert-warning small mb-2"><strong><ion-icon name="alert-circle-outline"></ion-icon> Possible duplicate detected.</strong><br>';
      if (matched) {
        html += 'Similar to: <strong>' + escapeHtml(matched.employee_id) + ' �� ' + escapeHtml(matched.fullname) + '</strong><br>';
        html += 'Distance: <strong>' + (typeof matched.distance !== 'undefined' ? Number(matched.distance).toFixed(4) : 'N/A') + '</strong><br>';
      }
      html += '<div class="mt-2">You can inspect the existing employee or choose to force the enrollment (logged).</div></div>';
      html += '<div><button id="forceBtn" class="btn btn-danger btn-sm">Force Replace (Override)</button> ';
      html += '<button id="dismissBtn" class="btn btn-outline-secondary btn-sm">Dismiss</button></div>';
      resultMessage.innerHTML = html;

      document.getElementById('dismissBtn').addEventListener('click', () => {
        resultMessage.innerHTML = '';
        btnEnrollNow.disabled = false;
        if (currentEmployeeEnrolled) btnPreviewReplace.disabled = false;
      });

      document.getElementById('forceBtn').addEventListener('click', async () => {
        if (!confirm('Are you sure you want to force the enrollment? This action will be logged.')) {
          return;
        }
        payload.force = true;
        resultMessage.innerHTML = '<div class="small text-muted">Forcing enrollment…</div>';
        try {
          const forced = await sendEnroll(payload);
          return forced;
        } catch (err) {
          console.error('Force enroll failed', err);
          resultMessage.innerHTML = '<div class="alert alert-danger small mb-0">Force failed</div>';
          btnEnrollNow.disabled = false;
          if (currentEmployeeEnrolled) btnPreviewReplace.disabled = false;
        }
      });

      return { ok: false, conflict: data };
    }

    enrollSpinner.style.display = 'none';
    resultMessage.innerHTML = '<div class="alert alert-danger small mb-0"><ion-icon name="alert-circle-outline"></ion-icon> Error: ' + escapeHtml(data.error || ('HTTP ' + resp.status)) + '</div>';
    btnEnrollNow.disabled = false;
    if (currentEmployeeEnrolled) btnPreviewReplace.disabled = false;
    return { ok: false, data };
  } catch (err) {
    console.error(err);
    enrollSpinner.style.display = 'none';
    resultMessage.innerHTML = '<div class="alert alert-danger small mb-0"><ion-icon name="alert-circle-outline"></ion-icon> Network or server error</div>';
    btnEnrollNow.disabled = false;
    if (currentEmployeeEnrolled) btnPreviewReplace.disabled = false;
    return { ok: false, error: err };
  } finally {
    confirmReplaceBtn.disabled = false;
  }
}

btnEnrollNow.addEventListener('click', async () => {
  if (!currentEmployee) { alert('Select employee'); return; }
  if (samples.length < REQUIRED_SAMPLES) { alert('Capture required samples first'); return; }
  const payload = {
    employee_id: currentEmployee,
    descriptor: averageDescriptors(samples.map(s => s.descriptor)),
    images: samples.map(s => s.image),
    update: false
  };
  await sendEnroll(payload);
});

btnPreviewReplace.addEventListener('click', () => {
  if (!currentEmployeeEnrolled) return;
  if (samples.length < REQUIRED_SAMPLES) { alert('Capture required samples first'); return; }
  previewSamples.innerHTML = '';
  for (const s of samples) {
    const im = document.createElement('img');
    im.src = s.image;
    im.className = 'sample-thumb';
    previewSamples.appendChild(im);
  }
  currentImage.src = currentEmployeeFaceImage ? currentEmployeeFaceImage : '../assets/images/default-profile.png';
  previewModal.show();
});

confirmReplaceBtn.addEventListener('click', async () => {
  confirmReplaceBtn.disabled = true;
  enrollSpinner.style.display = 'inline';
  const payload = {
    employee_id: currentEmployee,
    descriptor: averageDescriptors(samples.map(s => s.descriptor)),
    images: samples.map(s => s.image),
    update: true
  };
  const res = await sendEnroll(payload);
  enrollSpinner.style.display = 'none';
  confirmReplaceBtn.disabled = false;
  if (res && res.ok) previewModal.hide();
});

function averageDescriptors(arrays) {
  if (!arrays || arrays.length === 0) return [];
  const len = arrays[0].length;
  const sums = new Array(len).fill(0);
  for (let a of arrays) {
    for (let i=0;i<len;i++) sums[i] += Number(a[i]);
  }
  const avg = sums.map(s => s / arrays.length);
  return avg;
}

function escapeHtml(s) {
  if (s === null || s === undefined) return '';
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
</script>

</body>
</html>