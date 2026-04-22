<?php
// claim_submission.php
// Employee Claim Submission with AI Detection/Verification
// Purpose: Employees submit claims and receipts; AI verifies that filled details match the receipt OCR extraction
// Features:
//   - Employee selects from enrolled employees dropdown
//   - Fills claim details (vendor, amount, category, date, description)
//   - Uploads receipt/document
//   - AI extracts receipt data (OCR + NLP)
//   - AI Verification Engine compares employee-filled data vs extracted receipt data
//   - Shows confidence scores and discrepancies
//   - Final submission to database with AI verification results
//
// Place this file in /benefits/ directory alongside ../connection.php

require_once("../connection.php");
session_start();

// --- AUTH / SESSION CHECK ---
if (
    !isset($_SESSION['username']) ||
    !isset($_SESSION['role']) ||
    empty($_SESSION['username']) ||
    empty($_SESSION['role']) ||
    $_SESSION['role'] !== 'Benefits Officer'
) {
    session_unset();
    session_destroy();
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
    header("Location: ../login.php");
    exit();
}

// Session timeout (1 hour)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3600)) {
    session_unset();
    session_destroy();
    header("Location: ../login.php");
    exit();
}
$_SESSION['last_activity'] = time();

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Benefits Officer';
$role = $_SESSION['role'] ?? 'Benefits Officer';
$created_by = $_SESSION['username'] ?? '';

// === Fetch Enrolled Employees ===
$enrolled_employees = [];
if (isset($conn) && $conn) {
    $sql = "SELECT id, employee_id, fullname, department, job_title FROM employees WHERE face_enrolled = 1 ORDER BY fullname ASC";
    if ($res = mysqli_query($conn, $sql)) {
        while ($row = mysqli_fetch_assoc($res)) {
            $enrolled_employees[] = $row;
        }
        mysqli_free_result($res);
    }
}

// === Fetch Reimbursement Policies ===
$policies = [];
if (isset($conn) && $conn) {
    $sql = "SELECT category, limit_amount FROM reimbursement_policies ORDER BY category ASC";
    if ($res = mysqli_query($conn, $sql)) {
        while ($row = mysqli_fetch_assoc($res)) {
            $policies[] = $row;
        }
        mysqli_free_result($res);
    }
}
if (empty($policies)) {
    // Realistic PH defaults
    $policies = [
        ['category' => 'Meal', 'limit_amount' => 500.00],
        ['category' => 'Travel', 'limit_amount' => 2000.00],
        ['category' => 'Medical', 'limit_amount' => 15000.00],
        ['category' => 'Supplies', 'limit_amount' => 2500.00],
        ['category' => 'Others', 'limit_amount' => 5000.00],
    ];
}

