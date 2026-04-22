<?php
// filepath: admin/verify_attendance.php
// Complete attendance verification endpoint — DEBUGGED VERSION
// - Liveness verification (isLive + blinkCount)
// - Face descriptor matching against enrolled encrypted templates
// - Shift-based clock-in/out (employee must have a shift assigned today)
// - Early time-out detection with mandatory reason
// - Attendance recording in `attendance` table
// - Action logging in `schedule_logs`

declare(strict_types=1);

// Prevent any output before JSON
ob_start();

session_start();
header('Content-Type: application/json; charset=utf-8');

// === Debug Logger ===
$LOG = __DIR__ . '/verify_attendance_debug.log';
function vlog($m) {
    global $LOG;
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . (is_string($m) ? $m : print_r($m, true)) . PHP_EOL;
    @file_put_contents($LOG, $entry, FILE_APPEND | LOCK_EX);
}

// Catch all errors and log them
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    vlog("PHP ERROR [{$errno}]: {$errstr} in {$errfile}:{$errline}");
    return false;
});

set_exception_handler(function($e) {
    vlog("UNCAUGHT EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
});

// Require logged-in user
if (!isset($_SESSION['username']) || empty($_SESSION['username'])) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in.']);
    exit;
}

// Include database connection
$connFile = __DIR__ . '/../connection.php';
if (!file_exists($connFile)) {
    vlog("connection.php not found at: {$connFile}");
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server config error: connection.php not found']);
    exit;
}
require_once($connFile);

// Include encryption secret (optional)
$secretFile = '/home/hr3.viahale.com/public_html/secret.php';
if (file_exists($secretFile)) {
    @require_once($secretFile);
}

vlog("=== NEW ATTENDANCE REQUEST ===");
vlog("Session user: " . ($_SESSION['username'] ?? 'unknown'));

// === Helper: Euclidean distance ===
function euclidean_distance(array $a, array $b): float {
    if (count($a) !== count($b)) return INF;
    $sum = 0.0;
    for ($i = 0, $n = count($a); $i < $n; $i++) {
        $d = $a[$i] - $b[$i];
        $sum += $d * $d;
    }
    return sqrt($sum);
}

// === Helper: Decrypt payload ===
function decrypt_payload(string $payload_b64, string $keyBin) {
    $parts = explode(':', $payload_b64);
    if (count($parts) !== 2) return false;
    $iv = base64_decode($parts[0]);
    $cipher = base64_decode($parts[1]);
    if ($iv === false || $cipher === false) return false;
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $keyBin, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? false : $plain;
}

