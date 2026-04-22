<?php
/**
 * policy_checker.php
 * Employee-facing AI Policy Checker — Compliance Checker + Policy Chat merged into one page.
 * Matches employee sidebar layout (claim_submissions.php, employee_dashboard.php style).
 */

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

// === ANTI-BYPASS: Session fingerprint ===
$currentFingerprint = md5($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
if (!isset($_SESSION['fingerprint'])) {
    $_SESSION['fingerprint'] = $currentFingerprint;
} elseif ($_SESSION['fingerprint'] !== $currentFingerprint) {
    session_unset();
    session_destroy();
    header("Location: ../login.php?unauthorized=1");
    exit();
}

// === ANTI-BYPASS: Generate CSRF token ===
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$_SESSION['last_activity'] = time();

$fullname = $_SESSION['fullname'] ?? 'Employee';
$role = $_SESSION['role'] ?? 'employee';

// Fetch reimbursement policies for category dropdown + limits
$policies = [];
$sql = "SELECT id, category, limit_amount FROM reimbursement_policies ORDER BY category ASC";
$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $policies[] = $row;
    }
}
if (empty($policies)) {
    $policies = [
        ['id' => 1, 'category' => 'Accommodation', 'limit_amount' => 3000.00],
        ['id' => 2, 'category' => 'Communication', 'limit_amount' => 500.00],
        ['id' => 3, 'category' => 'Meal', 'limit_amount' => 500.00],
        ['id' => 4, 'category' => 'Medical', 'limit_amount' => 15000.00],
        ['id' => 5, 'category' => 'Other', 'limit_amount' => 5000.00],
        ['id' => 6, 'category' => 'Supplies', 'limit_amount' => 2500.00],
        ['id' => 7, 'category' => 'Training', 'limit_amount' => 5000.00],
        ['id' => 8, 'category' => 'Transportation', 'limit_amount' => 1500.00],
        ['id' => 9, 'category' => 'Travel', 'limit_amount' => 2000.00],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>AI Policy Checker - Employee Dashboard</title>
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
      transition: background 0.2s, color 0.2s, padding-left 0.2s;
      text-decoration: none;
    }
    .sidebar a.active, .sidebar a:hover, .sidebar button.active, .sidebar button:hover {
      background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
      color: #fff; padding-left: 1rem;
      box-shadow: 0 2px 8px rgba(154, 102, 255, 0.3);
    }
    .sidebar hr { border-top: 1px solid #232a43; margin: 0.7rem 0; }
    .sidebar h6 { font-size: 0.75rem; font-weight: 700; letter-spacing: 1px; color: #9A66ff; }
    .sidebar .nav-link ion-icon { font-size: 1.2rem; }
    .sidebar-close-btn { display:none; position:absolute; top:0.7rem; right:0.7rem; background:none; border:none; color:#fff; font-size:1.5rem; cursor:pointer; z-index:10; }

    /* === LAYOUT === */
    .content-wrapper { flex: 1; margin-left: 220px; display: flex; flex-direction: column; }
    .topbar {
      padding: 1.5rem 2rem; background: #fff; border-bottom: 2px solid #f0f0f0;
      box-shadow: 0 2px 8px rgba(140,140,200,0.05);
      display: flex; align-items: center; justify-content: space-between; gap: 2rem;
    }
    .topbar h3 {
      font-size: 1.65rem; font-weight: 800; margin: 0; color: #22223b;
      display: flex; align-items: center; gap: 0.65rem;
    }
    .topbar h3 ion-icon { font-size: 1.9rem; color: #9A66ff; }
    .main-content { flex: 1; overflow-y: auto; padding: 2rem; }

    /* === PROFILE === */
    .profile { display: flex; align-items: center; gap: 1rem; }
    .profile-img { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 3px solid #9A66ff; }
    .profile-info { line-height: 1.2; }
    .profile-info strong { font-size: 1rem; font-weight: 700; color: #22223b; display: block; }
    .profile-info small { color: #9A66ff; font-size: 0.88rem; font-weight: 500; }

    /* === CARDS === */
    .content-card {
      background: #fff; border-radius: 18px; padding: 1.5rem;
      box-shadow: 0 4px 15px rgba(140,140,200,0.08);
      border: 1px solid #f0f0f0; margin-bottom: 1.5rem;
    }
    .card-title-bar {
      display: flex; align-items: center; gap: 0.7rem;
      font-size: 1.15rem; font-weight: 700; color: #22223b; margin-bottom: 1.2rem;
    }
    .card-title-bar ion-icon { font-size: 1.4rem; color: #9A66ff; }

    /* === TAB NAVIGATION === */
    .tab-bar {
      display: flex; gap: 0; border-bottom: 3px solid #f0f0f0; margin-bottom: 1.5rem;
    }
    .tab-btn {
      background: none; border: none; padding: 0.8rem 1.5rem; font-size: 0.95rem;
      font-weight: 700; color: #6c757d; cursor: pointer; position: relative;
      display: flex; align-items: center; gap: 0.5rem; transition: color 0.2s;
    }
    .tab-btn.active { color: #6366f1; }
    .tab-btn.active::after {
      content: ''; position: absolute; bottom: -3px; left: 0; width: 100%;
      height: 3px; background: linear-gradient(90deg, #6366f1, #9A66ff); border-radius: 3px 3px 0 0;
    }
    .tab-btn:hover { color: #6366f1; }
    .tab-btn ion-icon { font-size: 1.15rem; }
    .tab-panel { display: none; }
    .tab-panel.active { display: block; }

    /* === FORM CONTROLS === */
    .form-label { font-weight: 600; color: #22223b; font-size: 0.93rem; }
    .form-control, .form-select {
      border: 2px solid #e0e7ff; border-radius: 10px;
      padding: 0.6rem 0.9rem; font-size: 0.93rem; transition: border-color 0.3s;
    }
    .form-control:focus, .form-select:focus { border-color: #6366f1; box-shadow: 0 0 0 0.15rem rgba(99,102,241,0.15); }

    /* === COMPLIANCE BADGES === */
    .compliance-badge {
      display: inline-flex; align-items: center; gap: 0.5rem;
      padding: 0.6rem 1.2rem; border-radius: 10px; font-weight: 700; font-size: 0.95rem;
    }
    .compliance-badge.compliant { background: #dcfce7; color: #15803d; border: 2px solid #22c55e; }
    .compliance-badge.non-compliant { background: #fee2e2; color: #991b1b; border: 2px solid #ef4444; }
    .compliance-badge.requires-review { background: #fef3c7; color: #92400e; border: 2px solid #f59e0b; }

    .risk-badge {
      display: inline-flex; align-items: center; gap: 0.5rem;
      padding: 0.6rem 1.2rem; border-radius: 10px; font-weight: 700; font-size: 0.95rem;
    }
    .risk-badge.risk-low { background: #dcfce7; color: #15803d; border: 2px solid #22c55e; }
    .risk-badge.risk-medium { background: #fef3c7; color: #92400e; border: 2px solid #f59e0b; }
    .risk-badge.risk-high { background: #fee2e2; color: #991b1b; border: 2px solid #ef4444; }

    .explanation-box {
      background: #f0f4ff; border-left: 4px solid #6366f1; padding: 1rem 1.2rem;
      border-radius: 0 10px 10px 0; font-size: 0.93rem; color: #22223b; line-height: 1.6;
    }

    /* === VIOLATION / RISK ITEMS === */
    .violation-item, .risk-factor-item {
      display: flex; align-items: flex-start; gap: 0.7rem; padding: 0.7rem 1rem;
      border-radius: 8px; margin-bottom: 0.5rem; font-size: 0.9rem;
    }
    .violation-item.critical { background: #fef2f2; border-left: 4px solid #ef4444; }
    .violation-item.warning { background: #fffbeb; border-left: 4px solid #f59e0b; }
    .violation-item.info { background: #eff6ff; border-left: 4px solid #3b82f6; }
    .risk-factor-item.high { background: #fef2f2; border-left: 4px solid #ef4444; }
    .risk-factor-item.medium { background: #fffbeb; border-left: 4px solid #f59e0b; }
    .risk-factor-item.low { background: #f0fdf4; border-left: 4px solid #22c55e; }

    .recommendation-item {
      display: flex; align-items: flex-start; gap: 0.5rem; padding: 0.5rem 0;
      font-size: 0.9rem; color: #3b82f6;
    }
    .recommendation-item ion-icon { flex-shrink: 0; margin-top: 2px; }

    /* === CHAT STYLES === */
    .chat-area { max-height: 420px; overflow-y: auto; padding: 1.5rem; background: #fafbfc; border-radius: 14px; border: 1px solid #f0f0f0; }
    .chat-msg { display: flex; gap: 0.7rem; margin-bottom: 1rem; }
    .chat-msg.user-msg { flex-direction: row-reverse; }
    .chat-avatar {
      width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center;
      justify-content: center; flex-shrink: 0; font-size: 1.1rem;
    }
    .ai-msg .chat-avatar { background: #dcfce7; color: #15803d; }
    .user-msg .chat-avatar { background: #e0e7ff; color: #6366f1; }
    .chat-bubble {
      max-width: 80%; padding: 0.8rem 1.2rem; border-radius: 14px;
      font-size: 0.93rem; line-height: 1.6; white-space: pre-wrap;
    }
    .ai-bubble { background: #f0fdf4; color: #22223b; border: 1px solid #e0e7ff; }
    .user-bubble { background: linear-gradient(90deg, #9A66ff 0%, #6366f1 100%); color: #fff; }
    .chat-typing { display: inline-block; }
    .chat-typing span {
      display: inline-block; width: 8px; height: 8px; background: #9ca3af;
      border-radius: 50%; margin: 0 2px; animation: chatTyping 1.2s infinite;
    }
    .chat-typing span:nth-child(2) { animation-delay: 0.2s; }
    .chat-typing span:nth-child(3) { animation-delay: 0.4s; }
    @keyframes chatTyping { 0%, 60%, 100% { transform: translateY(0); } 30% { transform: translateY(-6px); } }

    .suggested-questions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.8rem; }
    .suggested-btn {
      background: #e0e7ff; color: #4338ca; border: 1px solid #c7d2fe;
      border-radius: 20px; padding: 0.4rem 0.9rem; font-size: 0.82rem;
      font-weight: 600; cursor: pointer; transition: all 0.2s;
    }
    .suggested-btn:hover { background: #c7d2fe; transform: translateY(-1px); }
    .chat-confidence {
      display: inline-flex; align-items: center; gap: 0.3rem;
      font-size: 0.75rem; color: #9ca3af; margin-top: 0.3rem;
    }
    .chat-input-bar {
      display: flex; gap: 0.8rem; margin-top: 1rem;
    }
    .chat-input-bar input { flex: 1; }
    .chat-input-bar .btn-send {
      background: linear-gradient(90deg, #6366f1, #4338ca); color: #fff; border: none;
      border-radius: 10px; padding: 0.6rem 1.2rem; font-weight: 700; cursor: pointer;
      display: flex; align-items: center; gap: 0.4rem; white-space: nowrap;
    }
    .chat-input-bar .btn-send:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(99,102,241,0.3); }

    /* === POLICY QUICK-REF TABLE === */
    .policy-table { width: 100%; border-collapse: collapse; margin-top: 0.8rem; }
    .policy-table th { padding: 0.65rem 0.8rem; background: #f9fafb; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; color: #22223b; border-bottom: 2px solid #e5e7eb; text-align: left; }
    .policy-table td { padding: 0.65rem 0.8rem; border-bottom: 1px solid #f0f0f0; font-size: 0.93rem; }
    .policy-table tbody tr:hover { background: #f0f4ff; }
    .cat-pill { display: inline-block; padding: 0.3rem 0.7rem; border-radius: 20px; font-size: 0.82rem; font-weight: 600; background: #f0f4ff; color: #6366f1; }
    .amt-green { font-weight: 700; color: #10b981; }

    /* === MOBILE === */
    .mobile-menu-btn { display:none; position:fixed; top:1.1rem; left:1rem; z-index:1050; background:linear-gradient(90deg,#9A66ff,#4311a5); color:#fff; border:none; border-radius:10px; width:44px; height:44px; font-size:1.5rem; cursor:pointer; box-shadow:0 2px 8px rgba(154,102,255,0.3); align-items:center; justify-content:center; }
    .sidebar-overlay { display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.35); z-index:1035; }

    @media (max-width: 900px) {
      .sidebar { left:-220px; position:fixed; transition:left 0.3s; }
      .sidebar.show { left:0; }
      .sidebar-close-btn { display:block; }
      .sidebar-overlay.show { display:block; }
      .mobile-menu-btn { display:flex; }
      .content-wrapper { margin-left:0; }
      .topbar { padding:1rem 1rem 1rem 4rem; flex-direction:column; align-items:flex-start; gap:0.8rem; }
      .main-content { padding:1.2rem; }
    }
    @media (max-width: 600px) {
      .topbar h3 { font-size:1.15rem; }
      .main-content { padding:0.7rem; }
      .content-card { padding:1rem; border-radius:14px; }
      .tab-btn { padding:0.6rem 0.8rem; font-size:0.85rem; }
    }
    @media (min-width: 1400px) {
      .sidebar { width: 260px; padding: 2rem 1rem; }
      .content-wrapper { margin-left: 260px; }
      .main-content { padding: 2.5rem; }
    }
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
          <a class="nav-link" href="../employee/leave_history.php"><ion-icon name="calendar-outline"></ion-icon>Leave History</a>
        </nav>
      </div>
      <div class="mb-4">
        <h6 class="text-uppercase px-2 mb-2">Claims & Reimbursement</h6>
        <nav class="nav flex-column">
          <a class="nav-link" href="../employee/claim_submissions.php"><ion-icon name="create-outline"></ion-icon>File a Claim</a>
          <a class="nav-link active" href="../employee/policy_checker.php"><ion-icon name="shield-checkmark-outline"></ion-icon>Policy Checker</a>
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
        <h3><ion-icon name="shield-checkmark-outline"></ion-icon> AI Policy Checker</h3>
        <small class="text-muted">Check claim compliance and ask policy questions with AI assistance</small>
      </div>
      <div class="profile">
        <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
        <div class="profile-info">
          <strong><?= htmlspecialchars($fullname) ?></strong>
          <small><?= htmlspecialchars(ucfirst($role)) ?></small>
        </div>
      </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">

      <!-- Tab Navigation -->
      <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab('checker', this)">
          <ion-icon name="checkmark-done-outline"></ion-icon> Compliance Checker
        </button>
        <button class="tab-btn" onclick="switchTab('chat', this)">
          <ion-icon name="chatbubbles-outline"></ion-icon> Policy Assistant
        </button>
        <button class="tab-btn" onclick="switchTab('reference', this)">
          <ion-icon name="book-outline"></ion-icon> Policy Reference
        </button>
      </div>

      <!-- ============================================= -->
      <!-- TAB 1: AI CLAIM COMPLIANCE CHECKER            -->
      <!-- ============================================= -->
      <div class="tab-panel active" id="panel-checker">
        <div class="content-card">
          <div class="card-title-bar">
            <ion-icon name="checkmark-done-outline"></ion-icon> AI Claim Compliance Checker
          </div>
          <p style="color:#6c757d; margin-bottom:1.2rem; font-size:0.93rem;">
            Enter claim details below to get instant AI compliance analysis, risk scoring, and recommendations before you file.
          </p>
          <form id="complianceCheckForm" onsubmit="return runComplianceCheck(event)">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Claim Category <span style="color:#ef4444;">*</span></label>
                <select class="form-select" id="chkCategory" required>
                  <option value="">-- Select Category --</option>
                  <?php foreach ($policies as $p): ?>
                    <option value="<?= htmlspecialchars($p['category']) ?>"><?= htmlspecialchars($p['category']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Amount (₱) <span style="color:#ef4444;">*</span></label>
                <input type="number" class="form-control" id="chkAmount" step="0.01" min="0.01" placeholder="0.00" required>
                <small class="text-muted" id="chkAmountHint"></small>
              </div>
              <div class="col-md-4">
                <label class="form-label">Expense Date <span style="color:#ef4444;">*</span></label>
                <input type="date" class="form-control" id="chkDate" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Vendor / Merchant</label>
                <input type="text" class="form-control" id="chkVendor" placeholder="e.g., Jollibee, Shell Gas">
              </div>
              <div class="col-md-6">
                <label class="form-label">Description</label>
                <input type="text" class="form-control" id="chkDescription" placeholder="Brief description...">
              </div>
              <div class="col-md-6">
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" id="chkHasReceipt" checked>
                  <label class="form-check-label" for="chkHasReceipt">Receipt / Invoice Available</label>
                </div>
              </div>
              <div class="col-md-6 text-end">
                <button type="submit" class="btn-send" id="btnCheckCompliance" style="border:none; background:linear-gradient(90deg,#6366f1,#4338ca); color:#fff; border-radius:10px; padding:0.65rem 1.3rem; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:0.5rem;">
                  <ion-icon name="shield-checkmark-outline"></ion-icon> Check Compliance
                </button>
              </div>
            </div>
          </form>

          <!-- Compliance Results -->
          <div id="complianceResults" style="display:none; margin-top:1.5rem;">
            <hr style="border-color:#e5e7eb;">
            <div style="display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1rem;">
              <div id="complianceStatusBadge" class="compliance-badge"></div>
              <div id="riskScoreBadge" class="risk-badge"></div>
            </div>
            <div id="complianceExplanation" class="explanation-box"></div>
            <div id="violationsList" style="margin-top:1rem;"></div>
            <div id="riskFactorsList" style="margin-top:1rem;"></div>
            <div id="recommendationsList" style="margin-top:1rem;"></div>
          </div>
        </div>
      </div>

      <!-- ============================================= -->
      <!-- TAB 2: AI POLICY CHAT (NATURAL LANGUAGE Q&A)  -->
      <!-- ============================================= -->
      <div class="tab-panel" id="panel-chat">
        <div class="content-card">
          <div class="card-title-bar">
            <ion-icon name="chatbubbles-outline" style="color:#10b981;"></ion-icon> AI Policy Assistant
          </div>
          <p style="color:#6c757d; margin-bottom:1rem; font-size:0.93rem;">
            Ask questions about reimbursement policies in plain language. The AI will answer based on company policy rules.
          </p>

          <!-- Chat Messages Area -->
          <div class="chat-area" id="chatMessages">
            <div class="chat-msg ai-msg">
              <div class="chat-avatar"><ion-icon name="sparkles-outline"></ion-icon></div>
              <div class="chat-bubble ai-bubble">
                <strong>AI Policy Assistant</strong><br>
                Hello, <?= htmlspecialchars($fullname) ?>! I can help you understand our reimbursement policies. Ask me anything, such as:
                <ul style="margin:0.5rem 0 0 1rem; padding:0;">
                  <li>"What's the maximum for meal claims?"</li>
                  <li>"Do I need a receipt for travel?"</li>
                  <li>"How many days do I have to submit?"</li>
                </ul>
              </div>
            </div>
            <div id="suggestedQuestions" class="suggested-questions">
              <button onclick="askSuggested(this)" class="suggested-btn">What is the maximum meal reimbursement?</button>
              <button onclick="askSuggested(this)" class="suggested-btn">Do I need a receipt for travel claims?</button>
              <button onclick="askSuggested(this)" class="suggested-btn">How many days to submit a claim?</button>
              <button onclick="askSuggested(this)" class="suggested-btn">What categories are covered?</button>
              <button onclick="askSuggested(this)" class="suggested-btn">Can I reimburse meals during overtime?</button>
            </div>
          </div>

          <!-- Chat Input -->
          <div class="chat-input-bar">
            <input type="text" class="form-control" id="chatInput" placeholder="Ask a question about reimbursement policies..." onkeydown="if(event.key==='Enter')sendChatMessage()">
            <button class="btn-send" onclick="sendChatMessage()">
              <ion-icon name="send-outline"></ion-icon> Send
            </button>
          </div>
        </div>
      </div>

      <!-- ============================================= -->
      <!-- TAB 3: POLICY REFERENCE TABLE                 -->
      <!-- ============================================= -->
      <div class="tab-panel" id="panel-reference">
        <div class="content-card">
          <div class="card-title-bar">
            <ion-icon name="book-outline"></ion-icon> Policy Limits Quick Reference
          </div>
          <div style="overflow-x:auto;">
            <table class="policy-table">
              <thead>
                <tr>
                  <th>Category</th>
                  <th>Max Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($policies as $p): ?>
                <tr>
                  <td><span class="cat-pill"><?= htmlspecialchars($p['category']) ?></span></td>
                  <td><span class="amt-green">₱<?= number_format(floatval($p['limit_amount']), 2) ?></span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div style="margin-top:1.5rem; line-height:1.8;">
            <h6 style="font-weight:700; margin-bottom:0.8rem;">Key Policy Rules:</h6>
            <ul style="margin-left:1.5rem; color:#22223b;">
              <li><strong>Claim Limits:</strong> All claims must not exceed the maximum amount for that category.</li>
              <li><strong>Documentation:</strong> All claims require valid receipt or invoice documentation.</li>
              <li><strong>Timely Submission:</strong> Claims should be submitted within 30 days (60 days for medical).</li>
              <li><strong>AI Verification:</strong> Every claim is automatically checked by AI for compliance and risk scoring.</li>
              <li><strong>Risk Scoring:</strong> Claims scoring HIGH risk (60+) are automatically flagged for manual review.</li>
            </ul>
          </div>
        </div>
      </div>

    </div><!-- /main-content -->
  </div><!-- /content-wrapper -->
</div><!-- /wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ============================================================
// TAB SWITCHING
// ============================================================
function switchTab(panel, btn) {
  document.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.remove('active'); });
  document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
  document.getElementById('panel-' + panel).classList.add('active');
  btn.classList.add('active');
}

// ============================================================
// POLICY LIMITS CACHE
// ============================================================
var policyLimitsCache = {};
<?php foreach ($policies as $p): ?>
policyLimitsCache['<?= addslashes($p['category']) ?>'] = <?= floatval($p['limit_amount']) ?>;
<?php endforeach; ?>

// Date constraint
(function() {
  var today = new Date().toISOString().split('T')[0];
  var dateEl = document.getElementById('chkDate');
  if (dateEl) dateEl.setAttribute('max', today);
})();

// Category → show limit hint
document.getElementById('chkCategory').addEventListener('change', function() {
  var cat = this.value;
  var hint = document.getElementById('chkAmountHint');
  if (cat && policyLimitsCache[cat]) {
    hint.textContent = 'Policy limit: ₱' + Number(policyLimitsCache[cat]).toLocaleString('en', {minimumFractionDigits:2});
    hint.style.color = '#6366f1';
  } else if (cat) {
    hint.textContent = 'Category selected — no specific limit found';
    hint.style.color = '#6c757d';
  } else {
    hint.textContent = '';
  }
});

// ============================================================
// COMPLIANCE CHECK
// ============================================================
function runComplianceCheck(e) {
  e.preventDefault();
  var btn = document.getElementById('btnCheckCompliance');
  var resultsDiv = document.getElementById('complianceResults');
  btn.disabled = true;
  btn.innerHTML = '<ion-icon name="hourglass-outline"></ion-icon> Analyzing...';

  var formData = new URLSearchParams({
    action: 'check_compliance',
    category: document.getElementById('chkCategory').value,
    amount: document.getElementById('chkAmount').value,
    expense_date: document.getElementById('chkDate').value,
    vendor: document.getElementById('chkVendor').value,
    description: document.getElementById('chkDescription').value,
    has_receipt: document.getElementById('chkHasReceipt').checked ? '1' : '',
  });

  fetch('../benefits/policy_checker_api.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: formData.toString()
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    btn.disabled = false;
    btn.innerHTML = '<ion-icon name="shield-checkmark-outline"></ion-icon> Check Compliance';
    if (!data.ok) { alert('Error: ' + (data.error || 'Unknown error')); return; }

    resultsDiv.style.display = 'block';

    // Compliance Status Badge
    var statusBadge = document.getElementById('complianceStatusBadge');
    var statusClass = data.compliance_status === 'COMPLIANT' ? 'compliant' :
                      data.compliance_status === 'NON-COMPLIANT' ? 'non-compliant' : 'requires-review';
    var statusIcon = data.compliance_status === 'COMPLIANT' ? 'checkmark-circle' :
                     data.compliance_status === 'NON-COMPLIANT' ? 'close-circle' : 'alert-circle';
    statusBadge.className = 'compliance-badge ' + statusClass;
    statusBadge.innerHTML = '<ion-icon name="' + statusIcon + '-outline"></ion-icon> ' + data.compliance_status.replace(/_/g, ' ');

    // Risk Score Badge
    var riskBadge = document.getElementById('riskScoreBadge');
    var riskClass = data.risk_level === 'LOW' ? 'risk-low' : data.risk_level === 'MEDIUM' ? 'risk-medium' : 'risk-high';
    riskBadge.className = 'risk-badge ' + riskClass;
    riskBadge.innerHTML = '<ion-icon name="speedometer-outline"></ion-icon> Risk: ' + data.risk_score + '/100 (' + data.risk_level + ')';

    // Explanation
    document.getElementById('complianceExplanation').innerHTML = '<strong>AI Analysis:</strong> ' + escapeHtml(data.explanation);

    // Violations
    var violHtml = '';
    if (data.violations && data.violations.length > 0) {
      violHtml = '<h6 style="font-weight:700; font-size:0.93rem; margin-bottom:0.5rem;">⚠️ Policy Violations (' + data.violations.length + ')</h6>';
      data.violations.forEach(function(v) {
        var cls = v.severity === 'critical' ? 'critical' : (v.severity === 'warning' ? 'warning' : 'info');
        var icon = v.severity === 'critical' ? 'close-circle' : (v.severity === 'warning' ? 'alert-circle' : 'information-circle');
        violHtml += '<div class="violation-item ' + cls + '">';
        violHtml += '<ion-icon name="' + icon + '-outline" style="font-size:1.1rem; flex-shrink:0; margin-top:2px;"></ion-icon>';
        violHtml += '<div><strong>' + escapeHtml(v.rule || '') + '</strong>: ' + escapeHtml(v.message);
        if (v.section) violHtml += ' <small style="color:#6c757d;">(' + escapeHtml(v.section) + ')</small>';
        violHtml += '</div></div>';
      });
    }
    document.getElementById('violationsList').innerHTML = violHtml;

    // Risk Factors
    var riskHtml = '';
    if (data.risk_factors && data.risk_factors.length > 0) {
      riskHtml = '<h6 style="font-weight:700; font-size:0.93rem; margin-bottom:0.5rem;">📊 Risk Factors</h6>';
      data.risk_factors.forEach(function(rf) {
        var cls = rf.severity === 'high' ? 'high' : (rf.severity === 'medium' ? 'medium' : 'low');
        riskHtml += '<div class="risk-factor-item ' + cls + '">';
        riskHtml += '<ion-icon name="analytics-outline" style="font-size:1rem; flex-shrink:0; margin-top:2px;"></ion-icon>';
        riskHtml += '<div>' + escapeHtml(rf.detail) + ' <strong>(+' + rf.score + ' pts)</strong></div>';
        riskHtml += '</div>';
      });
    }
    document.getElementById('riskFactorsList').innerHTML = riskHtml;

    // Recommendations
    var recHtml = '';
    if (data.recommendations && data.recommendations.length > 0) {
      recHtml = '<h6 style="font-weight:700; font-size:0.93rem; margin-bottom:0.5rem;">💡 Recommendations</h6>';
      data.recommendations.forEach(function(r) {
        recHtml += '<div class="recommendation-item"><ion-icon name="bulb-outline" style="color:#3b82f6;"></ion-icon>' + escapeHtml(r) + '</div>';
      });
    }
    document.getElementById('recommendationsList').innerHTML = recHtml;

    resultsDiv.scrollIntoView({behavior: 'smooth', block: 'start'});
  })
  .catch(function() {
    btn.disabled = false;
    btn.innerHTML = '<ion-icon name="shield-checkmark-outline"></ion-icon> Check Compliance';
    alert('Network error. Please try again.');
  });

  return false;
}

// ============================================================
// POLICY CHAT
// ============================================================
function sendChatMessage() {
  var input = document.getElementById('chatInput');
  var question = input.value.trim();
  if (!question) return;
  input.value = '';
  appendChatMessage('user', question);

  var sugDiv = document.getElementById('suggestedQuestions');
  if (sugDiv) sugDiv.style.display = 'none';

  var typingId = 'typing-' + Date.now();
  appendTypingIndicator(typingId);

  fetch('../benefits/policy_chat_api.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=ask&question=' + encodeURIComponent(question)
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    removeTypingIndicator(typingId);
    if (data.ok) {
      appendChatMessage('ai', data.answer, data.confidence);
    } else {
      appendChatMessage('ai', 'Sorry, I encountered an error: ' + (data.error || 'Unknown'), 0);
    }
  })
  .catch(function() {
    removeTypingIndicator(typingId);
    appendChatMessage('ai', 'Sorry, I couldn\'t connect to the policy server. Please try again.', 0);
  });
}

function askSuggested(btn) {
  document.getElementById('chatInput').value = btn.textContent;
  sendChatMessage();
}

function appendChatMessage(type, text, confidence) {
  var container = document.getElementById('chatMessages');
  var div = document.createElement('div');
  div.className = 'chat-msg ' + (type === 'user' ? 'user-msg' : 'ai-msg');

  var avatar = '<div class="chat-avatar">';
  avatar += type === 'user' ? '<ion-icon name="person-outline"></ion-icon>' : '<ion-icon name="sparkles-outline"></ion-icon>';
  avatar += '</div>';

  var bubbleClass = type === 'user' ? 'user-bubble' : 'ai-bubble';
  var bubble = '<div class="chat-bubble ' + bubbleClass + '">' + escapeHtml(text);

  if (type === 'ai' && confidence !== undefined && confidence > 0) {
    var confColor = confidence >= 0.8 ? '#22c55e' : (confidence >= 0.5 ? '#f59e0b' : '#ef4444');
    bubble += '<div class="chat-confidence" style="color:' + confColor + '"><ion-icon name="analytics-outline"></ion-icon> Confidence: ' + Math.round(confidence * 100) + '%</div>';
  }
  bubble += '</div>';

  div.innerHTML = avatar + bubble;
  container.appendChild(div);
  container.scrollTop = container.scrollHeight;
}

function appendTypingIndicator(id) {
  var container = document.getElementById('chatMessages');
  var div = document.createElement('div');
  div.className = 'chat-msg ai-msg';
  div.id = id;
  div.innerHTML = '<div class="chat-avatar"><ion-icon name="sparkles-outline"></ion-icon></div>' +
    '<div class="chat-bubble ai-bubble"><div class="chat-typing"><span></span><span></span><span></span></div></div>';
  container.appendChild(div);
  container.scrollTop = container.scrollHeight;
}

function removeTypingIndicator(id) {
  var el = document.getElementById(id);
  if (el) el.remove();
}

// ============================================================
// UTILITY
// ============================================================
function escapeHtml(t) {
  if (!t) return '';
  var m = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
  return String(t).replace(/[&<>"']/g, function(c) { return m[c] || c; });
}

// ============================================================
// MOBILE SIDEBAR TOGGLE
// ============================================================
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

// ============================================================
// SESSION INACTIVITY TIMEOUT (15 minutes, warn at 13 min)
// ============================================================
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

  ['mousemove','mousedown','keydown','touchstart','scroll','click'].forEach(function(evt) {
    document.addEventListener(evt, resetTimers, {passive:true});
  });
  resetTimers();
})();
</script>
</body>
</html>
