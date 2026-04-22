<?php
/**
 * audit_reports.php
 * Audit & Reports - Query claims table directly (like dashboard)
 * Display claim records with summary statistics
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

// Get filter parameters
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$claim_type = $_GET['claim_type'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build where clauses
$where_clauses = [];

if (!empty($from_date)) {
    $from_date = mysqli_real_escape_string($conn, $from_date);
    $where_clauses[] = "DATE(c.created_at) >= '$from_date'";
}

if (!empty($to_date)) {
    $to_date = mysqli_real_escape_string($conn, $to_date);
    $where_clauses[] = "DATE(c.created_at) <= '$to_date'";
}

if (!empty($claim_type)) {
    $claim_type = mysqli_real_escape_string($conn, $claim_type);
    $where_clauses[] = "c.category = '$claim_type'";
}

if (!empty($status_filter)) {
    $status_filter = mysqli_real_escape_string($conn, $status_filter);
    $where_clauses[] = "c.status = '$status_filter'";
}

$where = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Fetch audit reports from claims table
$reports = [];
$sql = "SELECT 
            c.id,
            c.amount,
            c.category,
            c.vendor,
            c.created_at,
            c.status,
            c.created_by,
            c.reviewed_by,
            c.reviewed_at,
            c.review_notes,
            c.risk_score,
            c.receipt_validity,
            e.fullname
        FROM claims c
        LEFT JOIN employees e ON c.created_by = e.username
        $where
        ORDER BY c.created_at DESC
        LIMIT 500";

$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $reports[] = $row;
    }
}

// Calculate summary statistics
$total_claims = count($reports);
$total_amount = array_sum(array_column($reports, 'amount'));
$approved_count = count(array_filter($reports, fn($r) => in_array($r['status'], ['approved', 'processed'])));
$pending_count = count(array_filter($reports, fn($r) => $r['status'] === 'pending'));
$flagged_count = count(array_filter($reports, fn($r) => in_array($r['status'], ['flagged', 'needs_manual_review'])));
$reviewed_count = count(array_filter($reports, fn($r) => !empty($r['reviewed_by'])));

// Get available categories
$cat_sql = "SELECT DISTINCT category FROM claims WHERE category IS NOT NULL ORDER BY category ASC";
$cat_result = mysqli_query($conn, $cat_sql);
$categories = [];
while ($row = mysqli_fetch_assoc($cat_result)) {
    if (!empty($row['category'])) {
        $categories[] = $row['category'];
    }
}

// Get available statuses
$status_sql = "SELECT DISTINCT status FROM claims WHERE status IS NOT NULL ORDER BY status ASC";
$status_result = mysqli_query($conn, $status_sql);
$statuses = [];
while ($row = mysqli_fetch_assoc($status_result)) {
    if (!empty($row['status'])) {
        $statuses[] = $row['status'];
    }
}

// --- Sidebar renderer (matching other files) ---
function render_sidebar(string $active = 'audit_reports') {
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
    <title>Audit & Reports - ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
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

        .filter-section {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .filter-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #22223b;
            margin-bottom: 0.4rem;
            display: block;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.95rem;
            color: #22223b;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #9A66ff;
            box-shadow: 0 0 0 3px rgba(154, 102, 255, 0.1);
        }

        .filter-buttons {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
        }

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

        .btn-secondary {
            background: #e5e7eb;
            color: #22223b;
        }

        .btn-secondary:hover {
            background: #d1d5db;
        }

        .btn-sm {
            padding: 0.5rem 0.8rem;
            font-size: 0.85rem;
        }

        .reports-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5rem;
        }

        .reports-table thead {
            background: #f9fafb;
            border-bottom: 2px solid #e5e7eb;
        }

        .reports-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 700;
            color: #22223b;
            font-size: 0.9rem;
            text-transform: uppercase;
        }

        .reports-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
            transition: all 0.2s ease;
        }

        .reports-table tbody tr:hover {
            background: #f0f4ff;
        }

        .reports-table td {
            padding: 1rem;
            color: #22223b;
            font-size: 0.95rem;
        }

        .date-badge {
            font-weight: 600;
            color: #9A66ff;
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
        }

        .status-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-processed {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-flagged {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-needs_manual_review {
            background: #fecaca;
            color: #7c2d12;
        }

        .stats-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
            border: 1px solid #f0f0f0;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(140,140,200,0.05);
        }

        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: #9A66ff;
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

        @media (max-width: 1200px) { 
            .sidenav { width: 180px; }
            .main-content { margin-left: 180px; } 
            .page-content { padding: 1.5rem 1rem; }
        }

        @media (max-width: 900px) { 
            .sidenav {
                position: fixed;
                left: -220px;
                width: 220px;
                transition: left 0.3s ease;
            }
            .sidenav.show { left: 0; }
            .main-content { margin-left: 0; } 
            .topbar { 
                padding: 1rem 1.5rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            .topbar h1 { font-size: 1.4rem; }
            .profile { width: 100%; }
            .page-content { padding: 1rem; } 
            .filter-group {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 700px) { 
            .topbar h1 { font-size: 1.2rem; }
            .topbar { padding: 1rem 0.8rem; }
            .page-content { padding: 0.8rem 0.5rem; } 
            .filter-group {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 500px) {
            .sidenav {
                width: 100vw;
                left: -100vw;
            }
            .sidenav.show { left: 0; }
            .main-content { margin-left: 0; }
            .topbar {
                padding: 0.8rem;
                gap: 0.5rem;
            }
            .topbar h1 {
                font-size: 1rem;
                gap: 0.5rem;
            }
            .topbar h1 ion-icon { font-size: 1.5rem; }
            .profile-img { width: 40px; height: 40px; }
            .profile-info strong { font-size: 0.9rem; }
            .page-content { padding: 0.5rem; }
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
    <div class="sidenav col-auto p-0">
        <?php render_sidebar('audit_reports'); ?>
    </div>

    <div class="main-content">
        <div class="topbar">
            <div>
                <h1>
                    <ion-icon name="document-text-outline"></ion-icon> Audit & Reports
                </h1>
                <p class="topbar-subtitle">Generate and view claim audit reports and analytics</p>
            </div>
            <div class="profile">
                <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
                <div class="profile-info">
                    <strong><?= htmlspecialchars($fullname) ?></strong>
                    <small><?= htmlspecialchars(ucfirst($role)) ?></small>
                </div>
            </div>
        </div>

        <div class="page-content">
            <!-- Stats Section -->
            <div class="stats-section">
                <div class="stat-card">
                    <div class="stat-label">Total Claims</div>
                    <div class="stat-value"><?= $total_claims ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Amount</div>
                    <div class="stat-value">₱<?= number_format($total_amount, 2) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Approved</div>
                    <div class="stat-value"><?= $approved_count ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Pending</div>
                    <div class="stat-value"><?= $pending_count ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Flagged</div>
                    <div class="stat-value"><?= $flagged_count ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Reviewed</div>
                    <div class="stat-value"><?= $reviewed_count ?></div>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-header">
                    <ion-icon name="funnel-outline"></ion-icon> Filter Reports
                </div>
                <div class="card-body">
                    <form method="GET" class="filter-section">
                        <div class="filter-group">
                            <div>
                                <label for="from_date">From Date</label>
                                <input type="date" id="from_date" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
                            </div>
                            <div>
                                <label for="to_date">To Date</label>
                                <input type="date" id="to_date" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
                            </div>
                            <div>
                                <label for="claim_type">Category</label>
                                <select id="claim_type" name="claim_type">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>" <?= $claim_type === $cat ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="status">Status</label>
                                <select id="status" name="status">
                                    <option value="">All Statuses</option>
                                    <?php foreach ($statuses as $s): ?>
                                        <option value="<?= htmlspecialchars($s) ?>" <?= $status_filter === $s ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $s))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="filter-buttons">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <ion-icon name="search-outline"></ion-icon> Generate Report
                            </button>
                            <a href="audit_reports.php" class="btn btn-secondary btn-sm">
                                <ion-icon name="refresh-outline"></ion-icon> Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Reports Table Card -->
            <div class="card">
                <div class="card-header">
                    <ion-icon name="list-outline"></ion-icon> Audit Report
                </div>
                <div class="card-body">
                    <?php if (empty($reports)): ?>
                        <div class="empty-state">
                            <ion-icon name="document-outline"></ion-icon>
                            <h5>No Reports Found</h5>
                            <p>No claims found matching your filter criteria.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="reports-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Employee</th>
                                        <th>Category</th>
                                        <th>Vendor</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Reviewed By</th>
                                        <th>Review Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reports as $report): ?>
                                        <tr>
                                            <td><span class="date-badge"><?= date('M d, Y', strtotime($report['created_at'])) ?></span></td>
                                            <td><?= htmlspecialchars($report['fullname'] ?: $report['created_by']) ?></td>
                                            <td><span class="category-badge"><?= htmlspecialchars($report['category'] ?? 'N/A') ?></span></td>
                                            <td><?= htmlspecialchars($report['vendor'] ?? 'Not specified') ?></td>
                                            <td><span class="amount-badge">₱<?= number_format(floatval($report['amount'] ?? 0), 2) ?></span></td>
                                            <td>
                                                <span class="status-badge status-<?= strtolower(str_replace(' ', '_', $report['status'])) ?>">
                                                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $report['status']))) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($report['reviewed_by'] ?? 'Not reviewed') ?></td>
                                            <td>
                                                <?php if (!empty($report['reviewed_at'])): ?>
                                                    <?= date('M d, Y', strtotime($report['reviewed_at'])) ?>
                                                <?php else: ?>
                                                    <small class="text-muted">--</small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <button class="btn btn-primary btn-sm mt-3" onclick="exportToCSV()">
                            <ion-icon name="download-outline"></ion-icon> Export to CSV
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
function exportToCSV() {
    let csv = 'Date,Employee,Category,Vendor,Amount,Status,Reviewed By,Review Date\n';
    
    document.querySelectorAll('.reports-table tbody tr').forEach(row => {
        const cells = row.querySelectorAll('td');
        const date = cells[0]?.textContent?.trim() || '';
        const emp = cells[1]?.textContent?.trim() || '';
        const cat = cells[2]?.textContent?.trim() || '';
        const vendor = cells[3]?.textContent?.trim() || '';
        const amt = cells[4]?.textContent?.trim() || '';
        const status = cells[5]?.textContent?.trim() || '';
        const reviewer = cells[6]?.textContent?.trim() || '';
        const reviewDate = cells[7]?.textContent?.trim() || '';
        
        csv += `"${date}","${emp}","${cat}","${vendor}","${amt}","${status}","${reviewer}","${reviewDate}"\n`;
    });
    
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    link.setAttribute('href', url);
    link.setAttribute('download', 'audit_report_' + new Date().toISOString().split('T')[0] + '.csv');
    link.style.visibility = 'hidden';
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

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