// === Helper: Safe JSON output ===
function jsonOut(array $data, int $httpCode = 200): void {
    ob_clean();
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// === Configuration ===
$MATCH_THRESHOLD = 0.50;

try {
    // === Validate DB connection ===
    if (!isset($conn) || !($conn instanceof mysqli)) {
        vlog('No valid $conn (mysqli) available');
        jsonOut(['success' => false, 'error' => 'Server DB configuration error'], 500);
    }

    // Test the connection is alive
    if ($conn->connect_errno) {
        vlog('DB connection error: ' . $conn->connect_error);
        jsonOut(['success' => false, 'error' => 'Database connection failed'], 500);
    }

    vlog("DB connection OK");

    // === Parse Input ===
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        vlog("Empty request body");
        jsonOut(['success' => false, 'error' => 'Empty request body'], 400);
    }

    $input = json_decode($raw, true);
    if (!is_array($input)) {
        vlog("Invalid JSON input: " . substr($raw, 0, 500));
        jsonOut(['success' => false, 'error' => 'Invalid JSON input'], 400);
    }

    vlog("Input keys: " . implode(', ', array_keys($input)));

    $descriptor     = isset($input['descriptor']) && is_array($input['descriptor']) ? $input['descriptor'] : null;
    $earlyOutReason = isset($input['earlyOutReason']) ? trim((string)$input['earlyOutReason']) : '';

    if (!$descriptor || count($descriptor) === 0) {
        vlog("Missing face descriptor");
        jsonOut(['success' => false, 'error' => 'Missing face descriptor'], 400);
    }

    vlog("Descriptor length: " . count($descriptor));

    // === LIVENESS VERIFICATION CHECK ===
    $isLive = isset($input['isLive']) && $input['isLive'] === true;
    $blinkCount = isset($input['blinkCount']) ? (int)$input['blinkCount'] : 0;
    $faceConfidence = isset($input['confidence']) ? (float)$input['confidence'] : 0;

    if (!$isLive) {
        vlog("Attendance rejected: Liveness verification failed (isLive=" . var_export($input['isLive'] ?? null, true) . ")");
        jsonOut([
            'success' => false,
            'error'   => 'Liveness verification failed. Please complete face detection and blink verification.',
            'code'    => 'liveness_failed'
        ]);
    }
    vlog("Liveness verified: blinkCount={$blinkCount}, confidence={$faceConfidence}%");

    // === Normalize probe descriptor ===
    $probeArr = array_map('floatval', $descriptor);

    // === Load all enrolled templates and find best match ===
    $keyBin = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '') ? hash('sha256', ENCRYPTION_KEY, true) : null;
    vlog("Encryption key available: " . ($keyBin ? 'yes' : 'no'));

    // Build SELECT dynamically based on which columns exist
    $selectCols = ['id', 'employee_id', 'fullname'];

    // Check which face columns exist
    $checkCol = function(string $col) use ($conn): bool {
        $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('s', $col);
        $stmt->execute();
        $res = $stmt->get_result();
        $has = (bool)$res->fetch_row();
        $stmt->close();
        return $has;
    };

    $hasEncrypted  = $checkCol('face_template_encrypted');
    $hasPlain      = $checkCol('face_template');
    $hasEmbedding  = $checkCol('face_embedding');

    if ($hasEncrypted) $selectCols[] = 'face_template_encrypted';
    if ($hasPlain)     $selectCols[] = 'face_template';
    if ($hasEmbedding) $selectCols[] = 'face_embedding';

    vlog("Face columns: encrypted=" . ($hasEncrypted ? 'yes' : 'no') . ", plain=" . ($hasPlain ? 'yes' : 'no') . ", embedding=" . ($hasEmbedding ? 'yes' : 'no'));

    $sql = "SELECT " . implode(', ', $selectCols) . " FROM employees WHERE face_enrolled = 1 AND status = 'Active'";
    vlog("Query: {$sql}");

    $rows = $conn->query($sql);
    if (!$rows) {
        vlog("DB query failed: " . $conn->error);
        jsonOut(['success' => false, 'error' => 'Database error loading templates'], 500);
    }

    $totalRows = $rows->num_rows;
    vlog("Enrolled employees found: {$totalRows}");

    if ($totalRows === 0) {
        $rows->free();
        jsonOut(['success' => false, 'error' => 'No enrolled employees found in the system.', 'code' => 'no_match']);
    }

    $bestMatch   = null;
    $secondMatch = null;
    $bestDist    = INF;
    $secondDist  = INF;
    $checkedCount = 0;
    $skippedCount = 0;

    while ($r = $rows->fetch_assoc()) {
        $tpl = null;

        // Try encrypted template first
        if ($hasEncrypted && !empty($r['face_template_encrypted']) && $keyBin) {
            $plain = decrypt_payload($r['face_template_encrypted'], $keyBin);
            if ($plain !== false) {
                $decoded = json_decode($plain, true);
                if (is_array($decoded) && count($decoded) > 0) {
                    $tpl = array_map('floatval', $decoded);
                }
            }
        }

        // Fallback: plain face_template
        if ($tpl === null && $hasPlain && !empty($r['face_template'])) {
            $decoded = json_decode($r['face_template'], true);
            if (is_array($decoded) && count($decoded) > 0) {
                $tpl = array_map('floatval', $decoded);
            }
        }

        // Fallback: face_embedding
        if ($tpl === null && $hasEmbedding && !empty($r['face_embedding'])) {
            $decoded = json_decode($r['face_embedding'], true);
            if (is_array($decoded) && count($decoded) > 0) {
                $tpl = array_map('floatval', $decoded);
            }
        }

        if (!is_array($tpl) || count($tpl) === 0) {
            $skippedCount++;
            continue;
        }

        // Dimension mismatch — skip but don't error
        if (count($tpl) !== count($probeArr)) {
            vlog("Dimension mismatch for emp={$r['employee_id']}: template=" . count($tpl) . " vs probe=" . count($probeArr));
            $skippedCount++;
            continue;
        }

        $checkedCount++;
        $dist = euclidean_distance($probeArr, $tpl);

        if ($dist < $bestDist) {
            $secondDist  = $bestDist;
            $secondMatch = $bestMatch;
            $bestDist    = $dist;
            $bestMatch   = [
                'db_id'       => (int)$r['id'],
                'employee_id' => $r['employee_id'],
                'fullname'    => $r['fullname'],
                'dist'        => $dist
            ];
        } elseif ($dist < $secondDist) {
            $secondDist  = $dist;
            $secondMatch = [
                'db_id'       => (int)$r['id'],
                'employee_id' => $r['employee_id'],
                'fullname'    => $r['fullname'],
                'dist'        => $dist
            ];
        }
    }
    $rows->free();

    vlog("Checked: {$checkedCount}, Skipped: {$skippedCount}");
    vlog("Best match: " . ($bestMatch ? "{$bestMatch['fullname']} ({$bestMatch['employee_id']}), dist={$bestDist}" : 'none'));

    // === Check if match is good enough ===
    if ($bestMatch === null || $bestDist > $MATCH_THRESHOLD) {
        vlog("No match found. Best dist=" . ($bestMatch ? number_format($bestDist, 6) : 'none') . ", threshold={$MATCH_THRESHOLD}");
        jsonOut([
            'success' => false,
            'error'   => 'Face not recognized. Please ensure you are enrolled and try again.',
            'code'    => 'no_match'
        ]);
    }

    $empDbId     = $bestMatch['db_id'];
    $empCode     = $bestMatch['employee_id'];
    $empFullname = $bestMatch['fullname'];

    vlog("MATCHED: {$empFullname} ({$empCode}), dist={$bestDist}");

    // === Find today's shift for this employee ===
    $today = date('Y-m-d');
    $now   = date('Y-m-d H:i:s');

    vlog("Looking up shift for employee db_id={$empDbId} on date={$today}");

    $shiftStmt = $conn->prepare("
        SELECT s.id AS shift_id, s.shift_date, s.shift_type, s.shift_start, s.shift_end
        FROM shifts s
        WHERE s.employee_id = ? AND s.shift_date = ?
        ORDER BY s.shift_start ASC
        LIMIT 1
    ");
    if (!$shiftStmt) {
        vlog("Shift query prepare failed: " . $conn->error);
        jsonOut(['success' => false, 'error' => 'Database error (shift lookup): ' . $conn->error], 500);
    }
    $shiftStmt->bind_param('is', $empDbId, $today);
    $shiftStmt->execute();
    $shiftRes = $shiftStmt->get_result();
    $shift = $shiftRes->fetch_assoc();
    $shiftStmt->close();

    if (!$shift) {
        // Also check yesterday for overnight shifts that span into today
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        vlog("No shift today, checking yesterday ({$yesterday}) for overnight shift...");

        $shiftStmt2 = $conn->prepare("
            SELECT s.id AS shift_id, s.shift_date, s.shift_type, s.shift_start, s.shift_end
            FROM shifts s
            WHERE s.employee_id = ? AND s.shift_date = ?
              AND s.shift_end IS NOT NULL AND s.shift_start IS NOT NULL
              AND s.shift_end < s.shift_start
            ORDER BY s.shift_start ASC
            LIMIT 1
        ");
        if ($shiftStmt2) {
            $shiftStmt2->bind_param('is', $empDbId, $yesterday);
            $shiftStmt2->execute();
            $shiftRes2 = $shiftStmt2->get_result();
            $shift = $shiftRes2->fetch_assoc();
            $shiftStmt2->close();
        }

        if (!$shift) {
            vlog("No shift assigned for {$empCode} on {$today} or overnight from {$yesterday}");
            jsonOut([
                'success' => false,
                'error'   => "No shift assigned for today ({$today}). Contact your schedule officer.",
                'code'    => 'no_shift'
            ]);
        }
    }

    $shiftId    = (int)$shift['shift_id'];
    $shiftStart = $shift['shift_start'];
    $shiftEnd   = $shift['shift_end'];
    $shiftType  = $shift['shift_type'];
    $shiftDate  = $shift['shift_date'];

    vlog("Shift found: id={$shiftId}, date={$shiftDate}, type={$shiftType}, start={$shiftStart}, end={$shiftEnd}");

    // === Determine: Clock In or Clock Out ===
    // Find the latest open attendance record for this employee with this shift
    $attStmt = $conn->prepare("
        SELECT id, time_in, time_out
        FROM attendance
        WHERE employee_id = ? AND shift_id = ? AND time_out IS NULL
        ORDER BY time_in DESC
        LIMIT 1
    ");
    if (!$attStmt) {
        vlog("Attendance query prepare failed: " . $conn->error);
        jsonOut(['success' => false, 'error' => 'Database error (attendance lookup)'], 500);
    }
    $attStmt->bind_param('si', $empCode, $shiftId);
    $attStmt->execute();
    $attRes = $attStmt->get_result();
    $openRecord = $attRes->fetch_assoc();
    $attStmt->close();

    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

    vlog("Open attendance record: " . ($openRecord ? "id={$openRecord['id']}, time_in={$openRecord['time_in']}" : 'NONE (will clock in)'));

    if ($openRecord === null) {
        // ============================
        // CLOCK IN
        // ============================
        vlog("=== CLOCK IN ===");

        $insStmt = $conn->prepare("
            INSERT INTO attendance (employee_id, date, time_in, status, ip_in, method, shift_id, created_at)
            VALUES (?, ?, ?, 'Present', ?, 'face', ?, NOW())
        ");
        if (!$insStmt) {
            vlog("Insert attendance prepare failed: " . $conn->error);
            jsonOut(['success' => false, 'error' => 'Database error (clock in prepare)'], 500);
        }
        $insStmt->bind_param('ssssi', $empCode, $today, $now, $clientIp, $shiftId);
        if (!$insStmt->execute()) {
            vlog("Insert attendance execute failed: " . $insStmt->error);
            jsonOut(['success' => false, 'error' => 'Failed to record clock in'], 500);
        }
        $newAttId = $insStmt->insert_id;
        $insStmt->close();

        vlog("Clock IN recorded: attendance_id={$newAttId}");

        // Log to schedule_logs
        $logDetails = json_encode([
            'score'    => $bestDist,
            'best'     => $bestMatch,
            'second'   => $secondMatch,
            'shift_id' => $shiftId,
            'classification' => [
                'on_time' => null,
                'late'    => false,
            ]
        ], JSON_UNESCAPED_UNICODE);
        $logAction      = 'Time In';
        $logPerformedBy = $_SESSION['username'] ?? 'system';

        $logStmt = $conn->prepare("
            INSERT INTO schedule_logs (employee_id, action, action_time, status, performed_by, details)
            VALUES (?, ?, NOW(), ?, ?, ?)
        ");
        if ($logStmt) {
            $logStmt->bind_param('issss', $empDbId, $logAction, $logAction, $logPerformedBy, $logDetails);
            if (!$logStmt->execute()) {
                vlog("schedule_logs insert failed: " . $logStmt->error);
            }
            $logStmt->close();
        } else {
            vlog("schedule_logs prepare failed: " . $conn->error);
        }

        jsonOut([
            'success'     => true,
            'action'      => 'Clock In',
            'employee'    => $empFullname,
            'employee_id' => $empCode,
            'shift'       => $shiftType,
            'time'        => $now,
            'is_early'    => false,
            'message'     => "Welcome, {$empFullname}! Clocked in successfully."
        ]);

    } else {
        // ============================
        // CLOCK OUT
        // ============================
        vlog("=== CLOCK OUT ===");

        $attendanceId = (int)$openRecord['id'];

        // Check for early time-out
        $isEarly     = false;
        $forHrReview = false;

        if (!empty($shiftEnd)) {
            // Calculate full shift end datetime
            $shiftEndFull = $shiftDate . ' ' . $shiftEnd;

            // Handle overnight shifts: if shift_end <= shift_start, end is next day
            if (!empty($shiftStart) && $shiftEnd <= $shiftStart) {
                $shiftEndFull = date('Y-m-d', strtotime($shiftDate . ' +1 day')) . ' ' . $shiftEnd;
            }

            vlog("Shift end datetime: {$shiftEndFull}, Current: {$now}");

            if (strtotime($now) < strtotime($shiftEndFull)) {
                $isEarly     = true;
                $forHrReview = true;
                vlog("EARLY clock-out detected");
            }
        } else {
            vlog("No shift_end defined, skipping early check");
        }

        // If early, require a reason
        if ($isEarly && (empty($earlyOutReason) || strlen($earlyOutReason) < 2)) {
            vlog("Early clock-out attempted without reason for {$empCode}");
            jsonOut([
                'success'       => false,
                'error'         => 'You are clocking out before your shift ends. Please provide a reason.',
                'code'          => 'early_out_reason_required',
                'shift_end'     => $shiftEnd,
                'current_time'  => $now,
                'employee'      => $empFullname,
                'employee_id'   => $empCode
            ]);
        }

        // Prepare the update values
        $earlyInt  = $isEarly ? 1 : 0;
        $reviewInt = $forHrReview ? 1 : 0;
        $reasonVal = ($isEarly && !empty($earlyOutReason)) ? $earlyOutReason : '';

        vlog("Updating attendance id={$attendanceId}: early={$earlyInt}, reason=" . ($reasonVal ?: 'N/A'));

        // Update the attendance record
        $updSql = "UPDATE attendance SET time_out = ?, ip_out = ?, early_out_reason = ?, is_early_out = ?, for_hr_review = ? WHERE id = ?";
        $updStmt = $conn->prepare($updSql);
        if (!$updStmt) {
            vlog("Update attendance prepare failed: " . $conn->error . " SQL: " . $updSql);
            jsonOut(['success' => false, 'error' => 'Database error (clock out prepare)'], 500);
        }

        // All strings and integers properly typed
        // s = time_out (string datetime)
        // s = ip_out (string)
        // s = early_out_reason (string, can be empty)
        // i = is_early_out (int 0 or 1)
        // i = for_hr_review (int 0 or 1)
        // i = id (int)
        $updStmt->bind_param('sssiii', $now, $clientIp, $reasonVal, $earlyInt, $reviewInt, $attendanceId);

        if (!$updStmt->execute()) {
            vlog("Update attendance execute failed: " . $updStmt->error);
            jsonOut(['success' => false, 'error' => 'Failed to record clock out'], 500);
        }

        $affectedRows = $updStmt->affected_rows;
        $updStmt->close();

        vlog("Clock OUT recorded: attendance_id={$attendanceId}, affected_rows={$affectedRows}");

        // Log to schedule_logs
        $logDetails = json_encode([
            'score'    => $bestDist,
            'shift_id' => $shiftId,
            'early'    => $earlyInt,
            'reason'   => $reasonVal ?: null
        ], JSON_UNESCAPED_UNICODE);
        $logAction      = $isEarly ? 'Time Out (Early)' : 'Time Out';
        $logPerformedBy = $_SESSION['username'] ?? 'system';

        $logStmt = $conn->prepare("
            INSERT INTO schedule_logs (employee_id, action, action_time, status, performed_by, details)
            VALUES (?, ?, NOW(), ?, ?, ?)
        ");
        if ($logStmt) {
            $logStmt->bind_param('issss', $empDbId, $logAction, $logAction, $logPerformedBy, $logDetails);
            if (!$logStmt->execute()) {
                vlog("schedule_logs insert failed: " . $logStmt->error);
            }
            $logStmt->close();
        } else {
            vlog("schedule_logs prepare failed: " . $conn->error);
        }

        $msg = $isEarly
            ? "Clocked out early. Reason recorded for HR review."
            : "Clocked out successfully. Goodbye, {$empFullname}!";

        jsonOut([
            'success'     => true,
            'action'      => $isEarly ? 'Clock Out (Early)' : 'Clock Out',
            'employee'    => $empFullname,
            'employee_id' => $empCode,
            'shift'       => $shiftType,
            'time'        => $now,
            'is_early'    => $isEarly,
            'message'     => $msg
        ]);
    }

} catch (Exception $e) {
    vlog("EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    jsonOut(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
} catch (Error $e) {
    vlog("FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    jsonOut(['success' => false, 'error' => 'Server fatal error: ' . $e->getMessage()], 500);
}
?>