<?php
/**
 * verify_claim_enhanced.php
 * ──────────────────────────────────────────────────────────
 * Enhanced AI-Assisted Claim Verification Endpoint
 * ──────────────────────────────────────────────────────────
 *
 * POST endpoint called by claim_verification.php (AJAX).
 * Uses ReceiptOCR for real text extraction and
 * ClaimVerificationEngine for comprehensive verification.
 *
 * Parameters (POST):
 *   claim_id      — int, required
 *   force_reocr   — 0|1, optional (re-run OCR even if data exists)
 *
 * Returns JSON with: overall_score, status, verifications,
 *   receipt_analysis, anomalies, claim_data, nlp_data
 */

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

// Suppress all PHP output except our JSON
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

// Safety-net: even if a truly fatal error occurs, output valid JSON
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Clean any partial output
        while (ob_get_level()) ob_end_clean();
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'   => false,
            'error'     => 'Fatal server error: ' . $err['message'],
            'file'      => basename($err['file'] ?? ''),
            'line'      => $err['line'] ?? 0,
            'timestamp' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
    }
});

$response = ['success' => false, 'error' => 'Unknown error'];

try {
    // ── Request validation ──
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST required');
    }

    if (empty($_POST['claim_id'])) {
        throw new Exception('Missing claim_id');
    }

    session_start();
    if (empty($_SESSION['username'])) {
        throw new Exception('Not logged in');
    }

    if (!in_array($_SESSION['role'] ?? '', ['Benefits Officer', 'HR3 Admin'])) {
        throw new Exception('Insufficient permissions');
    }

    // ── Database ──
    $claim_id = intval($_POST['claim_id']);
    $forceReOCR = !empty($_POST['force_reocr']);

    require_once __DIR__ . '/../connection.php';
    if (!isset($conn) || !$conn) {
        throw new Exception('No database connection');
    }

    // ── Load verification engine (may fail if PHP version is too old) ──
    require_once __DIR__ . '/verification_engine.php';

    $engine = new ClaimVerificationEngine($conn, $_SESSION['username'] ?? '');
    $response = $engine->verifyClaim($claim_id, $forceReOCR);

    if (!$response['success']) {
        throw new Exception($response['error'] ?? 'Verification failed');
    }

} catch (\Throwable $e) {
    // Catches Exception, Error, TypeError, ParseError, etc.
    http_response_code(200); // Keep 200 for AJAX compatibility
    $response = [
        'success'   => false,
        'error'     => $e->getMessage(),
        'type'      => get_class($e),
        'file'      => basename($e->getFile()),
        'line'      => $e->getLine(),
        'timestamp' => date('Y-m-d H:i:s'),
    ];
}

// Clean output buffer and send JSON
ob_end_clean();
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>