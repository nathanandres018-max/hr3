<?php
/**
 * claim_verification.php
 * AI-powered Claim Verification & Receipt Matching
 * Enhanced with advanced receipt image detection and analysis
 * Verifies if employee-submitted claim details match the uploaded receipt
 * Maintains sidebar, topbar, and CSS styling
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

// Load reimbursement policies
$policies = [];
if (isset($conn) && $conn) {
    $sql = "SELECT category, limit_amount FROM reimbursement_policies ORDER BY category ASC";
    if ($res = mysqli_query($conn, $sql)) {
        while ($row = mysqli_fetch_assoc($res)) $policies[] = $row;
        mysqli_free_result($res);
    }
}
if (empty($policies)) {
    $policies = [
        ['category' => 'Meal', 'limit_amount' => 500.00],
        ['category' => 'Travel', 'limit_amount' => 2000.00],
        ['category' => 'Medical', 'limit_amount' => 15000.00],
        ['category' => 'Supplies', 'limit_amount' => 2500.00],
        ['category' => 'Others', 'limit_amount' => 5000.00],
    ];
}

// Fetch pending claims for verification
$pendingClaims = [];
if (isset($conn) && $conn) {
    $sql = "SELECT 
                c.id,
                c.amount,
                c.category,
                c.vendor,
                c.expense_date,
                c.description,
                c.created_by,
                c.status,
                c.receipt_path,
                c.created_at,
                c.nlp_suggestions,
                c.risk_score,
                c.receipt_validity,
                e.fullname as employee_name,
                e.employee_id
            FROM claims c
            LEFT JOIN employees e ON c.created_by = e.username
            WHERE c.status = 'pending' 
            ORDER BY c.created_at DESC 
            LIMIT 50";
    
    if ($res = mysqli_query($conn, $sql)) {
        while ($row = mysqli_fetch_assoc($res)) {
            $pendingClaims[] = $row;
        }
        mysqli_free_result($res);
    }
}

// --- SIDEBAR RENDERER FUNCTION ---
function render_sidebar(string $active = 'claim_verification') {
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
    <title>Claim Verification - ViaHale TNVS HR3</title>
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

        .sidebar hr { border-top: 1px solid #232a43; margin: 0.7rem 0; }
        .sidebar .nav-link ion-icon { font-size: 1.2rem; }

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

        .claim-item {
            background: linear-gradient(135deg, #f9fafb 0%, #f0f4ff 100%);
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .claim-item:hover {
            border-color: #9A66ff;
            box-shadow: 0 6px 20px rgba(154, 102, 255, 0.15);
            transform: translateY(-2px);
        }

        .claim-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 1rem;
        }

        .claim-title {
            font-weight: 700;
            color: #22223b;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .claim-subtitle {
            font-size: 0.85rem;
            color: #6c757d;
            display: block;
            margin-top: 0.3rem;
        }

        .claim-status {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-pending { background: #fff3cd; color: #856404; }
        .status-verified { background: #d1fae5; color: #065f46; }
        .status-flagged { background: #fee2e2; color: #991b1b; }

        .claim-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .detail-item {
            background: #fff;
            padding: 0.8rem;
            border-radius: 8px;
            border-left: 4px solid #9A66ff;
        }

        .detail-label {
            font-size: 0.85rem;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 0.3rem;
        }

        .detail-value {
            font-weight: 600;
            color: #22223b;
            font-size: 1rem;
        }

        .verification-score {
            background: #f0f4ff;
            border: 2px solid #9A66ff;
            border-radius: 12px;
            padding: 1.2rem;
            margin: 1rem 0;
        }

        .score-bar {
            height: 8px;
            background: #e0e7ff;
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .score-fill {
            height: 100%;
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
            transition: width 0.5s ease;
        }

        .score-fill.high { background: linear-gradient(90deg, #10b981 0%, #059669 100%); }
        .score-fill.medium { background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%); }
        .score-fill.low { background: linear-gradient(90deg, #ef4444 0%, #dc2626 100%); }

        .mismatch-warning {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            border-radius: 8px;
            padding: 1rem;
            margin: 0.8rem 0;
            color: #991b1b;
        }

        .match-success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            border-radius: 8px;
            padding: 1rem;
            margin: 0.8rem 0;
            color: #065f46;
        }

        .match-warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            border-radius: 8px;
            padding: 1rem;
            margin: 0.8rem 0;
            color: #92400e;
        }

        .verification-modal .modal-header {
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
            color: white;
            border: none;
        }

        .verification-modal .modal-title {
            font-weight: 700;
            font-size: 1.23rem;
        }

        .match-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.8rem 0;
            border-bottom: 1px solid #e0e7ff;
        }

        .match-item:last-child {
            border-bottom: none;
        }

        .match-label {
            font-weight: 600;
            color: #22223b;
        }

        .match-confidence {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .confidence-value {
            min-width: 60px;
            text-align: right;
            font-weight: 700;
            color: #9A66ff;
        }

        .confidence-badge {
            display: inline-block;
            padding: 0.3rem 0.6rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            min-width: 50px;
            text-align: center;
        }

        .badge-pass { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-fail { background: #fee2e2; color: #991b1b; }

        .receipt-analysis-section {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 12px;
            padding: 1.2rem;
            margin: 1.5rem 0;
        }

        .analysis-title {
            font-weight: 700;
            color: #0284c7;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .analysis-row {
            display: flex;
            justify-content: space-between;
            padding: 0.8rem 0;
            border-bottom: 1px solid #bae6fd;
        }

        .analysis-row:last-child {
            border-bottom: none;
        }

        .analysis-label {
            color: #0284c7;
            font-weight: 600;
        }

        .analysis-value {
            color: #22223b;
            font-weight: 600;
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

        .btn-primary:hover, .btn-primary:focus {
            background: linear-gradient(90deg, #8654e0 0%, #360090 100%);
            transform: translateY(-2px);
            color: white;
            box-shadow: 0 6px 20px rgba(154, 102, 255, 0.3);
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover, .btn-success:focus {
            background: #059669;
            transform: translateY(-2px);
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-warning:hover, .btn-warning:focus {
            background: #d97706;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover, .btn-danger:focus {
            background: #dc2626;
            transform: translateY(-2px);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.8rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .action-buttons .btn {
            flex: 1;
            min-width: 140px;
        }

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

        .empty-state h5 {
            color: #22223b;
            margin-bottom: 0.5rem;
        }

        .tabs-container {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid #e0e7ff;
        }

        .tab-btn {
            padding: 0.8rem 1.2rem;
            background: none;
            border: none;
            color: #6c757d;
            font-weight: 600;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.2s ease;
        }

        .tab-btn.active {
            color: #9A66ff;
            border-bottom-color: #9A66ff;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
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
            .claim-details { grid-template-columns: repeat(2, 1fr); }
            .action-buttons { flex-direction: column; }
            .action-buttons .btn { width: 100%; }
        }

        @media (max-width: 700px) { 
            .topbar h1 { font-size: 1.2rem; }
            .topbar { padding: 1rem 0.8rem; }
            .page-content { padding: 0.8rem 0.5rem; } 
            .claim-details { grid-template-columns: 1fr; }
            .claim-header { flex-direction: column; }
            .claim-status { margin-top: 0.5rem; }
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
            .claim-item { padding: 1rem; }
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
        <?php render_sidebar('claim_verification'); ?>
    </div>

    <!-- Main Content Area -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="topbar">
            <div>
                <h1>
                    <ion-icon name="checkmark-circle-outline"></ion-icon> Claim Verification
                </h1>
                <p class="topbar-subtitle">AI-powered verification with advanced receipt image detection</p>
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
            <!-- Pending Claims for Verification -->
            <div class="card mb-3">
                <div class="card-header">
                    <ion-icon name="list-outline"></ion-icon> Pending Claims to Verify
                </div>
                <div class="card-body">
                    <?php if (empty($pendingClaims)): ?>
                        <div class="empty-state">
                            <ion-icon name="document-outline"></ion-icon>
                            <h5>No Pending Claims</h5>
                            <p>There are no claims waiting for verification at this time.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pendingClaims as $claim): ?>
                            <?php 
                                $receiptSrc = '';
                                if (!empty($claim['receipt_path'])) {
                                    // Try relative to benefits/uploads/claims/
                                    if (file_exists(__DIR__ . '/uploads/claims/' . basename($claim['receipt_path']))) {
                                        $receiptSrc = 'uploads/claims/' . basename($claim['receipt_path']);
                                    } elseif (file_exists(__DIR__ . '/' . $claim['receipt_path'])) {
                                        $receiptSrc = $claim['receipt_path'];
                                    } elseif (file_exists(__DIR__ . '/../' . $claim['receipt_path'])) {
                                        $receiptSrc = '../' . $claim['receipt_path'];
                                    }
                                }
                            ?>
                            <div class="claim-item" data-claim-id="<?= htmlspecialchars($claim['id']) ?>" data-receipt-path="<?= htmlspecialchars($receiptSrc) ?>">
                                <div class="claim-header">
                                    <div>
                                        <div class="claim-title">
                                            <ion-icon name="receipt-outline"></ion-icon>
                                            Claim #<?= htmlspecialchars($claim['id']) ?>
                                        </div>
                                        <span class="claim-subtitle">
                                            <?= htmlspecialchars($claim['employee_name'] ?? 'Unknown') ?> 
                                            (<?= htmlspecialchars($claim['employee_id'] ?? 'N/A') ?>)
                                        </span>
                                    </div>
                                    <span class="claim-status status-pending">Pending Review</span>
                                </div>

                                <div class="claim-details">
                                    <div class="detail-item">
                                        <div class="detail-label">Category</div>
                                        <div class="detail-value"><?= htmlspecialchars($claim['category'] ?? 'N/A') ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Amount</div>
                                        <div class="detail-value">₱<?= number_format(floatval($claim['amount'] ?? 0), 2) ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Vendor</div>
                                        <div class="detail-value"><?= htmlspecialchars($claim['vendor'] ?? 'Not extracted') ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Expense Date</div>
                                        <div class="detail-value"><?= htmlspecialchars($claim['expense_date'] ?? 'N/A') ?></div>
                                    </div>
                                </div>

                                <div class="action-buttons">
                                    <button type="button" class="btn btn-sm btn-primary btn-verify" data-claim="<?= $claim['id'] ?>">
                                        <ion-icon name="search-outline"></ion-icon> Verify Claim
                                    </button>
                                    <button type="button" class="btn btn-sm btn-success btn-approve" data-claim="<?= $claim['id'] ?>" disabled>
                                        <ion-icon name="checkmark-outline"></ion-icon> Approve
                                    </button>
                                    <button type="button" class="btn btn-sm btn-warning btn-review" data-claim="<?= $claim['id'] ?>" disabled>
                                        <ion-icon name="eye-outline"></ion-icon> Review
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger btn-flag" data-claim="<?= $claim['id'] ?>" disabled>
                                        <ion-icon name="flag-outline"></ion-icon> Flag
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Claim Verification Modal -->
<div class="modal fade verification-modal" id="verificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <ion-icon name="checkmark-circle-outline"></ion-icon> Verification Results
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">
                
                <!-- Tab Navigation -->
                <div class="tabs-container">
                    <button class="tab-btn active" onclick="switchTab('details')">
                        <ion-icon name="document-outline"></ion-icon> Details
                    </button>
                    <button class="tab-btn" onclick="switchTab('receipt_preview')">
                        <ion-icon name="image-outline"></ion-icon> Receipt
                    </button>
                    <button class="tab-btn" onclick="switchTab('analysis')">
                        <ion-icon name="analytics-outline"></ion-icon> Receipt Analysis
                    </button>
                    <button class="tab-btn" onclick="switchTab('verification')">
                        <ion-icon name="checkmark-circle-outline"></ion-icon> Verification
                    </button>
                </div>

                <!-- Tab 0: Receipt Image Preview -->
                <div id="receipt_preview" class="tab-content">
                    <div class="row g-3">
                        <div class="col-md-7 text-center">
                            <div id="receiptImageContainer" style="background:#f8f9fa; border:2px dashed #dee2e6; border-radius:12px; padding:1rem; min-height:300px; display:flex; align-items:center; justify-content:center;">
                                <div id="receiptNoImage" style="color:#adb5bd;">
                                    <ion-icon name="image-outline" style="font-size:3rem; display:block; margin-bottom:0.5rem;"></ion-icon>
                                    <span>No receipt image available</span>
                                </div>
                                <img id="receiptPreviewImg" src="" alt="Receipt" style="max-width:100%; max-height:450px; border-radius:8px; display:none; cursor:pointer;" onclick="window.open(this.src,'_blank')">
                            </div>
                            <small class="text-muted mt-2 d-block">Click image to view full size</small>
                        </div>
                        <div class="col-md-5">
                            <h6 style="font-weight:700; color:#22223b; margin-bottom:1rem;">
                                <ion-icon name="text-outline"></ion-icon> Extracted Text
                            </h6>
                            <div id="receiptRawText" style="background:#1a1a2e; color:#e0e7ff; padding:1rem; border-radius:8px; font-family:monospace; font-size:0.8rem; max-height:400px; overflow-y:auto; white-space:pre-wrap; line-height:1.4;">
                                No OCR text available
                            </div>
                            <div class="mt-2 d-flex gap-2 flex-wrap">
                                <span class="badge" id="receiptOcrConfBadge" style="background:#9A66ff; font-size:0.75rem;">OCR: --</span>
                                <span class="badge" id="receiptExtrConfBadge" style="background:#6366f1; font-size:0.75rem;">Extraction: --</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 1: Details -->
                <div id="details" class="tab-content active">
                    <div class="row g-3 mb-4">
                        <!-- Submitted Details -->
                        <div class="col-md-6">
                            <h6 style="font-weight: 700; color: #22223b; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
                                <ion-icon name="document-outline"></ion-icon> Employee Submission
                            </h6>
                            <div class="detail-item">
                                <div class="detail-label">Vendor</div>
                                <div class="detail-value" id="modalSubmittedVendor">-</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Amount</div>
                                <div class="detail-value" id="modalSubmittedAmount">₱0.00</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Category</div>
                                <div class="detail-value" id="modalSubmittedCategory">-</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Expense Date</div>
                                <div class="detail-value" id="modalSubmittedDate">-</div>
                            </div>
                        </div>

                        <!-- Extracted from Receipt -->
                        <div class="col-md-6">
                            <h6 style="font-weight: 700; color: #22223b; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
                                <ion-icon name="camera-outline"></ion-icon> Receipt Extraction
                            </h6>
                            <div class="detail-item">
                                <div class="detail-label">Detected Vendor</div>
                                <div class="detail-value" id="modalExtractedVendor">Not detected</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Detected Amount</div>
                                <div class="detail-value" id="modalExtractedAmount">₱0.00</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Detected Date</div>
                                <div class="detail-value" id="modalExtractedDate">Not detected</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 2: Receipt Analysis -->
                <div id="analysis" class="tab-content">
                    <div class="receipt-analysis-section">
                        <div class="analysis-title">
                            <ion-icon name="image-outline"></ion-icon> Image Quality
                        </div>
                        <div class="analysis-row">
                            <span class="analysis-label">Quality Score:</span>
                            <span class="analysis-value" id="analysisQualityScore">-</span>
                        </div>
                        <div class="analysis-row">
                            <span class="analysis-label">Quality Level:</span>
                            <span class="analysis-value" id="analysisQualityLevel">-</span>
                        </div>
                        <div class="analysis-row">
                            <span class="analysis-label">Resolution:</span>
                            <span class="analysis-value" id="analysisResolution">-</span>
                        </div>
                        <div class="analysis-row">
                            <span class="analysis-label">Blur Detected:</span>
                            <span class="analysis-value" id="analysisBlur">-</span>
                        </div>
                    </div>

                    <div class="receipt-analysis-section">
                        <div class="analysis-title">
                            <ion-icon name="shield-checkmark-outline"></ion-icon> Authenticity Check
                        </div>
                        <div class="analysis-row">
                            <span class="analysis-label">Tampering Risk:</span>
                            <span class="analysis-value" id="analysisTamperRisk">-</span>
                        </div>
                        <div class="analysis-row">
                            <span class="analysis-label">Tampering Score:</span>
                            <span class="analysis-value" id="analysisTamperScore">-</span>
                        </div>
                        <div class="analysis-row">
                            <span class="analysis-label">Document Type:</span>
                            <span class="analysis-value" id="analysisDocType">-</span>
                        </div>
                        <div class="analysis-row">
                            <span class="analysis-label">Type Confidence:</span>
                            <span class="analysis-value" id="analysisTypeConfidence">-</span>
                        </div>
                    </div>

                    <div class="receipt-analysis-section">
                        <div class="analysis-title">
                            <ion-icon name="alert-circle-outline"></ion-icon> Anomalies Detected
                        </div>
                        <div id="anomaliesList" style="color: #22223b;">No anomalies detected</div>
                    </div>
                </div>

                <!-- Tab 3: Verification Score & Details -->
                <div id="verification" class="tab-content">
                    <!-- Verification Score -->
                    <div class="verification-score">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.8rem;">
                            <strong>Overall Verification Score</strong>
                            <span id="scorePercentage" style="font-size: 1.4rem; font-weight: 800; color: #9A66ff;">0%</span>
                        </div>
                        <div class="score-bar">
                            <div class="score-fill" id="scoreFill" style="width: 0%;"></div>
                        </div>
                        <div id="scoreMessage" class="small" style="margin-top: 0.5rem; color: #6c757d; font-style: italic;"></div>
                    </div>

                    <!-- Detailed Match Analysis -->
                    <div id="matchAnalysis" style="margin-top: 1.5rem;">
                        <h6 style="font-weight: 700; color: #22223b; margin-bottom: 1rem;">Match Analysis</h6>
                        <div id="matchDetails"></div>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="reRunOCR()" title="Re-run OCR analysis on this receipt">
                    <ion-icon name="refresh-outline"></ion-icon> Re-Analyze
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <ion-icon name="close-outline"></ion-icon> Close
                </button>
                <button type="button" id="approveFromModalBtn" class="btn btn-success" onclick="approveFromModal()">
                    <ion-icon name="checkmark-outline"></ion-icon> Approve Claim
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
let currentVerificationModal = null;
let currentClaimId = null;
let currentVerificationData = null;

// Initialize modal
document.addEventListener('DOMContentLoaded', function() {
    currentVerificationModal = new bootstrap.Modal(document.getElementById('verificationModal'), {
        backdrop: 'static',
        keyboard: false
    });

    // Attach event listeners to verify buttons
    document.querySelectorAll('.btn-verify').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const claimId = this.getAttribute('data-claim');
            verifyClaimReceipt(claimId);
        });
    });

    // Attach event listeners to approve buttons
    document.querySelectorAll('.btn-approve').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const claimId = this.getAttribute('data-claim');
            approveClaim(claimId);
        });
    });

    // Attach event listeners to review buttons
    document.querySelectorAll('.btn-review').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const claimId = this.getAttribute('data-claim');
            markForReview(claimId);
        });
    });

    // Attach event listeners to flag buttons
    document.querySelectorAll('.btn-flag').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const claimId = this.getAttribute('data-claim');
            flagClaim(claimId);
        });
    });
});

/**
 * Switch between tabs
 */
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });

    // Deactivate all buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });

    // Show selected tab
    document.getElementById(tabName).classList.add('active');

    // Activate selected button
    event.target.closest('.tab-btn').classList.add('active');
}

