<?php
/**
 * pending_claims.php
 * Displays pending claims awaiting approval
 * Shows verification status, amount, and action buttons
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
$filter_status = $_GET['status'] ?? 'pending';
$filter_category = $_GET['category'] ?? '';
$filter_from_date = $_GET['from_date'] ?? '';
$filter_to_date = $_GET['to_date'] ?? '';
$search_query = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'c.created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Build query with proper employee joining
$where_clauses = ["c.status = 'pending'"];

if (!empty($filter_category)) {
    $filter_category = mysqli_real_escape_string($conn, $filter_category);
    $where_clauses[] = "c.category = '$filter_category'";
}

if (!empty($filter_from_date)) {
    $filter_from_date = mysqli_real_escape_string($conn, $filter_from_date);
    $where_clauses[] = "DATE(c.created_at) >= '$filter_from_date'";
}

if (!empty($filter_to_date)) {
    $filter_to_date = mysqli_real_escape_string($conn, $filter_to_date);
    $where_clauses[] = "DATE(c.created_at) <= '$filter_to_date'";
}

if (!empty($search_query)) {
    $search = mysqli_real_escape_string($conn, $search_query);
    $where_clauses[] = "(c.vendor LIKE '%$search%' OR e.fullname LIKE '%$search%' OR e.employee_id LIKE '%$search%' OR c.id LIKE '%$search%')";
}

$where = implode(' AND ', $where_clauses);

// Validate sort
$allowed_sorts = ['c.created_at', 'c.amount', 'c.category', 'c.vendor', 'e.fullname'];
if (!in_array($sort_by, $allowed_sorts)) {
    $sort_by = 'c.created_at';
}

$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM claims c 
              LEFT JOIN employees e ON c.created_by = e.username
              WHERE $where";
$count_result = mysqli_query($conn, $count_sql);
$total_claims = mysqli_fetch_assoc($count_result)['total'] ?? 0;

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_claims / $per_page);

// Fetch claims with proper employee data
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
            COALESCE(e.fullname, 'Unknown') as employee_name,
            COALESCE(e.employee_id, 'N/A') as employee_id,
            e.id as employee_db_id
        FROM claims c
        LEFT JOIN employees e ON c.created_by = e.username
        WHERE $where
        ORDER BY $sort_by $sort_order
        LIMIT $offset, $per_page";

$result = mysqli_query($conn, $sql);
if (!$result) {
    die('Query Error: ' . mysqli_error($conn));
}

$pending_claims = [];
while ($row = mysqli_fetch_assoc($result)) {
    $pending_claims[] = $row;
}

// Get available categories
$cat_sql = "SELECT DISTINCT category FROM claims WHERE category IS NOT NULL AND category != '' AND status = 'pending' ORDER BY category ASC";
$cat_result = mysqli_query($conn, $cat_sql);
$categories = [];
while ($row = mysqli_fetch_assoc($cat_result)) {
    if (!empty($row['category'])) {
        $categories[] = $row['category'];
    }
}

// --- SIDEBAR RENDERER FUNCTION ---
function render_sidebar(string $active = 'pending_claims') {
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
    <title>Pending Claims - ViaHale TNVS HR3</title>
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

        .filter-section {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .filter-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        .claims-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5rem;
        }

        .claims-table thead {
            background: #f9fafb;
            border-bottom: 2px solid #e5e7eb;
        }

        .claims-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 700;
            color: #22223b;
            font-size: 0.9rem;
            text-transform: uppercase;
        }

        .claims-table th a {
            color: #9A66ff;
            text-decoration: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .claims-table th a:hover {
            color: #4311a5;
        }

        .claims-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
            transition: all 0.2s ease;
        }

        .claims-table tbody tr:hover {
            background: #f0f4ff;
        }

        .claims-table td {
            padding: 1rem;
            color: #22223b;
            font-size: 0.95rem;
        }

        .claim-id {
            font-weight: 700;
            color: #9A66ff;
        }

        .employee-info {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }

        .employee-name {
            font-weight: 600;
            color: #22223b;
        }

        .employee-id {
            font-size: 0.85rem;
            color: #6c757d;
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

        .amount {
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

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .action-icons {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #9A66ff;
            font-size: 1.2rem;
            padding: 0.4rem;
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .action-btn:hover {
            background: #f0f4ff;
            color: #4311a5;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .pagination a,
        .pagination span {
            padding: 0.5rem 0.8rem;
            border-radius: 6px;
            border: 1px solid #d1d5db;
            text-decoration: none;
            color: #22223b;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .pagination a:hover {
            background: #9A66ff;
            color: white;
            border-color: #9A66ff;
        }

        .pagination .current {
            background: #9A66ff;
            color: white;
            border-color: #9A66ff;
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
            .filter-group {
                grid-template-columns: 1fr;
            }
            .claims-table {
                font-size: 0.85rem;
            }
            .claims-table th,
            .claims-table td {
                padding: 0.8rem 0.5rem;
            }
        }

        @media (max-width: 700px) { 
            .topbar h1 { font-size: 1.2rem; }
            .topbar { padding: 1rem 0.8rem; }
            .page-content { padding: 0.8rem 0.5rem; } 
            .claims-table {
                font-size: 0.8rem;
            }
            .action-icons {
                flex-direction: column;
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
            .stats-section {
                grid-template-columns: 1fr;
            }
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
        <?php render_sidebar('pending_claims'); ?>
    </div>

    <!-- Main Content Area -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="topbar">
            <div>
                <h1>
                    <ion-icon name="cash-outline"></ion-icon> Pending Claims
                </h1>
                <p class="topbar-subtitle">Claims awaiting verification and approval</p>
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
            <!-- Stats Section -->
            <div class="stats-section">
                <div class="stat-card">
                    <div class="stat-label">Total Pending</div>
                    <div class="stat-value"><?= $total_claims ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">This Page</div>
                    <div class="stat-value"><?= count($pending_claims) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Value</div>
                    <div class="stat-value">₱<?= number_format(array_sum(array_column($pending_claims, 'amount')) ?: 0, 2) ?></div>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-header">
                    <ion-icon name="funnel-outline"></ion-icon> Filter Claims
                </div>
                <div class="card-body">
                    <form method="GET" class="filter-section">
                        <div class="filter-group">
                            <div>
                                <label for="search">Search (ID, Vendor, Employee)</label>
                                <input type="text" id="search" name="search" placeholder="Search..." value="<?= htmlspecialchars($search_query) ?>">
                            </div>
                            <div>
                                <label for="category">Category</label>
                                <select id="category" name="category">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>" <?= $filter_category === $cat ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="from_date">From Date</label>
                                <input type="date" id="from_date" name="from_date" value="<?= htmlspecialchars($filter_from_date) ?>">
                            </div>
                            <div>
                                <label for="to_date">To Date</label>
                                <input type="date" id="to_date" name="to_date" value="<?= htmlspecialchars($filter_to_date) ?>">
                            </div>
                        </div>
                        <div class="filter-buttons">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <ion-icon name="search-outline"></ion-icon> Apply Filters
                            </button>
                            <a href="pending_claims.php" class="btn btn-secondary btn-sm">
                                <ion-icon name="refresh-outline"></ion-icon> Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Claims Table Card -->
            <div class="card">
                <div class="card-header">
                    <ion-icon name="list-outline"></ion-icon> Pending Claims List
                </div>
                <div class="card-body">
                    <?php if (empty($pending_claims)): ?>
                        <div class="empty-state">
                            <ion-icon name="document-outline"></ion-icon>
                            <h5>No Pending Claims</h5>
                            <p>No claims found matching your filters.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="claims-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Employee</th>
                                        <th>
                                            <a href="?<?= build_sort_url('c.category') ?>">
                                                Category
                                                <?= $sort_by === 'c.category' ? ($sort_order === 'ASC' ? '↑' : '↓') : '' ?>
                                            </a>
                                        </th>
                                        <th>Vendor</th>
                                        <th>
                                            <a href="?<?= build_sort_url('c.amount') ?>">
                                                Amount
                                                <?= $sort_by === 'c.amount' ? ($sort_order === 'ASC' ? '↑' : '↓') : '' ?>
                                            </a>
                                        </th>
                                        <th>Expense Date</th>
                                        <th>Submitted</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_claims as $claim): ?>
                                        <tr>
                                            <td><span class="claim-id">#<?= $claim['id'] ?></span></td>
                                            <td>
                                                <div class="employee-info">
                                                    <span class="employee-name">
                                                        <?= htmlspecialchars($claim['employee_name'] ?? 'Unknown') ?>
                                                    </span>
                                                    <span class="employee-id">
                                                        <?= htmlspecialchars($claim['employee_id'] ?? 'N/A') ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td><span class="category-badge"><?= htmlspecialchars($claim['category'] ?? 'N/A') ?></span></td>
                                            <td><?= htmlspecialchars($claim['vendor'] ?? 'Not specified') ?></td>
                                            <td><span class="amount">₱<?= number_format(floatval($claim['amount'] ?? 0), 2) ?></span></td>
                                            <td><?= htmlspecialchars($claim['expense_date'] ?? 'N/A') ?></td>
                                            <td>
                                                <small><?= date('M d, Y', strtotime($claim['created_at'] ?? 'now')) ?></small>
                                            </td>
                                            <td>
                                                <div class="action-icons">
                                                    <a href="claim_verification.php?claim_id=<?= $claim['id'] ?>" class="action-btn" title="Verify">
                                                        <ion-icon name="search-outline"></ion-icon>
                                                    </a>
                                                    <a href="javascript:void(0)" onclick="approveClaim(<?= $claim['id'] ?>)" class="action-btn" title="Approve" style="color: #10b981;">
                                                        <ion-icon name="checkmark-outline"></ion-icon>
                                                    </a>
                                                    <a href="javascript:void(0)" onclick="flagClaim(<?= $claim['id'] ?>)" class="action-btn" title="Flag" style="color: #ef4444;">
                                                        <ion-icon name="flag-outline"></ion-icon>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?<?= build_page_url(1) ?>">First</a>
                                    <a href="?<?= build_page_url($page - 1) ?>">Previous</a>
                                <?php endif; ?>

                                <?php
                                $start = max(1, $page - 2);
                                $end = min($total_pages, $page + 2);

                                for ($p = $start; $p <= $end; $p++):
                                ?>
                                    <?php if ($p === $page): ?>
                                        <span class="current"><?= $p ?></span>
                                    <?php else: ?>
                                        <a href="?<?= build_page_url($p) ?>"><?= $p ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="?<?= build_page_url($page + 1) ?>">Next</a>
                                    <a href="?<?= build_page_url($total_pages) ?>">Last</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
function approveClaim(claimId) {
    if (confirm('Are you sure you want to approve Claim #' + claimId + '?')) {
        const formData = new FormData();
        formData.append('action', 'approve');
        formData.append('claim_id', claimId);

        fetch('process_claim.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('✅ Claim approved successfully!');
                location.reload();
            } else {
                alert('❌ Error: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            alert('❌ Error: ' + error.message);
        });
    }
}

function flagClaim(claimId) {
    const reason = prompt('Enter reason for flagging Claim #' + claimId + ':');
    if (reason === null) return;

    const formData = new FormData();
    formData.append('action', 'flag');
    formData.append('claim_id', claimId);
    formData.append('reason', reason);

    fetch('process_claim.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Claim flagged successfully!');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('❌ Error: ' + error.message);
    });
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

<?php
// Helper functions
function build_sort_url($column) {
    $params = [];
    if (!empty($_GET['search'])) {
        $params[] = 'search=' . urlencode($_GET['search']);
    }
    if (!empty($_GET['category'])) {
        $params[] = 'category=' . urlencode($_GET['category']);
    }
    if (!empty($_GET['from_date'])) {
        $params[] = 'from_date=' . urlencode($_GET['from_date']);
    }
    if (!empty($_GET['to_date'])) {
        $params[] = 'to_date=' . urlencode($_GET['to_date']);
    }
    
    global $sort_by, $sort_order;
    $new_order = ($sort_by === $column && $sort_order === 'DESC') ? 'ASC' : 'DESC';
    $params[] = 'sort=' . $column;
    $params[] = 'order=' . $new_order;
    $params[] = 'page=1';
    
    return implode('&', $params);
}

function build_page_url($page) {
    $params = [];
    if (!empty($_GET['search'])) {
        $params[] = 'search=' . urlencode($_GET['search']);
    }
    if (!empty($_GET['category'])) {
        $params[] = 'category=' . urlencode($_GET['category']);
    }
    if (!empty($_GET['from_date'])) {
        $params[] = 'from_date=' . urlencode($_GET['from_date']);
    }
    if (!empty($_GET['to_date'])) {
        $params[] = 'to_date=' . urlencode($_GET['to_date']);
    }
    
    global $sort_by, $sort_order;
    $params[] = 'sort=' . $sort_by;
    $params[] = 'order=' . $sort_order;
    $params[] = 'page=' . $page;
    
    return implode('&', $params);
}
?>