<?php
// filepath: admin/attendance.php
// Real-time attendance UI with enhanced modern design
// Uses server-side initial query to match attendance_fetch.php structure
// Includes client handling for Early Time Out reason (Bootstrap modal)

session_start();
include_once("../connection.php");

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

// Require logged-in user
if (!isset($_SESSION['username']) || empty($_SESSION['username'])) {
    header("Location: ../login.php");
    exit();
}

$fullname = $_SESSION['fullname'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';

// Initial recent rows for server-side render (client will refresh)
$recent = [];
$query = "
  SELECT a.*,
         COALESCE(e1.fullname, e2.fullname, a.employee_id) AS fullname_display,
         COALESCE(e1.employee_id, e2.employee_id, a.employee_id) AS emp_code,
         COALESCE(e1.id, e2.id, NULL) AS emp_db_id,
         s.shift_type AS assigned_shift_type,
         s.shift_date AS assigned_shift_date,
         s.shift_start, s.shift_end
  FROM attendance a
  LEFT JOIN employees e1 ON e1.employee_id = a.employee_id
  LEFT JOIN employees e2 ON e2.id = CAST(a.employee_id AS UNSIGNED)
  LEFT JOIN shifts s ON ( (s.employee_id = e1.id OR s.employee_id = e2.id) AND s.shift_date = a.date )
  ORDER BY a.id DESC
  LIMIT 30
";
if ($res = $conn->query($query)) {
    while ($r = $res->fetch_assoc()) {
        $r['shift_start'] = isset($r['shift_start']) && $r['shift_start'] !== null ? substr($r['shift_start'], 0, 5) : $r['shift_start'];
        $r['shift_end']   = isset($r['shift_end'])   && $r['shift_end'] !== null   ? substr($r['shift_end'],   0, 5) : $r['shift_end'];
        if (!array_key_exists('method', $r)) $r['method'] = null;
        $recent[] = $r;
    }
    $res->free();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Time & Attendance - Clock In/Out - Admin Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
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

    .topbar h3 { 
      font-size: 1.8rem;
      font-weight: 800;
      margin: 0;
      color: #22223b;
      display: flex;
      align-items: center;
      gap: 0.8rem;
    }

    .topbar h3 ion-icon { font-size: 2rem; color: #9A66ff; }

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

    .camera-box { 
      background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
      border-radius: 18px; 
      padding: 1.5rem; 
      box-shadow: 0 4px 15px rgba(140,140,200,0.08);
      border: 1px solid #f0f0f0;
    }

    video { 
      width: 100%; 
      height: auto; 
      border-radius: 12px; 
      background: #000;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    canvas { display: none; }

    .camera-instructions { 
      background: #dbeafe;
      border-left: 4px solid #0284c7;
      color: #0c4a6e;
      padding: 1rem;
      border-radius: 8px;
      font-size: 0.95rem;
      margin-bottom: 1rem;
    }

    .camera-instructions strong { 
      display: flex;
      align-items: center;
      gap: 0.5rem;
      margin-bottom: 0.5rem;
    }

    .camera-controls { 
      display: flex; 
      gap: 0.8rem;
      flex-wrap: wrap;
      margin-top: 1.2rem;
    }

    .btn { 
      border: none;
      border-radius: 8px;
      font-weight: 600;
      transition: all 0.2s ease;
      padding: 0.65rem 1.2rem;
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

    .btn-sm { 
      padding: 0.5rem 1rem; 
      font-size: 0.85rem;
    }

    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none !important;
    }

    .status-box { 
      background: #f0f9ff;
      border-left: 4px solid #0284c7;
      padding: 1rem;
      border-radius: 8px;
      margin-top: 1rem;
      font-size: 0.95rem;
      color: #0c4a6e;
    }

    .status-box strong { display: flex; align-items: center; gap: 0.5rem; }

    .badge-assigned { 
      background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
      color: #065f46; 
      padding: 0.4rem 0.75rem; 
      border-radius: 6px;
      font-weight: 600;
      font-size: 0.85rem;
    }

    .badge-unassigned { 
      background: linear-gradient(135deg, #fee2e2 0%, #fca5a5 100%);
      color: #7f1d1d;
      padding: 0.4rem 0.75rem; 
      border-radius: 6px;
      font-weight: 600;
      font-size: 0.85rem;
    }

    .live-clock { 
      font-weight: 800; 
      font-size: 1.1rem;
      color: #9A66ff;
      font-variant-numeric: tabular-nums;
    }

    .highlight { 
      animation: highlightFlash 1.2s ease-in-out;
    }

    @keyframes highlightFlash { 
      0% { background: #e6ffe6; }
      50% { background: #fff; }
      100% { background: #e6ffe6; }
    }

    .card { 
      border-radius: 18px; 
      box-shadow: 0 4px 15px rgba(140,140,200,0.08);
      border: 1px solid #f0f0f0;
      background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
    }

    .card h5 { 
      font-weight: 700;
      color: #22223b;
      display: flex;
      align-items: center;
      gap: 0.6rem;
      font-size: 1.1rem;
    }

    .table { 
      font-size: 0.95rem; 
      color: #22223b;
    }

    .table th { 
      color: #6c757d;
      font-weight: 700;
      border-color: #e8e8f0;
      background: #f9f9fc;
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .table td { 
      border-color: #e8e8f0;
      vertical-align: middle;
    }

    .table tbody tr { 
      transition: all 0.2s ease;
    }

    .table tbody tr:hover { 
      background: #f8f9ff;
    }

    .result-message { 
      margin-top: 1rem;
      border-radius: 8px;
      padding: 1rem;
      border-left: 4px solid;
    }

    .alert-success {
      background: #d1fae5;
      border-left-color: #10b981;
      color: #065f46;
    }

    .alert-danger {
      background: #fee2e2;
      border-left-color: #ef4444;
      color: #7f1d1d;
    }

    .modal-content { 
      border-radius: 18px; 
      border: 1px solid #e0e7ff; 
      box-shadow: 0 10px 40px rgba(70, 57, 130, 0.15);
    }

    .modal-header { 
      background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
      color: #fff; 
      border-bottom: none; 
      border-radius: 18px 18px 0 0; 
      padding: 1.5rem;
    }

    .modal-title { 
      font-size: 1.23rem; 
      font-weight: 700;
    }

    .modal-body { 
      background: #fafbfc; 
      padding: 1.7rem 1.5rem;
      color: #22223b;
    }

    .modal-footer { 
      background: #fafbfc; 
      border-top: 1px solid #e0e7ff; 
      padding: 1.2rem 1.5rem;
      border-radius: 0 0 18px 18px;
    }

    .form-label { 
      font-weight: 600;
      color: #22223b;
      margin-bottom: 0.5rem;
    }

    .form-control, .form-select { 
      border-radius: 8px;
      border: 1px solid #e0e7ff;
      padding: 0.7rem 1rem;
    }

    .form-control:focus, .form-select:focus { 
      border-color: #9A66ff;
      box-shadow: 0 0 0 0.2rem rgba(154, 102, 255, 0.15);
      outline: none;
    }

    .btn-close { filter: brightness(1.8); }

    .notes-box { 
      background: #f0f9ff;
      border-left: 4px solid #0284c7;
      padding: 1.2rem;
      border-radius: 8px;
    }

    .notes-box h5 { 
      color: #0c4a6e;
      font-weight: 700;
      margin-bottom: 0.8rem;
      display: flex;
      align-items: center;
      gap: 0.6rem;
    }

    .notes-box ul { 
      margin: 0;
      padding-left: 1.5rem;
      color: #0c4a6e;
      font-size: 0.95rem;
    }

    .notes-box li { 
      margin-bottom: 0.6rem;
    }

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
      .topbar { 
        padding: 1rem 1.5rem;
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
      }
    }

    @media (max-width: 700px) { 
      .topbar h3 { font-size: 1.4rem; }
      .main-content { padding: 1rem 0.8rem; } 
      .sidebar { width: 100%; left: -100%; } 
      .sidebar.show { left: 0; } 
      .camera-controls { flex-direction: column; }
      .camera-controls .btn { width: 100%; }
      .modal-body { padding: 1.2rem; }
      .modal-footer { padding: 1rem 1.2rem; }
    }

    @media (max-width: 500px) { 
      .sidebar { width: 100%; left: -100%; } 
      .sidebar.show { left: 0; } 
      .main-content { padding: 0.8rem 0.5rem; }
      .topbar h3 { font-size: 1.2rem; }
      .table { font-size: 0.85rem; }
      .table th, .table td { padding: 0.5rem; }
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
          <a class="nav-link active" href="attendance.php"><ion-icon name="timer-outline"></ion-icon>Employee Clock In/Out</a>
          <a class="nav-link" href="attendance_logs.php"><ion-icon name="list-outline"></ion-icon>Attendance Logs</a>
          <a class="nav-link" href="attendance_stats.php"><ion-icon name="stats-chart-outline"></ion-icon>Attendance Statistics</a>
          <a class="nav-link" href="face_enrollment.php"><ion-icon name="camera-outline"></ion-icon>Face Enrollment</a>
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
        <h3>
          <ion-icon name="timer-outline"></ion-icon> Employee Clock In / Clock Out
        </h3>
        <small class="text-muted">Face-based verification using registered face embeddings</small>
      </div>
      <div class="profile">
        <div class="live-clock" id="liveClock">--:--:--</div>
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
        <div class="col-lg-6">
          <div class="camera-box">
            <div class="camera-instructions">
              <strong>
                <ion-icon name="information-circle-outline"></ion-icon> How to use Camera:
              </strong>
              <div>1. Click "Load Models" to initialize face detection</div>
              <div>2. Click "Start Camera" to activate your webcam</div>
              <div>3. Position your face centrally in the frame</div>
              <div>4. Click "Capture" to verify and clock in/out</div>
              <div>5. Click "Stop" when finished</div>
            </div>

            <div id="videoContainer">
              <video id="video" autoplay muted playsinline></video>
              <canvas id="snapshotCanvas"></canvas>
            </div>

            <div class="camera-controls">
              <button id="btnLoadModels" class="btn btn-outline-primary btn-sm">
                <ion-icon name="download-outline"></ion-icon> Load Models
              </button>
              <button id="btnStart" class="btn btn-primary btn-sm" disabled>
                <ion-icon name="camera-outline"></ion-icon> Start Camera
              </button>
              <button id="btnCapture" class="btn btn-success btn-sm" disabled>
                <ion-icon name="camera-outline"></ion-icon> Capture
              </button>
              <button id="btnStop" class="btn btn-danger btn-sm" disabled>
                <ion-icon name="stop-outline"></ion-icon> Stop
              </button>
            </div>

            <div class="status-box">
              <strong id="status">🔍 Models not loaded.</strong>
            </div>

            <div id="resultMessage"></div>
          </div>
        </div>

        <div class="col-lg-6">
          <!-- Recent Attendance Card -->
          <div class="card p-3 mb-3">
            <h5>
              <ion-icon name="history-outline"></ion-icon> Recent Attendance (Latest 30)
            </h5>
            <div style="max-height:420px; overflow-y:auto; border-radius: 10px;">
              <table class="table table-sm mb-0">
                <thead style="position: sticky; top: 0; background: #f9f9fc; z-index: 10;">
                  <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Emp ID</th>
                    <th>Date</th>
                    <th>In</th>
                    <th>Out</th>
                    <th>Method</th>
                    <th>Assigned</th>
                  </tr>
                </thead>
                <tbody id="recentTbody">
                  <?php $i=0; foreach ($recent as $row): $i++; ?>
                    <tr data-att-id="<?= htmlspecialchars($row['id']) ?>">
                      <td><?= $i ?></td>
                      <td><strong><?= htmlspecialchars($row['fullname_display'] ?? 'Unknown') ?></strong></td>
                      <td><?= htmlspecialchars($row['emp_code'] ?? $row['employee_id']) ?></td>
                      <td><?= htmlspecialchars($row['date']) ?></td>
                      <td><?= htmlspecialchars($row['time_in']) ?></td>
                      <td><?= htmlspecialchars($row['time_out']) ?></td>
                      <td>
                        <span style="background: #ede9fe; color: #4311a5; padding: 0.3rem 0.6rem; border-radius: 4px; font-weight: 600; font-size: 0.8rem;">
                          <?= htmlspecialchars($row['method'] ?? 'face') ?>
                        </span>
                      </td>
                      <td>
                        <?php if (!empty($row['assigned_shift_type'])): 
                            $start = !empty($row['shift_start']) ? $row['shift_start'] : '';
                            $end = !empty($row['shift_end']) ? $row['shift_end'] : '';
                            $label = htmlspecialchars($row['assigned_shift_type']);
                        ?>
                          <div><span class="badge-assigned"><?= $label ?></span></div>
                          <?php if ($start || $end): ?>
                            <small class="text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars(($start?:'') . ($start? '–' : '') . ($end?:'')) ?></small>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="badge-unassigned">No Shift</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; if ($i===0): ?>
                    <tr id="noRecordsRow"><td colspan="8" class="text-center text-muted py-3" style="font-size: 0.95rem;">No attendance records yet.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Notes Card -->
          <div class="card p-3">
            <h5>
              <ion-icon name="document-text-outline"></ion-icon> Important Notes
            </h5>
            <div class="notes-box">
              <ul>
                <li><strong>Shift Assignment:</strong> Only employees assigned to a shift on the current date are permitted to punch. If you are not scheduled you will see the message: "You are not scheduled today".</li>
                <li><strong>Time-Window Blocking:</strong> Time-window blocking (early/late blocking) has been removed; actual timestamps are recorded and classification (on-time/late/overtime/early out) is computed after punches.</li>
                <li><strong>Admin Override:</strong> Admins/schedulers can override via an authenticated session using the override flag (for emergency/testing), if enabled.</li>
                <li><strong>Early Time Out:</strong> If clocking out before the shift end time, you will be asked to provide a reason for early time out. This will be recorded and flagged for HR review.</li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Early-Out Reason Modal (Bootstrap) -->
<div class="modal fade" id="earlyOutModal" tabindex="-1" aria-labelledby="earlyOutModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="earlyOutModalLabel">
          <ion-icon name="warning-outline"></ion-icon> Early Time Out — Reason Required
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p style="margin-bottom: 1rem;">Your clock-out is before the scheduled shift end. Please provide a reason for Early Time Out. This will be recorded and flagged for HR review.</p>
        <div class="mb-3">
          <label for="earlyReason" class="form-label">Reason <span class="text-danger">*</span></label>
          <textarea id="earlyReason" class="form-control" rows="4" placeholder="Enter reason for early time out (required)" style="resize: vertical;"></textarea>
        </div>
        <div id="earlyReasonMsg" class="small text-danger mb-2"></div>
      </div>
      <div class="modal-footer">
        <button type="button" id="earlyCancelBtn" class="btn btn-outline-secondary" data-bs-dismiss="modal">
          <ion-icon name="close-outline"></ion-icon> Cancel
        </button>
        <button type="button" id="earlySubmitBtn" class="btn btn-primary">
          <ion-icon name="checkmark-outline"></ion-icon> Submit Reason & Time Out
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
const btnLoadModels = document.getElementById('btnLoadModels');
const btnStart = document.getElementById('btnStart');
const btnCapture = document.getElementById('btnCapture');
const btnStop = document.getElementById('btnStop');
const status = document.getElementById('status');
const snapshotCanvas = document.getElementById('snapshotCanvas');
const resultMessage = document.getElementById('resultMessage');
const recentTbody = document.getElementById('recentTbody');
const liveClock = document.getElementById('liveClock');

const earlyOutModalEl = document.getElementById('earlyOutModal');
const earlyOutModal = new bootstrap.Modal(earlyOutModalEl);
const earlyReasonEl = document.getElementById('earlyReason');
const earlySubmitBtn = document.getElementById('earlySubmitBtn');
const earlyReasonMsg = document.getElementById('earlyReasonMsg');

let stream = null;
let modelsLoaded = false;
const pollingIntervalMs = 5000;

let pendingPayloadForEarlyOut = null;

function updateClock() {
  const now = new Date();
  liveClock.textContent = now.toLocaleTimeString();
}
setInterval(updateClock, 1000);
updateClock();

btnLoadModels.addEventListener('click', async () => {
  status.textContent = '⏳ Loading models...';
  try {
    await Promise.all([
      faceapi.nets.ssdMobilenetv1.loadFromUri('/models'),
      faceapi.nets.faceLandmark68Net.loadFromUri('/models'),
      faceapi.nets.faceRecognitionNet.loadFromUri('/models')
    ]);
    modelsLoaded = true;
    status.textContent = '✅ Models loaded successfully. Start camera.';
    btnStart.disabled = false;
    btnLoadModels.disabled = true;
  } catch (err) {
    status.textContent = '❌ Failed to load models. Check /models path and network.';
    console.error(err);
  }
});

btnStart.addEventListener('click', async () => {
  if (!modelsLoaded) { 
    status.textContent = '⚠️ Load models first.'; 
    return; 
  }
  try {
    stream = await navigator.mediaDevices.getUserMedia({ 
      video: { facingMode: 'user' }, 
      audio: false 
    });
    video.srcObject = stream;
    btnCapture.disabled = false;
    btnStop.disabled = false;
    status.textContent = '📹 Camera started. Position your face and click Capture.';
  } catch (err) {
    console.error(err);
    status.textContent = '❌ Unable to access camera: ' + err.message;
  }
});

btnStop.addEventListener('click', () => {
  if (stream) {
    stream.getTracks().forEach(t => t.stop());
    video.srcObject = null;
    btnCapture.disabled = true;
    btnStop.disabled = true;
    status.textContent = '⏹️ Camera stopped.';
  }
});

async function fetchRecentAttendance() {
  try {
    const resp = await fetch('attendance_fetch.php', { credentials: 'same-origin' });
    if (!resp.ok) throw new Error('Fetch failed: ' + resp.status);
    const data = await resp.json();
    if (!Array.isArray(data.rows)) return;
    renderRecentRows(data.rows);
  } catch (err) {
    console.error('fetchRecentAttendance error', err);
  }
}

function renderRecentRows(rows) {
  if (!recentTbody) return;
  recentTbody.innerHTML = '';
  if (!rows.length) {
    recentTbody.innerHTML = '<tr id="noRecordsRow"><td colspan="8" class="text-center text-muted py-3">No attendance records yet.</td></tr>';
    return;
  }
  let i = 0;
  for (const r of rows) {
    i++;
    const tr = document.createElement('tr');
    tr.dataset.attId = r.id;
    const assignedHtml = r.assigned_shift_type ? 
      `<div><span class="badge-assigned">${escapeHtml(r.assigned_shift_type)}</span>${r.shift_start||r.shift_end ? '<br><small class="text-muted" style="font-size: 0.75rem;">' + escapeHtml((r.shift_start||'') + (r.shift_start ? '–' : '') + (r.shift_end||'')) + '</small>' : ''}</div>` : 
      '<span class="badge-unassigned">No Shift</span>';
    tr.innerHTML = `
      <td>${i}</td>
      <td><strong>${escapeHtml(r.fullname_display || 'Unknown')}</strong></td>
      <td>${escapeHtml(r.emp_code || r.employee_id)}</td>
      <td>${escapeHtml(r.date || '')}</td>
      <td>${escapeHtml(r.time_in || '')}</td>
      <td>${escapeHtml(r.time_out || '')}</td>
      <td><span style="background: #ede9fe; color: #4311a5; padding: 0.3rem 0.6rem; border-radius: 4px; font-weight: 600; font-size: 0.8rem;">${escapeHtml(r.method || 'face')}</span></td>
      <td>${assignedHtml}</td>
    `;
    recentTbody.appendChild(tr);
  }
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

setInterval(fetchRecentAttendance, pollingIntervalMs);
fetchRecentAttendance();

btnCapture.addEventListener('click', async () => {
  status.textContent = '🔍 Detecting face and computing descriptor...';
  snapshotCanvas.width = video.videoWidth;
  snapshotCanvas.height = video.videoHeight;
  const ctx = snapshotCanvas.getContext('2d');
  ctx.drawImage(video, 0, 0, snapshotCanvas.width, snapshotCanvas.height);

  const detection = await faceapi.detectSingleFace(snapshotCanvas).withFaceLandmarks().withFaceDescriptor();
  if (!detection) {
    status.textContent = '❌ No face detected. Try again with better lighting and frontal pose.';
    resultMessage.innerHTML = '';
    return;
  }

  const descriptor = Array.from(detection.descriptor);
  const imgDataUrl = snapshotCanvas.toDataURL('image/jpeg', 0.8);
  const payload = { descriptor: descriptor, image: imgDataUrl };

  status.textContent = '📤 Sending to server for verification...';

  try {
    const resp = await fetch('verify_attendance.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      credentials: 'same-origin'
    });
    const data = await resp.json();
    
    if (!data.success && data.require_early_out_reason) {
      pendingPayloadForEarlyOut = payload;
      earlyReasonEl.value = '';
      earlyReasonMsg.textContent = '';
      earlyOutModal.show();
      status.textContent = '⏱️ Early Time Out requires reason';
      return;
    }

    if (data.success) {
      const icon = data.action === 'Clock In' ? '✅' : '🔓';
      resultMessage.innerHTML = `<div class="result-message alert-success">${icon} <strong>${escapeHtml(data.employee)} (${escapeHtml(data.employee_id)})</strong> — ${escapeHtml(data.action_time)}</div>`;
      status.textContent = '✅ Verified: ' + data.employee;
      await fetchRecentAttendance();
      highlightLatestRow(data.employee_id, data.action_time);
    } else {
      const errorMsg = (data.code === 'approved_leave' ? '⛔ ' : data.code === 'duplicate_clock' ? '🚫 ' : '❌ ') + data.error;
      resultMessage.innerHTML = `<div class="result-message alert-danger">${errorMsg}</div>`;
      status.textContent = '❌ Verification failed';
    }
  } catch (err) {
    console.error(err);
    resultMessage.innerHTML = `<div class="result-message alert-danger">❌ Network/server error</div>`;
    status.textContent = '❌ Verification failed';
  }
});

earlySubmitBtn.addEventListener('click', async () => {
  const reason = earlyReasonEl.value.trim();
  if (!reason) { 
    earlyReasonMsg.textContent = 'Reason is required for Early Time Out.'; 
    return; 
  }
  if (!pendingPayloadForEarlyOut) {
    earlyReasonMsg.textContent = 'No pending punch found. Please capture again.';
    return;
  }
  pendingPayloadForEarlyOut.early_out_reason = reason;
  earlySubmitBtn.disabled = true;
  earlyReasonMsg.textContent = '';
  try {
    const resp = await fetch('verify_attendance.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(pendingPayloadForEarlyOut),
      credentials: 'same-origin'
    });
    const data = await resp.json();
    earlySubmitBtn.disabled = false;
    earlyOutModal.hide();
    pendingPayloadForEarlyOut = null;
    if (data.success) {
      resultMessage.innerHTML = `<div class="result-message alert-success">✅ <strong>${escapeHtml(data.employee)} (${escapeHtml(data.employee_id)})</strong> — ${escapeHtml(data.action_time)}</div>`;
      status.textContent = '✅ Verified: ' + data.employee;
      await fetchRecentAttendance();
      highlightLatestRow(data.employee_id, data.action_time);
    } else {
      const errorMsg = (data.code === 'approved_leave' ? '⛔ ' : data.code === 'duplicate_clock' ? '🚫 ' : '❌ ') + data.error;
      resultMessage.innerHTML = `<div class="result-message alert-danger">${errorMsg}</div>`;
      status.textContent = '❌ Verification failed';
    }
  } catch (err) {
    console.error(err);
    earlySubmitBtn.disabled = false;
    earlyReasonMsg.textContent = 'Network error while submitting reason.';
  }
});

function highlightLatestRow(empCode, actionTime) {
  const rows = recentTbody.querySelectorAll('tr');
  let found = null;
  for (const tr of rows) {
    const cells = tr.children;
    const code = cells[2]?.textContent?.trim();
    const timeIn = cells[4]?.textContent?.trim();
    const timeOut = cells[5]?.textContent?.trim();
    if (code === empCode && (timeIn === actionTime || timeOut === actionTime || timeIn.startsWith(actionTime.substring(0,16)) || timeOut.startsWith(actionTime.substring(0,16)))) {
      found = tr;
      break;
    }
  }
  if (found) {
    found.classList.add('highlight');
    setTimeout(()=> found.classList.remove('highlight'), 2000);
    found.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}
</script>

</body>
</html>