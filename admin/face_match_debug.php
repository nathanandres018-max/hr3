<?php
// filepath: admin/face_match_debug.php
// Debug endpoint: given a probe descriptor (JSON), returns top N stored template distances.
// Adapted for your schema: prefers face_template_encrypted, falls back to face_embedding (JSON).
// Usage: POST JSON { "descriptor": [ ... ] }
// NOTE: restrict/remove this file after debugging.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

@ini_set('display_errors', '0');
ob_start();

require_once(__DIR__ . '/../connection.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection error']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input) || !isset($input['descriptor']) || !is_array($input['descriptor'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request. POST JSON with "descriptor": [...]']);
    exit;
}

$probe = array_map('floatval', $input['descriptor']);
$probe_len = count($probe);

$LOG = __DIR__ . '/verify_debug.log';
function vlog($m) {
    global $LOG;
    @file_put_contents($LOG, '['.date('Y-m-d H:i:s').'] '.(is_string($m)?$m:print_r($m,true)).PHP_EOL, FILE_APPEND|LOCK_EX);
}

// decrypt helper
$key = null;
if (file_exists(__DIR__ . '/../secret.php')) {
    @require_once(__DIR__ . '/../secret.php'); // may define ENCRYPTION_KEY
    if (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '') $key = hash('sha256', ENCRYPTION_KEY, true);
}
function decrypt_payload($payload_b64, $keyBin) {
    if (!$keyBin) return false;
    $parts = explode(':', $payload_b64);
    if (count($parts) !== 2) return false;
    $iv = base64_decode($parts[0]); $cipher = base64_decode($parts[1]);
    if ($iv === false || $cipher === false) return false;
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $keyBin, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? false : $plain;
}

function euclidean_distance(array $a, array $b) {
    if (count($a) !== count($b)) return INF;
    $sum = 0.0;
    for ($i=0,$n=count($a); $i<$n; $i++) { $d = $a[$i] - $b[$i]; $sum += $d*$d; }
    return sqrt($sum);
}

// Load templates: prefer encrypted column if present, else face_embedding
$templates = [];

// Check columns in your employees table
$has_enc = false;
$has_embed = false;
$colCheck = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME IN ('face_template_encrypted','face_embedding')");
if ($colCheck) {
    $colCheck->execute();
    $rc = $colCheck->get_result();
    while ($c = $rc->fetch_assoc()) {
        if ($c['COLUMN_NAME'] === 'face_template_encrypted') $has_enc = true;
        if ($c['COLUMN_NAME'] === 'face_embedding') $has_embed = true;
    }
    $colCheck->close();
}

// Try encrypted column first
if ($has_enc) {
    $sql = "SELECT id, employee_id, fullname, face_template_encrypted AS tpl FROM employees WHERE face_template_encrypted IS NOT NULL AND face_template_encrypted <> '' AND face_enrolled = 1";
    if ($res = $conn->query($sql)) {
        while ($r = $res->fetch_assoc()) {
            $rawTpl = $r['tpl'];
            $decoded = null;
            $plain = decrypt_payload($rawTpl, $key);
            if ($plain !== false) $decoded = json_decode($plain, true);
            $templates[] = ['db_id'=>$r['id'],'employee_id'=>$r['employee_id'],'fullname'=>$r['fullname'],'template'=>is_array($decoded)?array_map('floatval',$decoded):null,'raw'=>$rawTpl];
        }
        $res->free();
    }
}

// If no encrypted templates found, or even if some found but you want to include plain embeddings,
// also check face_embedding and include in the list (avoid duplicates).
if ($has_embed) {
    $sql2 = "SELECT id, employee_id, fullname, face_embedding AS tpl FROM employees WHERE face_embedding IS NOT NULL AND face_embedding <> '' AND face_enrolled = 1";
    if ($res2 = $conn->query($sql2)) {
        while ($r2 = $res2->fetch_assoc()) {
            // avoid duplicates by employee id (if already present from encrypted read)
            $dup = false;
            foreach ($templates as $t) { if ($t['employee_id'] === $r2['employee_id']) { $dup = true; break; } }
            if ($dup) continue;
            $decoded = json_decode($r2['tpl'], true);
            $templates[] = ['db_id'=>$r2['id'],'employee_id'=>$r2['employee_id'],'fullname'=>$r2['fullname'],'template'=>is_array($decoded)?array_map('floatval',$decoded):null,'raw'=>$r2['tpl']];
        }
        $res2->free();
    }
}

if (empty($templates)) {
    $buf = ob_get_clean();
    if (!empty($buf)) vlog("Unexpected output before no templates: " . substr($buf,0,2000));
    http_response_code(500);
    echo json_encode(['error'=>'No enrolled templates found']);
    exit;
}

// compute distances
$results = [];
foreach ($templates as $t) {
    $tpl = $t['template'];
    if (!is_array($tpl)) {
        $results[] = ['employee_id'=>$t['employee_id'],'fullname'=>$t['fullname'],'db_id'=>$t['db_id'],'dist'=>null,'note'=>'template_decode_failed'];
        continue;
    }
    if (count($tpl) !== $probe_len) {
        $results[] = ['employee_id'=>$t['employee_id'],'fullname'=>$t['fullname'],'db_id'=>$t['db_id'],'dist'=>null,'note'=>'length_mismatch','tpl_len'=>count($tpl),'probe_len'=>$probe_len];
        continue;
    }
    $dist = euclidean_distance($probe, array_map('floatval', $tpl));
    $results[] = ['employee_id'=>$t['employee_id'],'fullname'=>$t['fullname'],'db_id'=>$t['db_id'],'dist'=>$dist];
}

// sort by dist (nulls to end)
usort($results, function($a,$b){
    $da = isset($a['dist']) ? $a['dist'] : INF;
    $db = isset($b['dist']) ? $b['dist'] : INF;
    return $da <=> $db;
});

$topN = array_slice($results, 0, 10);

$buf = '';
if (ob_get_length() !== false && ob_get_length() > 0) $buf = ob_get_clean();
if (!empty($buf)) vlog("Unexpected output in face_match_debug: ".substr($buf,0,2000));

echo json_encode(['scanned'=>count($results),'probe_len'=>$probe_len,'top'=>$topN], JSON_UNESCAPED_UNICODE);
exit;
?>