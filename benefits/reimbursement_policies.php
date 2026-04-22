<?php
/**
 * reimbursement_policies.php
 * Displays and manages reimbursement policies
 * Aligns with pending_claims.php, processed_claims.php, and flagged_claims.php
 */

include_once("../connection.php");
session_start();

// === ANTI-BYPASS: Prevent browser caching of protected pages ===
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// --- Session timeout handling (15 minutes inactivity) ---
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

// === ANTI-BYPASS: Role enforcement ===
if (
    !isset($_SESSION['role']) ||
    empty($_SESSION['role']) ||
    ($_SESSION['role'] !== 'Benefits Officer' && $_SESSION['role'] !== 'HR3 Admin')
) {
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

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Benefits Officer';
$role = $_SESSION['role'] ?? 'Benefits Officer';

// Fetch reimbursement policies
$policies = [];

// Check which columns exist in the table
$column_check = mysqli_query($conn, "SHOW COLUMNS FROM reimbursement_policies");
$columns = [];
while ($col = mysqli_fetch_assoc($column_check)) {
    $columns[] = $col['Field'];
}

// Build SELECT query based on available columns
$select_cols = ['id', 'category', 'limit_amount'];
if (in_array('description', $columns)) {
    $select_cols[] = 'description';
}
if (in_array('created_at', $columns)) {
    $select_cols[] = 'created_at';
}
if (in_array('updated_at', $columns)) {
    $select_cols[] = 'updated_at';
}

$sql = "SELECT " . implode(',', $select_cols) . " FROM reimbursement_policies ORDER BY category ASC";

$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $policies[] = $row;
    }
}

// If no policies found in DB, use defaults
if (empty($policies)) {
    $policies = [
        [
            'id' => 1,
            'category' => 'Travel',
            'limit_amount' => 2000.00,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ],
        [
            'id' => 2,
            'category' => 'Meal',
            'limit_amount' => 500.00,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ],
        [
            'id' => 3,
            'category' => 'Medical',
            'limit_amount' => 15000.00,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ],
        [
            'id' => 4,
            'category' => 'Supplies',
            'limit_amount' => 2500.00,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ],
        [
            'id' => 5,
            'category' => 'Others',
            'limit_amount' => 5000.00,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]
    ];
}