/**
 * Verify claim against receipt with enhanced analysis
 */
async function verifyClaimReceipt(claimId) {
    const card = document.querySelector(`[data-claim-id="${claimId}"]`);
    const verifyBtn = card.querySelector('.btn-verify');

    currentClaimId = claimId;
    verifyBtn.disabled = true;
    verifyBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Analyzing...';

    try {
        const formData = new FormData();
        formData.append('claim_id', claimId);

        // Use enhanced verification endpoint
        const response = await fetch('verify_claim_enhanced.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Invalid response type:', contentType);
            console.error('Response:', text.substring(0, 500));
            throw new Error('Server returned invalid response: ' + (contentType || 'unknown'));
        }

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Verification failed');
        }

        // Store data for later use
        currentVerificationData = data;

        // Update modal with results
        updateVerificationResults(data, card);

        // Show modal
        currentVerificationModal.show();

    } catch (error) {
        console.error('Error:', error);
        alert('❌ Error verifying claim:\n\n' + error.message + 
              '\n\nPlease check the console for more details.');
    } finally {
        verifyBtn.disabled = false;
        verifyBtn.innerHTML = '<ion-icon name="search-outline"></ion-icon> Verify Claim';
    }
}

/**
 * Update verification modal with results
 */
function updateVerificationResults(data, card) {
    const results = data.verifications;
    const claimData = data.claim_data;
    const receipt = data.receipt_analysis || {};
    const anomalies = data.anomalies || [];

    // ===== RECEIPT PREVIEW TAB =====
    const receiptPath = card.getAttribute('data-receipt-path') || '';
    const receiptImg = document.getElementById('receiptPreviewImg');
    const noImageDiv = document.getElementById('receiptNoImage');
    if (receiptPath) {
        receiptImg.src = receiptPath;
        receiptImg.style.display = 'block';
        noImageDiv.style.display = 'none';
    } else {
        receiptImg.style.display = 'none';
        noImageDiv.style.display = 'block';
    }

    // OCR raw text
    const textExtraction = receipt.text_extraction || {};
    const rawText = textExtraction.full_text || 'No OCR text available';
    document.getElementById('receiptRawText').textContent = rawText;
    document.getElementById('receiptOcrConfBadge').textContent = 'OCR: ' + Math.round((textExtraction.confidence || 0) * 100) + '%';
    document.getElementById('receiptExtrConfBadge').textContent = 'Lines: ' + (textExtraction.lines_count || 0);

    // ===== TAB 1: DETAILS =====
    document.getElementById('modalSubmittedVendor').textContent = claimData.vendor || 'Not specified';
    document.getElementById('modalSubmittedAmount').textContent = '₱' + parseFloat(claimData.amount || 0).toFixed(2);
    document.getElementById('modalSubmittedCategory').textContent = claimData.category || 'Unknown';
    document.getElementById('modalSubmittedDate').textContent = claimData.expense_date || 'N/A';

    const detectedPatterns = receipt.text_extraction?.detected_patterns || {};
    document.getElementById('modalExtractedVendor').textContent = detectedPatterns.vendor || 'Not detected';
    document.getElementById('modalExtractedAmount').textContent = '₱' + (detectedPatterns.amounts?.[0] || 0).toFixed(2);
    document.getElementById('modalExtractedDate').textContent = detectedPatterns.dates?.[0] || 'Not detected';

    // ===== TAB 2: RECEIPT ANALYSIS =====
    const imageQuality = receipt.image_quality || {};
    document.getElementById('analysisQualityScore').textContent = (imageQuality.score || 0) + ' / 1.0';
    document.getElementById('analysisQualityLevel').textContent = (imageQuality.level || 'unknown').toUpperCase();
    document.getElementById('analysisResolution').textContent = imageQuality.resolution || 'Unknown';
    document.getElementById('analysisBlur').textContent = imageQuality.blur_detected ? '⚠️ Yes' : '✅ No';

    const tamper = receipt.tampering_score || {};
    document.getElementById('analysisTamperScore').textContent = ((tamper.score || 0) * 100).toFixed(1) + '%';
    document.getElementById('analysisTamperRisk').textContent = (tamper.risk || 'unknown').toUpperCase();

    const docType = receipt.receipt_type || {};
    document.getElementById('analysisDocType').textContent = (docType.type || 'unknown').toUpperCase();
    document.getElementById('analysisTypeConfidence').textContent = ((docType.confidence || 0) * 100).toFixed(1) + '%';

    // Anomalies — use engine anomalies (data.anomalies) merged with receipt anomalies
    const allAnomalies = [...(anomalies || []), ...(receipt.anomalies || [])];
    const anomaliesList = document.getElementById('anomaliesList');
    if (allAnomalies.length === 0) {
        anomaliesList.innerHTML = '<span style="color: #10b981;">✅ No anomalies detected</span>';
    } else {
        anomaliesList.innerHTML = allAnomalies.map(a => {
            const msg = typeof a === 'object' ? a.message : String(a).replace(/_/g, ' ');
            const severity = typeof a === 'object' ? (a.severity || 'warning') : 'warning';
            const icon = severity === 'critical' ? '🚨' : severity === 'warning' ? '⚠️' : 'ℹ️';
            const color = severity === 'critical' ? '#dc2626' : severity === 'warning' ? '#ef4444' : '#6366f1';
            return `<div style="color: ${color}; margin: 0.5rem 0; padding: 0.4rem 0.6rem; background: ${color}11; border-radius: 6px; font-size: 0.9rem;">${icon} ${msg}</div>`;
        }).join('');
    }

    // ===== TAB 3: VERIFICATION SCORE =====
    const scorePercentage = document.getElementById('scorePercentage');
    const scoreFill = document.getElementById('scoreFill');
    const scoreMessage = document.getElementById('scoreMessage');

    scorePercentage.textContent = data.overall_score + '%';
    scoreFill.style.width = data.overall_score + '%';
    scoreFill.className = 'score-fill';

    if (data.overall_score >= 80) {
        scoreFill.classList.add('high');
        scoreMessage.innerHTML = '✅ <strong>Strong match</strong> - Details align well with receipt';
    } else if (data.overall_score >= 60) {
        scoreFill.classList.add('medium');
        scoreMessage.innerHTML = '⚠️ <strong>Moderate match</strong> - Some discrepancies detected';
    } else {
        scoreFill.classList.add('low');
        scoreMessage.innerHTML = '❌ <strong>Low match</strong> - Significant differences detected';
    }

    // Build match analysis HTML
    let matchHtml = '';

    if (results.amount_match) {
        matchHtml += createMatchItem('Amount Match', results.amount_match);
    }
    if (results.vendor_match) {
        matchHtml += createMatchItem('Vendor Match', results.vendor_match);
    }
    if (results.date_match) {
        matchHtml += createMatchItem('Date Match', results.date_match);
    }
    if (results.category_match) {
        matchHtml += createMatchItem('Category Match', results.category_match);
    }
    if (results.receipt_quality) {
        matchHtml += createMatchItem('Receipt Quality', results.receipt_quality);
    }
    if (results.receipt_authenticity) {
        matchHtml += createMatchItem('Receipt Authenticity', results.receipt_authenticity);
    }
    if (results.duplicate_check) {
        matchHtml += createMatchItem('Duplicate Check', results.duplicate_check);
    }

    document.getElementById('matchDetails').innerHTML = matchHtml;

    // Update action button states
    const approveBtn = card.querySelector('.btn-approve');
    const reviewBtn = card.querySelector('.btn-review');
    const flagBtn = card.querySelector('.btn-flag');

    if (data.status === 'verified' || data.status === 'approved') {
        approveBtn.disabled = false;
        reviewBtn.disabled = true;
        flagBtn.disabled = true;
    } else if (data.status === 'review_pending' || data.status === 'manual_review') {
        approveBtn.disabled = false;
        reviewBtn.disabled = false;
        flagBtn.disabled = false;
    } else {
        approveBtn.disabled = true;
        reviewBtn.disabled = false;
        flagBtn.disabled = false;
    }
}

