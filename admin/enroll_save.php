<?php
// filepath: admin/enroll_save.php
// Enroll save with duplicate-check and re-enrollment (update) support, and force override support.
// - Validates input JSON: employee_id, descriptor (array), images (optional), update (optional boolean), force (optional boolean).
// - Performs duplicate-check against existing templates (excludes same employee).
// - Returns match details (employee_id, fullname, distance) on 409 for inspection.
// - If force=true the server proceeds despite a close match — action is logged.
// - Encrypts descriptor using ENCRYPTION_KEY and stores in employees.face_template_encrypted.
// - Saves up to 5 images to assets/images/faces.
// - If update=true, replaces existing template and removes old face_image file (best-effort).
// - Writes detailed debug to enroll_debug.log (restrict/remove in production).

declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../connection.php'); // expects $conn (mysqli)
@require_once('/home/hr3.viahale.com/public_html/secret.php'); // expects ENCRYPTION_KEY constant (optional, required for encryption)

$LOG = __DIR__ . '/enroll_debug.log';
function log_debug($m) {
    global $LOG;
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . (is_string($m) ? $m : print_r($m, true)) . PHP_EOL;
    @file_put_contents($LOG, $entry, FILE_APPEND | LOCK_EX);
}

function db_has_column(mysqli $conn, string $table, string $col): bool {
    $st = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$st) return false;
    $st->bind_param('ss', $table, $col);
    $st->execute();
    $res = $st->get_result();
    $has = (bool)$res->fetch_row();
    $st->close();
    return $has;
}
function get_table_columns_like(mysqli $conn, string $table, string $pattern): array {
    $st = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME LIKE ?");
    if (!$st) return [];
    $st->bind_param('ss', $table, $pattern);
    $st->execute();
    $res = $st->get_result();
    $cols = [];
    while ($r = $res->fetch_assoc()) $cols[] = $r['COLUMN_NAME'];
    $st->close();
    return $cols;
}
function euclidean_distance(array $a, array $b) {
    if (count($a) !== count($b)) return INF;
    $sum = 0.0;
    for ($i = 0, $n = count($a); $i < $n; $i++) {
        $d = $a[$i] - $b[$i];
        $sum += $d * $d;
    }
    return sqrt($sum);
}
function decrypt_payload($payload_b64, $keyBin) {
    $parts = explode(':', $payload_b64);
    if (count($parts) !== 2) return false;
    $iv = base64_decode($parts[0]);
    $cipher = base64_decode($parts[1]);
    if ($iv === false || $cipher === false) return false;
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $keyBin, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? false : $plain;
}

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        log_debug('No valid $conn (mysqli) available');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server DB configuration error']);
        exit();
    }

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        http_response_code(400);
        log_debug("Invalid JSON input: " . substr($raw, 0, 2000));
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit();
    }

    $employee_id = isset($input['employee_id']) ? trim((string)$input['employee_id']) : '';
    $descriptor = isset($input['descriptor']) && is_array($input['descriptor']) ? $input['descriptor'] : null;
    $images = isset($input['images']) && is_array($input['images']) ? $input['images'] : [];
    $updateFlag = isset($input['update']) && ($input['update'] === true || $input['update'] === '1') ? true : false;
    $forceFlag = isset($input['force']) && ($input['force'] === true || $input['force'] === '1' || $input['force'] === 'true') ? true : false;

    if (!$employee_id || !is_array($descriptor) || count($descriptor) === 0) {
        http_response_code(400);
        log_debug("Missing fields. employee_id={$employee_id}, descriptor_ok=" . (is_array($descriptor) ? '1' : '0') . ", images_count=" . count($images) . ", update=" . ($updateFlag ? '1' : '0') . ", force=" . ($forceFlag ? '1' : '0'));
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit();
    }

    // ensure employee exists
    $stmt = $conn->prepare("SELECT id, fullname, face_image FROM employees WHERE employee_id = ? LIMIT 1");
    if (!$stmt) {
        log_debug("Prepare failed (select employee): " . $conn->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
        exit();
    }
    $stmt->bind_param('s', $employee_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $emp = $res->fetch_assoc();
    $stmt->close();
    if (!$emp) {
        http_response_code(404);
        log_debug("Employee not found: {$employee_id}");
        echo json_encode(['success' => false, 'error' => 'Employee not found']);
        exit();
    }
    $emp_db_id = (int)$emp['id'];
    $previousFaceImage = isset($emp['face_image']) && $emp['face_image'] !== '' ? $emp['face_image'] : null;

    // normalize descriptor
    $probeArr = array_map('floatval', $descriptor);

    // duplicate-check: fetch existing templates and compare distances (skip self)
    $DUPLICATE_THRESHOLD = 0.45; // tune if needed
    $faceCols = get_table_columns_like($conn, 'employees', 'face%');
    $encCol = db_has_column($conn, 'employees', 'face_template_encrypted') ? 'face_template_encrypted' : null;
    $plainCol = db_has_column($conn, 'employees', 'face_template') ? 'face_template' : null;
    if (!$encCol && !$plainCol && count($faceCols) > 0) {
        foreach ($faceCols as $c) {
            if (stripos($c, 'encrypt') !== false) { $encCol = $c; break; }
        }
        if (!$encCol) $plainCol = $faceCols[0] ?? null;
    }

    $selectCols = ["id", "employee_id", "fullname"];
    if ($encCol && db_has_column($conn, 'employees', $encCol)) $selectCols[] = "`$encCol`";
    if ($plainCol && db_has_column($conn, 'employees', $plainCol) && $plainCol !== $encCol) $selectCols[] = "`$plainCol`";
    $sql = "SELECT " . implode(', ', $selectCols) . " FROM employees WHERE 1=1";

    $rows = $conn->query($sql);
    if ($rows === false) {
        log_debug("Failed to fetch existing templates for duplicate check: " . $conn->error . " SQL: " . $sql);
    } else {
        $keyBin = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '') ? hash('sha256', ENCRYPTION_KEY, true) : null;
        $matched = null;
        while ($r = $rows->fetch_assoc()) {
            // skip self (allow updating own template)
            if (isset($r['employee_id']) && $r['employee_id'] === $employee_id) continue;

            $plain = null;
            if ($encCol && isset($r[$encCol]) && $r[$encCol] !== '' && $keyBin) {
                $plain = decrypt_payload($r[$encCol], $keyBin);
                if ($plain === false && $plainCol && isset($r[$plainCol]) && $r[$plainCol] !== '') $plain = $r[$plainCol];
            } elseif ($plainCol && isset($r[$plainCol]) && $r[$plainCol] !== '') {
                $plain = $r[$plainCol];
            }
            if (!$plain) continue;
            $stored = json_decode($plain, true);
            if (!is_array($stored)) { log_debug("Stored template JSON decode failed for emp={$r['employee_id']}"); continue; }
            $stored = array_map('floatval', $stored);
            if (count($stored) !== count($probeArr)) continue;
            $dist = euclidean_distance($probeArr, $stored);

            if ($dist < $DUPLICATE_THRESHOLD) {
                // record the first conflict details
                $matched = [
                    'employee_id' => $r['employee_id'],
                    'fullname' => $r['fullname'] ?? '',
                    'distance' => $dist
                ];
                // If admin explicitly asked to force the operation, allow it but log it.
                if ($forceFlag) {
                    log_debug("Duplicate threshold exceeded but forceFlag=true; proceeding. Match: " . json_encode($matched));
                    break; // proceed with enrollment/update
                }

                // Otherwise reject with 409 and include match details for inspection
                log_debug("Enrollment rejected: new template too close to existing emp={$r['employee_id']} dist={$dist}");
                $rows->free();
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'error' => 'Enrollment too similar to existing employee (possible duplicate)',
                    'match' => $matched,
                    'note' => 'Run duplicate_check.php or duplicate_scan.php to review similar templates. To override, resend with { "force": true }.'
                ]);
                exit();
            }
        }
        // free result set after loop if not freed earlier
        if ($rows instanceof mysqli_result) $rows->free();
    }

    // Save up to 5 sample images
    $facesDir = __DIR__ . '/../assets/images/faces';
    if (!file_exists($facesDir)) {
        if (!mkdir($facesDir, 0755, true)) {
            log_debug("Failed creating facesDir: $facesDir");
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Server file error']);
            exit();
        }
    }
    if (!is_writable($facesDir)) {
        log_debug("FacesDir not writable: $facesDir");
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server file error (not writable)']);
        exit();
    }

    $savedImages = [];
    $timestamp = time();
    $baseSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $employee_id);
    $idx = 1;
    foreach ($images as $imgData) {
        if ($idx > 5) break;
        if (!is_string($imgData) || !preg_match('/^data:image\/(\w+);base64,/', $imgData, $m)) {
            log_debug("Image $idx invalid data URL for employee {$employee_id}");
            $idx++;
            continue;
        }
        $bin = base64_decode(substr($imgData, strpos($imgData, ',') + 1));
        if ($bin === false) {
            log_debug("Image $idx base64 decode failed for employee {$employee_id}");
            $idx++;
            continue;
        }
        $ext = strtolower($m[1]) === 'png' ? 'png' : 'jpg';
        $fname = "face_{$baseSafe}_{$timestamp}_s{$idx}.{$ext}";
        $fpath = $facesDir . '/' . $fname;
        if (file_put_contents($fpath, $bin) !== false) {
            $savedImages[] = 'assets/images/faces/' . $fname;
        } else {
            log_debug("Failed to write image file: $fpath");
        }
        $idx++;
    }

    // Encryption: ensure ENCRYPTION_KEY present
    if (!defined('ENCRYPTION_KEY') || empty(ENCRYPTION_KEY)) {
        log_debug("ENCRYPTION_KEY not defined; aborting enrollment for {$employee_id}");
        foreach ($savedImages as $p) @unlink(__DIR__ . '/../' . $p);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server config error (encryption key)']);
        exit();
    }
    $key = hash('sha256', ENCRYPTION_KEY, true);
    $plaintext = json_encode(array_map('floatval', $descriptor));
    $iv = random_bytes(16);
    $ciphertext_raw = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext_raw === false) {
        log_debug("openssl_encrypt failed for employee {$employee_id}");
        foreach ($savedImages as $p) @unlink(__DIR__ . '/../' . $p);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Encryption error']);
        exit();
    }
    $payload = base64_encode($iv) . ':' . base64_encode($ciphertext_raw);

    // Update DB: set face_template_encrypted, face_image, face_enrolled, last_enrolled_at
    $firstImagePath = !empty($savedImages) ? $savedImages[0] : null;
    $actionType = $updateFlag ? 'update' : 'create';

    $upd = $conn->prepare("UPDATE employees SET face_template_encrypted = ?, face_image = ?, face_enrolled = 1, last_enrolled_at = NOW() WHERE employee_id = ?");
    if (!$upd) {
        foreach ($savedImages as $p) @unlink(__DIR__ . '/../' . $p);
        log_debug("Prepare failed (update employee): " . $conn->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
        exit();
    }
    $upd->bind_param('sss', $payload, $firstImagePath, $employee_id);
    if (!$upd->execute()) {
        foreach ($savedImages as $p) @unlink(__DIR__ . '/../' . $p);
        $err = $upd->error;
        $upd->close();
        log_debug("Execute failed (update employee): " . $err);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
        exit();
    }
    $upd->close();

    // remove previous image file if update and different
    if ($updateFlag && $previousFaceImage && $firstImagePath && $previousFaceImage !== $firstImagePath) {
        $previousPath = __DIR__ . '/../' . $previousFaceImage;
        if (file_exists($previousPath) && is_file($previousPath)) {
            @unlink($previousPath); // best-effort
            log_debug("Removed previous face image for {$employee_id}: {$previousFaceImage}");
        }
    }

    // Log the enrollment action in enroll_debug.log and schedule_logs
    log_debug("Enrollment {$actionType}" . ($forceFlag ? " (FORCED)" : "") . " successful for {$employee_id}, savedImages=" . json_encode($savedImages));

    try {
        if ($stmt_log = $conn->prepare("INSERT INTO schedule_logs (employee_id, action, action_time, status, performed_by, details) VALUES (?, ?, NOW(), ?, ?, ?)")) {
            $status = ($updateFlag ? 'Face Re-Enroll' : 'Face Enroll') . ($forceFlag ? ' (FORCED)' : '');
            $performed_by = $_SESSION['username'] ?? 'admin';
            $details = json_encode(['images'=>$savedImages,'update'=>$updateFlag,'force'=>$forceFlag,'conflict'=>$matched ?? null], JSON_UNESCAPED_UNICODE);
            $stmt_log->bind_param('issss', $emp_db_id, $status, $status, $performed_by, $details);
            $stmt_log->execute(); $stmt_log->close();
        }
    } catch (Exception $e) { log_debug("schedule_logs insert failed: " . $e->getMessage()); }

    echo json_encode(['success' => true, 'employee' => $emp['fullname'], 'action' => $actionType, 'forced' => $forceFlag]);
    exit();

} catch (Exception $e) {
    log_debug("Unhandled exception in enroll_save: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error (check logs)']);
    exit();
}
?>