// --- SIDEBAR RENDERER FUNCTION ---
function render_sidebar(string $active = 'reimbursement_policies') {
    $links = [
        'dashboard' => ['url' => 'benefits_officer_dashboard.php', 'icon' => 'home-outline', 'label' => 'Dashboard'],
        'claim_verification' => ['url' => 'claim_verification.php', 'icon' => 'checkmark-circle-outline', 'label' => 'Verify Claims'],
        'pending_claims' => ['url' => 'pending_claims.php', 'icon' => 'cash-outline', 'label' => 'Pending Claims'],
        'processed_claims' => ['url' => 'processed_claims.php', 'icon' => 'checkmark-done-outline', 'label' => 'Processed Claims'],
        'flagged_claims' => ['url' => 'flagged_claims.php', 'icon' => 'alert-circle-outline', 'label' => 'Flagged Claims'],
        'reimbursement_policies' => ['url' => 'reimbursement_policies.php', 'icon' => 'settings-outline', 'label' => 'Reimbursement Policies'],
        'audit_reports' => ['url' => 'audit_reports.php', 'icon' => 'document-text-outline', 'label' => 'Audit & Reports'],
    ];

    echo '<div class="sidebar d-flex flex-column justify-content-between shadow-sm border-end">
        <div>
            <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
                <img src="../assets/images/image.png" class="img-fluid" style="height: 55px;" alt="Logo">
            </div>

            <div class="mb-4">
                <nav class="nav flex-column">';
                    foreach ($links as $key => $meta) {
                        if ($key === 'dashboard') {
                            $activeClass = ($key === $active) ? 'active' : '';
                            echo '<a class="nav-link ' . $activeClass . '" href="' . htmlspecialchars($meta['url']) . '">
                                <ion-icon name="' . htmlspecialchars($meta['icon']) . '"></ion-icon>' . htmlspecialchars($meta['label']) . '
                            </a>';
                        }
                    }
                echo '</nav>
            </div>

            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-3">Claims & Reimbursement</h6>
                <nav class="nav flex-column">';
                    foreach ($links as $key => $meta) {
                        if ($key !== 'dashboard') {
                            $activeClass = ($key === $active) ? 'active' : '';
                            echo '<a class="nav-link ' . $activeClass . '" href="' . htmlspecialchars($meta['url']) . '">
                                <ion-icon name="' . htmlspecialchars($meta['icon']) . '"></ion-icon>' . htmlspecialchars($meta['label']) . '
                            </a>';
                        }
                    }
                echo '</nav>
            </div>
        </div>

        <div class="p-3 border-top mb-2">
            <a class="nav-link text-danger" href="../logout.php">
                <ion-icon name="log-out-outline"></ion-icon>Logout
            </a>
        </div>
    </div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reimbursement Policies - ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap + Ionicons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
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

        .layout { display: flex; min-height: 100vh; }

        .sidenav {
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            width: 220px;
            z-index: 1040;
            overflow-y: auto;
            padding: 0;
        }

        .sidebar { 
            background: linear-gradient(180deg, #181818ff 0%, #1a1a2e 100%);
            color: #fff; 
            height: 100vh; 
            overflow-y: auto; 
            padding: 1rem 0.3rem; 
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
            border-right: 1px solid rgba(255, 255, 255, 0.02);
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
            transition: all 0.2s ease;
            width: 100%; 
            text-align: left; 
            white-space: nowrap; 
            cursor: pointer;
            text-decoration: none;
        }

        .sidebar a.active, .sidebar a:hover, .sidebar button.active, .sidebar button:hover { 
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
            color: #fff; 
            padding-left: 1rem;
            box-shadow: 0 2px 8px rgba(154, 102, 255, 0.3);
        }

        .sidebar h6 {
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 1px;
            color: #9A66ff;
            text-transform: uppercase;
            margin-bottom: 0.8rem;
        }
        .sidebar .nav-link ion-icon { font-size: 1.2rem; }
        .sidebar hr { border-top: 1px solid #232a43; margin: 0.7rem 0; }

        .main-content { 
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

        .topbar h1 { 
            font-size: 1.8rem;
            font-weight: 800;
            margin: 0;
            color: #22223b;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .topbar h1 ion-icon { font-size: 2rem; color: #9A66ff; }

        .topbar-subtitle {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 0.3rem;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 1.2rem;
        }

        .profile-img { 
            width: 45px; 
            height: 45px; 
            border-radius: 50%; 
            object-fit: cover; 
            border: 3px solid #9A66ff;
        }

        .profile-info { line-height: 1.1; }
        .profile-info strong { font-size: 1.08rem; font-weight: 600; color: #22223b; display: block; }
        .profile-info small { color: #9A66ff; font-size: 0.93rem; font-weight: 500; }

        .page-content { 
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

        .btn {
            border: none;
            border-radius: 8px;
            font-weight: 600;
            padding: 0.65rem 1.2rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(154, 102, 255, 0.3);
        }

        .btn-sm {
            padding: 0.5rem 0.8rem;
            font-size: 0.85rem;
        }

        .policies-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5rem;
        }

        .policies-table thead {
            background: #f9fafb;
            border-bottom: 2px solid #e5e7eb;
        }

        .policies-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 700;
            color: #22223b;
            font-size: 0.9rem;
            text-transform: uppercase;
        }

        .policies-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
            transition: all 0.2s ease;
        }

        .policies-table tbody tr:hover {
            background: #f0f4ff;
        }

        .policies-table td {
            padding: 1rem;
            color: #22223b;
            font-size: 0.95rem;
        }

        .category-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            background: #f0f4ff;
            color: #9A66ff;
        }

        .amount-badge {
            font-weight: 700;
            color: #10b981;
            font-size: 1rem;
        }

        .ai-checker-box {
            background: linear-gradient(135deg, #f3f4f6 0%, #ede9fe 100%);
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 2rem;
            display: flex;
            gap: 1.2rem;
        }

        .ai-checker-icon {
            font-size: 2.5rem;
            color: #9A66ff;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f4ff;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            flex-shrink: 0;
        }

        .ai-checker-content {
            flex: 1;
        }

        .ai-checker-title {
            font-weight: 700;
            font-size: 1.1rem;
            color: #22223b;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .ai-checker-status {
            font-size: 0.95rem;
            color: #22223b;
            margin-bottom: 0.8rem;
            line-height: 1.5;
        }

        .ai-status-pass {
            background: #d1fae5;
            color: #065f46;
            padding: 0.5rem 0.8rem;
            border-radius: 6px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            margin-top: 0.5rem;
        }

        .btn-check-ai {
            background: #9A66ff;
            color: white;
            margin-top: 0.8rem;
        }

        .btn-check-ai:hover {
            background: #7d4fd9;
        }

        /* === FORM CONTROLS === */
        .form-label { font-weight: 600; color: #22223b; font-size: 0.93rem; }
        .form-control, .form-select {
            border: 2px solid #e0e7ff; border-radius: 10px;
            padding: 0.6rem 0.9rem; font-size: 0.93rem; transition: border-color 0.3s;
        }
        .form-control:focus, .form-select:focus { border-color: #9A66ff; box-shadow: 0 0 0 0.15rem rgba(154,102,255,0.15); }

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

        .suggested-questions {
            display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.8rem;
        }
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

        /* === AUDIT DETAIL STYLES === */
        .audit-item {
            display: flex; align-items: center; gap: 0.7rem; padding: 0.6rem 0.8rem;
            border-radius: 8px; margin-bottom: 0.4rem; font-size: 0.9rem; font-weight: 600;
        }
        .audit-item.pass { background: #f0fdf4; color: #15803d; }
        .audit-item.fail { background: #fef2f2; color: #991b1b; }
        .audit-item.warn { background: #fffbeb; color: #92400e; }
        .audit-item ion-icon { font-size: 1.2rem; flex-shrink: 0; }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #6c757d;
        }

        .empty-state ion-icon {
            font-size: 3rem;
            color: #d1d5db;
            margin-bottom: 1rem;
        }

        @media (max-width: 1200px) { 
            .sidenav { width: 180px; } 
            .main-content { margin-left: 180px; } 
            .page-content { padding: 1.5rem 1rem; }
        }

        @media (max-width: 900px) { 
            .sidenav { position: fixed; left: -220px; width: 220px; transition: left 0.3s ease; } 
            .sidenav.show { left: 0; } 
            .main-content { margin-left: 0; } 
            .page-content { padding: 1rem; } 
            .topbar { 
                padding: 1rem 1.5rem;
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            .topbar h1 { font-size: 1.4rem; }
            .profile { width: 100%; }
            .policies-table {
                font-size: 0.85rem;
            }
            .policies-table th,
            .policies-table td {
                padding: 0.8rem 0.5rem;
            }
            .ai-checker-box {
                flex-direction: column;
            }
        }

        @media (max-width: 700px) { 
            .topbar h1 { font-size: 1.2rem; }
            .topbar { padding: 1rem 0.8rem; }
            .page-content { padding: 0.8rem 0.5rem; } 
            .policies-table {
                font-size: 0.8rem;
            }
        }

        @media (max-width: 500px) { 
            .sidenav { width: 100vw; left: -100vw; } 
            .sidenav.show { left: 0; } 
            .main-content { margin-left: 0; }
            .topbar { padding: 0.8rem; gap: 0.5rem; }
            .topbar h1 { font-size: 1rem; gap: 0.5rem; }
            .topbar h1 ion-icon { font-size: 1.5rem; }
            .profile-img { width: 40px; height: 40px; }
            .profile-info strong { font-size: 0.9rem; }
            .page-content { padding: 0.5rem; }
            .card-body { padding: 1rem; }
        }

        @media (min-width: 1400px) { 
            .sidenav { width: 260px; } 
            .main-content { margin-left: 260px; } 
            .sidebar { padding: 2rem 1rem; } 
            .page-content { padding: 2.5rem; }
        }
    </style>
</head>
<body>
<div class="layout">
    <!-- Sidebar Navigation -->
    <div class="sidenav col-auto p-0">
        <?php render_sidebar('reimbursement_policies'); ?>
    </div>

    <!-- Main Content Area -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="topbar">
            <div>
                <h1>
                    <ion-icon name="settings-outline"></ion-icon> Reimbursement Policies
                </h1>
                <p class="topbar-subtitle">Manage and verify reimbursement policy limits and rules</p>
            </div>
            <div class="profile">
                <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
                <div class="profile-info">
                    <strong><?= htmlspecialchars($fullname) ?></strong>
                    <small><?= htmlspecialchars(ucfirst($role)) ?></small>
                </div>
            </div>
        </div>

        <!-- Page Content -->
        <div class="page-content">
            <!-- Policies Table Card -->
            <div class="card">
                <div class="card-header">
                    <ion-icon name="list-outline"></ion-icon> Active Reimbursement Policies
                </div>
                <div class="card-body">
                    <?php if (empty($policies)): ?>
                        <div class="empty-state">
                            <ion-icon name="settings-outline"></ion-icon>
                            <h5>No Policies Found</h5>
                            <p>No reimbursement policies are currently configured.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="policies-table">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th>Max Amount</th>
                                        <th>Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($policies as $policy): ?>
                                        <tr>
                                            <td><span class="category-badge"><?= htmlspecialchars($policy['category']) ?></span></td>
                                            <td><span class="amount-badge">₱<?= number_format(floatval($policy['limit_amount'] ?? 0), 2) ?></span></td>
                                            <td>
                                                <small style="color: #6c757d;">
                                                    <?php if (!empty($policy['updated_at'])): ?>
                                                        <?= date('M d, Y', strtotime($policy['updated_at'])) ?>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============================================= -->
            <!-- AI-POWERED POLICY CHECKER SECTION            -->
            <!-- ============================================= -->

            <!-- AI Policy Audit Card -->
            <div class="card" style="margin-top: 2rem;">
                <div class="card-header">
                    <ion-icon name="sparkles-outline"></ion-icon> AI Policy Verification Engine
                </div>
                <div class="card-body">
                    <div class="ai-checker-box" id="auditBox">
                        <div class="ai-checker-icon">
                            <ion-icon name="shield-checkmark-outline"></ion-icon>
                        </div>
                        <div class="ai-checker-content">
                            <div class="ai-checker-title">
                                <ion-icon name="sparkles-outline" style="color:#9A66ff;"></ion-icon> Automated Policy Audit
                            </div>
                            <div class="ai-checker-status">
                                Run a comprehensive AI audit to check all reimbursement policies for completeness, consistency, missing rules, and potential conflicts.
                            </div>
                            <div id="auditResultBadge" class="ai-status-pass" style="display:none;">
                                <ion-icon name="checkmark-circle-outline"></ion-icon>
                                <span id="auditBadgeText">Checking...</span>
                            </div>
                            <div id="auditDetails" style="display:none; margin-top:1rem;"></div>
                            <button class="btn btn-check-ai btn-sm" id="btnRunAudit" onclick="runPolicyAudit()">
                                <ion-icon name="refresh-outline"></ion-icon> Run AI Audit
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Policy Guidelines Card -->
            <div class="card" style="margin-top: 2rem;">
                <div class="card-header" style="background: linear-gradient(90deg, #10b981 0%, #059669 100%);">
                    <ion-icon name="information-circle-outline"></ion-icon> Policy Guidelines
                </div>
                <div class="card-body">
                    <div style="line-height: 1.8;">
                        <h6 style="font-weight: 700; margin-bottom: 1rem;">Key Points:</h6>
                        <ul style="margin-left: 1.5rem;">
                            <li><strong>Claim Limits:</strong> All claims must not exceed the maximum amount specified in the policy for that category.</li>
                            <li><strong>Documentation:</strong> All claims require valid receipt or invoice documentation.</li>
                            <li><strong>Timely Submission:</strong> Claims should be submitted within 30 days of expense incurrence (60 days for medical).</li>
                            <li><strong>Policy Compliance:</strong> All submitted claims are automatically verified by the AI Policy Checker against these policies.</li>
                            <li><strong>AI Verification:</strong> Advanced AI checks policy consistency, flags violations, and provides risk scoring.</li>
                            <li><strong>Risk Scoring:</strong> Each claim receives a risk score (0-100) — HIGH risk claims are automatically flagged for manual review.</li>
                            <li><strong>Natural Language Q&A:</strong> Employees can use the AI Policy Checker page to ask questions about policies in plain language.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ============================================================
// AI-POWERED POLICY CHECKER — Frontend Logic (Admin: Audit Only)
// ============================================================

// ============================================================
// RUN POLICY AUDIT
// ============================================================
function runPolicyAudit() {
    var btn = document.getElementById('btnRunAudit');
    var badge = document.getElementById('auditResultBadge');
    var details = document.getElementById('auditDetails');

    btn.disabled = true;
    btn.innerHTML = '<ion-icon name="hourglass-outline"></ion-icon> Running AI Audit...';
    badge.style.display = 'flex';
    badge.className = 'ai-status-pass';
    document.getElementById('auditBadgeText').textContent = 'Analyzing policies...';
    details.style.display = 'none';

    fetch('policy_checker_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=run_audit'
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<ion-icon name="refresh-outline"></ion-icon> Run AI Audit';

        if (!data.ok) {
            badge.className = 'ai-status-pass';
            badge.style.background = '#fee2e2'; badge.style.color = '#991b1b';
            document.getElementById('auditBadgeText').textContent = 'Error: ' + (data.error || 'Unknown');
            return;
        }

        var audit = data.audit;

        if (audit.overall === 'ALL_PASS') {
            badge.style.background = '#dcfce7'; badge.style.color = '#15803d';
            document.getElementById('auditBadgeText').innerHTML = '<ion-icon name="checkmark-circle-outline"></ion-icon> All ' + audit.total_policies + ' policies passed verification';
        } else {
            badge.style.background = '#fef3c7'; badge.style.color = '#92400e';
            document.getElementById('auditBadgeText').innerHTML = '<ion-icon name="alert-circle-outline"></ion-icon> ' + audit.passed + '/' + audit.total_policies + ' passed — ' + audit.failed + ' with issues';
        }

        var html = '';
        audit.results.forEach(function(r) {
            var cls = r.status === 'PASS' ? 'pass' : (r.status === 'FAIL' ? 'fail' : 'warn');
            var icon = r.status === 'PASS' ? 'checkmark-circle' : (r.status === 'FAIL' ? 'close-circle' : 'alert-circle');
            html += '<div class="audit-item ' + cls + '">';
            html += '<ion-icon name="' + icon + '-outline"></ion-icon>';
            html += '<span>' + escapeHtml(r.category) + ' — ₱' + Number(r.limit).toLocaleString('en',{minimumFractionDigits:2}) + ' — ' + r.rule_count + ' rule(s)</span>';
            if (r.issues.length > 0) {
                html += '<small style="margin-left:auto; font-weight:400;"> ' + escapeHtml(r.issues.join('; ')) + '</small>';
            }
            html += '</div>';
        });
        html += '<div style="margin-top:0.7rem; font-size:0.82rem; color:#9ca3af;">Checked at: ' + escapeHtml(audit.checked_at) + '</div>';

        details.innerHTML = html;
        details.style.display = 'block';
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '<ion-icon name="refresh-outline"></ion-icon> Run AI Audit';
        document.getElementById('auditBadgeText').textContent = 'Network error';
    });
}

function escapeHtml(t) {
    if (!t) return '';
    var m = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
    return String(t).replace(/[&<>"']/g, function(c) { return m[c] || c; });
}
</script>

<script>
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

    ['mousemove','mousedown','keydown','touchstart','scroll','click'].forEach(function(evt) {
        document.addEventListener(evt, resetTimers, {passive:true});
    });
    resetTimers();
})();

// Periodic session check
setInterval(function() {
    fetch("check_session.php")
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.expired) {
                location.replace("../login.php?session=expired");
            }
        });
}, 30000);

// Prevent back navigation after logout
window.history.pushState(null, "", window.location.href);
window.onpopstate = function () {
    location.replace("../login.php?auth=required");
};
</script>

</body>
</html>