/**
 * Create a match item HTML element
 */
function createMatchItem(label, item) {
    const confidencePercent = Math.round((item.confidence || 0) * 100);
    let statusClass = 'badge-warning';
    
    if (item.status === 'pass') {
        statusClass = 'badge-pass';
    } else if (item.status === 'fail') {
        statusClass = 'badge-fail';
    }

    return `
        <div class="match-item">
            <div style="flex: 1;">
                <div class="match-label">${label}</div>
                <small style="color: #6c757d; display: block; margin-top: 0.3rem;">${item.message || ''}</small>
            </div>
            <div class="match-confidence">
                <span class="confidence-badge ${statusClass}">${(item.status || 'unknown').toUpperCase()}</span>
                <span class="confidence-value">${confidencePercent}%</span>
            </div>
        </div>
    `;
}

/**
 * Approve claim
 */
async function approveClaim(claimId) {
    if (!confirm('Are you sure you want to approve this claim?')) {
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'approve');
        formData.append('claim_id', claimId);

        const response = await fetch('process_claim.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Invalid response format');
        }

        const data = await response.json();

        if (data.success) {
            alert('✅ Claim approved successfully!');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.error || 'Unknown error'));
        }
    } catch (error) {
        alert('❌ Error approving claim: ' + error.message);
    }
}

