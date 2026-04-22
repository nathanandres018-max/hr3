<?php
// filepath: admin/attendance_with_liveness.php
// Enhanced attendance UI with real-time face detection and blink (liveness) verification
// COMPLETE PRODUCTION VERSION - SHIFT-BASED + EARLY OUT REASON SUPPORT
// Features:
// - Live face detection with bounding box
// - Eye blink detection for liveness verification
// - Anti-spoofing protection
// - Shift-based clock in/out
// - Early time-out reason modal
// - Full database connection
// - Face descriptor extraction for server-side matching

session_start();
include_once("../connection.php");

// Require logged-in user
if (!isset($_SESSION['username']) || empty($_SESSION['username'])) {
    header("Location: ../login.php");
    exit();
}

$_SESSION['last_activity'] = time();

$fullname = $_SESSION['fullname'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Time & Attendance - Liveness Verification - Admin Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <style>
    * {
      transition: all 0.3s ease;
      box-sizing: border-box;
    }

    html, body {
      height: 100%;
    }

    body {
      font-family: 'QuickSand','Poppins',Arial,sans-serif;
      background: linear-gradient(135deg, #f5f7fa 0%, #fafbfc 100%);
      color: #22223b;
      font-size: 16px;
      margin: 0;
      padding: 0;
    }

    .wrapper {
      display: flex;
      min-height: 100vh;
    }

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

    .sidebar a.active,
    .sidebar a:hover,
    .sidebar button.active,
    .sidebar button:hover {
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

    .camera-container {
      background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
      border-radius: 18px;
      padding: 1.5rem;
      box-shadow: 0 4px 15px rgba(140,140,200,0.08);
      border: 1px solid #f0f0f0;
      max-width: 900px;
      margin: 0 auto;
    }

    .camera-wrapper {
      position: relative;
      background: #000;
      border-radius: 12px;
      overflow: hidden;
      aspect-ratio: 16 / 9;
      margin-bottom: 1.5rem;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 360px;
    }

    video {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    canvas {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
    }

    .status-indicator {
      display: flex;
      align-items: center;
      gap: 0.8rem;
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1rem;
      font-weight: 600;
      font-size: 0.95rem;
    }

    .status-indicator.waiting {
      background: #dbeafe;
      color: #0c4a6e;
      border-left: 4px solid #0284c7;
    }

    .status-indicator.detecting {
      background: #fef3c7;
      color: #92400e;
      border-left: 4px solid #f59e0b;
      animation: pulse 1s infinite;
    }

    .status-indicator.detected {
      background: #dcfce7;
      color: #15803d;
      border-left: 4px solid #22c55e;
    }

    .status-indicator.error {
      background: #fee2e2;
      color: #7f1d1d;
      border-left: 4px solid #ef4444;
    }

    .status-indicator.verified {
      background: #dbeafe;
      color: #0c4a6e;
      border-left: 4px solid #0284c7;
    }

    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.7; }
    }

    .status-icon {
      font-size: 1.2rem;
      display: inline-flex;
      align-items: center;
    }

    .blink-counter {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.8rem 1.2rem;
      background: #f0f9ff;
      border: 1px solid #0284c7;
      border-radius: 8px;
      margin-bottom: 1rem;
      font-weight: 600;
      text-align: center;
      color: #0c4a6e;
      justify-content: center;
    }

    .blink-counter span {
      font-size: 1.5rem;
      color: #9A66ff;
    }

    .face-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 1rem;
      margin-bottom: 1.5rem;
      padding: 1rem;
      background: #f8f9ff;
      border-radius: 8px;
      border: 1px solid #e0e7ff;
    }

    .stat-item { text-align: center; }

    .stat-label {
      font-size: 0.85rem;
      color: #6c757d;
      font-weight: 600;
      margin-bottom: 0.5rem;
    }

    .stat-value {
      font-size: 1.4rem;
      font-weight: 800;
      color: #22223b;
    }

    .controls {
      display: flex;
      gap: 0.8rem;
      flex-wrap: wrap;
      margin-bottom: 1.5rem;
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
      cursor: pointer;
    }

    .btn-primary {
      background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
      color: white;
    }

    .btn-primary:hover:not(:disabled) {
      background: linear-gradient(90deg, #8654e0 0%, #360090 100%);
      transform: translateY(-2px);
    }

    .btn-success {
      background: #10b981;
      color: white;
    }

    .btn-success:hover:not(:disabled) {
      background: #059669;
      transform: translateY(-2px);
    }

    .btn-danger {
      background: #ef4444;
      color: white;
    }

    .btn-danger:hover:not(:disabled) {
      background: #dc2626;
      transform: translateY(-2px);
    }

    .btn-secondary {
      background: #6b7280;
      color: white;
    }

    .btn-secondary:hover:not(:disabled) {
      background: #4b5563;
      transform: translateY(-2px);
    }

    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none !important;
    }

    .info-box {
      background: #f0f9ff;
      border-left: 4px solid #0284c7;
      padding: 1rem;
      border-radius: 8px;
      color: #0c4a6e;
      font-size: 0.95rem;
      margin-bottom: 1rem;
    }

    .info-box strong {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      margin-bottom: 0.5rem;
    }

    .info-box ul { margin: 0; padding-left: 1.5rem; }
    .info-box li { margin-bottom: 0.4rem; }

    .live-clock {
      font-weight: 800;
      font-size: 1.1rem;
      color: #9A66ff;
      font-variant-numeric: tabular-nums;
    }

    .result-message {
      margin-top: 1rem;
      border-radius: 8px;
      padding: 1rem;
      border-left: 4px solid;
    }

    .alert-success-box {
      background: #d1fae5;
      border-left: 4px solid #10b981;
      color: #065f46;
      padding: 1rem;
      border-radius: 8px;
    }

    .alert-danger-box {
      background: #fee2e2;
      border-left: 4px solid #ef4444;
      color: #7f1d1d;
      padding: 1rem;
      border-radius: 8px;
    }

    .alert-warning-box {
      background: #fef3c7;
      border-left: 4px solid #f59e0b;
      color: #92400e;
      padding: 1rem;
      border-radius: 8px;
    }

    .notes-box {
      background: #f0f9ff;
      border-left: 4px solid #0284c7;
      padding: 1.2rem;
      border-radius: 8px;
      margin-top: 1.5rem;
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

    .notes-box li { margin-bottom: 0.6rem; }

    /* === LIVE EMPLOYEE IDENTIFICATION PANEL === */
    .id-panel {
      position: relative;
      margin-bottom: 1.5rem;
      border-radius: 14px;
      overflow: hidden;
      border: 2px solid #e0e7ff;
      background: linear-gradient(135deg, #f8f9ff 0%, #fff 100%);
      box-shadow: 0 4px 18px rgba(140,140,200,0.10);
      transition: all 0.4s ease;
    }

    .id-panel.identified {
      border-color: #22c55e;
      box-shadow: 0 4px 24px rgba(34,197,94,0.18);
    }

    .id-panel.scanning {
      border-color: #f59e0b;
    }

    .id-panel.no-match {
      border-color: #ef4444;
    }

    .id-panel-header {
      display: flex;
      align-items: center;
      gap: 0.7rem;
      padding: 0.8rem 1.2rem;
      font-weight: 700;
      font-size: 0.95rem;
      color: #fff;
      background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
    }

    .id-panel-header ion-icon {
      font-size: 1.3rem;
    }

    .id-panel-header .live-badge {
      margin-left: auto;
      background: #ef4444;
      color: #fff;
      font-size: 0.72rem;
      font-weight: 800;
      padding: 0.18rem 0.55rem;
      border-radius: 20px;
      letter-spacing: 1.2px;
      animation: livePulse 1.5s infinite;
    }

    @keyframes livePulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.5; }
    }

    .id-panel-body {
      display: flex;
      align-items: center;
      gap: 1.5rem;
      padding: 1.2rem 1.5rem;
      min-height: 100px;
    }

    .id-avatar {
      width: 72px;
      height: 72px;
      border-radius: 50%;
      object-fit: cover;
      border: 3px solid #9A66ff;
      background: #e0e7ff;
      flex-shrink: 0;
    }

    .id-info {
      flex: 1;
      min-width: 0;
    }

    .id-name {
      font-size: 1.35rem;
      font-weight: 800;
      color: #22223b;
      margin-bottom: 0.15rem;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .id-emp-id {
      font-size: 0.95rem;
      color: #6c757d;
      font-weight: 600;
      margin-bottom: 0.4rem;
    }

    .id-match-bar-container {
      width: 100%;
      height: 8px;
      background: #e0e7ff;
      border-radius: 4px;
      overflow: hidden;
      margin-top: 0.3rem;
    }

    .id-match-bar {
      height: 100%;
      border-radius: 4px;
      background: linear-gradient(90deg, #22c55e 0%, #10b981 100%);
      transition: width 0.5s ease;
    }

    .id-match-label {
      font-size: 0.82rem;
      color: #6c757d;
      font-weight: 600;
      margin-top: 0.25rem;
    }

    .id-placeholder {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      width: 100%;
      padding: 0.5rem;
      text-align: center;
      color: #9ca3af;
    }

    .id-placeholder ion-icon {
      font-size: 2.2rem;
      margin-bottom: 0.4rem;
      color: #c4b5fd;
    }

    .id-placeholder .id-placeholder-text {
      font-size: 0.92rem;
      font-weight: 600;
    }

    .id-placeholder .id-placeholder-sub {
      font-size: 0.8rem;
      color: #c4b5fd;
      margin-top: 0.15rem;
    }

    /* Overlay on camera */
    .camera-id-overlay {
      position: absolute;
      bottom: 12px;
      left: 12px;
      right: 12px;
      background: rgba(0,0,0,0.72);
      backdrop-filter: blur(6px);
      color: #fff;
      border-radius: 10px;
      padding: 0.7rem 1rem;
      display: none;
      align-items: center;
      gap: 0.8rem;
      z-index: 10;
      font-size: 0.92rem;
    }

    .camera-id-overlay.show {
      display: flex;
    }

    .camera-id-overlay .overlay-avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid #22c55e;
    }

    .camera-id-overlay .overlay-name {
      font-weight: 700;
      font-size: 1rem;
    }

    .camera-id-overlay .overlay-id {
      font-size: 0.82rem;
      color: #a5b4fc;
    }

    .camera-id-overlay .overlay-score {
      margin-left: auto;
      font-weight: 800;
      font-size: 1.1rem;
      color: #22c55e;
    }

    .modal-content {
      border-radius: 18px;
      border: 1px solid #e0e7ff;
      box-shadow: 0 6px 32px rgba(70, 57, 130, 0.15);
    }

    .modal-header-warning {
      background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);
      color: #fff;
      border-bottom: none;
      border-radius: 18px 18px 0 0;
      padding: 1.5rem;
    }

    .modal-title { font-size: 1.13rem; font-weight: 700; }
    .modal-body-custom { background: #fafbfc; padding: 1.7rem 1.5rem; }
    .modal-footer-custom { background: #fafbfc; border-top: 1px solid #e0e7ff; padding: 1.2rem 1.5rem; }

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
      .topbar { flex-direction: column; align-items: flex-start; gap: 1rem; }
    }

    @media (max-width: 700px) {
      .topbar h3 { font-size: 1.4rem; }
      .camera-container { padding: 1rem; }
      .controls { flex-direction: column; }
      .controls .btn { width: 100%; justify-content: center; }
      .face-stats { grid-template-columns: repeat(2, 1fr); }
    }

    @media (max-width: 500px) {
      .sidebar { width: 100%; left: -100%; }
      .sidebar.show { left: 0; }
      .main-content { padding: 0.8rem 0.5rem; }
      .topbar h3 { font-size: 1.2rem; }
      .topbar { padding: 1rem 0.8rem; }
      .camera-wrapper { min-height: 250px; }
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
          <a class="nav-link active" href="attendance_with_liveness.php"><ion-icon name="camera-outline"></ion-icon>Liveness Verified</a>
          <a class="nav-link" href="attendance.php"><ion-icon name="timer-outline"></ion-icon>Standard Clock</a>
          <a class="nav-link" href="attendance_logs.php"><ion-icon name="list-outline"></ion-icon>Attendance Logs</a>
          <a class="nav-link" href="attendance_stats.php"><ion-icon name="stats-chart-outline"></ion-icon>Statistics</a>
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
          <ion-icon name="camera-outline"></ion-icon> Clock In / Clock Out (Liveness Verified)
        </h3>
        <small class="text-muted">Face detection + eye blink verification for secure attendance</small>
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
      <div class="camera-container">
        <!-- Instructions -->
        <div class="info-box">
          <strong>
            <ion-icon name="information-circle-outline"></ion-icon> How to use:
          </strong>
          <ul>
            <li>Click "Start Camera" to activate your webcam</li>
            <li>Position your face in the center of the camera frame</li>
            <li>The system will detect your face automatically</li>
            <li>Blink your eyes at least twice to verify you are live</li>
            <li>Click "Mark Attendance" once verification is complete</li>
            <li><strong>Shift required:</strong> You must have a shift assigned today to clock in/out</li>
            <li><strong>Early out:</strong> If clocking out before shift end, you must provide a reason</li>
          </ul>
        </div>

        <!-- Status Indicator -->
        <div id="statusIndicator" class="status-indicator waiting">
          <span class="status-icon">ℹ️</span>
          <span id="statusText">Ready to start. Click "Start Camera"</span>
        </div>

        <!-- Blink Counter -->
        <div id="blinkCounter" class="blink-counter" style="display: none;">
          <ion-icon name="eye-outline"></ion-icon>
          Blinks detected: <span id="blinkCount">0</span> / 2
        </div>

        <!-- Face Statistics -->
        <div id="faceStats" class="face-stats" style="display: none;">
          <div class="stat-item">
            <div class="stat-label">Face Detected</div>
            <div class="stat-value" id="faceDetected">No</div>
          </div>
          <div class="stat-item">
            <div class="stat-label">Confidence</div>
            <div class="stat-value" id="confidence">--</div>
          </div>
          <div class="stat-item">
            <div class="stat-label">Face Count</div>
            <div class="stat-value" id="faceCount">0</div>
          </div>
          <div class="stat-item">
            <div class="stat-label">Eyes Open</div>
            <div class="stat-value" id="eyesOpen">--</div>
          </div>
        </div>

        <!-- Live Employee Identification Panel -->
        <div class="id-panel" id="idPanel">
          <div class="id-panel-header">
            <ion-icon name="person-circle-outline"></ion-icon>
            Live Employee Identification
            <span class="live-badge" id="liveBadge" style="display:none;">● LIVE</span>
          </div>
          <div class="id-panel-body" id="idPanelBody">
            <div class="id-placeholder" id="idPlaceholder">
              <ion-icon name="scan-outline"></ion-icon>
              <div class="id-placeholder-text">No employee detected</div>
              <div class="id-placeholder-sub">Start the camera to begin live identification</div>
            </div>
            <img id="idAvatar" class="id-avatar" src="../assets/images/default-profile.png" alt="Employee" style="display:none;">
            <div class="id-info" id="idInfo" style="display:none;">
              <div class="id-name" id="idName">—</div>
              <div class="id-emp-id" id="idEmpId">—</div>
              <div class="id-match-bar-container">
                <div class="id-match-bar" id="idMatchBar" style="width:0%"></div>
              </div>
              <div class="id-match-label" id="idMatchLabel">Match: —</div>
            </div>
          </div>
        </div>

        <!-- Camera Feed -->
        <div class="camera-wrapper">
          <video id="video" autoplay muted playsinline></video>
          <canvas id="detectionCanvas"></canvas>
          <!-- Overlay showing identified employee on camera -->
          <div class="camera-id-overlay" id="cameraIdOverlay">
            <img id="overlayAvatar" class="overlay-avatar" src="../assets/images/default-profile.png" alt="">
            <div>
              <div class="overlay-name" id="overlayName">—</div>
              <div class="overlay-id" id="overlayEmpId">—</div>
            </div>
            <div class="overlay-score" id="overlayScore">—</div>
          </div>
        </div>

        <!-- Controls -->
        <div class="controls">
          <button id="btnStart" class="btn btn-primary" type="button">
            <ion-icon name="camera-outline"></ion-icon> Start Camera
          </button>
          <button id="btnStop" class="btn btn-danger" disabled style="display: none;" type="button">
            <ion-icon name="stop-outline"></ion-icon> Stop Camera
          </button>
          <button id="btnMarkAttendance" class="btn btn-success" disabled type="button">
            <ion-icon name="checkmark-circle-outline"></ion-icon> Mark Attendance
          </button>
          <button id="btnReset" class="btn btn-secondary" type="button">
            <ion-icon name="refresh-outline"></ion-icon> Reset
          </button>
        </div>

        <!-- Result Message -->
        <div id="resultMessage"></div>

        <!-- Notes -->
        <div class="notes-box">
          <h5>
            <ion-icon name="document-text-outline"></ion-icon> Important Information
          </h5>
          <ul>
            <li><strong>Liveness Verification:</strong> Blink detection prevents spoofing attacks using static images or photos.</li>
            <li><strong>Shift Assignment:</strong> Only employees assigned to a shift on the current date are permitted to punch.</li>
            <li><strong>Early Time Out:</strong> If clocking out before shift end, you will be asked to provide a reason.</li>
            <li><strong>Single Face Required:</strong> The system detects and blocks if multiple faces are present.</li>
            <li><strong>High Accuracy:</strong> Real-time face detection with eye blink anti-spoofing technology.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ========================================== -->
<!-- EARLY TIME OUT REASON MODAL                -->
<!-- ========================================== -->
<div class="modal fade" id="earlyOutModal" tabindex="-1" aria-labelledby="earlyOutLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header modal-header-warning">
        <h5 class="modal-title" id="earlyOutLabel">
          <ion-icon name="alert-circle-outline"></ion-icon> Early Clock Out
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body modal-body-custom">
        <p>You are clocking out <strong>before your shift ends</strong>.</p>
        <p id="earlyOutShiftInfo" class="text-muted small"></p>
        <p id="earlyOutEmployeeInfo" class="fw-bold"></p>
        <div class="mb-3">
          <label for="earlyOutReason" class="form-label fw-bold">Reason for early departure:</label>
          <textarea id="earlyOutReason" class="form-control" rows="3" placeholder="Enter your reason (required, minimum 2 characters)..." required></textarea>
        </div>
        <div class="alert-warning-box small mb-0">
          <strong><ion-icon name="alert-circle-outline"></ion-icon> Warning:</strong>
          This early departure will be flagged for HR review.
        </div>
      </div>
      <div class="modal-footer modal-footer-custom">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <ion-icon name="close-outline"></ion-icon> Cancel
        </button>
        <button type="button" id="confirmEarlyOut" class="btn" style="background: #f59e0b; color: #fff;">
          <ion-icon name="checkmark-circle-outline"></ion-icon> Submit & Clock Out
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Face-api.js library -->
<script src="https://unpkg.com/face-api.js@0.22.2/dist/face-api.min.js"></script>
<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ===================================================================
// FACE DETECTION & LIVENESS VERIFICATION - PRODUCTION READY VERSION
// WITH SHIFT-BASED ATTENDANCE + EARLY OUT REASON SUPPORT
// COMPLETE JAVASCRIPT — NO TRUNCATION
// ===================================================================

console.log('🚀 Script initialization started...');

// ===== CONFIGURATION =====
var CONFIG = {
  REQUIRED_BLINKS: 2,
  EYE_CLOSURE_THRESHOLD: 0.3,
  BLINK_COOLDOWN_MS: 300,
  DETECTION_INTERVAL_MS: 100
};

// ===== STATE MANAGEMENT =====
var STATE = {
  stream: null,
  isRunning: false,
  isCameraActive: false,
  modelsLoaded: false,
  currentFace: null,
  faceCount: 0,
  faceConfidence: 0,
  blinkCount: 0,
  eyesOpen: false,
  lastBlinkTime: 0,
  previousEyeClosure: 1,
  livenessVerified: false,
  eyeWasJustClosed: false
};

// Pending payload for early-out resubmission
var pendingPayload = null;

// === LIVE IDENTIFICATION STATE ===
var IDENTIFY = {
  lastSentTime: 0,
  intervalMs: 1500,        // Send identification request every 1.5 seconds
  isIdentifying: false,
  currentEmployee: null,
  noMatchCount: 0,
  maxNoMatch: 3            // Clear identity after 3 consecutive no-matches
};

// ===== DOM ELEMENTS =====
var DOM = {
  video: document.getElementById('video'),
  canvas: document.getElementById('detectionCanvas'),
  btnStart: document.getElementById('btnStart'),
  btnStop: document.getElementById('btnStop'),
  btnMarkAttendance: document.getElementById('btnMarkAttendance'),
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
  resultMessage: document.getElementById('resultMessage'),
  liveClock: document.getElementById('liveClock')
};

// Live identification DOM elements
var idPanel = document.getElementById('idPanel');
var idPanelBody = document.getElementById('idPanelBody');
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

// Early out modal elements
var earlyOutModalEl = document.getElementById('earlyOutModal');
var earlyOutModal = earlyOutModalEl ? new bootstrap.Modal(earlyOutModalEl) : null;
var earlyOutReasonEl = document.getElementById('earlyOutReason');
var earlyOutShiftInfoEl = document.getElementById('earlyOutShiftInfo');
var earlyOutEmployeeInfoEl = document.getElementById('earlyOutEmployeeInfo');
var confirmEarlyOutBtn = document.getElementById('confirmEarlyOut');

console.log('✓ DOM elements loaded');

// ===== UTILITY FUNCTIONS =====
function updateStatus(status, message) {
  if (!DOM.statusIndicator || !DOM.statusText) return;
  var classes = ['waiting', 'detecting', 'detected', 'error', 'verified'];
  classes.forEach(function(cls) { DOM.statusIndicator.classList.remove(cls); });
  DOM.statusIndicator.classList.add(status);
  var icons = { 'waiting': 'ℹ️', 'detecting': '🔄', 'detected': '✓', 'error': '✕', 'verified': '🔒' };
  if (DOM.statusText) DOM.statusText.textContent = message || '';
  var statusIcon = DOM.statusIndicator.querySelector('.status-icon');
  if (statusIcon) statusIcon.textContent = icons[status] || 'ℹ️';
}

function showError(message) {
  console.error('❌ ERROR:', message);
  updateStatus('error', message);
  if (DOM.resultMessage) {
    DOM.resultMessage.innerHTML = '<div class="alert-danger-box"><strong>❌ Error:</strong> ' + escapeHtml(message) + '</div>';
  }
}

function showSuccess(message) {
  console.log('✓ SUCCESS:', message);
  updateStatus('verified', message);
  if (DOM.resultMessage) {
    DOM.resultMessage.innerHTML = '<div class="alert-success-box"><strong>✓ Success:</strong> ' + escapeHtml(message) + '</div>';
  }
}

function showWarning(message) {
  console.warn('⚠️ WARNING:', message);
  updateStatus('waiting', message);
  if (DOM.resultMessage) {
    DOM.resultMessage.innerHTML = '<div class="alert-warning-box"><strong>⚠️ Notice:</strong> ' + escapeHtml(message) + '</div>';
  }
}

function escapeHtml(text) {
  if (!text) return '';
  var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
  return String(text).replace(/[&<>"']/g, function(m) { return map[m] || m; });
}

function updateClock() {
  if (DOM.liveClock) DOM.liveClock.textContent = new Date().toLocaleTimeString();
}
setInterval(updateClock, 1000);
updateClock();

// ===== MODEL LOADING =====
async function loadModels() {
  try {
    console.log('📦 Loading models...');
    updateStatus('detecting', 'Loading face detection models...');
    if (typeof faceapi === 'undefined') throw new Error('face-api.js not loaded');

    await faceapi.nets.tinyFaceDetector.loadFromUri('/models');
    console.log('✓ tinyFaceDetector loaded');

    await faceapi.nets.faceLandmark68Net.loadFromUri('/models');
    console.log('✓ faceLandmark68Net loaded');

    await faceapi.nets.faceExpressionNet.loadFromUri('/models');
    console.log('✓ faceExpressionNet loaded');

    await faceapi.nets.faceRecognitionNet.loadFromUri('/models');
    console.log('✓ faceRecognitionNet loaded');

    STATE.modelsLoaded = true;
    updateStatus('detected', '✓ Models loaded! Click "Start Camera"');
    console.log('✓✓✓ All models loaded!');
  } catch (err) {
    console.error('❌ Model loading failed:', err);
    showError('Failed to load models: ' + err.message);
  }
}

// ===== FACE DETECTION =====
async function detectFaces() {
  try {
    if (!STATE.isCameraActive || !DOM.video || !DOM.video.srcObject) return false;
    if (!DOM.canvas) return false;

    DOM.canvas.width = DOM.video.videoWidth || 640;
    DOM.canvas.height = DOM.video.videoHeight || 480;
    if (DOM.canvas.width === 0 || DOM.canvas.height === 0) return false;

    var ctx = DOM.canvas.getContext('2d');
    if (!ctx) return false;
    ctx.drawImage(DOM.video, 0, 0, DOM.canvas.width, DOM.canvas.height);

    var detections = null;
    try {
      detections = await faceapi
        .detectAllFaces(DOM.canvas, new faceapi.TinyFaceDetectorOptions())
        .withFaceLandmarks()
        .withFaceExpressions()
        .withFaceDescriptors();
    } catch (err) {
      try {
        detections = await faceapi
          .detectAllFaces(DOM.canvas)
          .withFaceLandmarks()
          .withFaceExpressions()
          .withFaceDescriptors();
      } catch (fallbackErr) {
        return false;
      }
    }

    STATE.faceCount = (detections && Array.isArray(detections)) ? detections.length : 0;

    if (detections && Array.isArray(detections) && detections.length === 1) {
      var detection = detections[0];
      if (!detection) return false;

      STATE.currentFace = detection;
      STATE.faceConfidence = (detection.detection && detection.detection.score)
        ? (detection.detection.score * 100).toFixed(1) : 0;

      if (detection.landmarks && detection.landmarks.positions && Array.isArray(detection.landmarks.positions)) {
        var positions = detection.landmarks.positions;
        var eyeClosure = getEyeClosure(positions);
        detectBlink(eyeClosure);
      }

      if (!STATE.livenessVerified) {
        updateStatus('detected', '👤 Face detected (' + STATE.faceConfidence + '%). Please blink to verify.');
      }
      return true;
    } else if (STATE.faceCount > 1) {
      showError('Multiple faces detected. Only one person allowed.');
      STATE.currentFace = null;
      return false;
    } else {
      updateStatus('detecting', '🔍 No face detected. Position your face.');
      STATE.currentFace = null;
      return false;
    }
  } catch (err) {
    console.error('❌ Face detection error:', err);
    return false;
  }
}

// ===== EYE CLOSURE CALCULATION =====
function getEyeClosure(landmarks) {
  try {
    if (!landmarks || !Array.isArray(landmarks) || landmarks.length < 68) return 1;
    var leftEye = landmarks.slice(36, 42);
    var rightEye = landmarks.slice(42, 48);

    var calculateEAR = function(eye) {
      if (!eye || !Array.isArray(eye) || eye.length < 6) return 1;
      var dist = function(p1, p2) {
        if (!p1 || !p2) return 0;
        var x1 = parseFloat(p1.x || 0), y1 = parseFloat(p1.y || 0);
        var x2 = parseFloat(p2.x || 0), y2 = parseFloat(p2.y || 0);
        return Math.sqrt(Math.pow(x1 - x2, 2) + Math.pow(y1 - y2, 2));
      };
      var d1 = dist(eye[1], eye[5]);
      var d2 = dist(eye[2], eye[4]);
      var d3 = dist(eye[0], eye[3]);
      return d3 === 0 ? 1 : (d1 + d2) / (2 * d3);
    };

    var leftEAR = calculateEAR(leftEye);
    var rightEAR = calculateEAR(rightEye);
    var avgEAR = (leftEAR + rightEAR) / 2;
    return avgEAR > 0.3 ? 1 : 0;
  } catch (err) {
    return 1;
  }
}

// ===== BLINK DETECTION =====
function detectBlink(eyeClosure) {
  try {
    var now = Date.now();
    var timeSinceLastBlink = now - STATE.lastBlinkTime;
    var wasOpen = STATE.previousEyeClosure > 0.5;
    var isNowClosed = eyeClosure <= CONFIG.EYE_CLOSURE_THRESHOLD;
    var isNowOpen = eyeClosure > 0.5;

    if (wasOpen && isNowClosed) {
      STATE.eyeWasJustClosed = true;
    } else if (STATE.eyeWasJustClosed && isNowOpen && timeSinceLastBlink > CONFIG.BLINK_COOLDOWN_MS) {
      STATE.blinkCount++;
      STATE.lastBlinkTime = now;
      STATE.eyeWasJustClosed = false;
      console.log('✓ Blink detected! Total: ' + STATE.blinkCount);
      if (DOM.blinkCount) DOM.blinkCount.textContent = String(STATE.blinkCount);

      if (STATE.blinkCount >= CONFIG.REQUIRED_BLINKS) {
        STATE.livenessVerified = true;
        updateStatus('verified', '🔒 Liveness verified! Click "Mark Attendance".');
        if (DOM.btnMarkAttendance) DOM.btnMarkAttendance.disabled = false;
      } else {
        var remaining = CONFIG.REQUIRED_BLINKS - STATE.blinkCount;
        updateStatus('detected', '✓ Blink ' + STATE.blinkCount + '/' + CONFIG.REQUIRED_BLINKS + '. Blink ' + remaining + ' more.');
      }
    }

    STATE.previousEyeClosure = eyeClosure;
    STATE.eyesOpen = eyeClosure > 0.5;
  } catch (err) {
    console.error('❌ detectBlink error:', err);
  }
}

// ===== DRAWING =====
function drawFaceDetection() {
  try {
    if (!STATE.currentFace || !DOM.canvas) return;
    var ctx = DOM.canvas.getContext('2d');
    if (!ctx) return;
    var detection = STATE.currentFace.detection;
    if (!detection || !detection.box) return;

    var box = detection.box;
    var color = STATE.livenessVerified ? '#22c55e' : '#f59e0b';

    ctx.strokeStyle = color;
    ctx.lineWidth = 3;
    ctx.strokeRect(box.x, box.y, box.width, box.height);

    ctx.fillStyle = color;
    ctx.font = 'bold 14px Arial';
    ctx.fillText(STATE.faceConfidence + '%', box.x + 5, box.y - 5);

    // Show identified employee name on canvas
    if (IDENTIFY.currentEmployee) {
      ctx.fillStyle = '#22c55e';
      ctx.font = 'bold 16px Arial';
      ctx.fillText('✓ ' + IDENTIFY.currentEmployee.fullname, box.x + 5, box.y - 25);
    }

    if (STATE.eyesOpen) {
      ctx.fillStyle = '#22c55e';
      ctx.fillText('👁 Eyes: Open', box.x + 5, box.y + box.height + 20);
    } else {
      ctx.fillStyle = '#ef4444';
      ctx.fillText('👁 Eyes: Closed', box.x + 5, box.y + box.height + 20);
    }
  } catch (err) {
    console.error('❌ drawFaceDetection error:', err);
  }
}

function updateStatsDisplay() {
  try {
    if (DOM.faceDetected) DOM.faceDetected.textContent = STATE.currentFace ? 'Yes' : 'No';
    if (DOM.confidence) DOM.confidence.textContent = STATE.faceConfidence ? STATE.faceConfidence + '%' : '--';
    if (DOM.faceCount) DOM.faceCount.textContent = String(STATE.faceCount || 0);
    if (DOM.eyesOpen) DOM.eyesOpen.textContent = STATE.eyesOpen ? 'Yes' : 'No';
  } catch (err) {
    console.error('❌ updateStatsDisplay error:', err);
  }
}

// ===== CAMERA CONTROL FUNCTIONS =====
async function startCamera() {
  try {
    console.log('🎬 START CAMERA BUTTON CLICKED');
    if (!STATE.modelsLoaded) {
      console.log('⏳ Models not loaded, loading...');
      await loadModels();
    }

    console.log('📹 Requesting camera...');
    updateStatus('detecting', '📹 Requesting camera access...');

    STATE.stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
      audio: false
    });

    console.log('✓ Camera access granted');
    if (!DOM.video) { showError('Video element not found'); return; }

    DOM.video.srcObject = STATE.stream;
    STATE.isCameraActive = true;
    STATE.isRunning = true;

    if (DOM.canvas) {
      DOM.canvas.width = DOM.video.videoWidth || 640;
      DOM.canvas.height = DOM.video.videoHeight || 480;
    }

    startDetectionLoop();
    startDrawLoop();

    if (DOM.blinkCounter) DOM.blinkCounter.style.display = 'flex';
    if (DOM.faceStats) DOM.faceStats.style.display = 'grid';
    if (DOM.btnStart) DOM.btnStart.style.display = 'none';

    // Reset identification for new session
    IDENTIFY.noMatchCount = 0;
    IDENTIFY.currentEmployee = null;
    clearIdentification();
    if (liveBadge) liveBadge.style.display = 'inline-block';
    if (DOM.btnStop) { DOM.btnStop.style.display = 'inline-flex'; DOM.btnStop.disabled = false; }

    updateStatus('detecting', '📹 Camera active. Position your face...');
    console.log('✓ Camera started successfully');
  } catch (err) {
    console.error('❌ Camera error:', err);
    showError('Camera error: ' + err.message);
  }
}

function stopCamera() {
  try {
    console.log('⏹️ Stopping camera...');
    if (STATE.stream) {
      STATE.stream.getTracks().forEach(function(track) { track.stop(); });
      STATE.stream = null;
    }
    STATE.isCameraActive = false;
    STATE.isRunning = false;
    if (DOM.video) DOM.video.srcObject = null;
    if (DOM.blinkCounter) DOM.blinkCounter.style.display = 'none';
    if (DOM.faceStats) DOM.faceStats.style.display = 'none';
    if (DOM.btnStart) DOM.btnStart.style.display = 'inline-flex';
    if (DOM.btnStop) DOM.btnStop.style.display = 'none';
    updateStatus('waiting', '⏹️ Camera stopped.');

    // Clear identification
    IDENTIFY.currentEmployee = null;
    IDENTIFY.noMatchCount = 0;
    clearIdentification();
    console.log('✓ Camera stopped');
  } catch (err) {
    console.error('❌ stopCamera error:', err);
  }
}

function resetDetection() {
  try {
    console.log('🔄 Resetting...');
    STATE.blinkCount = 0;
    STATE.livenessVerified = false;
    STATE.previousEyeClosure = 1;
    STATE.lastBlinkTime = 0;
    STATE.eyeWasJustClosed = false;
    STATE.currentFace = null;
    pendingPayload = null;

    // Reset identification
    IDENTIFY.currentEmployee = null;
    IDENTIFY.noMatchCount = 0;
    clearIdentification();

    if (DOM.blinkCount) DOM.blinkCount.textContent = '0';
    if (DOM.btnMarkAttendance) DOM.btnMarkAttendance.disabled = true;
    if (DOM.resultMessage) DOM.resultMessage.innerHTML = '';

    if (STATE.isCameraActive) {
      updateStatus('detecting', '🔄 Reset. Position your face...');
    } else {
      updateStatus('waiting', '🔄 Reset. Click "Start Camera" to begin.');
    }
  } catch (err) {
    console.error('❌ resetDetection error:', err);
  }
}

// ===== LIVE EMPLOYEE IDENTIFICATION =====
async function identifyEmployee() {
  try {
    if (!STATE.isCameraActive || !STATE.currentFace || IDENTIFY.isIdentifying) return;
    if (!STATE.currentFace.descriptor) return;

    var now = Date.now();
    if (now - IDENTIFY.lastSentTime < IDENTIFY.intervalMs) return;
    IDENTIFY.lastSentTime = now;
    IDENTIFY.isIdentifying = true;

    var descriptor = Array.from(STATE.currentFace.descriptor);

    var resp = await fetch('identify_face.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ descriptor: descriptor }),
      credentials: 'same-origin'
    });

    var data = await resp.json();
    IDENTIFY.isIdentifying = false;

    if (data.success && data.identified) {
      IDENTIFY.noMatchCount = 0;
      IDENTIFY.currentEmployee = data;
      showIdentifiedEmployee(data);
    } else {
      IDENTIFY.noMatchCount++;
      if (IDENTIFY.noMatchCount >= IDENTIFY.maxNoMatch) {
        IDENTIFY.currentEmployee = null;
        clearIdentification();
      }
    }
  } catch (err) {
    console.error('Identification error:', err);
    IDENTIFY.isIdentifying = false;
  }
}

function showIdentifiedEmployee(data) {
  // Update panel
  if (idPanel) idPanel.className = 'id-panel identified';
  if (idPlaceholder) idPlaceholder.style.display = 'none';
  if (idAvatar) {
    idAvatar.style.display = 'block';
    idAvatar.src = data.profile_photo
      ? '../assets/images/' + data.profile_photo
      : '../assets/images/default-profile.png';
    idAvatar.onerror = function() { this.src = '../assets/images/default-profile.png'; };
  }
  if (idInfo) idInfo.style.display = 'block';
  if (idName) idName.textContent = data.fullname || '—';
  if (idEmpId) idEmpId.textContent = 'ID: ' + (data.employee_id || '—');
  if (idMatchBar) idMatchBar.style.width = (data.match_score || 0) + '%';
  if (idMatchLabel) idMatchLabel.textContent = 'Match: ' + (data.match_score || 0) + '% (distance: ' + (data.distance || '—') + ')';
  if (liveBadge) liveBadge.style.display = 'inline-block';

  // Update camera overlay
  if (cameraIdOverlay) cameraIdOverlay.classList.add('show');
  if (overlayAvatar) {
    overlayAvatar.src = data.profile_photo
      ? '../assets/images/' + data.profile_photo
      : '../assets/images/default-profile.png';
    overlayAvatar.onerror = function() { this.src = '../assets/images/default-profile.png'; };
  }
  if (overlayName) overlayName.textContent = data.fullname || '—';
  if (overlayEmpId) overlayEmpId.textContent = 'ID: ' + (data.employee_id || '—');
  if (overlayScore) overlayScore.textContent = (data.match_score || 0) + '%';
}

function clearIdentification() {
  if (idPanel) idPanel.className = 'id-panel';
  if (idPlaceholder) {
    idPlaceholder.style.display = 'flex';
    var placeholderText = idPlaceholder.querySelector('.id-placeholder-text');
    var placeholderSub = idPlaceholder.querySelector('.id-placeholder-sub');
    if (STATE.isCameraActive) {
      if (placeholderText) placeholderText.textContent = 'Scanning...';
      if (placeholderSub) placeholderSub.textContent = 'Looking for a recognized employee';
    } else {
      if (placeholderText) placeholderText.textContent = 'No employee detected';
      if (placeholderSub) placeholderSub.textContent = 'Start the camera to begin live identification';
    }
  }
  if (idAvatar) idAvatar.style.display = 'none';
  if (idInfo) idInfo.style.display = 'none';
  if (liveBadge) liveBadge.style.display = 'none';
  if (cameraIdOverlay) cameraIdOverlay.classList.remove('show');
}

// ===== DETECTION LOOPS =====
var detectionLoopId = null;
var drawLoopId = null;

function startDetectionLoop() {
  if (detectionLoopId) clearInterval(detectionLoopId);
  detectionLoopId = setInterval(async function() {
    if (!STATE.isRunning) return;
    try {
      await detectFaces();
      updateStatsDisplay();
      // Trigger live identification
      identifyEmployee();
    } catch (err) {
      console.error('❌ Detection loop error:', err);
    }
  }, CONFIG.DETECTION_INTERVAL_MS);
}

function startDrawLoop() {
  if (drawLoopId) cancelAnimationFrame(drawLoopId);
  var draw = function() {
    if (STATE.isRunning) {
      drawFaceDetection();
      drawLoopId = requestAnimationFrame(draw);
    }
  };
  drawLoopId = requestAnimationFrame(draw);
}

// ===== MARK ATTENDANCE (WITH SHIFT + EARLY OUT SUPPORT) =====
async function markAttendance(overridePayload) {
  try {
    if (!STATE.livenessVerified && !overridePayload) {
      showError('Please blink to verify liveness first');
      return;
    }

    console.log('📤 Marking attendance...');
    updateStatus('detecting', '📤 Sending to server...');

    var payload;

    if (overridePayload) {
      payload = overridePayload;
    } else {
      if (!DOM.canvas || !DOM.video) { showError('Camera not ready'); return; }

      var tempCanvas = document.createElement('canvas');
      tempCanvas.width = DOM.video.videoWidth;
      tempCanvas.height = DOM.video.videoHeight;
      var tempCtx = tempCanvas.getContext('2d');
      tempCtx.drawImage(DOM.video, 0, 0);
      var imgDataUrl = tempCanvas.toDataURL('image/jpeg', 0.8);

      var descriptor = null;
      if (STATE.currentFace && STATE.currentFace.descriptor) {
        descriptor = Array.from(STATE.currentFace.descriptor);
      }

      if (!descriptor) {
        showError('Unable to extract face descriptor. Make sure your face is detected.');
        return;
      }

      payload = {
        descriptor: descriptor,
        image: imgDataUrl,
        isLive: true,
        blinkCount: STATE.blinkCount,
        confidence: parseFloat(STATE.faceConfidence) || 0
      };
    }

    // Store payload for potential early-out resubmission
    pendingPayload = {};
    for (var key in payload) {
      if (payload.hasOwnProperty(key)) {
        pendingPayload[key] = payload[key];
      }
    }

    if (DOM.btnMarkAttendance) DOM.btnMarkAttendance.disabled = true;

    var resp = await fetch('verify_attendance.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      credentials: 'same-origin'
    });

    var data;
    try {
      data = await resp.json();
    } catch (parseErr) {
      showError('Invalid server response. Check server logs.');
      if (DOM.btnMarkAttendance) DOM.btnMarkAttendance.disabled = false;
      return;
    }

    if (data.success) {
      var icon = data.action.indexOf('Clock In') !== -1 ? '🟢' : '🔴';
      var earlyNote = data.is_early ? ' (Early — reason recorded for HR review)' : '';
      var successMsg = icon + ' ' + data.action + ': ' + data.employee + ' (' + data.employee_id + ') — ' + data.shift + ' shift at ' + data.time + earlyNote;
      showSuccess(successMsg);
      setTimeout(function() { resetDetection(); }, 3000);
      return;
    }

    // === HANDLE SPECIFIC ERROR CODES ===

    if (data.code === 'early_out_reason_required') {
      console.log('⚠️ Early clock-out detected, requesting reason...');
      updateStatus('waiting', '⚠️ Early clock-out — please provide a reason.');

      if (earlyOutShiftInfoEl) {
        earlyOutShiftInfoEl.textContent = 'Shift ends at ' + (data.shift_end || 'N/A') + '. Current time: ' + (data.current_time || 'now') + '.';
      }
      if (earlyOutEmployeeInfoEl) {
        earlyOutEmployeeInfoEl.textContent = (data.employee || '') + ' (' + (data.employee_id || '') + ')';
      }
      if (earlyOutReasonEl) {
        earlyOutReasonEl.value = '';
      }

      if (earlyOutModal) {
        earlyOutModal.show();
      } else {
        var reason = prompt('You are clocking out early. Please enter a reason:');
        if (reason && reason.trim().length >= 2) {
          pendingPayload.earlyOutReason = reason.trim();
          await markAttendance(pendingPayload);
        } else {
          showWarning('Early clock-out cancelled or reason too short.');
          if (DOM.btnMarkAttendance) DOM.btnMarkAttendance.disabled = false;
        }
      }
      return;
    }

    if (data.code === 'no_shift') {
      showError('📅 ' + data.error);
      if (DOM.btnMarkAttendance) DOM.btnMarkAttendance.disabled = false;
      return;
    }

    if (data.code === 'no_match') {
      showError('🔍 ' + data.error);
      if (DOM.btnMarkAttendance) DOM.btnMarkAttendance.disabled = false;
      return;
    }

    if (data.code === 'liveness_failed') {
      showError('🔒 ' + data.error);
      if (DOM.btnMarkAttendance) DOM.btnMarkAttendance.disabled = false;
      return;
    }

    showError(data.error || 'Attendance marking failed');
    if (DOM.btnMarkAttendance) DOM.btnMarkAttendance.disabled = false;

  } catch (err) {
    console.error('❌ markAttendance error:', err);
    showError('Error: ' + err.message);
    if (DOM.btnMarkAttendance) DOM.btnMarkAttendance.disabled = false;
  }
}

// ===== EARLY OUT MODAL: CONFIRM BUTTON =====
if (confirmEarlyOutBtn) {
  confirmEarlyOutBtn.addEventListener('click', async function() {
    var reason = earlyOutReasonEl ? earlyOutReasonEl.value.trim() : '';
    if (reason.length < 2) {
      alert('Please enter a valid reason (at least 2 characters).');
      return;
    }
    if (earlyOutModal) earlyOutModal.hide();
    if (pendingPayload) {
      pendingPayload.earlyOutReason = reason;
      await markAttendance(pendingPayload);
    } else {
      showError('No pending attendance data. Please try again.');
    }
  });
  console.log('✓ Early out confirm button listener attached');
}

// ===== ATTACH EVENT LISTENERS =====
console.log('🔗 Attaching event listeners...');

if (DOM.btnStart) {
  DOM.btnStart.addEventListener('click', function(e) {
    e.preventDefault();
    e.stopPropagation();
    console.log('✓ Start button clicked');
    startCamera();
  });
  console.log('✓ Start button listener attached');
} else {
  console.error('❌ CRITICAL: Start button (btnStart) not found!');
}

if (DOM.btnStop) {
  DOM.btnStop.addEventListener('click', function(e) {
    e.preventDefault();
    stopCamera();
  });
  console.log('✓ Stop button listener attached');
}

if (DOM.btnReset) {
  DOM.btnReset.addEventListener('click', function(e) {
    e.preventDefault();
    resetDetection();
  });
  console.log('✓ Reset button listener attached');
}

if (DOM.btnMarkAttendance) {
  DOM.btnMarkAttendance.addEventListener('click', function(e) {
    e.preventDefault();
    markAttendance(null);
  });
  console.log('✓ Mark Attendance button listener attached');
}

// ===== INITIALIZATION COMPLETE =====
console.log('✓✓✓ SCRIPT INITIALIZATION COMPLETE ✓✓✓');
updateStatus('waiting', 'Ready. Click "Start Camera" to begin.');

</script>

</body>
</html>