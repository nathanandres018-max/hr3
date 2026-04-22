<?php
session_start();
require_once("../includes/db.php");

// Auth check
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'Benefits Officer') {
    header("Location: ../login.php");
    exit();
}

$_SESSION['last_activity'] = time();
$fullname = $_SESSION['fullname'] ?? 'Benefits Officer';

// Get pending claims
$stmt = $pdo->prepare("
    SELECT id, amount, category, vendor, expense_date, description, status, created_at, created_by
    FROM claims 
    WHERE status = 'pending'
    ORDER BY created_at DESC
    LIMIT 50
");
$stmt->execute();
$pending_claims = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Claim Verification - ViaHale HR3</title>
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
        }

        .wrapper { display: flex; min-height: 100vh; }

        .sidebar { 
            background: linear-gradient(180deg, #181818ff 0%, #1a1a2e 100%);
            color: #fff; 
            width: 220px; 
            position: fixed; 
            left: 0; top: 0;
            height: 100vh; 
            z-index: 1040;
            overflow-y: auto; 
            padding: 1rem 0.3rem;
            box-shadow: 2px 0 15px rgba(0,0,0,0.1);
        }

        .sidebar::-webkit-scrollbar { width: 6px; }
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
            cursor: pointer;
        }

        .sidebar a.active, .sidebar a:hover, .sidebar button.active, .sidebar button:hover { 
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
            color: #fff; 
            padding-left: 1rem;
            box-shadow: 0 2px 8px rgba(154, 102, 255, 0.3);
        }

        .sidebar h6 { font-size: 0.75rem; font-weight: 700; color: #9A66ff; padding: 0.5rem 0.7rem; }

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

        .topbar .profile-info strong { font-size: 1.08rem; font-weight: 600; color: #22223b; }
        .topbar .profile-info small { color: #9A66ff; font-size: 0.93rem; }

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

        .claim-card { 
            background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%);
            border: 1px solid #e0e7ff;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }

        .claim-card:hover { 
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(154, 102, 255, 0.15);
            border-color: #9A66ff;
        }

        .claim-header { 
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 1rem;
        }

        .claim-title { 
            font-size: 1.1rem;
            font-weight: 700;
            color: #22223b;
        }

        .claim-grid { 
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .claim-field { 
            background: #f9f9fc;
            border-left: 4px solid #9A66ff;
            padding: 0.8rem;
            border-radius: 6px;
        }

        .claim-field-label { 
            font-size: 0.85rem;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 0.3rem;
        }

        .claim-field-value { 
            font-weight: 600;
            color: #22223b;
            font-size: 1rem;
        }

        .verification-result { 
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .verification-item { 
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.8rem 0;
            border-bottom: 1px solid #e0e7ff;
        }

        .verification-item:last-child { border-bottom: none; }

        .verification-status { 
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .verification-status.pass { 
            background: #d1fae5; 
            color: #065f46;
        }

        .verification-status.warning { 
            background: #fef3c7; 
            color: #b45309;
        }

        .verification-status.fail { 
            background: #fee2e2; 
            color: #991b1b;
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
            transition: width 0.3s ease;
        }

        .action-buttons { 
            display: flex;
            gap: 0.8rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .btn { 
            border-radius: 8px; 
            font-weight: 600;
            padding: 0.65rem 1.2rem;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }

        .btn-primary { 
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
            color: white;
        }

        .btn-primary:hover { 
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(154, 102, 255, 0.3);
        }

        .btn-success { 
            background: #10b981; 
            color: white;
        }

        .btn-success:hover { 
            background: #059669;
        }

        .btn-warning { 
            background: #f59e0b; 
            color: white;
        }

        .btn-warning:hover { 
            background: #d97706;
        }

        .btn-danger { 
            background: #ef4444; 
            color: white;
        }

        .btn-danger:hover { 
            background: #dc2626;
        }

        .btn-sm { 
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .loading-spinner { 
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
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
            .sidebar { width: 180px; } 
            .content-wrapper { margin-left: 180px; } 
            .main-content { padding: 1.5rem 1rem; }
        }

        @media (max-width: 900px) { 
            .sidebar { left: -220px; width: 220px; } 
            .sidebar.show { left: 0; } 
            .content-wrapper { margin-left: 0; } 
            .main-content { padding: 1rem; } 
            .topbar { padding: 1rem 1.5rem; }
            .claim-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <!-- Sidebar -->
    <div class="sidebar d-flex flex-column justify-content-between">
        <div>
            <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
                <img src="../assets/images/image.png" style="height:55px" alt="Logo">
            </div>
            <div class="mb-4">
                <nav class="nav flex-column">
                    <a class="nav-link" href="benefits_officer_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
                </nav>
            </div>
            <div class="mb-4">
                <h6 class="px-2 mb-2">CLAIMS & REIMBURSEMENT</h6>
                <nav class="nav flex-column">
                    <a class="nav-link" href="claim_submission.php"><ion-icon name="create-outline"></ion-icon>Claim Submission</a>
                    <a class="nav-link active" href="verify_claims.php"><ion-icon name="checkmark-circle-outline"></ion-icon>Verify Claims</a>
                    <a class="nav-link" href="pending_claims.php"><ion-icon name="cash-outline"></ion-icon>Pending Claims</a>
                    <a class="nav-link" href="processed_claims.php"><ion-icon name="checkmark-done-outline"></ion-icon>Processed Claims</a>
                    <a class="nav-link" href="flagged_claims.php"><ion-icon name="alert-circle-outline"></ion-icon>Flagged Claims</a>
                </nav>
            </div>
        </div>
        <div class="p-3 border-top mb-2">
            <a class="nav-link text-danger" href="../logout.php"><ion-icon name="log-out-outline"></ion-icon>Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="content-wrapper">
        <!-- Top Bar -->
        <div class="topbar">
            <h3>
                <ion-icon name="checkmark-circle-outline"></ion-icon> Claim Verification
            </h3>
            <div class="profile">
                <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
                <div class="profile-info">
                    <strong><?= htmlspecialchars($fullname) ?></strong><br>
                    <small>Benefits Officer</small>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="card">
                <div class="card-header">
                    <ion-icon name="search-outline"></ion-icon> Pending Claims to Verify
                </div>
                <div class="card-body">
                    <?php if (empty($pending_claims)): ?>
                        <div class="empty-state">
                            <ion-icon name="checkmark-done-outline"></ion-icon>
                            <h5>No Pending Claims</h5>
                            <p>All claims have been verified or processed.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pending_claims as $claim): ?>
                            <div class="claim-card" data-claim-id="<?= $claim['id'] ?>">
                                <div class="claim-header">
                                    <div>
                                        <div class="claim-title">Claim #<?= $claim['id'] ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($claim['created_by']) ?></small>
                                    </div>
                                    <span class="badge bg-warning">Pending Verification</span>
                                </div>

                                <div class="claim-grid">
                                    <div class="claim-field">
                                        <div class="claim-field-label">Category</div>
                                        <div class="claim-field-value"><?= htmlspecialchars($claim['category'] ?? 'N/A') ?></div>
                                    </div>
                                    <div class="claim-field">
                                        <div class="claim-field-label">Amount</div>
                                        <div class="claim-field-value">₱<?= number_format($claim['amount'] ?? 0, 2) ?></div>
                                    </div>
                                    <div class="claim-field">
                                        <div class="claim-field-label">Vendor</div>
                                        <div class="claim-field-value"><?= htmlspecialchars($claim['vendor'] ?? 'Unknown') ?></div>
                                    </div>
                                    <div class="claim-field">
                                        <div class="claim-field-label">Expense Date</div>
                                        <div class="claim-field-value"><?= htmlspecialchars($claim['expense_date'] ?? 'N/A') ?></div>
                                    </div>
                                </div>

                                <div id="verification-<?= $claim['id'] ?>" class="verification-result" style="display:none;">
                                    <!-- Verification results will be loaded here -->
                                </div>

                                <div class="action-buttons">
                                    <button class="btn btn-primary btn-verify" data-claim="<?= $claim['id'] ?>">
                                        <ion-icon name="search-outline"></ion-icon> Verify Claim
                                    </button>
                                    <button class="btn btn-success btn-approve" data-claim="<?= $claim['id'] ?>" disabled>
                                        <ion-icon name="checkmark-outline"></ion-icon> Approve
                                    </button>
                                    <button class="btn btn-warning btn-review" data-claim="<?= $claim['id'] ?>" disabled>
                                        <ion-icon name="eye-outline"></ion-icon> Needs Review
                                    </button>
                                    <button class="btn btn-danger btn-flag" data-claim="<?= $claim['id'] ?>" disabled>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('click', async (e) => {
    const verifyBtn = e.target.closest('.btn-verify');
    if (!verifyBtn) return;

    const claimId = verifyBtn.dataset.claim;
    const verificationDiv = document.getElementById(`verification-${claimId}`);
    const card = verifyBtn.closest('.claim-card');

    // Show loading
    verifyBtn.disabled = true;
    verifyBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying...';

    try {
        const formData = new FormData();
        formData.append('claim_id', claimId);

        const response = await fetch('verify_claim.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();

        if (data.success) {
            const results = data.verification_details;
            let html = '<div><strong>Verification Results</strong></div><hr>';

            // Amount verification
            if (results.amount_match) {
                html += createVerificationItem(
                    'Amount Match',
                    results.amount_match.message,
                    results.amount_match.status,
                    results.amount_match.confidence
                );
            }

            // Vendor verification
            if (results.vendor_match) {
                html += createVerificationItem(
                    'Vendor Match',
                    results.vendor_match.message,
                    results.vendor_match.status,
                    results.vendor_match.confidence
                );
            }

            // Date verification
            if (results.date_match) {
                html += createVerificationItem(
                    'Date Match',
                    results.date_match.message,
                    results.date_match.status,
                    results.date_match.confidence
                );
            }

            // Category verification
            if (results.category_match) {
                html += createVerificationItem(
                    'Category Match',
                    results.category_match.message,
                    results.category_match.status,
                    results.category_match.confidence
                );
            }

            // Receipt validity
            if (results.receipt_validity) {
                html += createVerificationItem(
                    'Receipt Validity',
                    results.receipt_validity.message,
                    results.receipt_validity.status,
                    results.receipt_validity.confidence
                );
            }

            // Duplicate check
            if (results.duplicate_check) {
                html += createVerificationItem(
                    'Duplicate Check',
                    results.duplicate_check.message,
                    results.duplicate_check.status,
                    results.duplicate_check.confidence
                );
            }

            // Overall score
            html += `<hr>
                    <div class="verification-item">
                        <strong>Overall Score</strong>
                        <span class="verification-status ${data.status === 'approved' ? 'pass' : (data.status === 'review_pending' ? 'warning' : 'fail')}">
                            ${data.overall_score}%
                        </span>
                    </div>`;

            verificationDiv.innerHTML = html;
            verificationDiv.style.display = 'block';

            // Enable action buttons based on status
            const approveBtn = card.querySelector('.btn-approve');
            const reviewBtn = card.querySelector('.btn-review');
            const flagBtn = card.querySelector('.btn-flag');

            if (data.status === 'approved') {
                approveBtn.disabled = false;
                reviewBtn.disabled = true;
                flagBtn.disabled = true;
            } else if (data.status === 'review_pending') {
                approveBtn.disabled = false;
                reviewBtn.disabled = false;
                flagBtn.disabled = false;
            } else {
                approveBtn.disabled = true;
                reviewBtn.disabled = false;
                flagBtn.disabled = false;
            }
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to verify claim: ' + error.message);
    } finally {
        verifyBtn.disabled = false;
        verifyBtn.innerHTML = '<ion-icon name="search-outline"></ion-icon> Verify Claim';
    }
});

function createVerificationItem(label, message, status, confidence) {
    return `
        <div class="verification-item">
            <div>
                <strong>${label}</strong>
                <div class="small text-muted">${message}</div>
                <div class="score-bar">
                    <div class="score-fill" style="width: ${confidence * 100}%"></div>
                </div>
            </div>
            <span class="verification-status ${status}">
                ${status.toUpperCase()}
            </span>
        </div>
    `;
}
</script>
</body>
</html>