/**
 * Mark claim for review
 */
async function markForReview(claimId) {
    const reason = prompt('Enter reason for manual review:');
    if (!reason) return;

    try {
        const formData = new FormData();
        formData.append('action', 'review');
        formData.append('claim_id', claimId);
        formData.append('reason', reason);

        const response = await fetch('process_claim.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (data.success) {
            alert('✅ Claim marked for review!');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.error || 'Unknown error'));
        }
    } catch (error) {
        alert('❌ Error marking claim for review: ' + error.message);
    }
}

/**
 * Flag claim
 */
async function flagClaim(claimId) {
    const reason = prompt('Enter reason for flagging this claim:');
    if (!reason) return;

    try {
        const formData = new FormData();
        formData.append('action', 'flag');
        formData.append('claim_id', claimId);
        formData.append('reason', reason);

        const response = await fetch('process_claim.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (data.success) {
            alert('✅ Claim flagged for investigation!');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.error || 'Unknown error'));
        }
    } catch (error) {
        alert('❌ Error flagging claim: ' + error.message);
    }
}

/**
 * Approve from modal
 */
function approveFromModal() {
    if (currentClaimId) {
        currentVerificationModal.hide();
        approveClaim(currentClaimId);
    }
}

/**
 * Re-run OCR analysis on the current claim (force fresh extraction)
 */
async function reRunOCR() {
    if (!currentClaimId) return;

    const card = document.querySelector(`[data-claim-id="${currentClaimId}"]`);
    const reBtn = document.querySelector('.modal-footer .btn-outline-primary');
    if (reBtn) {
        reBtn.disabled = true;
        reBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Re-analyzing...';
    }

    try {
        const formData = new FormData();
        formData.append('claim_id', currentClaimId);
        formData.append('force_reocr', '1');

        const response = await fetch('verify_claim_enhanced.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Re-analysis failed');
        }

        currentVerificationData = data;
        updateVerificationResults(data, card);

        // Flash the score to indicate update
        const scoreEl = document.getElementById('scorePercentage');
        scoreEl.style.transition = 'none';
        scoreEl.style.color = '#10b981';
        setTimeout(() => {
            scoreEl.style.transition = 'color 1s ease';
            scoreEl.style.color = '#9A66ff';
        }, 100);

    } catch (error) {
        alert('❌ Re-analysis error: ' + error.message);
    } finally {
        if (reBtn) {
            reBtn.disabled = false;
            reBtn.innerHTML = '<ion-icon name="refresh-outline"></ion-icon> Re-Analyze';
        }
    }
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