<?php
// process_claim_db.php
// Insert submitted claim into the existing `claims` table (hr3_viahale).
// - Saves uploaded receipt to uploads/claims/
// - Computes a lightweight phash (Imagick or GD)
// - Stores nlp_suggestions in the nlp_suggestions column (longtext)
// - Sets status = 'pending' so pending_claims.php will show the submission
//
// Place this file next to claim_submission.php and ensure ../connection.php exists and provides $conn (mysqli).
// Usage: form POST (multipart/form-data) from claim_submission.php.
//
// Returns JSON for XHR requests, otherwise redirects to pending_claims.php.

declare(strict_types=1);
session_start();

// AUTH: Benefits Officer required
if (!isset($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Benefits Officer') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
              || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    } else {
        header('Location: ../login.php');
        exit;
    }
}

require_once(__DIR__ . '/../connection.php'); // expects $conn (mysqli)
if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Database connection missing']);
    exit;
}

function json_response($data, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
function safe_filename($name) {
    $n = preg_replace('/[^A-Za-z0-9\-\_\.]/', '_', $name);
    return mb_substr($n, 0, 200);
}

// phash helper (Imagick preferred, fallback to GD 8x8 aHash)
function compute_phash_file(string $path): ?string {
    if (!is_file($path)) return null;
    // Imagick path
    if (extension_loaded('imagick')) {
        try {
            $im = new Imagick($path);
            $im->setImageColorspace(Imagick::COLORSPACE_GRAY);
            $im->resizeImage(8,8,Imagick::FILTER_BOX,1);
            $pixels = $im->exportImagePixels(0,0,8,8,"I",Imagick::PIXEL_CHAR);
            $avg = array_sum($pixels)/count($pixels);
            $bits='';
            foreach ($pixels as $p) $bits .= ($p > $avg) ? '1' : '0';
            $hex='';
            for ($i=0;$i<strlen($bits);$i+=4) $hex .= dechex(bindec(substr($bits,$i,4)));
            return $hex;
        } catch (Exception $e) {
            // continue to GD fallback
        }
    }

    // GD fallback
    $data = @file_get_contents($path);
    if ($data === false) return null;
    $img = @imagecreatefromstring($data);
    if (!$img) return null;
    $tmp = imagecreatetruecolor(8,8);
    imagecopyresampled($tmp, $img, 0,0,0,0,8,8, imagesx($img), imagesy($img));
    $vals = [];
    for ($y=0;$y<8;$y++){
        for ($x=0;$x<8;$x++){
            $rgb = imagecolorat($tmp, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $lum = 0.2126*$r + 0.7152*$g + 0.0722*$b;
            $vals[] = $lum;
        }
    }
    imagedestroy($tmp);
    imagedestroy($img);
    $avg = array_sum($vals)/count($vals);
    $bits = '';
    foreach ($vals as $v) $bits .= ($v >= $avg) ? '1' : '0';
    $hex = '';
    for ($i=0;$i<strlen($bits);$i+=4) $hex .= dechex(bindec(substr($bits,$i,4)));
    return $hex;
}

// Read inputs (matching your claim_submission form)
$claimType = trim($_POST['claimType'] ?? '');
$amount = isset($_POST['amount']) ? floatval($_POST['amount']) : null;
$desc = trim($_POST['desc'] ?? '');
$expenseDate = trim($_POST['expenseDate'] ?? '');
$created_by = $_SESSION['username'];
$ocrText = trim($_POST['ocrText'] ?? '');
$ocrConf = isset($_POST['ocrConfidence']) ? intval($_POST['ocrConfidence']) : null;
$nlpRaw = trim($_POST['nlpSuggestions'] ?? '');
$other_details = trim($_POST['other_details'] ?? '');
$receipt_is_invoice = isset($_POST['receipt_is_invoice']) ? intval($_POST['receipt_is_invoice']) : 0;
$receipt_type_confidence = isset($_POST['receipt_type_confidence']) ? floatval($_POST['receipt_type_confidence']) : null;

// Validate minimal required fields
$errors = [];
if ($amount === null || $amount <= 0) $errors[] = 'Amount must be greater than zero';
if ($expenseDate === '') $errors[] = 'Expense date required';
if ($claimType === '') $errors[] = 'Claim type required';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
          || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

if (!empty($errors)) {
    if ($isAjax) return json_response(['ok'=>false,'errors'=>$errors], 400);
    $_SESSION['claim_errors'] = $errors;
    header('Location: claim_submission.php');
    exit;
}

// Handle file upload
$uploadDirRel = 'uploads/claims';
$uploadDir = __DIR__ . '/' . $uploadDirRel;
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$receiptPathRel = null;
if (!empty($_FILES['receipt']) && $_FILES['receipt']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['receipt'];
    if ($f['error'] === UPLOAD_ERR_OK) {
        $orig = safe_filename(basename($f['name']));
        $ext = pathinfo($orig, PATHINFO_EXTENSION) ?: 'jpg';
        $filename = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $target = $uploadDir . '/' . $filename;
        if (@move_uploaded_file($f['tmp_name'], $target)) {
            // store relative path as in your DB
            $receiptPathRel = $uploadDirRel . '/' . $filename;
            // optionally set permissions
            @chmod($target, 0644);
        } else {
            error_log('process_claim_db: upload move failed for ' . $orig);
        }
    } else {
        error_log('process_claim_db: file upload error code ' . $f['error']);
    }
}

// Compute phash if receipt saved
$phash = null;
if ($receiptPathRel) {
    $fp = __DIR__ . '/' . $receiptPathRel;
    $phash = compute_phash_file($fp);
}

// Prepare nlp_suggestions: try to preserve the client-provided JSON, enrich with metadata
$nlp = null;
if ($nlpRaw) {
    $tmp = json_decode($nlpRaw, true);
    if (is_array($tmp)) $nlp = $tmp;
}
if (!is_array($nlp)) $nlp = [];
// add metadata fields if missing
if (!isset($nlp['source_snippet']) && $ocrText) $nlp['source_snippet'] = substr($ocrText, 0, 400);
if ($phash) $nlp['phash'] = $phash;
$nlp['submitted_by'] = $created_by;
$nlp_json = json_encode($nlp, JSON_UNESCAPED_UNICODE);

// Simple risk scoring (keeps minimal)
$risk_score = 0.0;
if (!empty($nlp['confidence'])) $risk_score += max(0, 1 - floatval($nlp['confidence']));
if (!empty($ocrConf)) $risk_score += max(0, (100 - intval($ocrConf)) / 100 * 0.4);
$risk_score = min(1.0, round($risk_score, 2));

// Insert into DB (matching your claims table columns)
$created_at = date('Y-m-d H:i:s');
$status = 'pending';
$receipt_validity = 'unknown';
$tamper_evidence = null;
$ai_raw = null;

// Build prepared INSERT
$sql = "INSERT INTO claims (amount, category, vendor, expense_date, description, created_by, status, ocr_text, ocr_confidence, nlp_suggestions, receipt_path, created_at, updated_at, risk_score, receipt_validity, tamper_evidence, ai_raw, phash, review_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log('DB prepare failed: ' . $conn->error);
    if ($isAjax) json_response(['ok'=>false,'error'=>'DB prepare failed'], 500);
    $_SESSION['claim_errors'] = ['Server error (DB prepare)'];
    header('Location: claim_submission.php');
    exit;
}

// vendor: prefer nlp.vendor if available, else first line of description
$vendor = $nlp['vendor'] ?? null;
if (!$vendor && !empty($desc)) {
    $lines = preg_split('/\r\n|\n|\r/', trim($desc));
    $vendor = trim($lines[0]) ?: null;
}

$ocr_conf_db = $ocrConf !== null ? floatval($ocrConf) : null;
$phash_db = $phash ?? null;
$tamper_json = $tamper_evidence ? json_encode($tamper_evidence, JSON_UNESCAPED_UNICODE) : null;
$ai_raw_db = $ai_raw ? json_encode($ai_raw, JSON_UNESCAPED_UNICODE) : null;
$review_notes_db = null;

$stmt->bind_param(
    'dsssssssdssssdsssss',
    $amount,
    $claimType,       // category
    $vendor,
    $expenseDate,
    $desc,
    $created_by,
    $status,
    $ocrText,
    $ocr_conf_db,
    $nlp_json,
    $receiptPathRel,
    $created_at,
    $created_at,
    $risk_score,
    $receipt_validity,
    $tamper_json,
    $ai_raw_db,
    $phash_db,
    $review_notes_db
);

$executed = $stmt->execute();
if (!$executed) {
    error_log('DB insert failed: ' . $stmt->error);
    $stmt->close();
    if ($isAjax) json_response(['ok'=>false,'error'=>'DB insert failed'], 500);
    $_SESSION['claim_errors'] = ['Server error (DB insert)'];
    header('Location: claim_submission.php');
    exit;
}
$insert_id = $stmt->insert_id;
$stmt->close();

// Insert minimal audit entry if table exists (claims_audit)
if ($conn) {
    $aud = $conn->prepare("INSERT INTO claims_audit (claim_id, action, actor, note) VALUES (?, 'submitted', ?, 'submitted via UI')");
    if ($aud) {
        $aud->bind_param('is', $insert_id, $created_by);
        $aud->execute();
        $aud->close();
    }
}

// Return result
if ($isAjax) {
    json_response([
        'ok' => true,
        'claim_id' => $insert_id,
        'status' => $status,
        'message' => 'Claim submitted and queued for review'
    ]);
} else {
    // redirect to pending claims where the new record should appear
    header('Location: pending_claims.php');
    exit;
}
?>