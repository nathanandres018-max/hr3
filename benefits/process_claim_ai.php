<?php
// process_claim_ai.php
// Final submission endpoint for processed claims from claim_submission.php
// - Saves uploaded receipt (uploads/claims/), computes phash, basic duplicate/risk heuristics,
// - Saves claim record in `claims` table with nlp_suggestions JSON and returns claim_id + status.
//
// Requirements:
// - ../connection.php must provide $conn (mysqli)
// - uploads/claims/ directory must be writable by PHP
// - session-based auth (Benefits Officer)

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Benefits Officer') {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}

require_once(__DIR__ . '/../connection.php');

function json_exit($data, int $code = 200) { http_response_code($code); echo json_encode($data); exit; }

// minimal image phash function (same approach as extractor)
function image_phash_file(string $path): ?string {
    if (!is_file($path)) return null;
    if (extension_loaded('imagick')) {
        try {
            $im = new Imagick($path);
            $im->setImageColorspace(Imagick::COLORSPACE_GRAY);
            $im->resizeImage(16,16,Imagick::FILTER_BOX,1);
            $pixels = $im->exportImagePixels(0,0,16,16,"I",Imagick::PIXEL_CHAR);
            $avg = array_sum($pixels)/count($pixels);
            $bits=''; foreach ($pixels as $p) $bits .= ($p > $avg) ? '1' : '0';
            $hex=''; for ($i=0;$i<strlen($bits);$i+=4) $hex .= dechex(bindec(substr($bits,$i,4)));
            return $hex;
        } catch (Exception $e) {}
    }
    $data = @file_get_contents($path); if ($data === false) return null;
    $img = @imagecreatefromstring($data); if (!$img) return null;
    $w = imagesx($img); $h = imagesy($img); $tmp = imagecreatetruecolor(16,16);
    imagecopyresampled($tmp,$img,0,0,0,0,16,16,$w,$h);
    $vals=[]; for ($y=0;$y<16;$y++){ for ($x=0;$x<16;$x++){ $rgb = imagecolorat($tmp,$x,$y); $r=($rgb>>16)&0xFF;$g=($rgb>>8)&0xFF;$b=$rgb&0xFF; $lum=(0.2126*$r+0.7152*$g+0.0722*$b); $vals[]=$lum; } }
    $avg=array_sum($vals)/count($vals); $bits=''; foreach ($vals as $v) $bits .= ($v>$avg)?'1':'0';
    imagedestroy($tmp); imagedestroy($img);
    $hex=''; for ($i=0;$i<strlen($bits);$i+=4) $hex .= dechex(bindec(substr($bits,$i,4)));
    return $hex;
}
function safe_filename($name) {
    $name = preg_replace('/[^A-Za-z0-9\-\_\.]/', '_', $name);
    return substr($name, 0, 180);
}

// Read inputs
$claimType = trim($_POST['claimType'] ?? '');
$amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0.0;
$desc = trim($_POST['desc'] ?? '');
$expenseDate = trim($_POST['expenseDate'] ?? '');
$created_by = trim($_POST['created_by'] ?? $_SESSION['username']);
$ocrText = trim($_POST['ocrText'] ?? '');
$ocrConf = intval($_POST['ocrConfidence'] ?? 0);
$nlpRaw = $_POST['nlpSuggestions'] ?? '';
$otherDetails = $_POST['other_details'] ?? '';
$receipt_is_invoice = $_POST['receipt_is_invoice'] ?? '0';
$receipt_type_confidence = $_POST['receipt_type_confidence'] ?? '0';

// Validate minimal
if (empty($claimType) || $amount <= 0 || empty($expenseDate)) {
    json_exit(['ok'=>false,'error'=>'Missing required fields'], 400);
}

