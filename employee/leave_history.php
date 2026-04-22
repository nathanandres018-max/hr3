<?php
/**
 * leave_history.php
 * Employee leave history with LIVE FACE DETECTION for identity verification.
 * Uses face-api.js + blink liveness verification before showing leave records.
 */

session_start();
include_once("../connection.php");

// === ANTI-BYPASS: Prevent browser caching of protected pages ===
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// ===== AJAX ENDPOINT: Fetch leave history =====
if (isset($_GET['action']) && $_GET['action'] === 'fetch_history' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    // AJAX also requires valid session
    if (!isset($_SESSION['username']) || empty($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Regular') {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $emp_id = trim($_POST['emp_id'] ?? '');
    if (empty($emp_id)) { echo json_encode(['leaves' => []]); exit; }

    $stmt = mysqli_prepare($conn, "SELECT id FROM employees WHERE employee_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $emp_id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $emp = mysqli_fetch_assoc($r);
    mysqli_stmt_close($stmt);
    if (!$emp) { echo json_encode(['leaves' => []]); exit; }

    $stmt = mysqli_prepare($conn, "SELECT * FROM leave_requests WHERE employee_id = ? ORDER BY requested_at DESC LIMIT 100");
    mysqli_stmt_bind_param($stmt, 'i', $emp['id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $leaves = [];
    while ($row = mysqli_fetch_assoc($result)) { $leaves[] = $row; }
    mysqli_stmt_close($stmt);

    echo json_encode(['leaves' => $leaves]);
    exit;
}

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Leave History - Employee Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
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

    /* === CARDS === */
    .content-card {
      background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
      border-radius: 18px; padding: 1.5rem;
      box-shadow: 0 4px 15px rgba(140,140,200,0.08);
      border: 1px solid #f0f0f0; max-width: 1100px; margin: 0 auto 1.5rem auto;
    }
    .content-card h5 {
      font-size: 1.13rem; font-weight: 700; color: #22223b; margin-bottom: 1.2rem;
      display: flex; align-items: center; gap: 0.6rem;
    }
    .content-card h5 ion-icon { color: #9A66ff; font-size: 1.3rem; }

    /* === CAMERA === */
    .camera-wrapper {
      position: relative; background: #000; border-radius: 12px; overflow: hidden;
      aspect-ratio: 16 / 9; margin-bottom: 1.5rem;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      display: flex; align-items: center; justify-content: center; min-height: 320px;
    }
    .camera-wrapper video { width: 100%; height: 100%; object-fit: cover; display: block; }
    .camera-wrapper canvas { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
    .camera-id-overlay {
      position: absolute; bottom: 12px; left: 12px; right: 12px;
      background: rgba(0,0,0,0.72); backdrop-filter: blur(6px); color: #fff;
      border-radius: 10px; padding: 0.7rem 1rem; display: none;
      align-items: center; gap: 0.8rem; z-index: 10; font-size: 0.92rem;
    }
    .camera-id-overlay.show { display: flex; }
    .camera-id-overlay .overlay-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #22c55e; }
    .camera-id-overlay .overlay-name { font-weight: 700; font-size: 1rem; }
    .camera-id-overlay .overlay-id { font-size: 0.82rem; color: #a5b4fc; }
    .camera-id-overlay .overlay-score { margin-left: auto; font-weight: 800; font-size: 1.1rem; color: #22c55e; }

    /* === STATUS === */
    .status-indicator {
      display: flex; align-items: center; gap: 0.8rem; padding: 1rem;
      border-radius: 8px; margin-bottom: 1rem; font-weight: 600; font-size: 0.95rem;
    }
    .status-indicator.waiting { background: #dbeafe; color: #0c4a6e; border-left: 4px solid #0284c7; }
    .status-indicator.detecting { background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; animation: pulse 1s infinite; }
    .status-indicator.detected { background: #dcfce7; color: #15803d; border-left: 4px solid #22c55e; }
    .status-indicator.error { background: #fee2e2; color: #7f1d1d; border-left: 4px solid #ef4444; }
    .status-indicator.verified { background: #dbeafe; color: #0c4a6e; border-left: 4px solid #0284c7; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }
    .status-icon { font-size: 1.2rem; display: inline-flex; align-items: center; }
    .blink-counter {
      display: flex; align-items: center; gap: 0.5rem; padding: 0.8rem 1.2rem;
      background: #f0f9ff; border: 1px solid #0284c7; border-radius: 8px;
      margin-bottom: 1rem; font-weight: 600; text-align: center; color: #0c4a6e; justify-content: center;
    }
    .blink-counter span { font-size: 1.5rem; color: #9A66ff; }
    .face-stats {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 1rem; margin-bottom: 1.5rem; padding: 1rem;
      background: #f8f9ff; border-radius: 8px; border: 1px solid #e0e7ff;
    }
    .stat-item { text-align: center; }
    .stat-label { font-size: 0.85rem; color: #6c757d; font-weight: 600; margin-bottom: 0.5rem; }
    .stat-value { font-size: 1.4rem; font-weight: 800; color: #22223b; }

    /* === ID PANEL === */
    .id-panel {
      position: relative; margin-bottom: 1.5rem; border-radius: 14px; overflow: hidden;
      border: 2px solid #e0e7ff; background: linear-gradient(135deg, #f8f9ff 0%, #fff 100%);
      box-shadow: 0 4px 18px rgba(140,140,200,0.10); transition: all 0.4s ease;
    }
    .id-panel.identified { border-color: #22c55e; box-shadow: 0 4px 24px rgba(34,197,94,0.18); }
    .id-panel-header {
      display: flex; align-items: center; gap: 0.7rem; padding: 0.8rem 1.2rem;
      font-weight: 700; font-size: 0.95rem; color: #fff;
      background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
    }
    .id-panel-header ion-icon { font-size: 1.3rem; }
    .id-panel-header .live-badge {
      margin-left: auto; background: #ef4444; color: #fff; font-size: 0.72rem;
      font-weight: 800; padding: 0.18rem 0.55rem; border-radius: 20px;
      letter-spacing: 1.2px; animation: livePulse 1.5s infinite;
    }
    @keyframes livePulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
    .id-panel-body {
      display: flex; align-items: center; gap: 1.5rem; padding: 1.2rem 1.5rem; min-height: 100px;
    }
    .id-avatar {
      width: 72px; height: 72px; border-radius: 50%; object-fit: cover;
      border: 3px solid #9A66ff; background: #e0e7ff; flex-shrink: 0;
    }
    .id-info { flex: 1; min-width: 0; }
    .id-name { font-size: 1.35rem; font-weight: 800; color: #22223b; margin-bottom: 0.15rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .id-emp-id { font-size: 0.95rem; color: #6c757d; font-weight: 600; margin-bottom: 0.4rem; }
    .id-match-bar-container { width: 100%; height: 8px; background: #e0e7ff; border-radius: 4px; overflow: hidden; margin-top: 0.3rem; }
    .id-match-bar { height: 100%; border-radius: 4px; background: linear-gradient(90deg, #22c55e 0%, #10b981 100%); transition: width 0.5s ease; }
    .id-match-label { font-size: 0.82rem; color: #6c757d; font-weight: 600; margin-top: 0.25rem; }
    .id-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%; padding: 0.5rem; text-align: center; color: #9ca3af; }
    .id-placeholder ion-icon { font-size: 2.2rem; margin-bottom: 0.4rem; color: #c4b5fd; }
    .id-placeholder .id-placeholder-text { font-size: 0.92rem; font-weight: 600; }
    .id-placeholder .id-placeholder-sub { font-size: 0.8rem; color: #c4b5fd; margin-top: 0.15rem; }

    /* === CONTROLS === */
    .controls { display: flex; gap: 0.8rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .btn-cam { border: none; border-radius: 8px; font-weight: 600; transition: all 0.2s ease; padding: 0.65rem 1.2rem; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; }
    .btn-cam-primary { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: white; }
    .btn-cam-primary:hover:not(:disabled) { background: linear-gradient(90deg, #8654e0 0%, #360090 100%); transform: translateY(-2px); }
    .btn-cam-danger { background: #ef4444; color: white; }
    .btn-cam-danger:hover:not(:disabled) { background: #dc2626; transform: translateY(-2px); }
    .btn-cam-secondary { background: #6b7280; color: white; }
    .btn-cam-secondary:hover:not(:disabled) { background: #4b5563; transform: translateY(-2px); }
    .btn-cam:disabled { opacity: 0.6; cursor: not-allowed; transform: none !important; }

    /* === ALERTS === */
    .alert-box { padding: 1rem; border-radius: 12px; margin-bottom: 1.2rem; font-size: 0.95rem; border-left: 4px solid; }
    .alert-success-box { background: #dcfce7; color: #15803d; border-color: #22c55e; }
    .alert-danger-box { background: #fee2e2; color: #7f1d1d; border-color: #ef4444; }

    /* === INFO BOX === */
    .info-box {
      background: #f0f9ff; border-left: 4px solid #0284c7; padding: 1rem;
      border-radius: 8px; color: #0c4a6e; font-size: 0.95rem; margin-bottom: 1rem;
    }
    .info-box strong { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.5rem; }
    .info-box ul { margin: 0; padding-left: 1.5rem; }
    .info-box li { margin-bottom: 0.4rem; }

    /* === EMPLOYEE INFO BAR === */
    .employee-info-bar {
      display: flex; align-items: center; gap: 1rem; padding: 1rem 1.2rem;
      background: linear-gradient(90deg, #f0f9ff 0%, #e8f4fd 100%);
      border-left: 4px solid #22c55e; border-radius: 10px; margin-bottom: 1.2rem;
    }
    .employee-info-bar ion-icon { font-size: 2rem; color: #22c55e; }
    .employee-info-bar .info-text { line-height: 1.4; }
    .employee-info-bar .info-text strong { font-size: 1.05rem; color: #22223b; }
    .employee-info-bar .info-text small { color: #6c757d; display: block; font-size: 0.88rem; }

    /* === TABLE === */
    .table { font-size: 0.95rem; color: #22223b; background: #fff; border-radius: 12px; overflow: hidden; }
    .table thead th {
      background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
      color: #fff; font-weight: 600; font-size: 0.9rem; border: none;
      text-align: center; padding: 0.8rem 0.6rem;
    }
    .table td { text-align: center; vertical-align: middle; border-color: #f0f0f0; padding: 0.7rem 0.6rem; }
    .table tbody tr:hover { background: #f8f9ff; }
    .status-badge { display: inline-block; padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.82rem; font-weight: 700; }
    .status-approved { background: #dcfce7; color: #15803d; }
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-rejected { background: #fee2e2; color: #7f1d1d; }
    .status-success { background: #dbeafe; color: #2563eb; }
    .empty-state { text-align: center; padding: 2rem; color: #9ca3af; }
    .empty-state ion-icon { font-size: 3rem; color: #c4b5fd; margin-bottom: 0.5rem; }

    /* === FACE DETECTION CARD COLLAPSE === */
    #faceDetectionCard {
      max-height: 3000px; overflow: hidden;
      transition: max-height 0.7s ease, opacity 0.5s ease, padding 0.7s ease, margin 0.7s ease, border 0.5s ease;
    }
    #faceDetectionCard.collapsing {
      max-height: 0 !important; opacity: 0; padding: 0 1.5rem !important;
      margin-bottom: 0 !important; border-color: transparent !important;
    }
    #faceDetectionCard.collapsed { display: none; }

    /* === HISTORY SECTION TRANSITION === */
    .history-section {
      display: none; opacity: 0; transform: translateY(30px);
    }
    .history-section.show {
      display: block;
      animation: historySlideIn 0.6s ease forwards;
      animation-delay: 0.4s;
    }
    @keyframes historySlideIn {
      from { opacity: 0; transform: translateY(30px); }
      to { opacity: 1; transform: translateY(0); }
    }

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
    }
    @media (max-width: 700px) {
      .topbar h3 { font-size: 1.4rem; } .content-card { padding: 1rem; }
      .table { font-size: 0.85rem; }
      .controls { flex-direction: column; } .controls .btn-cam { width: 100%; justify-content: center; }
      .camera-wrapper { min-height: 240px; }
      .id-panel-body { flex-direction: column; text-align: center; gap: 0.8rem; padding: 1rem; }
      .id-name { white-space: normal; font-size: 1.15rem; }
      .face-stats { grid-template-columns: repeat(2, 1fr); gap: 0.6rem; }
    }
    @media (max-width: 500px) {
      .sidebar { width: 85vw; left: -85vw; }
      .sidebar.show { left: 0; }
      .main-content { padding: 0.8rem 0.5rem; }
      .topbar h3 { font-size: 1.2rem; } .topbar { padding: 1rem 0.8rem; padding-left: 4rem; }
      .camera-wrapper { min-height: 200px; aspect-ratio: 4/3; }
      .content-card { padding: 0.8rem; border-radius: 14px; }
      .id-avatar { width: 56px; height: 56px; }
      .btn-cam { padding: 0.8rem 1rem; font-size: 1rem; }
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
          <a class="nav-link" href="../employee/employee_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
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
          <a class="nav-link active" href="../employee/leave_history.php"><ion-icon name="calendar-outline"></ion-icon>Leave History</a>
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
        <h3><ion-icon name="calendar-outline"></ion-icon> Leave History</h3>
        <small class="text-muted">Verify your identity with face detection, then view your leave records</small>
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
    <div class="main-content">

      <!-- ============================================= -->
      <!-- STEP 1: FACE DETECTION & LIVENESS             -->
      <!-- ============================================= -->
      <div class="content-card" id="faceDetectionCard">
        <h5><ion-icon name="camera-outline"></ion-icon> Step 1: Verify Your Identity</h5>

        <div class="info-box">
          <strong><ion-icon name="information-circle-outline"></ion-icon> How to verify:</strong>
          <ul>
            <li>Click <strong>"Start Camera"</strong> to activate your webcam</li>
            <li>Position your face in the center of the camera frame</li>
            <li>The system will detect and identify you automatically</li>
            <li><strong>Blink your eyes at least twice</strong> to verify you are a live person</li>
            <li>Once verified, your leave history will appear below</li>
          </ul>
        </div>

        <!-- Status Indicator -->
        <div id="statusIndicator" class="status-indicator waiting">
          <span class="status-icon">&#8505;&#65039;</span>
          <span id="statusText">Ready to start. Click "Start Camera"</span>
        </div>

        <!-- Blink Counter -->
        <div id="blinkCounter" class="blink-counter" style="display: none;">
          <ion-icon name="eye-outline"></ion-icon>
          Blinks detected: <span id="blinkCount">0</span> / 2
        </div>

        <!-- Face Statistics -->
        <div id="faceStats" class="face-stats" style="display: none;">
          <div class="stat-item"><div class="stat-label">Face Detected</div><div class="stat-value" id="faceDetected">No</div></div>
          <div class="stat-item"><div class="stat-label">Confidence</div><div class="stat-value" id="confidence">--</div></div>
          <div class="stat-item"><div class="stat-label">Face Count</div><div class="stat-value" id="faceCount">0</div></div>
          <div class="stat-item"><div class="stat-label">Eyes Open</div><div class="stat-value" id="eyesOpen">--</div></div>
        </div>

        <!-- Live Employee Identification Panel -->
        <div class="id-panel" id="idPanel">
          <div class="id-panel-header">
            <ion-icon name="person-circle-outline"></ion-icon>
            Live Employee Identification
            <span class="live-badge" id="liveBadge" style="display:none;">&#9679; LIVE</span>
          </div>
          <div class="id-panel-body" id="idPanelBody">
            <div class="id-placeholder" id="idPlaceholder">
              <ion-icon name="scan-outline"></ion-icon>
              <div class="id-placeholder-text">No employee detected</div>
              <div class="id-placeholder-sub">Start the camera to begin live identification</div>
            </div>
            <img id="idAvatar" class="id-avatar" src="../assets/images/default-profile.png" alt="Employee" style="display:none;">
            <div class="id-info" id="idInfo" style="display:none;">
              <div class="id-name" id="idName">&mdash;</div>
              <div class="id-emp-id" id="idEmpId">&mdash;</div>
              <div class="id-match-bar-container"><div class="id-match-bar" id="idMatchBar" style="width:0%"></div></div>
              <div class="id-match-label" id="idMatchLabel">Match: &mdash;</div>
            </div>
          </div>
        </div>

        <!-- Camera Feed -->
        <div class="camera-wrapper">
          <video id="video" autoplay muted playsinline></video>
          <canvas id="detectionCanvas"></canvas>
          <div class="camera-id-overlay" id="cameraIdOverlay">
            <img id="overlayAvatar" class="overlay-avatar" src="../assets/images/default-profile.png" alt="">
            <div>
              <div class="overlay-name" id="overlayName">&mdash;</div>
              <div class="overlay-id" id="overlayEmpId">&mdash;</div>
            </div>
            <div class="overlay-score" id="overlayScore">&mdash;</div>
          </div>
        </div>

        <!-- Controls -->
        <div class="controls">
          <button id="btnStart" class="btn-cam btn-cam-primary" type="button">
            <ion-icon name="camera-outline"></ion-icon> Start Camera
          </button>
          <button id="btnStop" class="btn-cam btn-cam-danger" disabled style="display:none;" type="button">
            <ion-icon name="stop-outline"></ion-icon> Stop Camera
          </button>
          <button id="btnReset" class="btn-cam btn-cam-secondary" type="button">
            <ion-icon name="refresh-outline"></ion-icon> Reset
          </button>
        </div>

        <!-- Result Message -->
        <div id="resultMessage"></div>
      </div>

      <!-- ============================================= -->
      <!-- STEP 2: LEAVE HISTORY (hidden until verified) -->
      <!-- ============================================= -->
      <div class="history-section" id="historySection">
        <div class="content-card">
          <h5><ion-icon name="document-text-outline"></ion-icon> Step 2: Your Leave History</h5>

          <div class="employee-info-bar" id="verifiedEmployeeBar">
            <ion-icon name="checkmark-circle-outline"></ion-icon>
            <div class="info-text">
              <strong id="verifiedName">&mdash;</strong>
              <small id="verifiedDetails">&mdash;</small>
            </div>
          </div>

          <!-- Leave History Table (populated via AJAX) -->
          <div class="table-responsive" id="historyTableContainer">
            <div class="empty-state" id="historyLoading">
              <div class="spinner-border text-primary mb-3" role="status"><span class="visually-hidden">Loading...</span></div>
              <p>Loading leave history...</p>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Face-api.js -->
<script src="https://unpkg.com/face-api.js@0.22.2/dist/face-api.min.js"></script>

<script>
// ===================================================================
// FACE DETECTION + LIVENESS VERIFICATION FOR LEAVE HISTORY
// ===================================================================

var CONFIG = { REQUIRED_BLINKS: 2, EYE_CLOSURE_THRESHOLD: 0.3, BLINK_COOLDOWN_MS: 300, DETECTION_INTERVAL_MS: 100 };

var STATE = {
  stream: null, isRunning: false, isCameraActive: false, modelsLoaded: false,
  currentFace: null, faceCount: 0, faceConfidence: 0,
  blinkCount: 0, eyesOpen: false, lastBlinkTime: 0, previousEyeClosure: 1,
  livenessVerified: false, eyeWasJustClosed: false
};

var IDENTIFY = { lastSentTime: 0, intervalMs: 1500, isIdentifying: false, currentEmployee: null, noMatchCount: 0, maxNoMatch: 3 };

var DOM = {
  video: document.getElementById('video'),
  canvas: document.getElementById('detectionCanvas'),
  btnStart: document.getElementById('btnStart'),
  btnStop: document.getElementById('btnStop'),
  btnReset: document.getElementById('btnReset'),
  statusIndicator: document.getElementById('statusIndicator'),
  statusText: document.getElementById('statusText'),
  blinkCounter: document.getElementById('blinkCounter'),
  blinkCount: document.getElementById('blinkCount'),
  faceStats: document.getElementById('faceStats'),
  faceDetected: document.getElementById('faceDetected'),
  confidence: document.getElementById('confidence'),
  faceCount: document.getElementById('faceCount'),
  eyesOpen: document.getElementById('eyesOpen'),
  resultMessage: document.getElementById('resultMessage')
};

var idPanel = document.getElementById('idPanel');
var idPlaceholder = document.getElementById('idPlaceholder');
var idAvatar = document.getElementById('idAvatar');
var idInfo = document.getElementById('idInfo');
var idName = document.getElementById('idName');
var idEmpId = document.getElementById('idEmpId');
var idMatchBar = document.getElementById('idMatchBar');
var idMatchLabel = document.getElementById('idMatchLabel');
var liveBadge = document.getElementById('liveBadge');
var cameraIdOverlay = document.getElementById('cameraIdOverlay');
var overlayAvatar = document.getElementById('overlayAvatar');
var overlayName = document.getElementById('overlayName');
var overlayEmpId = document.getElementById('overlayEmpId');
var overlayScore = document.getElementById('overlayScore');

var historySection = document.getElementById('historySection');
var verifiedName = document.getElementById('verifiedName');
var verifiedDetails = document.getElementById('verifiedDetails');

// ===== UTILITIES =====
function updateStatus(status, message) {
  if (!DOM.statusIndicator || !DOM.statusText) return;
  ['waiting','detecting','detected','error','verified'].forEach(function(c) { DOM.statusIndicator.classList.remove(c); });
  DOM.statusIndicator.classList.add(status);
  var icons = { 'waiting':'\u2139\uFE0F', 'detecting':'\uD83D\uDD04', 'detected':'\u2713', 'error':'\u2715', 'verified':'\uD83D\uDD12' };
  DOM.statusText.textContent = message || '';
  var si = DOM.statusIndicator.querySelector('.status-icon');
  if (si) si.textContent = icons[status] || '\u2139\uFE0F';
}

function escapeHtml(t) {
  if (!t) return '';
  var m = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
  return String(t).replace(/[&<>"']/g, function(c) { return m[c] || c; });
}

function showError(msg) {
  updateStatus('error', msg);
  if (DOM.resultMessage) DOM.resultMessage.innerHTML = '<div class="alert-box alert-danger-box"><strong>Error:</strong> ' + escapeHtml(msg) + '</div>';
}

function showSuccess(msg) {
  updateStatus('verified', msg);
  if (DOM.resultMessage) DOM.resultMessage.innerHTML = '<div class="alert-box alert-success-box"><strong>Success:</strong> ' + escapeHtml(msg) + '</div>';
}

// ===== MODEL LOADING =====
async function loadModels() {
  try {
    updateStatus('detecting', 'Loading face detection models...');
    if (typeof faceapi === 'undefined') throw new Error('face-api.js not loaded');
    await faceapi.nets.tinyFaceDetector.loadFromUri('/models');
    await faceapi.nets.faceLandmark68Net.loadFromUri('/models');
    await faceapi.nets.faceExpressionNet.loadFromUri('/models');
    await faceapi.nets.faceRecognitionNet.loadFromUri('/models');
    STATE.modelsLoaded = true;
    updateStatus('detected', 'Models loaded! Click "Start Camera"');
  } catch (err) {
    showError('Failed to load models: ' + err.message);
  }
}

// ===== FACE DETECTION =====
async function detectFaces() {
  try {
    if (!STATE.isCameraActive || !DOM.video || !DOM.video.srcObject || !DOM.canvas) return false;
    DOM.canvas.width = DOM.video.videoWidth || 640;
    DOM.canvas.height = DOM.video.videoHeight || 480;
    if (DOM.canvas.width === 0 || DOM.canvas.height === 0) return false;
    var ctx = DOM.canvas.getContext('2d');
    if (!ctx) return false;
    ctx.drawImage(DOM.video, 0, 0, DOM.canvas.width, DOM.canvas.height);

    var detections = null;
    try {
      detections = await faceapi.detectAllFaces(DOM.canvas, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks().withFaceExpressions().withFaceDescriptors();
    } catch (e) {
      try { detections = await faceapi.detectAllFaces(DOM.canvas).withFaceLandmarks().withFaceExpressions().withFaceDescriptors(); } catch (e2) { return false; }
    }

    STATE.faceCount = (detections && Array.isArray(detections)) ? detections.length : 0;

    if (detections && detections.length === 1) {
      var det = detections[0];
      if (!det) return false;
      STATE.currentFace = det;
      STATE.faceConfidence = (det.detection && det.detection.score) ? (det.detection.score * 100).toFixed(1) : 0;
      if (det.landmarks && det.landmarks.positions && Array.isArray(det.landmarks.positions)) {
        detectBlink(getEyeClosure(det.landmarks.positions));
      }
      if (!STATE.livenessVerified) updateStatus('detected', 'Face detected (' + STATE.faceConfidence + '%). Please blink to verify.');
      return true;
    } else if (STATE.faceCount > 1) {
      showError('Multiple faces detected. Only one person allowed.');
      STATE.currentFace = null; return false;
    } else {
      updateStatus('detecting', 'No face detected. Position your face.');
      STATE.currentFace = null; return false;
    }
  } catch (err) { return false; }
}

// ===== EYE CLOSURE =====
function getEyeClosure(lm) {
  try {
    if (!lm || !Array.isArray(lm) || lm.length < 68) return 1;
    var le = lm.slice(36, 42), re = lm.slice(42, 48);
    var ear = function(eye) {
      if (!eye || eye.length < 6) return 1;
      var d = function(a, b) { return Math.sqrt(Math.pow((a.x||0)-(b.x||0), 2) + Math.pow((a.y||0)-(b.y||0), 2)); };
      var d3 = d(eye[0], eye[3]);
      return d3 === 0 ? 1 : (d(eye[1], eye[5]) + d(eye[2], eye[4])) / (2 * d3);
    };
    return (ear(le) + ear(re)) / 2 > 0.3 ? 1 : 0;
  } catch (e) { return 1; }
}

// ===== BLINK DETECTION =====
function detectBlink(ec) {
  try {
    var now = Date.now();
    var wasOpen = STATE.previousEyeClosure > 0.5;
    var closed = ec <= CONFIG.EYE_CLOSURE_THRESHOLD;
    var open = ec > 0.5;
    if (wasOpen && closed) { STATE.eyeWasJustClosed = true; }
    else if (STATE.eyeWasJustClosed && open && (now - STATE.lastBlinkTime > CONFIG.BLINK_COOLDOWN_MS)) {
      STATE.blinkCount++; STATE.lastBlinkTime = now; STATE.eyeWasJustClosed = false;
      if (DOM.blinkCount) DOM.blinkCount.textContent = String(STATE.blinkCount);
      if (STATE.blinkCount >= CONFIG.REQUIRED_BLINKS) {
        STATE.livenessVerified = true;
        checkVerificationComplete();
      } else {
        var r = CONFIG.REQUIRED_BLINKS - STATE.blinkCount;
        updateStatus('detected', 'Blink ' + STATE.blinkCount + '/' + CONFIG.REQUIRED_BLINKS + '. Blink ' + r + ' more time(s).');
      }
    }
    STATE.previousEyeClosure = ec;
    STATE.eyesOpen = ec > 0.5;
  } catch (e) {}
}

// ===== VERIFICATION CHECK =====
function checkVerificationComplete() {
  if (STATE.livenessVerified && IDENTIFY.currentEmployee) {
    updateStatus('verified', 'Identity verified! (' + IDENTIFY.currentEmployee.fullname + ') — Loading your leave history...');
    showSuccess('Identity verified as ' + IDENTIFY.currentEmployee.fullname + '. Loading leave records...');
    revealHistorySection(IDENTIFY.currentEmployee);
  } else if (STATE.livenessVerified) {
    updateStatus('verified', 'Liveness verified! Waiting for face identification...');
  }
}

// ===== REVEAL HISTORY SECTION WITH TRANSITION =====
function revealHistorySection(emp) {
  if (!historySection) return;

  // Stop camera
  if (STATE.stream) { STATE.stream.getTracks().forEach(function(t) { t.stop(); }); STATE.stream = null; }
  STATE.isCameraActive = false; STATE.isRunning = false;
  if (DOM.video) DOM.video.srcObject = null;

  // Collapse face detection card
  var faceCard = document.getElementById('faceDetectionCard');
  if (faceCard) {
    faceCard.style.maxHeight = faceCard.scrollHeight + 'px';
    void faceCard.offsetHeight;
    faceCard.classList.add('collapsing');
    setTimeout(function() { faceCard.classList.add('collapsed'); }, 750);
  }

  // Populate employee info
  if (verifiedName) verifiedName.textContent = emp.fullname || '\u2014';
  if (verifiedDetails) verifiedDetails.textContent = (emp.employee_id || '\u2014') + ' \u2014 Identity verified via face detection';

  // Show history section with delay
  setTimeout(function() {
    historySection.classList.add('show');
    setTimeout(function() {
      historySection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 100);
  }, 500);

  // Fetch leave history via AJAX
  fetchLeaveHistory(emp.employee_id);
}

// ===== FETCH LEAVE HISTORY VIA AJAX =====
function fetchLeaveHistory(empId) {
  var container = document.getElementById('historyTableContainer');
  if (!container) return;

  var xhr = new XMLHttpRequest();
  xhr.open('POST', 'leave_history.php?action=fetch_history', true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  xhr.onload = function() {
    if (xhr.status === 200) {
      try {
        var data = JSON.parse(xhr.responseText);

        if (data.leaves && data.leaves.length > 0) {
          var html = '<table class="table table-bordered"><thead><tr>';
          html += '<th>Type</th><th>Date From</th><th>Date To</th><th>Days</th>';
          html += '<th>Reason</th><th>Status</th><th>Requested At</th>';
          html += '</tr></thead><tbody>';

          data.leaves.forEach(function(leave) {
            var st = (leave.status || 'pending').toLowerCase();
            var badgeClass = 'status-pending';
            if (st === 'approved') badgeClass = 'status-approved';
            else if (st === 'rejected') badgeClass = 'status-rejected';
            else if (st === 'success') badgeClass = 'status-success';

            var statusText = (leave.status || 'Pending');
            statusText = statusText.charAt(0).toUpperCase() + statusText.slice(1);

            // Calculate days
            var days = leave.days_requested || 1;
            if (!leave.days_requested && leave.date_from && leave.date_to) {
              var df = new Date(leave.date_from), dt = new Date(leave.date_to);
              days = Math.round((dt - df) / 86400000) + 1;
              if (days < 1) days = 1;
            }

            // Format requested_at
            var reqAt = leave.requested_at || '';
            if (reqAt) {
              var d = new Date(reqAt);
              if (!isNaN(d.getTime())) {
                reqAt = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0') + ' ' + String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
              }
            }

            html += '<tr>';
            html += '<td>' + escapeHtml(leave.leave_type || '') + '</td>';
            html += '<td>' + escapeHtml(leave.date_from || '') + '</td>';
            html += '<td>' + escapeHtml(leave.date_to || '') + '</td>';
            html += '<td>' + days + '</td>';
            html += '<td>' + escapeHtml(leave.reason || '') + '</td>';
            html += '<td><span class="status-badge ' + badgeClass + '">' + escapeHtml(statusText) + '</span></td>';
            html += '<td>' + escapeHtml(reqAt) + '</td>';
            html += '</tr>';
          });

          html += '</tbody></table>';
          container.innerHTML = html;
        } else {
          container.innerHTML = '<div class="empty-state"><ion-icon name="document-outline"></ion-icon><h5>No Leave Requests</h5><p>No leave requests found for this employee.</p></div>';
        }
      } catch (e) {
        container.innerHTML = '<div class="alert-box alert-danger-box">Failed to parse leave history data.</div>';
      }
    } else {
      container.innerHTML = '<div class="alert-box alert-danger-box">Failed to load leave history.</div>';
    }
  };
  xhr.onerror = function() {
    container.innerHTML = '<div class="alert-box alert-danger-box">Network error. Could not load leave history.</div>';
  };
  xhr.send('emp_id=' + encodeURIComponent(empId));
}

// ===== DRAWING =====
function drawFaceDetection() {
  try {
    if (!STATE.currentFace || !DOM.canvas) return;
    var ctx = DOM.canvas.getContext('2d');
    if (!ctx) return;
    var det = STATE.currentFace.detection;
    if (!det || !det.box) return;
    var box = det.box;
    var color = STATE.livenessVerified ? '#22c55e' : '#f59e0b';
    ctx.strokeStyle = color; ctx.lineWidth = 3;
    ctx.strokeRect(box.x, box.y, box.width, box.height);
    ctx.fillStyle = color; ctx.font = 'bold 14px Arial';
    ctx.fillText(STATE.faceConfidence + '%', box.x + 5, box.y - 5);
    if (IDENTIFY.currentEmployee) {
      ctx.fillStyle = '#22c55e'; ctx.font = 'bold 16px Arial';
      ctx.fillText('\u2713 ' + IDENTIFY.currentEmployee.fullname, box.x + 5, box.y - 25);
    }
    ctx.fillStyle = STATE.eyesOpen ? '#22c55e' : '#ef4444';
    ctx.fillText('Eyes: ' + (STATE.eyesOpen ? 'Open' : 'Closed'), box.x + 5, box.y + box.height + 20);
  } catch (e) {}
}

function updateStatsDisplay() {
  if (DOM.faceDetected) DOM.faceDetected.textContent = STATE.currentFace ? 'Yes' : 'No';
  if (DOM.confidence) DOM.confidence.textContent = STATE.faceConfidence ? STATE.faceConfidence + '%' : '--';
  if (DOM.faceCount) DOM.faceCount.textContent = String(STATE.faceCount || 0);
  if (DOM.eyesOpen) DOM.eyesOpen.textContent = STATE.eyesOpen ? 'Yes' : 'No';
}

// ===== LIVE EMPLOYEE IDENTIFICATION =====
var identifyEndpoint = 'identify_face.php';

async function identifyEmployee() {
  try {
    if (!STATE.isCameraActive || !STATE.currentFace || IDENTIFY.isIdentifying) return;
    if (!STATE.currentFace.descriptor) return;
    var now = Date.now();
    if (now - IDENTIFY.lastSentTime < IDENTIFY.intervalMs) return;
    IDENTIFY.lastSentTime = now; IDENTIFY.isIdentifying = true;

    var descriptor = Array.from(STATE.currentFace.descriptor);
    var resp = await fetch(identifyEndpoint, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ descriptor: descriptor }), credentials: 'same-origin'
    });

    if (!resp.ok && identifyEndpoint === 'identify_face.php') {
      identifyEndpoint = '../admin/identify_face.php';
      resp = await fetch(identifyEndpoint, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ descriptor: descriptor }), credentials: 'same-origin'
      });
    }

    var data = await resp.json();
    IDENTIFY.isIdentifying = false;

    if (data.success && data.identified) {
      IDENTIFY.noMatchCount = 0;
      IDENTIFY.currentEmployee = data;
      showIdentifiedEmployee(data);
      if (STATE.livenessVerified) checkVerificationComplete();
    } else {
      IDENTIFY.noMatchCount++;
      if (IDENTIFY.noMatchCount >= IDENTIFY.maxNoMatch) { IDENTIFY.currentEmployee = null; clearIdentification(); }
    }
  } catch (err) { IDENTIFY.isIdentifying = false; }
}

function showIdentifiedEmployee(data) {
  if (idPanel) idPanel.className = 'id-panel identified';
  if (idPlaceholder) idPlaceholder.style.display = 'none';
  if (idAvatar) {
    idAvatar.style.display = 'block';
    idAvatar.src = data.profile_photo ? '../assets/images/' + data.profile_photo : '../assets/images/default-profile.png';
    idAvatar.onerror = function() { this.src = '../assets/images/default-profile.png'; };
  }
  if (idInfo) idInfo.style.display = 'block';
  if (idName) idName.textContent = data.fullname || '\u2014';
  if (idEmpId) idEmpId.textContent = 'ID: ' + (data.employee_id || '\u2014');
  if (idMatchBar) idMatchBar.style.width = (data.match_score || 0) + '%';
  if (idMatchLabel) idMatchLabel.textContent = 'Match: ' + (data.match_score || 0) + '% (distance: ' + (data.distance || '\u2014') + ')';
  if (liveBadge) liveBadge.style.display = 'inline-block';
  if (cameraIdOverlay) cameraIdOverlay.classList.add('show');
  if (overlayAvatar) {
    overlayAvatar.src = data.profile_photo ? '../assets/images/' + data.profile_photo : '../assets/images/default-profile.png';
    overlayAvatar.onerror = function() { this.src = '../assets/images/default-profile.png'; };
  }
  if (overlayName) overlayName.textContent = data.fullname || '\u2014';
  if (overlayEmpId) overlayEmpId.textContent = 'ID: ' + (data.employee_id || '\u2014');
  if (overlayScore) overlayScore.textContent = (data.match_score || 0) + '%';
}

function clearIdentification() {
  if (idPanel) idPanel.className = 'id-panel';
  if (idPlaceholder) {
    idPlaceholder.style.display = 'flex';
    var pt = idPlaceholder.querySelector('.id-placeholder-text');
    var ps = idPlaceholder.querySelector('.id-placeholder-sub');
    if (STATE.isCameraActive) { if (pt) pt.textContent = 'Scanning...'; if (ps) ps.textContent = 'Looking for a recognized employee'; }
    else { if (pt) pt.textContent = 'No employee detected'; if (ps) ps.textContent = 'Start the camera to begin live identification'; }
  }
  if (idAvatar) idAvatar.style.display = 'none';
  if (idInfo) idInfo.style.display = 'none';
  if (liveBadge) liveBadge.style.display = 'none';
  if (cameraIdOverlay) cameraIdOverlay.classList.remove('show');
}

// ===== CAMERA CONTROLS =====
async function startCamera() {
  try {
    if (!STATE.modelsLoaded) await loadModels();
    updateStatus('detecting', 'Requesting camera access...');
    STATE.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }, audio: false });
    if (!DOM.video) { showError('Video element not found'); return; }
    DOM.video.srcObject = STATE.stream;
    STATE.isCameraActive = true; STATE.isRunning = true;
    if (DOM.canvas) { DOM.canvas.width = DOM.video.videoWidth || 640; DOM.canvas.height = DOM.video.videoHeight || 480; }
    startDetectionLoop(); startDrawLoop();
    if (DOM.blinkCounter) DOM.blinkCounter.style.display = 'flex';
    if (DOM.faceStats) DOM.faceStats.style.display = 'grid';
    if (DOM.btnStart) DOM.btnStart.style.display = 'none';
    IDENTIFY.noMatchCount = 0; IDENTIFY.currentEmployee = null; clearIdentification();
    if (liveBadge) liveBadge.style.display = 'inline-block';
    if (DOM.btnStop) { DOM.btnStop.style.display = 'inline-flex'; DOM.btnStop.disabled = false; }
    updateStatus('detecting', 'Camera active. Position your face...');
  } catch (err) { showError('Camera error: ' + err.message); }
}

function stopCamera() {
  if (STATE.stream) { STATE.stream.getTracks().forEach(function(t) { t.stop(); }); STATE.stream = null; }
  STATE.isCameraActive = false; STATE.isRunning = false;
  if (DOM.video) DOM.video.srcObject = null;
  if (DOM.blinkCounter) DOM.blinkCounter.style.display = 'none';
  if (DOM.faceStats) DOM.faceStats.style.display = 'none';
  if (DOM.btnStart) DOM.btnStart.style.display = 'inline-flex';
  if (DOM.btnStop) DOM.btnStop.style.display = 'none';
  updateStatus('waiting', 'Camera stopped.');
  IDENTIFY.currentEmployee = null; IDENTIFY.noMatchCount = 0; clearIdentification();
}

function resetDetection() {
  STATE.blinkCount = 0; STATE.livenessVerified = false; STATE.previousEyeClosure = 1;
  STATE.lastBlinkTime = 0; STATE.eyeWasJustClosed = false; STATE.currentFace = null;
  IDENTIFY.currentEmployee = null; IDENTIFY.noMatchCount = 0; clearIdentification();
  if (DOM.blinkCount) DOM.blinkCount.textContent = '0';
  if (DOM.resultMessage) DOM.resultMessage.innerHTML = '';
  if (historySection) historySection.classList.remove('show');
  var faceCard = document.getElementById('faceDetectionCard');
  if (faceCard) { faceCard.classList.remove('collapsing', 'collapsed'); faceCard.style.maxHeight = ''; }
  if (STATE.isCameraActive) updateStatus('detecting', 'Reset. Position your face...');
  else updateStatus('waiting', 'Reset. Click "Start Camera" to begin.');
}

// ===== LOOPS =====
var detectionLoopId = null, drawLoopId = null;

function startDetectionLoop() {
  if (detectionLoopId) clearInterval(detectionLoopId);
  detectionLoopId = setInterval(async function() {
    if (!STATE.isRunning) return;
    try { await detectFaces(); updateStatsDisplay(); identifyEmployee(); } catch (e) {}
  }, CONFIG.DETECTION_INTERVAL_MS);
}

function startDrawLoop() {
  if (drawLoopId) cancelAnimationFrame(drawLoopId);
  var draw = function() { if (STATE.isRunning) { drawFaceDetection(); drawLoopId = requestAnimationFrame(draw); } };
  drawLoopId = requestAnimationFrame(draw);
}

// ===== EVENT LISTENERS =====
if (DOM.btnStart) DOM.btnStart.addEventListener('click', function(e) { e.preventDefault(); startCamera(); });
if (DOM.btnStop) DOM.btnStop.addEventListener('click', function(e) { e.preventDefault(); stopCamera(); });
if (DOM.btnReset) DOM.btnReset.addEventListener('click', function(e) { e.preventDefault(); resetDetection(); });

// ===== INIT =====
updateStatus('waiting', 'Ready. Click "Start Camera" to verify your identity.');

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
