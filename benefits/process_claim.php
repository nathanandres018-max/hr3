<?php
/**
 * process_claim.php
 * ──────────────────────────────────────────────────────────
 * Handle claim approval, flagging, and review actions
 * with full audit logging and verification status tracking.
 * ──────────────────────────────────────────────────────────
 *
 * POST parameters:
 *   action   — approve | flag | review
 *   claim_id — int
 *   reason   — string (optional notes / reason)
 */

header('Content-Type: application/json; charset=utf-8');

// Buffer all output so stray PHP warnings don't corrupt JSON
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    session_start();

    if (empty($_SESSION['username'])) {
        http_response_code(401);
        throw new Exception('Unauthorized');
    }

    if (!in_array($_SESSION['role'] ?? '', ['Benefits Officer', 'HR3 Admin'])) {
        http_response_code(403);
        throw new Exception('Insufficient permissions');
    }

    // Get inputs
    $action   = $_POST['action'] ?? '';
    $claim_id = intval($_POST['claim_id'] ?? 0);
    $reason   = trim($_POST['reason'] ?? '');

    if (!$claim_id || !in_array($action, ['approve', 'flag', 'review'])) {
        throw new Exception('Invalid parameters');
    }

    // Database
    include_once(__DIR__ . '/../connection.php');
    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection failed');
    }

    // Map action to status
    $statusMap = [
        'approve' => 'approved',
        'flag'    => 'flagged',
        'review'  => 'needs_manual_review',
    ];
    $newStatus = $statusMap[$action];

    // Fetch old status for audit trail
    $oldStatus = 'pending';
    $stmtOld = $conn->prepare("SELECT status FROM claims WHERE id = ? LIMIT 1");
    if ($stmtOld) {
        $stmtOld->bind_param('i', $claim_id);
        $stmtOld->execute();
        $resOld = $stmtOld->get_result();
        $rowOld = $resOld->fetch_assoc();
        $oldStatus = $rowOld['status'] ?? 'pending';
        $stmtOld->close();
    }

    // Update claim status + reviewer info
    $username = $_SESSION['username'];
    $query = "UPDATE claims 
              SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
              WHERE id = ?";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('sssi', $newStatus, $username, $reason, $claim_id);
    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    $stmt->close();

    // Also update verification_status column if it exists
    $conn->query("UPDATE claims SET verification_status = '{$conn->real_escape_string($newStatus)}' WHERE id = {$claim_id}");

    // ── Audit Logging ──
    try {
        require_once __DIR__ . '/audit_logger.php';
        $logger = new ClaimAuditLogger($conn, $username);
        $logger->logStatusChange(
            $claim_id,
            $action,
            $oldStatus,
            $newStatus,
            null, null, // amount unchanged
            null, null, // category unchanged
            $reason
        );
    } catch (Exception $auditErr) {
        // Audit failure should not block the action
        error_log("Audit log failed for claim #{$claim_id}: " . $auditErr->getMessage());
    }

    echo json_encode([
        'success'  => true,
        'message'  => ucfirst($action) . ' successful',
        'claim_id' => $claim_id,
        'status'   => $newStatus,
    ]);

} catch (\Throwable $e) {
    if (!headers_sent()) {
        http_response_code(200);
    }
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
    exit;
}

ob_end_flush();
exit;
?>