// Handle upload
$uploadDir = __DIR__ . '/uploads/claims';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$receiptPath = null;
if (!empty($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
    $f = $_FILES['receipt'];
    $orig = safe_filename(basename($f['name']));
    $ext = pathinfo($orig, PATHINFO_EXTENSION) ?: 'jpg';
    $target = $uploadDir . '/' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (@move_uploaded_file($f['tmp_name'], $target)) {
        $receiptPath = 'uploads/claims/' . basename($target); // relative path for DB/UI links
    }
}

// compute phash if receipt saved
$phash = null;
if ($receiptPath) {
    $phash = image_phash_file(__DIR__ . '/' . $receiptPath);
}

// prepare nlp_suggestions JSON to store (merge with incoming)
$nlp = null;
if ($nlpRaw) {
    $tmp = json_decode($nlpRaw, true);
    if (is_array($tmp)) $nlp = $tmp;
}
if (!is_array($nlp)) $nlp = [];
if ($phash) $nlp['phash'] = $phash;
if ($ocrText) $nlp['source_snippet'] = $nlp['source_snippet'] ?? substr($ocrText,0,400);
$nlp['submitted_by'] = $created_by;
$nlp_json = json_encode($nlp, JSON_UNESCAPED_UNICODE);

// Basic duplicate detection: same user, same amount, same date within 30 days
$duplicateDetected = false;
$duplicateInfo = [];
if (isset($conn) && $conn) {
    $stmt = $conn->prepare("SELECT id FROM claims WHERE created_by = ? AND ABS(DATEDIFF(?, expense_date)) <= 30 AND ROUND(amount,2) = ROUND(?,2) LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ssd', $created_by, $expenseDate, $amount);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $duplicateDetected = true;
                $duplicateInfo[] = ['existing_id' => $row['id'], 'reason' => 'recent_similar'];
            }
            $res->free();
        }
        $stmt->close();
    }
}

// Risk scoring simple heuristic
$risk = 0.0;
if ($nlp['confidence'] ?? false) {
    $risk += max(0, 1 - ($nlp['confidence']));
}
if ($ocrConf > 0) {
    $risk += (($OCR_CONF_PENALTY := max(0, (100 - $ocrConf) / 100)) * 0.4);
}
if ($duplicateDetected) $risk += 0.6;
$risk_score = min(1.0, round($risk, 2));

// Decide initial status
$status = 'pending';
if ($risk_score >= 0.75) $status = 'needs_manual_review';
elseif ($risk_score >= 0.5) $status = 'flagged';
else $status = 'pending';

// Insert into DB
$created_at = date('Y-m-d H:i:s');
$receipt_path_db = $receiptPath ?? null;

if (isset($conn) && $conn) {
    $stmt = $conn->prepare("INSERT INTO claims (claim_type, amount, description, expense_date, created_by, created_at, nlp_suggestions, phash, receipt_path, status, risk_score, ocr_text, ocr_confidence, other_details, receipt_is_invoice, receipt_type_confidence) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        json_exit(['ok'=>false,'error'=>'DB prepare failed: '. $conn->error], 500);
    }
    $phash_db = $phash ?? null;
    $stmt->bind_param('sdsssssssdidsssi', $claimType, $amount, $desc, $expenseDate, $created_by, $created_at, $nlp_json, $phash_db, $receipt_path_db, $status, $risk_score, $ocrText, $ocrConf, $otherDetails, $receipt_is_invoice, $receipt_type_confidence);
    if (!$stmt->execute()) {
        json_exit(['ok'=>false,'error'=>'DB insert failed: '. $stmt->error], 500);
    }
    $inserted_id = $stmt->insert_id;
    $stmt->close();
} else {
    json_exit(['ok'=>false,'error'=>'No database connection'], 500);
}

// respond
json_exit([
    'ok' => true,
    'claim_id' => $inserted_id,
    'status' => $status,
    'risk_score' => $risk_score,
    'duplicateDetected' => $duplicateDetected,
    'duplicateInfo' => $duplicateInfo
]);