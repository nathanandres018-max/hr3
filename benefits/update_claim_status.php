<?php
// update_claim_status.php
// Updates claim verification status in the database

include_once("../connection.php");
session_start();

header('Content-Type: application/json');

// Authorization check
if ($_SESSION['role'] !== 'Benefits Officer') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit();
}

$claim_id = intval($_POST['claim_id'] ?? 0);
$status = trim($_POST['status'] ?? '');

$allowedStatuses = ['approved', 'needs_manual_review', 'rejected', 'flagged', 'processed'];

if (!$claim_id || !in_array($status, $allowedStatuses)) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit();
}

// Update the claim status
$sql = "UPDATE claims SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?";
$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . mysqli_error($conn)]);
    exit();
}

mysqli_stmt_bind_param($stmt, 'ssi', $status, $_SESSION['username'], $claim_id);

if (mysqli_stmt_execute($stmt)) {
    echo json_encode(['success' => true, 'message' => 'Claim status updated successfully']);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to update claim']);
}

mysqli_stmt_close($stmt);
mysqli_close($conn);
?>