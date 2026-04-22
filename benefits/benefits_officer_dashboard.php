<?php
/**
 * benefits_officer_dashboard.php
 * Benefits Officer Dashboard - Main Hub
 * Displays dashboard statistics, recent activity, and navigation
 * Aligned with claim_verification.php UI/UX standards
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

// Terms acceptance logic
if (!isset($_SESSION['claims_terms_accepted']) || !$_SESSION['claims_terms_accepted']) {
    $showTermsModal = true;
} else {
    $showTermsModal = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_terms'])) {
    $_SESSION['claims_terms_accepted'] = true;
    header("Location: benefits_officer_dashboard.php");
    exit();
}

// --- FETCH DASHBOARD STATISTICS FROM DATABASE ---
$stats = [
    'pending' => 0,
    'approved' => 0,
    'flagged' => 0,
    'total_amount' => 0,
];

$recent_activity = [];

if (isset($conn) && $conn) {
    // Get pending claims count
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM claims WHERE status = 'pending'");
    if ($row = mysqli_fetch_assoc($result)) {
        $stats['pending'] = $row['count'];
    }

    // Get approved claims count
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM claims WHERE status IN ('approved', 'processed')");
    if ($row = mysqli_fetch_assoc($result)) {
        $stats['approved'] = $row['count'];
    }

    // Get flagged claims count
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM claims WHERE status IN ('flagged', 'needs_manual_review')");
    if ($row = mysqli_fetch_assoc($result)) {
        $stats['flagged'] = $row['count'];
    }

    // Get total claims amount
    $result = mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM claims WHERE status IN ('approved', 'processed')");
    if ($row = mysqli_fetch_assoc($result)) {
        $stats['total_amount'] = floatval($row['total']);
    }

    // Get recent activity
    $activity_sql = "SELECT 
                        c.id,
                        c.amount,
                        c.category,
                        c.vendor,
                        c.created_at,
                        c.status,
                        e.fullname as employee_name,
                        e.employee_id
                    FROM claims c
                    LEFT JOIN employees e ON c.created_by = e.username
                    ORDER BY c.created_at DESC
                    LIMIT 10";

    $result = mysqli_query($conn, $activity_sql);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $recent_activity[] = $row;
        }
    }
}

// --- SIDEBAR RENDERER FUNCTION ---
function render_sidebar(string $active = 'dashboard') {
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - ViaHale TNVS HR3</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Ionicons -->
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>

    <style>
        * {
            transition: all 0.3s ease;
        }

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            font-family: 'QuickSand', 'Poppins', Arial, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #fafbfc 100%);
            color: #22223b;
            font-size: 16px;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

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

        .sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: #9A66ff;
            border-radius: 3px;
        }

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

        .sidebar .nav-link ion-icon {
            font-size: 1.2rem;
        }

        .sidebar hr {
            border-top: 1px solid #232a43;
            margin: 0.7rem 0;
        }

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
            box-shadow: 0 2px 8px rgba(140, 140, 200, 0.05);
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

        .topbar h1 ion-icon {
            font-size: 2rem;
            color: #9A66ff;
        }

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

        .profile-info {
            line-height: 1.1;
        }

        .profile-info strong {
            font-size: 1.08rem;
            font-weight: 600;
            color: #22223b;
            display: block;
        }

        .profile-info small {
            color: #9A66ff;
            font-size: 0.93rem;
            font-weight: 500;
        }

        .dashboard-content {
            flex: 1;
            overflow-y: auto;
            padding: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
            border: 1px solid #f0f0f0;
            border-radius: 18px;
            padding: 1.8rem;
            box-shadow: 0 4px 15px rgba(140, 140, 200, 0.08);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.8rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(140, 140, 200, 0.15);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ede9fe 0%, #f3f4f6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #9A66ff;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 800;
            color: #22223b;
            line-height: 1;
        }

        .stat-subtext {
            font-size: 0.8rem;
            color: #9A66ff;
            margin-top: 0.3rem;
        }

        .charts-row {
            display: grid;
            grid-template-columns: 1.8fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .card {
            background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
            border: 1px solid #f0f0f0;
            border-radius: 18px;
            box-shadow: 0 4px 15px rgba(140, 140, 200, 0.08);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
            color: white;
            padding: 1.5rem;
            border: none;
            font-weight: 700;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .card-body {
            padding: 1.8rem;
        }

        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 1rem;
        }

        .activity-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .activity-table thead {
            background: #f9fafb;
            border-bottom: 2px solid #e5e7eb;
        }

        .activity-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 700;
            color: #22223b;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .activity-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
            transition: all 0.2s ease;
        }

        .activity-table tbody tr:hover {
            background: #f0f4ff;
        }

        .activity-table td {
            padding: 1rem;
            color: #22223b;
            font-size: 0.95rem;
        }

        .status-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-processed {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-flagged {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-needs_manual_review {
            background: #fef3c7;
            color: #92400e;
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

        .modal-backdrop-blur {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(33, 30, 70, 0.24);
            z-index: 1040;
            backdrop-filter: blur(18px);
        }

        .blurred-content {
            filter: blur(8px) brightness(0.95);
            pointer-events: none;
            user-select: none;
        }

        .modal-content {
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(70, 57, 130, 0.3);
            border: 1px solid #e0e7ff;
            background: #fff;
        }

        .modal-header {
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
            color: #fff;
            border-bottom: none;
            border-radius: 16px 16px 0 0;
            padding: 1.5rem;
        }

        .modal-title {
            font-size: 1.3rem;
            font-weight: 700;
        }

        .modal-body {
            background: #fafbfc;
            padding: 1.8rem 1.5rem;
            max-height: 70vh;
            overflow-y: auto;
            font-size: 1.02rem;
            color: #22223b;
            line-height: 1.6;
        }

        .modal-footer {
            border-top: none;
            padding: 1.2rem;
            background: #fff;
        }

        .btn-primary {
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
            border: none;
            color: white;
            font-weight: 600;
            border-radius: 8px;
        }

        .btn-primary:hover {
            background: linear-gradient(90deg, #8654e0 0%, #360090 100%);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(154, 102, 255, 0.3);
        }

        .btn-close-white {
            filter: invert(1) brightness(1.2);
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
            border: 2px solid #e0e7ff;
            border-radius: 12px;
            text-decoration: none;
            color: #22223b;
            font-weight: 600;
            font-size: 0.9rem;
            gap: 0.8rem;
            transition: all 0.3s ease;
        }

        .action-btn ion-icon {
            font-size: 2rem;
            color: #9A66ff;
        }

        .action-btn:hover {
            border-color: #9A66ff;
            background: linear-gradient(135deg, #f0f4ff 0%, #f8f9ff 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(154, 102, 255, 0.15);
            color: #9A66ff;
        }

        @media (max-width: 1200px) {
            .sidenav {
                width: 180px;
            }

            .main-content {
                margin-left: 180px;
            }

            .dashboard-content {
                padding: 1.5rem 1rem;
            }

            .charts-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 900px) {
            .sidenav {
                position: fixed;
                left: -220px;
                width: 220px;
                transition: left 0.3s ease;
            }

            .sidenav.show {
                left: 0;
            }

            .main-content {
                margin-left: 0;
            }

            .topbar {
                padding: 1rem 1.5rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .topbar h1 {
                font-size: 1.4rem;
            }

            .profile {
                width: 100%;
            }

            .dashboard-content {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 700px) {
            .topbar h1 {
                font-size: 1.2rem;
            }

            .topbar {
                padding: 1rem 0.8rem;
            }

            .dashboard-content {
                padding: 0.8rem 0.5rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .stat-card {
                padding: 1.5rem;
            }

            .stat-value {
                font-size: 1.8rem;
            }

            .charts-row {
                gap: 1rem;
            }

            .card-body {
                padding: 1rem;
            }

            .activity-table {
                font-size: 0.85rem;
            }

            .activity-table th,
            .activity-table td {
                padding: 0.8rem 0.5rem;
            }
        }

        @media (max-width: 500px) {
            .sidenav {
                width: 100vw;
                left: -100vw;
            }

            .sidenav.show {
                left: 0;
            }

            .main-content {
                margin-left: 0;
            }

            .topbar {
                padding: 0.8rem;
                gap: 0.5rem;
            }

            .topbar h1 {
                font-size: 1rem;
                gap: 0.5rem;
            }

            .topbar h1 ion-icon {
                font-size: 1.5rem;
            }

            .profile-img {
                width: 40px;
                height: 40px;
            }

            .profile-info strong {
                font-size: 0.9rem;
            }

            .dashboard-content {
                padding: 0.5rem;
            }

            .stats-grid {
                gap: 0.8rem;
            }

            .stat-card {
                padding: 1rem;
            }

            .stat-value {
                font-size: 1.5rem;
            }
        }

        @media (min-width: 1400px) {
            .sidenav {
                width: 260px;
            }

            .main-content {
                margin-left: 260px;
            }

            .sidebar {
                padding: 2rem 1rem;
            }

            .dashboard-content {
                padding: 2.5rem;
            }

            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="layout">
        <!-- Sidebar Navigation -->
        <div class="sidenav col-auto p-0">
            <?php render_sidebar('dashboard'); ?>
        </div>

        <!-- Main Content Area -->
        <div class="main-content <?php if ($showTermsModal) echo 'blurred-content'; ?>">
            <!-- Top Bar -->
            <div class="topbar">
                <div>
                    <h1>
                        <ion-icon name="home-outline"></ion-icon> Welcome back, <?= htmlspecialchars(explode(' ', $fullname)[0]) ?>!
                    </h1>
                    <p class="topbar-subtitle">Your Claims & Reimbursement Dashboard</p>
                </div>
                <div class="profile">
                    <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
                    <div class="profile-info">
                        <strong><?= htmlspecialchars($fullname) ?></strong>
                        <small><?= htmlspecialchars(ucfirst($role)) ?></small>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Statistics Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <ion-icon name="cash-outline"></ion-icon>
                        </div>
                        <div class="stat-label">Pending Claims</div>
                        <div class="stat-value"><?= $stats['pending'] ?></div>
                        <div class="stat-subtext">Awaiting verification</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="color: #10b981;">
                            <ion-icon name="checkmark-done-outline"></ion-icon>
                        </div>
                        <div class="stat-label">Approved Claims</div>
                        <div class="stat-value"><?= $stats['approved'] ?></div>
                        <div class="stat-subtext">Completed & paid</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="color: #ef4444;">
                            <ion-icon name="alert-circle-outline"></ion-icon>
                        </div>
                        <div class="stat-label">Flagged Claims</div>
                        <div class="stat-value"><?= $stats['flagged'] ?></div>
                        <div class="stat-subtext">Needs review</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="color: #0284c7;">
                            <ion-icon name="document-text-outline"></ion-icon>
                        </div>
                        <div class="stat-label">Total Processed</div>
                        <div class="stat-value">₱<?= number_format($stats['total_amount'], 0) ?></div>
                        <div class="stat-subtext">This period</div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div style="margin-bottom: 2rem;">
                    <h5 style="font-weight: 700; color: #22223b; margin-bottom: 1rem;">Quick Actions</h5>
                    <div class="quick-actions">
                        <a href="claim_verification.php" class="action-btn">
                            <ion-icon name="checkmark-circle-outline"></ion-icon>
                            Verify Claims
                        </a>
                        <a href="pending_claims.php" class="action-btn">
                            <ion-icon name="cash-outline"></ion-icon>
                            Pending Claims
                        </a>
                        <a href="flagged_claims.php" class="action-btn">
                            <ion-icon name="alert-circle-outline"></ion-icon>
                            Flagged Claims
                        </a>
                        <a href="audit_reports.php" class="action-btn">
                            <ion-icon name="document-text-outline"></ion-icon>
                            Audit Reports
                        </a>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="charts-row">
                    <!-- Bar Chart -->
                    <div class="card">
                        <div class="card-header">
                            <ion-icon name="bar-chart-outline"></ion-icon> Claims by Status
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="barChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Pie Chart -->
                    <div class="card">
                        <div class="card-header">
                            <ion-icon name="pie-chart-outline"></ion-icon> Distribution
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="pieChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity Table -->
                <div class="card">
                    <div class="card-header">
                        <ion-icon name="list-outline"></ion-icon> Recent Claims Activity
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_activity)): ?>
                            <div class="empty-state">
                                <ion-icon name="document-outline"></ion-icon>
                                <h5>No Recent Activity</h5>
                                <p>No claims have been submitted yet.</p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="activity-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Employee</th>
                                            <th>Type</th>
                                            <th>Vendor</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_activity as $activity): ?>
                                            <tr>
                                                <td><?= date('M d, Y', strtotime($activity['created_at'])) ?></td>
                                                <td>
                                                    <div><?= htmlspecialchars($activity['employee_name'] ?? 'Unknown') ?></div>
                                                    <small style="color: #9A66ff;"><?= htmlspecialchars($activity['employee_id'] ?? '') ?></small>
                                                </td>
                                                <td><?= htmlspecialchars($activity['category'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($activity['vendor'] ?? 'Not specified') ?></td>
                                                <td style="font-weight: 700; color: #10b981;">₱<?= number_format(floatval($activity['amount'] ?? 0), 2) ?></td>
                                                <td>
                                                    <span class="status-badge status-<?= str_replace(' ', '_', strtolower($activity['status'])) ?>">
                                                        <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $activity['status']))) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Terms & Conditions Modal -->
    <?php if ($showTermsModal): ?>
        <div class="modal-backdrop-blur"></div>
        <div class="modal fade show" id="termsModal" tabindex="-1" style="display: block;" aria-modal="true" role="dialog">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <form method="post" class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Terms and Conditions for Claims & Reimbursement Module</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <h6 style="font-weight: 600; margin-bottom: 1rem;">Welcome to the Claims & Reimbursement module of the ViaHale HR System.</h6>

                        <p style="margin-bottom: 1rem;">By using this module, you agree to comply with the following Terms and Conditions, which are designed to ensure legal compliance, transparency, and responsible use.</p>

                        <hr style="margin: 1.5rem 0;">

                        <h6 style="font-weight: 700; margin-top: 1.5rem; margin-bottom: 0.8rem;">1. Legal Compliance</h6>
                        <p style="margin-bottom: 1rem;">All claims and reimbursements processed through this system must comply with the laws and regulations of the Republic of the Philippines, including BIR Revenue Regulations, Labor Code of the Philippines (PD 442), and Data Privacy Act of 2012 (RA 10173).</p>

                        <h6 style="font-weight: 700; margin-top: 1.5rem; margin-bottom: 0.8rem;">2. User Responsibilities</h6>
                        <p style="margin-bottom: 1rem;">Users must provide true, accurate, and complete information in all claim submissions. Submission of fraudulent, altered, or falsified documents is strictly prohibited and may result in disciplinary action and/or legal liability.</p>

                        <h6 style="font-weight: 700; margin-top: 1.5rem; margin-bottom: 0.8rem;">3. System Usage</h6>
                        <p style="margin-bottom: 1rem;">The Claims & Reimbursement module is to be used solely for legitimate, work-related expense claims as defined by company policy and BIR regulations.</p>

                        <h6 style="font-weight: 700; margin-top: 1.5rem; margin-bottom: 0.8rem;">4. Policy and Approval</h6>
                        <p style="margin-bottom: 1rem;">All reimbursements are subject to company-defined policies and limits. The Benefits Officer will review all claims for compliance with policy and legal requirements.</p>

                        <h6 style="font-weight: 700; margin-top: 1.5rem; margin-bottom: 0.8rem;">5. Data Privacy and Security</h6>
                        <p style="margin-bottom: 1rem;">All documents and data submitted are stored securely and accessed only by authorized personnel in accordance with the Data Privacy Act of 2012.</p>

                        <h6 style="font-weight: 700; margin-top: 1.5rem; margin-bottom: 0.8rem;">6. Record-Keeping and Audit</h6>
                        <p style="margin-bottom: 1rem;">All claims and supporting documents will be retained for the period required by law and company policy for audit and compliance purposes.</p>

                        <hr style="margin: 1.5rem 0;">

                        <p style="font-size: 0.9rem; color: #6c757d;">By clicking "I Accept" below, you acknowledge that you have read, understood, and agree to comply with these Terms and Conditions.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="accept_terms" class="btn btn-primary w-100">
                            I Accept & Continue
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            document.body.classList.add('modal-open');
            document.body.style.overflow = 'hidden';
        </script>
    <?php endif; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Initialize Charts (only if modal is not showing)
        <?php if (!$showTermsModal): ?>
            const barCtx = document.getElementById('barChart');
            if (barCtx) {
                new Chart(barCtx, {
                    type: 'bar',
                    data: {
                        labels: ['Pending', 'Approved', 'Flagged'],
                        datasets: [
                            {
                                label: 'Number of Claims',
                                data: [<?= $stats['pending'] ?>, <?= $stats['approved'] ?>, <?= $stats['flagged'] ?>],
                                backgroundColor: [
                                    'rgba(255, 193, 7, 0.7)',
                                    'rgba(16, 185, 129, 0.7)',
                                    'rgba(239, 68, 68, 0.7)'
                                ],
                                borderColor: [
                                    'rgb(255, 193, 7)',
                                    'rgb(16, 185, 129)',
                                    'rgb(239, 68, 68)'
                                ],
                                borderWidth: 2,
                                borderRadius: 8,
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                labels: {
                                    font: { family: "'QuickSand', 'Poppins', Arial, sans-serif" },
                                    color: '#22223b',
                                    padding: 15
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    color: '#6c757d',
                                    font: { family: "'QuickSand', 'Poppins', Arial, sans-serif" }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                }
                            },
                            x: {
                                ticks: {
                                    color: '#6c757d',
                                    font: { family: "'QuickSand', 'Poppins', Arial, sans-serif" }
                                },
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }

            const pieCtx = document.getElementById('pieChart');
            if (pieCtx) {
                new Chart(pieCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Pending', 'Approved', 'Flagged'],
                        datasets: [
                            {
                                data: [<?= $stats['pending'] ?>, <?= $stats['approved'] ?>, <?= $stats['flagged'] ?>],
                                backgroundColor: [
                                    'rgba(255, 193, 7, 0.8)',
                                    'rgba(16, 185, 129, 0.8)',
                                    'rgba(239, 68, 68, 0.8)'
                                ],
                                borderColor: [
                                    'rgba(255, 193, 7, 1)',
                                    'rgba(16, 185, 129, 1)',
                                    'rgba(239, 68, 68, 1)'
                                ],
                                borderWidth: 2,
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    font: { family: "'QuickSand', 'Poppins', Arial, sans-serif" },
                                    color: '#22223b',
                                    padding: 15
                                }
                            }
                        }
                    }
                });
            }
        <?php endif; ?>

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