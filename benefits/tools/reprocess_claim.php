<?php
// tools/reprocess_claim.php
// Re-run server-side OCR+NLP worker (scripts/ocr_nlp.py) for an existing claim and update the DB.
// Usage (browser): tools/reprocess_claim.php?id=12
// NOTE: keep this file in a tools/ folder and protect access in production (require admin auth, HTTPS, or remove after use).

header('Content-Type: application/json; charset=utf-8');

include_once(__DIR__ . '/../connection.php');
session_start();

// --- AUTH CHECK ---
// Only allow Benefits Officer (or change as needed). Adjust role check to match your app's roles.
if (!isset($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Benefits Officer') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized.']);
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid claim id. Use ?id=NUMBER']);
    exit();
}

$claimId = intval($_GET['id']);

// Fetch claim and receipt path
$stmt = mysqli_prepare($conn, "SELECT id, receipt_path FROM claims WHERE id = ? LIMIT 1");
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'DB prepare failed', 'detail' => mysqli_error($conn)]);
    exit();
}
mysqli_stmt_bind_param($stmt, "i", $claimId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$row) {
    echo json_encode(['ok' => false, 'error' => "Claim not found: {$claimId}"]);
    exit();
}

$receiptPath = $row['receipt_path'] ?? '';
if (empty($receiptPath)) {
    echo json_encode(['ok' => false, 'error' => 'No receipt_path stored for this claim.']);
    exit();
}

// Resolve absolute path (attempt relative to project root)
$absPath = $receiptPath;
if (!file_exists($absPath)) {
    // Try relative path from project root
    $absPath = realpath(__DIR__ . '/../' . ltrim($receiptPath, '/'));
}
if (!$absPath || !file_exists($absPath)) {
    echo json_encode(['ok' => false, 'error' => 'Receipt file not found on disk', 'tried' => [$receiptPath, __DIR__ . '/../' . $receiptPath]]);
    exit();
}

// Worker script
$worker = realpath(__DIR__ . '/../scripts/ocr_nlp.py');
if (!$worker || !file_exists($worker)) {
    echo json_encode(['ok' => false, 'error' => 'Worker not found', 'worker_path' => $worker]);
    exit();
}

// Python binary - adjust if your environment uses a virtualenv or different python path
$pythonBin = 'python3';

// Build command
$cmd = [$pythonBin, $worker, '--file', $absPath];

// Use proc_open to capture stdout/stderr safely
$descriptors = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w']
];

$proc = proc_open($cmd, $descriptors, $pipes, __DIR__);
if (!is_resource($proc)) {
    echo json_encode(['ok' => false, 'error' => 'Failed to start worker process.']);
    exit();
}

$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($proc);

if ($exitCode !== 0) {
    echo json_encode(['ok' => false, 'error' => 'Worker returned non-zero exit code', 'exit' => $exitCode, 'stderr' => $err, 'stdout' => $out]);
    exit();
}

$json = json_decode($out, true);
if (json_last_error() !== JSON_ERROR_NONE || !$json) {
    echo json_encode(['ok' => false, 'error' => 'Invalid worker output (not JSON)', 'stdout' => $out, 'stderr' => $err]);
    exit();
}

// Prepare updates
$ocrText = $json['text'] ?? '';
$ocrConfidence = isset($json['confidence']) ? floatval($json['confidence']) : 0.0;
$nlpSuggestions = $json; // store whole worker output for audit

// Determine new status: if confidence low -> needs_manual_review, else keep existing status
$newStatus = null;
$CONF_THRESHOLD = 0.55; // adjust threshold as desired
if ($ocrConfidence < $CONF_THRESHOLD) {
    $newStatus = 'needs_manual_review';
}

// Update DB
$ocrTextEsc = mysqli_real_escape_string($conn, $ocrText);
$nlpEsc = mysqli_real_escape_string($conn, json_encode($nlpSuggestions, JSON_UNESCAPED_UNICODE));
$ocrConfNum = $ocrConfidence;

$updateSql = "UPDATE claims SET ocr_text = ?, ocr_confidence = ?, nlp_suggestions = ?, updated_at = NOW()" . ($newStatus ? ", status = ?" : "") . " WHERE id = ?";
$stmt = mysqli_prepare($conn, $updateSql);
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'DB prepare failed', 'detail' => mysqli_error($conn)]);
    exit();
}

if ($newStatus) {
    mysqli_stmt_bind_param($stmt, "sdssi", $ocrTextEsc, $ocrConfNum, $nlpEsc, $newStatus, $claimId);
} else {
    mysqli_stmt_bind_param($stmt, "sdsi", $ocrTextEsc, $ocrConfNum, $nlpEsc, $claimId);
}

$exec = mysqli_stmt_execute($stmt);
if (!$exec) {
    $err = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);
    echo json_encode(['ok' => false, 'error' => 'Failed to update DB', 'detail' => $err]);
    exit();
}
mysqli_stmt_close($stmt);

// Return worker analysis and confirmation
$response = [
    'ok' => true,
    'claim_id' => $claimId,
    'ocr_confidence' => $ocrConfidence,
    'status' => $newStatus ?? 'unchanged',
    'analysis' => $json
];

echo json_encode($response, JSON_PRETTY_PRINT);
exit();