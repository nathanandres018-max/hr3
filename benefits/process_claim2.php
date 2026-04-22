<?php
// process_claim2.php
// Lightweight claim action endpoint used by pending_claims.php for approve / flag / request_info
// Expects POST: claim_id, action, (message for request_info)
// Returns JSON { ok:true, status:'approved' }
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Benefits Officer') {
    http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
}
require_once(__DIR__ . '/../connection.php');

function json_exit($data,$code=200){ http_response_code($code); echo json_encode($data); exit; }

$claim_id = isset($_POST['claim_id']) ? intval($_POST['claim_id']) : 0;
$action = trim($_POST['action'] ?? '');
$message = trim($_POST['message'] ?? '');

if (!$claim_id || !$action) json_exit(['ok'=>false,'error'=>'Missing parameters'],400);

$valid_actions = ['approve','flag','request_info'];
if (!in_array($action, $valid_actions)) json_exit(['ok'=>false,'error'=>'Invalid action'],400);

$status_map = [
    'approve' => 'approved',
    'flag' => 'flagged',
    'request_info' => 'needs_more_info'
];

$new_status = $status_map[$action] ?? 'pending';

// update DB
if (!isset($conn) || !$conn) json_exit(['ok'=>false,'error'=>'DB missing'],500);

if ($action === 'request_info') {
    // store message in a messages table if exists else in claims.notes
    $stmt = $conn->prepare("UPDATE claims SET status = ?, reviewer_notes = CONCAT(IFNULL(reviewer_notes,''),'\n[Request Info] ', ?), updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('ssi', $new_status, $message, $claim_id);
    $ok = $stmt->execute();
    $stmt->close();
} else {
    $stmt = $conn->prepare("UPDATE claims SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('si', $new_status, $claim_id);
    $ok = $stmt->execute();
    $stmt->close();
}

if (!$ok) json_exit(['ok'=>false,'error'=>'DB update failed'],500);

// optional: record audit log
if (isset($conn)) {
    $user = $_SESSION['username'];
    $stmt2 = $conn->prepare("INSERT INTO claims_audit (claim_id, action, actor, note, created_at) VALUES (?, ?, ?, ?, NOW())");
    if ($stmt2) {
        $stmt2->bind_param('isss', $claim_id, $action, $user, $message);
        $stmt2->execute();
        $stmt2->close();
    }
}

json_exit(['ok'=>true,'status'=>$new_status,'message'=>'Action applied']);