<?php
require_once("../includes/db.php");

// Get all enrolled employees
$stmt = $pdo->prepare("SELECT id, employee_id, fullname, department, job_title FROM employees WHERE face_enrolled = 1 ORDER BY fullname");
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$selected_employee = null;
$swap_requests = [];
$success_message = '';
$error_message = '';

// Check if employee is selected
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['employee_selection'])) {
    $selected_emp_id = $_POST['employee_selection'] ?? null;
    
    if ($selected_emp_id) {
        $stmt = $pdo->prepare("SELECT id, employee_id, fullname, department, job_title FROM employees WHERE id = ? AND face_enrolled = 1");
        $stmt->execute([$selected_emp_id]);
        $selected_employee = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// If employee is selected from GET or POST, use it
if (isset($_GET['emp_id'])) {
    $stmt = $pdo->prepare("SELECT id, employee_id, fullname, department, job_title FROM employees WHERE id = ? AND face_enrolled = 1");
    $stmt->execute([$_GET['emp_id']]);
    $selected_employee = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle cancel swap request
if ($selected_employee && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_swap') {
    $request_id = intval($_POST['request_id'] ?? 0);
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM swap_requests WHERE id = ? AND requester_id = ? AND status = 'pending'");
        $stmt->execute([$request_id, $selected_employee['id']]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            $error_message = "Request not found or cannot be cancelled.";
        } else {
            $stmt = $pdo->prepare("UPDATE swap_requests SET status = 'cancelled' WHERE id = ?");
            if ($stmt->execute([$request_id])) {
                $success_message = "Shift swap request cancelled successfully.";
            }
        }
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Handle swap request submission
if ($selected_employee && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_swap') {
    $requester_shift_id = intval($_POST['requester_shift_id'] ?? 0);
    $target_employee_id = intval($_POST['target_employee_id'] ?? 0);
    $target_shift_id = intval($_POST['target_shift_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    // Validate inputs
    if (!$requester_shift_id || empty($reason)) {
        $error_message = "All required fields must be filled.";
    } else if (strlen($reason) < 10) {
        $error_message = "Please provide a detailed reason (at least 10 characters).";
    } else {
        // Verify the shift belongs to the employee
        $stmt = $pdo->prepare("SELECT * FROM shifts WHERE id = ? AND employee_id = ? AND shift_date >= CURDATE()");
        $stmt->execute([$requester_shift_id, $selected_employee['id']]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift) {
            $error_message = "Invalid shift selected or shift is in the past.";
        } else {
            // Check for duplicate pending requests for this shift
            $stmt = $pdo->prepare("SELECT id FROM swap_requests WHERE requester_id = ? AND requester_shift_id = ? AND status = 'pending'");
            $stmt->execute([$selected_employee['id'], $requester_shift_id]);
            if ($stmt->fetch()) {
                $error_message = "You already have a pending swap request for this shift.";
            } else {
                // Validate target shift if provided
                if ($target_shift_id > 0) {
                    $stmt = $pdo->prepare("SELECT * FROM shifts WHERE id = ?");
                    $stmt->execute([$target_shift_id]);
                    if (!$stmt->fetch()) {
                        $error_message = "Invalid target shift selected.";
                    }
                }

                if (!$error_message) {
                    // Insert swap request
                    $stmt = $pdo->prepare("INSERT INTO swap_requests 
                        (requester_id, requester_shift_id, target_employee_id, target_shift_id, reason, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
                    
                    $insert_success = $stmt->execute([
                        $selected_employee['id'],
                        $requester_shift_id,
                        ($target_employee_id > 0 ? $target_employee_id : null),
                        ($target_shift_id > 0 ? $target_shift_id : null),
                        $reason
                    ]);

                    if ($insert_success) {
                        $success_message = "✅ Shift swap request submitted successfully! Awaiting scheduler review.";
                        // Clear the form after successful submission
                        $_POST = [];
                    } else {
                        $error_message = "Failed to submit swap request. Please try again.";
                    }
                }
            }
        }
    }
}

// Fetch employee's shifts for dropdown
$my_shifts = [];
if ($selected_employee) {
    $stmt = $pdo->prepare("
        SELECT id, shift_date, shift_type, shift_start, shift_end
        FROM shifts 
        WHERE employee_id = ? AND shift_date >= CURDATE()
        ORDER BY shift_date ASC
    ");
    $stmt->execute([$selected_employee['id']]);
    $my_shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch all other employees' shifts for swap options
$other_shifts = [];
if ($selected_employee) {
    $stmt = $pdo->prepare("
        SELECT s.id, s.employee_id, s.shift_date, s.shift_type, s.shift_start, s.shift_end, e.fullname
        FROM shifts s
        JOIN employees e ON s.employee_id = e.id
        WHERE s.employee_id != ? AND s.shift_date >= CURDATE()
        ORDER BY s.shift_date ASC, e.fullname ASC
    ");
    $stmt->execute([$selected_employee['id']]);
    $other_shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch employee's swap request history
$swap_history = [];
if ($selected_employee) {
    $stmt = $pdo->prepare("
        SELECT sr.*, s.shift_date, s.shift_type, e.fullname as target_employee_name
        FROM swap_requests sr
        JOIN shifts s ON sr.requester_shift_id = s.id
        LEFT JOIN employees e ON sr.target_employee_id = e.id
        WHERE sr.requester_id = ?
        ORDER BY sr.created_at DESC
    ");
    $stmt->execute([$selected_employee['id']]);
    $swap_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Swap Request - ViaHale HR3</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        * {
            font-family: 'QuickSand', 'Poppins', Arial, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 0;
            color: #22223b;
        }

        .container-wrapper {
            max-width: 1000px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 2rem;
            border-radius: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #22223b;
            margin: 0;
        }

        .header-info {
            text-align: right;
        }

        .header-info p {
            margin: 0.2rem 0;
            font-size: 0.95rem;
            color: #6c757d;
        }

        .header-info strong {
            color: #22223b;
            font-weight: 600;
        }

        .card {
            border: none;
            border-radius: 18px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }

        .card-header {
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 18px 18px 0 0;
            padding: 1.5rem;
            border: none;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .card-body {
            padding: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            font-weight: 600;
            color: #22223b;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        .form-control, .form-select {
            border: 2px solid #e0e7ff;
            border-radius: 12px;
            padding: 0.7rem 1rem;
            font-size: 0.95rem;
            transition: border-color 0.3s;
        }

        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.1);
        }

        .btn-submit {
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 0.8rem 2rem;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
            color: white;
        }

        .btn-back {
            background: #e0e7ff;
            color: #667eea;
            border: none;
            border-radius: 12px;
            padding: 0.8rem 2rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
            display: inline-block;
        }

        .btn-back:hover {
            background: #667eea;
            color: white;
        }

        .btn-cancel {
            background: #fee2e2;
            color: #991b1b;
            border: none;
            border-radius: 8px;
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-cancel:hover {
            background: #fecaca;
        }

        .alert {
            border-radius: 12px;
            border: none;
            padding: 1.2rem;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .alert ion-icon {
            margin-right: 0.5rem;
            font-size: 1.2rem;
            vertical-align: middle;
        }

        .status-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-cancelled {
            background: #f3f4f6;
            color: #4b5563;
        }

        .swap-request-card {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s;
        }

        .swap-request-card:hover {
            border-color: #667eea;
            background: #f3f4f6;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1);
        }

        .swap-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .swap-title {
            font-weight: 700;
            color: #22223b;
            font-size: 1.1rem;
        }

        .swap-details {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1rem;
            margin: 1rem 0;
        }

        .detail-item {
            padding: 0.8rem;
            background: white;
            border-radius: 8px;
            border-left: 4px solid #667eea;
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

        .employee-selector {
            margin-bottom: 2rem;
        }

        .selector-card {
            background: white;
            border-radius: 18px;
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .shift-info {
            background: #f3f0ff;
            border-left: 4px solid #667eea;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .shift-info strong {
            color: #764ba2;
            display: block;
            margin-bottom: 0.3rem;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                text-align: center;
            }

            .header-info {
                text-align: center;
                margin-top: 1rem;
            }

            .swap-details {
                grid-template-columns: 1fr;
            }

            .swap-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .status-badge {
                margin-top: 0.5rem;
            }
        }
    </style>
</head>
<body>

<div class="container-wrapper">
    <!-- Employee Selector -->
    <div class="selector-card employee-selector">
        <h2 style="font-size: 1.3rem; font-weight: 700; color: #22223b; margin-bottom: 1.5rem;">
            <ion-icon name="person-outline"></ion-icon> Select Employee
        </h2>
        <form method="POST" action="shift_swap_request.php">
            <div class="form-group">
                <label for="employee_selection" class="form-label">Choose an Enrolled Employee</label>
                <select class="form-select" id="employee_selection" name="employee_selection" required onchange="this.form.submit();">
                    <option value="">-- Select Employee --</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>" <?= ($selected_employee && $selected_employee['id'] == $emp['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($emp['fullname']) ?> (<?= htmlspecialchars($emp['employee_id']) ?>) - <?= htmlspecialchars($emp['department']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <?php if ($selected_employee): ?>
        <!-- Header -->
        <div class="header">
            <div>
                <h1><ion-icon name="swap-horizontal-outline"></ion-icon> Request Shift Swap</h1>
            </div>
            <div class="header-info">
                <p><strong><?= htmlspecialchars($selected_employee['fullname']) ?></strong></p>
                <p><?= htmlspecialchars($selected_employee['department']) ?></p>
                <p style="font-size: 0.85rem;"><?= htmlspecialchars($selected_employee['employee_id']) ?></p>
            </div>
        </div>

        <!-- Messages -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success" role="alert">
                <ion-icon name="checkmark-circle-outline"></ion-icon>
                <?= $success_message ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger" role="alert">
                <ion-icon name="alert-circle-outline"></ion-icon>
                <?= $error_message ?>
            </div>
        <?php endif; ?>

        <!-- Shift Swap Request Form -->
        <div class="card">
            <div class="card-header">
                <ion-icon name="add-circle-outline"></ion-icon> Submit New Shift Swap Request
            </div>
            <div class="card-body">
                <?php if (empty($my_shifts)): ?>
                    <div class="alert alert-info">
                        <ion-icon name="information-circle-outline"></ion-icon>
                        This employee has no upcoming shifts available for swap.
                    </div>
                <?php else: ?>
                    <form method="POST" action="shift_swap_request.php" id="swapForm">
                        <input type="hidden" name="action" value="submit_swap">
                        <input type="hidden" name="employee_selection" value="<?= htmlspecialchars($selected_employee['id']) ?>">

                        <div class="form-group">
                            <label for="requester_shift_id" class="form-label">Your Shift to Swap <span style="color: #dc3545;">*</span></label>
                            <select class="form-select" id="requester_shift_id" name="requester_shift_id" required onchange="updateShiftInfo()">
                                <option value="">-- Select Your Shift --</option>
                                <?php foreach ($my_shifts as $shift): ?>
                                    <option value="<?= $shift['id'] ?>" data-date="<?= $shift['shift_date'] ?>" data-type="<?= $shift['shift_type'] ?>" data-start="<?= $shift['shift_start'] ?? '' ?>" data-end="<?= $shift['shift_end'] ?? '' ?>">
                                        <?= htmlspecialchars($shift['shift_type']) ?> - <?= date('M d, Y', strtotime($shift['shift_date'])) ?> (<?= ucfirst(strtolower(date('l', strtotime($shift['shift_date'])))) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Select the shift you want to swap out</small>
                        </div>

                        <div id="shiftInfo" style="display: none;">
                            <div class="shift-info">
                                <strong>Your Shift Details:</strong>
                                <div><small><span id="shiftDateInfo"></span> | <span id="shiftTypeInfo"></span> | <span id="shiftTimeInfo"></span></small></div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="target_employee_id" class="form-label">Swap With Employee (Optional)</label>
                                    <select class="form-select" id="target_employee_id" name="target_employee_id" onchange="updateTargetShifts()">
                                        <option value="">-- Any Employee --</option>
                                        <?php 
                                        $employees_list = [];
                                        foreach ($other_shifts as $shift) {
                                            if (!in_array($shift['employee_id'], array_column($employees_list, 'id'))) {
                                                $employees_list[] = ['id' => $shift['employee_id'], 'name' => $shift['fullname']];
                                            }
                                        }
                                        foreach ($employees_list as $emp): 
                                        ?>
                                            <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Leave blank if open to anyone</small>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="target_shift_id" class="form-label">Their Shift (Optional)</label>
                                    <select class="form-select" id="target_shift_id" name="target_shift_id">
                                        <option value="">-- Select Shift --</option>
                                        <?php foreach ($other_shifts as $shift): ?>
                                            <option value="<?= $shift['id'] ?>" data-employee="<?= $shift['employee_id'] ?>">
                                                <?= htmlspecialchars($shift['fullname']) ?> - <?= htmlspecialchars($shift['shift_type']) ?> - <?= date('M d, Y', strtotime($shift['shift_date'])) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Leave blank if no specific shift in mind</small>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="reason" class="form-label">Reason for Swap <span style="color: #dc3545;">*</span></label>
                            <textarea class="form-control" id="reason" name="reason" rows="4" placeholder="Please explain why you need this shift swap..." required minlength="10"></textarea>
                            <small class="text-muted">Minimum 10 characters required</small>
                        </div>

                        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                            <button type="submit" class="btn btn-submit">
                                <ion-icon name="send-outline"></ion-icon> Submit Request
                            </button>
                            <a href="javascript:history.back()" class="btn-back">
                                <ion-icon name="arrow-back-outline"></ion-icon> Go Back
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Swap Request History -->
        <div class="card">
            <div class="card-header">
                <ion-icon name="list-outline"></ion-icon> Shift Swap Request History
            </div>
            <div class="card-body">
                <?php if (empty($swap_history)): ?>
                    <div class="empty-state">
                        <ion-icon name="document-outline"></ion-icon>
                        <h5>No Swap Requests Yet</h5>
                        <p>This employee hasn't submitted any shift swap requests.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($swap_history as $request): ?>
                        <div class="swap-request-card">
                            <div class="swap-header">
                                <div class="swap-title"><?= htmlspecialchars($request['shift_type']) ?> Shift</div>
                                <span class="status-badge status-<?= strtolower($request['status']) ?>">
                                    <?= htmlspecialchars(ucfirst($request['status'])) ?>
                                </span>
                            </div>

                            <div class="swap-details">
                                <div class="detail-item">
                                    <div class="detail-label">Original Shift Date</div>
                                    <div class="detail-value"><?= date('M d, Y', strtotime($request['shift_date'])) ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Target Employee</div>
                                    <div class="detail-value"><?= htmlspecialchars($request['target_employee_name'] ?? 'Any') ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Status</div>
                                    <div class="detail-value"><span class="status-badge status-<?= strtolower($request['status']) ?>"><?= htmlspecialchars(ucfirst($request['status'])) ?></span></div>
                                </div>
                            </div>

                            <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e5e7eb;">
                                <p style="margin: 0.5rem 0; color: #6c757d; font-size: 0.9rem;">
                                    <strong>Reason:</strong> <?= htmlspecialchars($request['reason']) ?>
                                </p>
                                <p style="margin: 0.5rem 0; color: #6c757d; font-size: 0.85rem;">
                                    <strong>Requested:</strong> <?= date('M d, Y \a\t h:i A', strtotime($request['created_at'])) ?>
                                </p>
                                <?php if ($request['reviewed_at']): ?>
                                    <p style="margin: 0.5rem 0; color: #6c757d; font-size: 0.85rem;">
                                        <strong>Reviewed:</strong> <?= date('M d, Y \a\t h:i A', strtotime($request['reviewed_at'])) ?> by <?= htmlspecialchars($request['reviewed_by'] ?? 'Scheduler') ?>
                                    </p>
                                    <?php if ($request['review_note']): ?>
                                        <p style="margin: 0.5rem 0; color: #6c757d; font-size: 0.85rem;">
                                            <strong>Reviewer Note:</strong> <?= htmlspecialchars($request['review_note']) ?>
                                        </p>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($request['status'] === 'pending'): ?>
                                    <div style="margin-top: 1rem;">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                            <input type="hidden" name="action" value="cancel_swap">
                                            <input type="hidden" name="employee_selection" value="<?= htmlspecialchars($selected_employee['id']) ?>">
                                            <button type="submit" class="btn btn-cancel" onclick="return confirm('Cancel this shift swap request?')">
                                                <ion-icon name="close-outline"></ion-icon> Cancel Request
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <!-- No Employee Selected Message -->
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <ion-icon name="person-circle-outline"></ion-icon>
                    <h5>Select an Employee</h5>
                    <p>Please select an enrolled employee from the dropdown above to view or submit shift swap requests.</p>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateShiftInfo() {
    const select = document.getElementById('requester_shift_id');
    const option = select.options[select.selectedIndex];
    const infoDiv = document.getElementById('shiftInfo');
    
    if (option.value) {
        document.getElementById('shiftDateInfo').textContent = option.getAttribute('data-date');
        document.getElementById('shiftTypeInfo').textContent = option.getAttribute('data-type');
        const start = option.getAttribute('data-start');
        const end = option.getAttribute('data-end');
        document.getElementById('shiftTimeInfo').textContent = start && end ? `${start} - ${end}` : 'Time not set';
        infoDiv.style.display = 'block';
    } else {
        infoDiv.style.display = 'none';
    }
}

function updateTargetShifts() {
    const empSelect = document.getElementById('target_employee_id');
    const shiftSelect = document.getElementById('target_shift_id');
    const selectedEmpId = empSelect.value;
    
    // Reset and filter shifts
    Array.from(shiftSelect.options).forEach(opt => {
        if (opt.value === '') {
            opt.style.display = 'block';
        } else {
            opt.style.display = (selectedEmpId === '' || opt.getAttribute('data-employee') === selectedEmpId) ? 'block' : 'none';
        }
    });
    
    // Reset selection
    shiftSelect.value = '';
}
</script>

</body>
</html>