// === Sidebar Renderer ===
function render_sidebar($active = 'claim_submission') {
    $links = [
        'claim_submission' => ['url' => 'claim_submission.php', 'icon' => 'create-outline', 'label' => 'Claim Submission'],
        'pending_claims' => ['url' => 'pending_claims.php', 'icon' => 'cash-outline', 'label' => 'Pending Claims'],
        'processed_claims' => ['url' => 'processed_claims.php', 'icon' => 'checkmark-done-outline', 'label' => 'Processed Claims'],
        'flagged_claims' => ['url' => 'flagged_claims.php', 'icon' => 'alert-circle-outline', 'label' => 'Flagged Claims'],
        'reimbursement_policies' => ['url' => 'reimbursement_policies.php', 'icon' => 'settings-outline', 'label' => 'Reimbursement Policies'],
        'audit_reports' => ['url' => 'audit_reports.php', 'icon' => 'document-text-outline', 'label' => 'Audit & Reports'],
    ];

    echo '<div class="sidenav col-auto p-0">
      <div class="sidebar d-flex flex-column justify-content-between shadow-sm border-end">
        <div>
          <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
            <img src="../assets/images/image.png" class="img-fluid" style="height:55px;" alt="Logo">
          </div>';

    echo '<div class="mb-3"><a class="nav-link" href="benefits_officer_dashboard.php"><ion-icon name="home-outline"></ion-icon> Dashboard</a></div>';

    echo '<div class="mb-3">
            <h6 class="text-uppercase px-2 mb-2">Claims & Reimbursement</h6>
            <nav class="nav flex-column">';
    foreach ($links as $key => $meta) {
        $activeClass = ($key === $active) ? 'active' : '';
        echo '<a class="nav-link ' . $activeClass . '" href="' . htmlspecialchars($meta['url']) . '"><ion-icon name="' . htmlspecialchars($meta['icon']) . '"></ion-icon>' . htmlspecialchars($meta['label']) . '</a>';
    }
    echo '    </nav>
          </div>
        </div>

        <div class="p-3 border-top mb-2">
          <a class="nav-link text-danger" href="../logout.php"><ion-icon name="log-out-outline"></ion-icon> Logout</a>
        </div>
      </div>
    </div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee Claim Submission - ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <script src="https://unpkg.com/tesseract.js@4.1.1/dist/tesseract.min.js"></script>

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

        .card { 
            border-radius: 18px; 
            box-shadow: 0 4px 15px rgba(140,140,200,0.08); 
            border: 1px solid #f0f0f0;
            background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
            margin-bottom: 2rem;
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

        .form-label { 
            font-weight: 600;
            color: #22223b;
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #e0e7ff;
            padding: 0.7rem 1rem;
            background: #fff;
            color: #22223b;
            font-size: 0.95rem;
        }

        .form-control:focus, .form-select:focus {
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

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
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

        .verification-panel {
            background: linear-gradient(135deg, #f0f9ff 0%, #f8f5ff 100%);
            border: 1px solid #e0e7ff;
            border-left: 5px solid #9A66ff;
            border-radius: 12px;
            padding: 1.5rem;
            margin: 1.5rem 0;
            display: none;
        }

        .verification-panel.show {
            display: block;
        }

        .verification-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
            align-items: center;
        }

        .verification-field {
            padding: 1rem;
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e7ff;
        }

        .verification-field label {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 0.3rem;
            display: block;
        }

        .verification-field .value {
            font-weight: 600;
            color: #22223b;
            font-size: 0.95rem;
        }

        .match-score {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            margin-top: 0.3rem;
        }

        .match-score.high {
            background: #d1fae5;
            color: #065f46;
        }

        .match-score.medium {
            background: #fef3c7;
            color: #92400e;
        }

        .match-score.low {
            background: #fee2e2;
            color: #991b1b;
        }

        .discrepancy-alert {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }

        .discrepancy-alert ul {
            margin: 0.5rem 0 0 1.5rem;
            padding: 0;
        }

        .discrepancy-alert li {
            margin: 0.3rem 0;
            color: #991b1b;
            font-size: 0.95rem;
        }

        .confidence-badge {
            display: inline-block;
            padding: 0.3rem 0.6rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.8rem;
        }

        .confidence-high {
            background: #d1fae5;
            color: #065f46;
        }

        .confidence-medium {
            background: #fef3c7;
            color: #92400e;
        }

        .confidence-low {
            background: #fee2e2;
            color: #991b1b;
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
        }

        .btn-close { filter: brightness(1.8); }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.4);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            backdrop-filter: blur(4px);
        }

        .loading-overlay.show {
            display: flex;
        }

        .loading-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }

        @media (max-width: 1200px) { 
            .sidebar { width: 180px; } 
            .content-wrapper { margin-left: 180px; } 
            .main-content { padding: 1.5rem 1rem; } 
            .verification-row { grid-template-columns: 1fr 1fr; }
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
            .verification-row { grid-template-columns: 1fr; }
        }

        @media (max-width: 700px) { 
            .topbar h3 { font-size: 1.4rem; }
            .main-content { padding: 1rem 0.8rem; } 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; } 
        }

        @media (max-width: 500px) { 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; } 
            .main-content { padding: 0.8rem 0.5rem; }
            .topbar h3 { font-size: 1.2rem; }
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
    <?php render_sidebar('claim_submission'); ?>

    <div class="content-wrapper">
        <!-- Top Bar -->
        <div class="topbar">
            <div>
                <h3>
                    <ion-icon name="receipt-outline"></ion-icon> Employee Claim Submission
                </h3>
                <small class="text-muted">Submit claims with AI verification against uploaded receipts</small>
            </div>
            <div class="profile">
                <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
                <div class="profile-info">
                    <strong><?= htmlspecialchars($fullname) ?></strong>
                    <br>
                    <small><?= htmlspecialchars(ucfirst($role)) ?></small>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Employee Selection -->
            <div class="card">
                <div class="card-header">
                    <ion-icon name="person-outline"></ion-icon> Select Employee
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="employeeSelect" class="form-label">Choose Enrolled Employee</label>
                        <select id="employeeSelect" class="form-select" required>
                            <option value="">-- Select Employee --</option>
                            <?php foreach ($enrolled_employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>" data-empid="<?= htmlspecialchars($emp['employee_id']) ?>">
                                    <?= htmlspecialchars($emp['fullname']) ?> (<?= htmlspecialchars($emp['employee_id']) ?>) - <?= htmlspecialchars($emp['department']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Claim Form -->
            <div class="card" id="claimFormCard" style="display: none;">
                <div class="card-header">
                    <ion-icon name="document-outline"></ion-icon> Claim Details
                </div>
                <div class="card-body">
                    <form id="claimForm" class="row g-3">
                        <div class="col-md-6">
                            <label for="vendor" class="form-label">Vendor / Merchant</label>
                            <input type="text" id="vendor" name="vendor" class="form-control" placeholder="e.g., McDonald's, Clinic ABC" required>
                            <small class="text-muted">Name of vendor where expense was made</small>
                        </div>

                        <div class="col-md-6">
                            <label for="amount" class="form-label">Amount (₱)</label>
                            <input type="number" id="amount" name="amount" class="form-control" step="0.01" min="0" placeholder="0.00" required>
                        </div>

                        <div class="col-md-6">
                            <label for="category" class="form-label">Category</label>
                            <select id="category" name="category" class="form-select" required>
                                <option value="">-- Select Category --</option>
                                <?php foreach ($policies as $p): ?>
                                    <option value="<?= htmlspecialchars($p['category']) ?>" data-limit="<?= htmlspecialchars($p['limit_amount']) ?>">
                                        <?= htmlspecialchars($p['category']) ?> (Limit: ₱<?= number_format($p['limit_amount'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="expenseDate" class="form-label">Date of Expense</label>
                            <input type="date" id="expenseDate" name="expenseDate" class="form-control" required>
                        </div>

                        <div class="col-12">
                            <label for="description" class="form-label">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="3" placeholder="Brief description of the expense..."></textarea>
                        </div>

                        <div class="col-12">
                            <label for="receipt" class="form-label">Upload Receipt / Document</label>
                            <input type="file" id="receipt" name="receipt" class="form-control" accept="image/*,.pdf" required>
                            <small class="text-muted">Clear, well-lit photos of receipts produce best AI verification results</small>
                        </div>

                        <div class="col-12">
                            <button type="button" id="verifyBtn" class="btn btn-primary">
                                <ion-icon name="checkmark-done-outline"></ion-icon> Verify with Receipt
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- AI Verification Panel -->
            <div class="verification-panel" id="verificationPanel">
                <h5 class="mb-3">
                    <ion-icon name="flash-outline"></ion-icon> AI Verification Results
                </h5>

                <!-- Overall Verification Score -->
                <div style="background: white; padding: 1.2rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #e0e7ff;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Overall Match Score</h6>
                            <small class="text-muted">Confidence that submitted details match receipt</small>
                        </div>
                        <div>
                            <div id="overallScore" style="font-size: 2rem; font-weight: 800; color: #9A66ff;">--</div>
                            <span id="overallScoreBadge" class="confidence-badge confidence-medium">--</span>
                        </div>
                    </div>
                </div>

                <!-- Field Comparison -->
                <h6 class="mb-3">Field Verification</h6>

                <!-- Vendor Comparison -->
                <div class="verification-row">
                    <div class="verification-field">
                        <label>Submitted Vendor</label>
                        <div class="value" id="submittedVendor">--</div>
                    </div>
                    <div class="verification-field">
                        <label>Extracted Vendor</label>
                        <div class="value" id="extractedVendor">--</div>
                    </div>
                    <div class="verification-field">
                        <label>Vendor Match</label>
                        <div id="vendorScore" class="match-score">--</div>
                    </div>
                </div>

                <!-- Amount Comparison -->
                <div class="verification-row">
                    <div class="verification-field">
                        <label>Submitted Amount</label>
                        <div class="value" id="submittedAmount">--</div>
                    </div>
                    <div class="verification-field">
                        <label>Extracted Amount</label>
                        <div class="value" id="extractedAmount">--</div>
                    </div>
                    <div class="verification-field">
                        <label>Amount Match</label>
                        <div id="amountScore" class="match-score">--</div>
                    </div>
                </div>

                <!-- Date Comparison -->
                <div class="verification-row">
                    <div class="verification-field">
                        <label>Submitted Date</label>
                        <div class="value" id="submittedDate">--</div>
                    </div>
                    <div class="verification-field">
                        <label>Extracted Date</label>
                        <div class="value" id="extractedDate">--</div>
                    </div>
                    <div class="verification-field">
                        <label>Date Match</label>
                        <div id="dateScore" class="match-score">--</div>
                    </div>
                </div>

                <!-- Category Comparison -->
                <div class="verification-row">
                    <div class="verification-field">
                        <label>Submitted Category</label>
                        <div class="value" id="submittedCategory">--</div>
                    </div>
                    <div class="verification-field">
                        <label>Suggested Category</label>
                        <div class="value" id="suggestedCategory">--</div>
                    </div>
                    <div class="verification-field">
                        <label>Category Match</label>
                        <div id="categoryScore" class="match-score">--</div>
                    </div>
                </div>

                <!-- Discrepancies -->
                <div id="discrepancyAlert" class="discrepancy-alert" style="display: none;">
                    <strong>⚠️ Discrepancies Detected</strong>
                    <ul id="discrepancyList"></ul>
                </div>

                <!-- OCR Confidence -->
                <div style="background: #f0f9ff; padding: 1rem; border-radius: 8px; margin-top: 1.5rem; border: 1px solid #bfdbfe;">
                    <h6 class="mb-2">OCR Confidence</h6>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <small class="text-muted">OCR Text Confidence</small>
                            <div class="progress" style="height: 6px;">
                                <div id="ocrConfidenceBar" class="progress-bar" style="width: 0%;" role="progressbar"></div>
                            </div>
                            <small id="ocrConfidenceText">--</small>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">NLP Confidence</small>
                            <div class="progress" style="height: 6px;">
                                <div id="nlpConfidenceBar" class="progress-bar" style="width: 0%;" role="progressbar"></div>
                            </div>
                            <small id="nlpConfidenceText">--</small>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div style="margin-top: 1.5rem; display: flex; gap: 1rem;">
                    <button type="button" id="submitClaimBtn" class="btn btn-success">
                        <ion-icon name="checkmark-circle-outline"></ion-icon> Submit Claim
                    </button>
                    <button type="button" id="editClaimBtn" class="btn btn-outline-secondary">
                        <ion-icon name="pencil-outline"></ion-icon> Edit Details
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-card">
        <div class="d-flex align-items-center justify-content-center gap-3">
            <div class="spinner-border text-primary" role="status"></div>
            <div style="text-align: left;">
                <h6 id="loadingTitle" style="margin: 0; color: #22223b; font-weight: 700;">Processing Receipt...</h6>
                <small id="loadingMsg" class="text-muted">Extracting and verifying data</small>
            </div>
        </div>
    </div>
</div>

<!-- Success Modal -->
<div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Claim Submitted Successfully</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div style="text-align: center; padding: 1rem;">
                    <ion-icon name="checkmark-circle" style="font-size: 3rem; color: #10b981; display: block; margin-bottom: 1rem;"></ion-icon>
                    <h6>Your claim has been submitted</h6>
                    <p class="text-muted">The claim will be reviewed by the Benefits Officer and processed accordingly.</p>
                    <p id="claimIdDisplay" style="font-weight: 600; color: #22223b;"></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const OCR_CONF_THRESHOLD = 0.65;
const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

// DOM Elements
const employeeSelect = document.getElementById('employeeSelect');
const claimFormCard = document.getElementById('claimFormCard');
const claimForm = document.getElementById('claimForm');
const verifyBtn = document.getElementById('verifyBtn');
const verificationPanel = document.getElementById('verificationPanel');
const loadingOverlay = document.getElementById('loadingOverlay');
const successModal = new bootstrap.Modal(document.getElementById('successModal'));

// Form fields
const vendorField = document.getElementById('vendor');
const amountField = document.getElementById('amount');
const categoryField = document.getElementById('category');
const expenseDateField = document.getElementById('expenseDate');
const receiptField = document.getElementById('receipt');

// Verification display fields
const overallScore = document.getElementById('overallScore');
const overallScoreBadge = document.getElementById('overallScoreBadge');
const submittedVendor = document.getElementById('submittedVendor');
const extractedVendor = document.getElementById('extractedVendor');
const vendorScore = document.getElementById('vendorScore');
const submittedAmount = document.getElementById('submittedAmount');
const extractedAmount = document.getElementById('extractedAmount');
const amountScore = document.getElementById('amountScore');
const submittedDate = document.getElementById('submittedDate');
const extractedDate = document.getElementById('extractedDate');
const dateScore = document.getElementById('dateScore');
const submittedCategory = document.getElementById('submittedCategory');
const suggestedCategory = document.getElementById('suggestedCategory');
const categoryScore = document.getElementById('categoryScore');

// Event: Show form when employee selected
employeeSelect.addEventListener('change', () => {
    if (employeeSelect.value) {
        claimFormCard.style.display = 'block';
    } else {
        claimFormCard.style.display = 'none';
        verificationPanel.classList.remove('show');
    }
});

// Verification Logic
function calculateFieldMatchScore(submitted, extracted) {
    if (!submitted || !extracted) return 0;
    
    const clean = (s) => s.trim().toLowerCase();
    const subClean = clean(submitted);
    const extClean = clean(extracted);
    
    if (subClean === extClean) return 1.0;
    
    // Levenshtein distance for partial matching
    const lev = (a, b) => {
        const matrix = Array(b.length + 1).fill(null).map(() => Array(a.length + 1).fill(0));
        for (let i = 0; i <= a.length; i++) matrix[0][i] = i;
        for (let j = 0; j <= b.length; j++) matrix[j][0] = j;
        for (let i = 1; i <= b.length; i++) {
            for (let j = 1; j <= a.length; j++) {
                const cost = a[j-1] === b[i-1] ? 0 : 1;
                matrix[i][j] = Math.min(
                    matrix[i-1][j] + 1,
                    matrix[i][j-1] + 1,
                    matrix[i-1][j-1] + cost
                );
            }
        }
        return matrix[b.length][a.length];
    };
    
    const distance = lev(subClean, extClean);
    const maxLen = Math.max(subClean.length, extClean.length);
    return Math.max(0, 1 - (distance / maxLen));
}

function getScoreBadgeClass(score) {
    if (score >= 0.8) return 'match-score high';
    if (score >= 0.6) return 'match-score medium';
    return 'match-score low';
}

function getScoreText(score) {
    const percent = Math.round(score * 100);
    if (score >= 0.8) return `${percent}% Match ✓`;
    if (score >= 0.6) return `${percent}% Partial`;
    return `${percent}% Mismatch`;
}

function displayScore(element, score, scoreThreshold = 0.7) {
    element.textContent = getScoreText(score);
    element.className = getScoreBadgeClass(score);
}

async function verifyClaimWithReceipt() {
    if (!vendorField.value || !amountField.value || !categoryField.value || !expenseDateField.value || !receiptField.files.length) {
        alert('Please fill all fields and upload a receipt');
        return;
    }

    const file = receiptField.files[0];
    
    if (file.size > MAX_FILE_SIZE) {
        alert('File too large. Maximum 5MB');
        return;
    }

    showLoading(true, 'Processing Receipt...', 'Extracting data from receipt');

    try {
        // Read file as data URL
        const fileData = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(reader.error);
            reader.readAsDataURL(file);
        });

        // Extract receipt data (OCR + NLP)
        const extractedData = await extractReceiptData(fileData);

        showLoading(false);

        // Display extracted data
        const extractedVendorValue = extractedData.vendor || 'Not detected';
        const extractedAmountValue = extractedData.amount ? `₱${parseFloat(extractedData.amount).toFixed(2)}` : 'Not detected';
        const extractedDateValue = extractedData.date || 'Not detected';
        const suggestedCategoryValue = extractedData.category || 'Not detected';

        // Calculate match scores
        const vendorScoreValue = calculateFieldMatchScore(vendorField.value, extractedVendorValue);
        const amountScoreValue = Math.abs(parseFloat(amountField.value) - parseFloat(extractedData.amount || 0)) < 1 ? 1.0 : 
                                Math.max(0, 1 - Math.abs(parseFloat(amountField.value) - parseFloat(extractedData.amount || 0)) / parseFloat(amountField.value || 1));
        const dateScoreValue = expenseDateField.value === (extractedData.date || '') ? 1.0 : 0.5;
        const categoryScoreValue = categoryField.value === (extractedData.category || '') ? 1.0 : 0.6;

        // Calculate overall score (weighted average)
        const overallScoreValue = (vendorScoreValue * 0.25) + (amountScoreValue * 0.4) + (dateScoreValue * 0.2) + (categoryScoreValue * 0.15);

        // Update display
        submittedVendor.textContent = vendorField.value;
        extractedVendor.textContent = extractedVendorValue;
        displayScore(vendorScore, vendorScoreValue);

        submittedAmount.textContent = `₱${parseFloat(amountField.value).toFixed(2)}`;
        extractedAmount.textContent = extractedAmountValue;
        displayScore(amountScore, amountScoreValue);

        submittedDate.textContent = expenseDateField.value;
        extractedDate.textContent = extractedDateValue;
        displayScore(dateScore, dateScoreValue);

        submittedCategory.textContent = categoryField.value;
        suggestedCategory.textContent = suggestedCategoryValue;
        displayScore(categoryScore, categoryScoreValue);

        // Overall score
        overallScore.textContent = Math.round(overallScoreValue * 100) + '%';
        if (overallScoreValue >= 0.8) {
            overallScoreBadge.className = 'confidence-badge confidence-high';
            overallScoreBadge.textContent = 'HIGH CONFIDENCE';
        } else if (overallScoreValue >= 0.6) {
            overallScoreBadge.className = 'confidence-badge confidence-medium';
            overallScoreBadge.textContent = 'MEDIUM CONFIDENCE';
        } else {
            overallScoreBadge.className = 'confidence-badge confidence-low';
            overallScoreBadge.textContent = 'LOW CONFIDENCE';
        }

        // Confidence bars
        document.getElementById('ocrConfidenceBar').style.width = (extractedData.ocrConfidence || 65) + '%';
        document.getElementById('ocrConfidenceText').textContent = `${extractedData.ocrConfidence || 65}%`;
        document.getElementById('nlpConfidenceBar').style.width = (extractedData.nlpConfidence || 70) + '%';
        document.getElementById('nlpConfidenceText').textContent = `${extractedData.nlpConfidence || 70}%`;

        // Show/hide discrepancy alert
        const discrepancies = [];
        if (vendorScoreValue < 0.7) discrepancies.push(`Vendor mismatch: submitted "${vendorField.value}" vs extracted "${extractedVendorValue}"`);
        if (amountScoreValue < 0.85) discrepancies.push(`Amount difference: submitted ₱${parseFloat(amountField.value).toFixed(2)} vs extracted ₱${parseFloat(extractedData.amount || 0).toFixed(2)}`);
        if (dateScoreValue < 0.8) discrepancies.push(`Date mismatch: submitted "${expenseDateField.value}" vs extracted "${extractedDateValue}"`);
        if (categoryScoreValue < 0.8) discrepancies.push(`Category mismatch: submitted "${categoryField.value}" vs extracted "${suggestedCategoryValue}"`);

        if (discrepancies.length > 0) {
            document.getElementById('discrepancyAlert').style.display = 'block';
            document.getElementById('discrepancyList').innerHTML = discrepancies.map(d => `<li>${d}</li>`).join('');
        } else {
            document.getElementById('discrepancyAlert').style.display = 'none';
        }

        // Show verification panel
        verificationPanel.classList.add('show');

    } catch (error) {
        showLoading(false);
        alert('Error processing receipt: ' + error.message);
    }
}

async function extractReceiptData(fileData) {
    // ────────────────────────────────────────────────
    // Real Tesseract.js OCR + receipt text parsing
    // ────────────────────────────────────────────────

    try {
        // 1. Run Tesseract OCR on the receipt image
        const worker = await Tesseract.createWorker('eng', 1, {
            logger: m => {
                if (m.status === 'recognizing text') {
                    const pct = Math.round((m.progress || 0) * 100);
                    showLoading(true, 'Reading Receipt...', `OCR progress: ${pct}%`);
                }
            }
        });

        const { data } = await worker.recognize(fileData);
        await worker.terminate();

        const rawText = (data.text || '').trim();
        const ocrConfidence = data.confidence || 0;

        if (!rawText || rawText.length < 5) {
            return {
                vendor: null, amount: null, date: null, category: null,
                ocrConfidence: 0, nlpConfidence: 0, rawText: ''
            };
        }

        // 2. Parse structured data from OCR text
        const lines = rawText.split(/\r?\n/).map(l => l.trim()).filter(l => l.length > 0);

        // --- Extract vendor (first prominent non-keyword line) ---
        const skipKW = ['RECEIPT','INVOICE','OFFICIAL','TAX INVOICE','DATE','TIME','CASHIER',
                        'TERMINAL','STORE','BRANCH','TIN','VAT','REG','PTU','BIR','SERIAL',
                        'PERMIT','TEL','PHONE','EMAIL','ADDRESS','ACCREDITATION','MIN'];
        let vendor = null;
        for (let i = 0; i < Math.min(8, lines.length); i++) {
            const line = lines[i];
            const upper = line.toUpperCase();
            if (line.length < 3) continue;
            if (/^\d[\d\-\/,:.\s]+$/.test(line)) continue;
            let skip = false;
            for (const kw of skipKW) {
                if (upper.startsWith(kw) || upper === kw) { skip = true; break; }
            }
            if (skip) continue;
            if (/^TIN|^VAT|^REG|^\d{3}[\-\s]\d{3}/i.test(line)) continue;
            vendor = line.replace(/^[\*\-=\s]+|[\*\-=\s]+$/g, '');
            if (vendor.length >= 2) break;
            vendor = null;
        }

        // --- Extract amounts ---
        const amountPatterns = [
            /(?:TOTAL|GRAND\s*TOTAL|Amount\s*Due|Amount\s*Paid|NET|AMOUNT|Balance)\s*[:\-]?\s*(?:[₱]|PHP|P)?\s*([\d,]+\.\d{2})/gi,
            /(?:[₱]|PHP|Php|php|P)\s*([\d,]+(?:\.\d{1,2})?)/gu,
            /([\d,]+\.\d{2})\s*(?:PHP|TOTAL|Total)/gi
        ];
        let amounts = [];
        for (const pat of amountPatterns) {
            let m;
            while ((m = pat.exec(rawText)) !== null) {
                const val = parseFloat(m[1].replace(/,/g, ''));
                if (val > 0 && val < 10000000) amounts.push(val);
            }
        }
        // Also find standalone decimal amounts
        const standaloneAmounts = rawText.match(/(?<!\d)(\d{1,6}\.\d{2})(?!\d)/g);
        if (standaloneAmounts) {
            for (const sa of standaloneAmounts) {
                const v = parseFloat(sa);
                if (v > 0 && v < 10000000) amounts.push(v);
            }
        }
        amounts = [...new Set(amounts)].sort((a, b) => b - a);

        // Try to find labeled total
        let totalAmount = null;
        for (const line of lines) {
            const totalMatch = line.match(/(?:GRAND\s*TOTAL|TOTAL\s*(?:DUE|AMOUNT|SALE)?|AMOUNT\s*DUE|AMOUNT\s*PAID)\s*[:\-]?\s*(?:[₱]|PHP|P)?\s*([\d,]+\.\d{2})/i);
            if (totalMatch) {
                totalAmount = parseFloat(totalMatch[1].replace(/,/g, ''));
                break;
            }
        }
        if (!totalAmount && amounts.length > 0) totalAmount = amounts[0];

        // --- Extract date ---
        const datePatterns = [
            /(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/,
            /(\d{1,2}[-\/]\d{1,2}[-\/]\d{4})/,
            /(\d{1,2}[-\/]\d{1,2}[-\/]\d{2})(?!\d)/,
            /(\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+\d{2,4})/i,
            /((?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+\d{1,2},?\s+\d{2,4})/i
        ];
        let extractedDate = null;
        for (const dp of datePatterns) {
            const dm = rawText.match(dp);
            if (dm) {
                // Try to parse into YYYY-MM-DD
                const parsed = new Date(dm[1]);
                if (!isNaN(parsed.getTime()) && parsed.getFullYear() > 2000 && parsed.getFullYear() < 2040) {
                    extractedDate = parsed.toISOString().split('T')[0];
                } else {
                    extractedDate = dm[1]; // Return raw
                }
                break;
            }
        }

        // --- Detect category from keywords ---
        const textLower = rawText.toLowerCase();
        const catKeywords = {
            'Meal':            ['restaurant','cafe','food','dine','lunch','dinner','breakfast','meal','eat','coffee','pizza','burger'],
            'Travel':          ['travel','taxi','uber','grab','fare','flight','airline','gas','fuel','parking','toll','transport'],
            'Medical':         ['medical','pharmacy','medicine','clinic','hospital','doctor','dental','health','optical'],
            'Supplies':        ['supplies','office','stationery','paper','ink','pen','pencil','stapler','folder','equipment'],
            'Training':        ['training','seminar','workshop','conference','course','certification','education'],
            'Accommodation':   ['hotel','lodging','inn','resort','airbnb','accommodation','room'],
            'Communication':   ['phone','internet','mobile','data','telecom','postage','sim','load'],
            'Transportation':  ['bus','mrt','lrt','jeep','tricycle','van','shuttle','commute']
        };
        let detectedCategory = null;
        let maxMatches = 0;
        for (const [cat, keywords] of Object.entries(catKeywords)) {
            let matches = 0;
            for (const kw of keywords) {
                if (textLower.includes(kw)) matches++;
            }
            if (matches > maxMatches) {
                maxMatches = matches;
                detectedCategory = cat;
            }
        }

        // Calculate NLP confidence based on what we extracted
        let nlpConf = 10;
        if (totalAmount) nlpConf += 30;
        if (vendor) nlpConf += 25;
        if (extractedDate) nlpConf += 20;
        if (detectedCategory) nlpConf += 15;

        return {
            vendor:        vendor,
            amount:        totalAmount,
            date:          extractedDate,
            category:      detectedCategory,
            ocrConfidence: Math.round(ocrConfidence),
            nlpConfidence: Math.min(100, nlpConf),
            rawText:       rawText
        };

    } catch (ocrError) {
        console.error('OCR extraction error:', ocrError);
        return {
            vendor: null, amount: null, date: null, category: null,
            ocrConfidence: 0, nlpConfidence: 0, rawText: '',
            error: ocrError.message
        };
    }
}

function showLoading(show, title = 'Processing...', msg = 'Please wait') {
    if (show) {
        document.getElementById('loadingTitle').textContent = title;
        document.getElementById('loadingMsg').textContent = msg;
        loadingOverlay.classList.add('show');
    } else {
        loadingOverlay.classList.remove('show');
    }
}

verifyBtn.addEventListener('click', verifyClaimWithReceipt);

document.getElementById('editClaimBtn').addEventListener('click', () => {
    verificationPanel.classList.remove('show');
});

document.getElementById('submitClaimBtn').addEventListener('click', async () => {
    showLoading(true, 'Submitting Claim...', 'Saving to database');
    
    try {
        // Send form data to server
        const formData = new FormData();
        formData.append('employee_id', employeeSelect.value);
        formData.append('vendor', vendorField.value);
        formData.append('amount', amountField.value);
        formData.append('category', categoryField.value);
        formData.append('expense_date', expenseDateField.value);
        formData.append('description', document.getElementById('description').value);
        formData.append('receipt', receiptField.files[0]);
        formData.append('overall_score', overallScore.textContent.replace('%', '') / 100);
        formData.append('created_by', '<?= $created_by ?>');

        const response = await fetch('process_claim_db.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        showLoading(false);

        if (result.success) {
            document.getElementById('claimIdDisplay').textContent = `Claim ID: ${result.claim_id}`;
            successModal.show();
            claimForm.reset();
            verificationPanel.classList.remove('show');
        } else {
            alert('Error: ' + (result.message || 'Failed to submit claim'));
        }
    } catch (error) {
        showLoading(false);
        alert('Error submitting claim: ' + error.message);
    }
});
</script>

</body>
</html>