<?php
// filepath: admin/verify_attendance.php
// Verify attendance endpoint — corrected shift-detection and stronger template handling.
// NEW: Validates no duplicate clock in/out same day and blocks if approved leave exists.
//
// Usage: POST JSON { descriptor: [...], image?: dataurl, early_out_reason?: "..." }
// Returns JSON. Client handles { require_early_out_reason: true } by re-sending with early_out_reason.

declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

@ini_set('display_errors', '0');
ob_start();

$LOG = __DIR__ . '/verify_debug.log';
function vlog($m) {
    global $LOG;
    @file_put_contents($LOG, '['.date('Y-m-d H:i:s').'] '.(is_string($m)?$m:print_r($m,true)).PHP_EOL, FILE_APPEND|LOCK_EX);
}

try {
    // ===== COMPANY NETWORK (IP-BASED) SECURITY =====
    // Only allow clock in/out from the company network
    $ALLOWED_IPS = ['2406:2d40:94b4:1610:f58c:f27:e24:8a2d'];

    function getClientPublicIP() {
        // Check forwarding headers (reverse proxy / load balancer)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            return $ips[0];
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return trim($_SERVER['HTTP_X_REAL_IP']);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    $clientIP = getClientPublicIP();
    vlog("Attendance attempt from IP: {$clientIP}");

    if (!in_array($clientIP, $ALLOWED_IPS, true)) {
        vlog("BLOCKED: IP {$clientIP} is not in allowed list: " . implode(', ', $ALLOWED_IPS));
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Clock in/out is only allowed when connected to the company network (Wi-Fi). Your current IP (' . $clientIP . ') is not authorized.',
            'code'    => 'network_restricted',
            'ip'      => $clientIP
        ]);
        exit;
    }
    vlog("IP check passed: {$clientIP}");

    // read/validate input
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input) || !isset($input['descriptor']) || !is_array($input['descriptor'])) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Invalid request: missing descriptor']);
        exit;
    }
    $probe = array_map('floatval', $input['descriptor']);
    if (count($probe) === 0) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Empty descriptor']); exit; }
    $imageData = isset($input['image']) ? $input['image'] : null;
    $earlyOutReason = isset($input['early_out_reason']) ? trim((string)$input['early_out_reason']) : null;

    require_once(__DIR__ . '/../connection.php');
    if (!isset($conn) || !($conn instanceof mysqli)) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'Server DB config error']); exit; }

    // helpers
    function euclidean_distance(array $a, array $b) {
        if (count($a) !== count($b)) return INF;
        $sum = 0.0;
        for ($i = 0, $n = count($a); $i < $n; $i++) {
            $d = $a[$i] - $b[$i];
            $sum += $d * $d;
        }
        return sqrt($sum);
    }
    function db_has_column(mysqli $conn, string $table, string $col): bool {
        $st = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        if (!$st) return false;
        $st->bind_param('ss', $table, $col);
        $st->execute();
        $res = $st->get_result();
        $exists = (bool)$res->fetch_row();
        $st->close();
        return $exists;
    }
    function decrypt_payload(string $payload_b64, $keyBin) {
        if (!$keyBin) return false;
        $parts = explode(':', $payload_b64);
        if (count($parts) !== 2) return false;
        $iv = base64_decode($parts[0]); $cipher = base64_decode($parts[1]);
        if ($iv === false || $cipher === false) return false;
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $keyBin, OPENSSL_RAW_DATA, $iv);
        return $plain === false ? false : $plain;
    }

    // Load encryption key if present
    $ENCRYPTION_KEY_BIN = null;
    if (file_exists(__DIR__ . '/../secret.php')) {
        @require_once(__DIR__ . '/../secret.php');
        if (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '') $ENCRYPTION_KEY_BIN = hash('sha256', ENCRYPTION_KEY, true);
    }

    // detect template columns
    $has_enc_col = db_has_column($conn, 'employees', 'face_template_encrypted');
    $has_plain_col = db_has_column($conn, 'employees', 'face_embedding');

    if ($has_enc_col && !$has_plain_col && $ENCRYPTION_KEY_BIN === null) {
        vlog("Encrypted templates exist but ENCRYPTION_KEY missing");
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'Server misconfiguration: encryption key required to verify templates. Contact administrator.']);
        exit;
    }

    // fetch employees that are marked enrolled (face_enrolled=1)
    $cols = ['id','employee_id','fullname'];
    if ($has_enc_col) $cols[] = 'face_template_encrypted';
    if ($has_plain_col) $cols[] = 'face_embedding';
    $sql = 'SELECT '.implode(',', $cols)." FROM employees WHERE face_enrolled = 1";
    $res = $conn->query($sql);
    if (!$res) { vlog("Failed to read employees: ".$conn->error); echo json_encode(['success'=>false,'error'=>'Server error']); exit; }

    $templates = [];
    while ($r = $res->fetch_assoc()) {
        $tpl = null;
        if ($has_enc_col && isset($r['face_template_encrypted']) && $r['face_template_encrypted'] !== '') {
            if ($ENCRYPTION_KEY_BIN !== null) {
                $plain = decrypt_payload($r['face_template_encrypted'], $ENCRYPTION_KEY_BIN);
                if ($plain !== false) {
                    $decoded = json_decode($plain, true);
                    if (is_array($decoded)) $tpl = array_map('floatval', $decoded);
                } else {
                    vlog("Decryption failed for employee {$r['employee_id']} (db id {$r['id']})");
                }
            }
        }
        if ($tpl === null && $has_plain_col && isset($r['face_embedding']) && $r['face_embedding'] !== '') {
            $decoded2 = json_decode($r['face_embedding'], true);
            if (is_array($decoded2)) $tpl = array_map('floatval', $decoded2);
        }
        $templates[] = [
            'db_id' => (int)$r['id'],
            'employee_id' => $r['employee_id'],
            'fullname' => $r['fullname'],
            'template' => $tpl
        ];
    }
    $res->free();

    // ensure there are usable templates
    $usable = 0;
    foreach ($templates as $t) if (is_array($t['template'])) $usable++;
    if ($usable === 0) {
        vlog("No usable templates available (usable={$usable})");
        echo json_encode(['success'=>false,'error'=>'No enrolled/usable facial templates available for verification']);
        exit;
    }

    // compute distances and pick best/second
    $matches = [];
    foreach ($templates as $t) {
        if (!is_array($t['template']) || count($t['template']) !== count($probe)) {
            $matches[] = ['dist'=>INF,'employee_id'=>$t['employee_id'],'fullname'=>$t['fullname'],'db_id'=>$t['db_id']];
            continue;
        }
        $dist = euclidean_distance($probe, $t['template']);
        $matches[] = ['dist'=>$dist,'employee_id'=>$t['employee_id'],'fullname'=>$t['fullname'],'db_id'=>$t['db_id']];
    }
    usort($matches, function($a,$b){ return ($a['dist'] ?? INF) <=> ($b['dist'] ?? INF); });
    $best = $matches[0] ?? null;
    $second = $matches[1] ?? null;
    vlog("Top matches: ".json_encode(array_slice($matches,0,6)));

    // matching parameters
    $THRESH = 0.55; $MIN_MARGIN = 0.08;
    if (!isset($best['dist']) || $best['dist'] === INF) {
        echo json_encode(['success'=>false,'error'=>'No matching templates found']);
        exit;
    }
    if ($best['dist'] > $THRESH) {
        vlog("Low confidence best={$best['employee_id']} dist={$best['dist']}");
        echo json_encode(['success'=>false,'error'=>'No matching face (low confidence)','best'=>$best,'second'=>$second]);
        exit;
    }
    if (isset($second['dist']) && ($second['dist'] - $best['dist']) < $MIN_MARGIN) {
        vlog("Ambiguous match best={$best['employee_id']} dist={$best['dist']} second={$second['dist']}");
        echo json_encode(['success'=>false,'error'=>'Ambiguous face match (please retake)','best'=>$best,'second'=>$second]);
        exit;
    }

    // ensure matched employee exists and is enrolled
    $emp_code = $best['employee_id'];
    $stmtEmp = $conn->prepare("SELECT id, fullname, face_enrolled FROM employees WHERE employee_id = ? LIMIT 1");
    if (!$stmtEmp) { vlog("Emp select prepare failed: ".$conn->error); echo json_encode(['success'=>false,'error'=>'Server error']); exit; }
    $stmtEmp->bind_param('s', $emp_code);
    $stmtEmp->execute();
    $empRow = $stmtEmp->get_result()->fetch_assoc() ?: null;
    $stmtEmp->close();
    if (!$empRow) { echo json_encode(['success'=>false,'error'=>'Matched employee record not found']); exit; }
    if ((int)$empRow['face_enrolled'] !== 1) { echo json_encode(['success'=>false,'error'=>'Employee not enrolled for attendance (face not registered)']); exit; }
    $emp_db_id = (int)$empRow['id'];
    $emp_fullname = $empRow['fullname'];

    // ---------- SHIFT DETECTION (CORRECTED) ----------
    $now = new DateTime();
    $today = $now->format('Y-m-d');
    $shift_row = null;

    // (A) Always lookup shifts with where shifts.employee_id = employees.id (int FK)
    $sql = "SELECT id, shift_date, shift_type, shift_start, shift_end 
            FROM shifts 
            WHERE employee_id = ? AND shift_date = ? 
            LIMIT 1";
    $st = $conn->prepare($sql);
    if ($st) {
        $st->bind_param('is', $emp_db_id, $today);
        if ($st->execute()) {
            $res = $st->get_result();
            $shift_row = $res->fetch_assoc() ?: null;
            $res->free();
        } else {
            vlog("Shift lookup (by int id) execute failed: " . $st->error);
        }
        $st->close();
    } else {
        vlog("Shift lookup (by int id) prepare failed: " . $conn->error);
    }

    // (B) Night lookback: If still not found and morning, try yesterday, require shift type Night
    if (!$shift_row) {
        $hour = (int)$now->format('H');
        if ($hour <= 10) {
            $yesterday = (new DateTime('yesterday'))->format('Y-m-d');
            $st2 = $conn->prepare($sql);
            if ($st2) {
                $st2->bind_param('is', $emp_db_id, $yesterday);
                if ($st2->execute()) {
                    $r2 = $st2->get_result();
                    $tmp = $r2->fetch_assoc() ?: null;
                    $r2->free();
                    if ($tmp && strcasecmp((string)$tmp['shift_type'], 'Night') === 0) $shift_row = $tmp;
                }
                $st2->close();
            }
        }
    }

    if (!$shift_row) {
        vlog("No shift found for employee {$emp_code} (db {$emp_db_id}) on {$today}");
        echo json_encode(['success'=>false,'error'=>'You are not scheduled today','code'=>'not_scheduled']);
        exit;
    }

    $logical_shift_date = $shift_row['shift_date'];
    $shift_start = $shift_row['shift_start'] ?? null;
    $shift_end = $shift_row['shift_end'] ?? null;
    $shift_id = isset($shift_row['id']) ? (int)$shift_row['id'] : null;

    // ========== NEW VALIDATION 1: CHECK FOR APPROVED LEAVE ON THIS DATE ==========
    $stmtLeave = $conn->prepare("
        SELECT id FROM leave_requests 
        WHERE employee_id = ? 
        AND status = 'approved' 
        AND ? >= date_from 
        AND ? <= date_to 
        LIMIT 1
    ");
    if ($stmtLeave) {
        $stmtLeave->bind_param('iss', $emp_db_id, $logical_shift_date, $logical_shift_date);
        $stmtLeave->execute();
        $leaveRow = $stmtLeave->get_result()->fetch_assoc() ?: null;
        $stmtLeave->close();
        if ($leaveRow) {
            vlog("Employee {$emp_code} has approved leave on {$logical_shift_date}");
            echo json_encode(['success'=>false,'error'=>'You have an approved leave request for this date. Clock in/out is not permitted.','code'=>'approved_leave']);
            exit;
        }
    } else {
        vlog("Leave validation prepare failed: " . $conn->error);
    }

    // ---------- ATTENDANCE EXISTING ROW ----------
    $attendance_identifier = $emp_code;
    $attSelect = $conn->prepare("SELECT id, time_in, time_out, submitted_to_timesheet FROM attendance WHERE employee_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
    if (!$attSelect) { vlog("attSelect prepare failed: ".$conn->error); echo json_encode(['success'=>false,'error'=>'Server error']); exit; }
    $attSelect->bind_param('ss', $attendance_identifier, $logical_shift_date);
    $attSelect->execute();
    $attRow = $attSelect->get_result()->fetch_assoc() ?: null;
    $attSelect->close();

    if ($attRow && (int)$attRow['submitted_to_timesheet'] === 1) {
        echo json_encode(['success'=>false,'error'=>'Attendance locked (already submitted)']); exit;
    }

    // ========== NEW VALIDATION 2: CHECK FOR DUPLICATE CLOCK IN/OUT ON SAME DAY ==========
    $is_time_in = true;
    if ($attRow && !empty($attRow['time_in']) && empty($attRow['time_out'])) {
        $is_time_in = false;
    } else {
        $is_time_in = true;
    }

    // If trying to clock in but already has time_in and time_out on same day, reject (already clocked in/out)
    if ($is_time_in && $attRow && !empty($attRow['time_in']) && !empty($attRow['time_out'])) {
        vlog("Duplicate clock attempt: Employee {$emp_code} already has complete time in/out on {$logical_shift_date}");
        echo json_encode(['success'=>false,'error'=>'You have already completed your clock in/out for today. Only one entry per day is allowed.','code'=>'duplicate_clock']);
        exit;
    }

    // Standard duplicate checks
    if ($is_time_in && $attRow && !empty($attRow['time_in']) && empty($attRow['time_out'])) {
        echo json_encode(['success'=>false,'error'=>'Duplicate Time In (you already timed in)']); exit;
    }
    if (!$is_time_in && (!$attRow || empty($attRow['time_in']))) {
        echo json_encode(['success'=>false,'error'=>'Cannot Time Out without a Time In']); exit;
    }

    $now_str = (new DateTime())->format('Y-m-d H:i:s');
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? null;

    if ($is_time_in) {
        // insert attendance
        $cols = ['employee_id','date','time_in','method','created_at'];
        $placeholders = ['?','?','?','?','NOW()'];
        $params = [$attendance_identifier, $logical_shift_date, $now_str, 'face'];
        if (db_has_column($conn,'attendance','shift_id') && $shift_id !== null) {
            array_splice($cols,3,0,'shift_id');
            array_splice($placeholders,3,0,'?');
            array_splice($params,3,0,$shift_id);
        }
        $sqlIns = "INSERT INTO attendance (".implode(',',$cols).") VALUES (".implode(',',$placeholders).")";
        $stmtIns = $conn->prepare($sqlIns);
        if (!$stmtIns) { vlog("Insert prepare failed: ".$conn->error); echo json_encode(['success'=>false,'error'=>'Server error']); exit; }
        $types = '';
        foreach ($params as $p) { if (is_int($p)) $types .= 'i'; elseif (is_float($p)) $types .= 'd'; else $types .= 's'; }
        $bind = array_merge([$types], $params);
        $refs = []; foreach ($bind as $k => $v) $refs[$k] = &$bind[$k];
        call_user_func_array([$stmtIns,'bind_param'],$refs);
        if (!$stmtIns->execute()) { vlog("Insert execute failed: ".$stmtIns->error); $stmtIns->close(); echo json_encode(['success'=>false,'error'=>'DB error']); exit; }
        $attendance_id = $conn->insert_id;
        $stmtIns->close();
        $action = 'Time In';
    } else {
        // Time Out path
        $isEarly = 0;
        if (!empty($shift_end)) {
            $shift_end_dt = new DateTime($logical_shift_date . ' ' . $shift_end);
            if (!empty($shift_start) && $shift_end <= $shift_start) $shift_end_dt->modify('+1 day');
            $now_dt = new DateTime();
            if ($now_dt < $shift_end_dt) $isEarly = 1;
        }
        if ($isEarly && empty($earlyOutReason)) {
            echo json_encode(['success'=>false,'error'=>'Early Time Out requires reason','require_early_out_reason'=>true,'shift_end'=>$shift_end,'shift_date'=>$logical_shift_date]);
            exit;
        }
        $setParts = ['time_out = ?'];
        $params = [$now_str];
        if (db_has_column($conn,'attendance','method')) { $setParts[] = 'method = ?'; $params[] = 'face'; }
        if (db_has_column($conn,'attendance','ip_out')) { $setParts[] = 'ip_out = ?'; $params[] = $client_ip; }
        if ($isEarly) {
            if (db_has_column($conn,'attendance','early_out_reason')) { $setParts[] = 'early_out_reason = ?'; $params[] = $earlyOutReason; }
            if (db_has_column($conn,'attendance','is_early_out')) { $setParts[] = 'is_early_out = ?'; $params[] = 1; }
            if (db_has_column($conn,'attendance','for_hr_review')) { $setParts[] = 'for_hr_review = ?'; $params[] = 1; }
        }
        $params[] = (int)$attRow['id'];
        $sqlUpd = "UPDATE attendance SET ".implode(', ',$setParts)." WHERE id = ?";
        $stmtUpd = $conn->prepare($sqlUpd);
        if (!$stmtUpd) { vlog("Update prepare failed: ".$conn->error); echo json_encode(['success'=>false,'error'=>'Server error']); exit; }
        $types = '';
        foreach ($params as $p) { if (is_int($p)) $types .= 'i'; elseif (is_float($p)) $types .= 'd'; else $types .= 's'; }
        $bind = array_merge([$types], $params);
        $refs = []; foreach ($bind as $k => $v) $refs[$k] = &$bind[$k];
        call_user_func_array([$stmtUpd,'bind_param'],$refs);
        if (!$stmtUpd->execute()) { vlog("Update execute failed: ".$stmtUpd->error); $stmtUpd->close(); echo json_encode(['success'=>false,'error'=>'DB error']); exit; }
        $attendance_id = (int)$attRow['id'];
        $stmtUpd->close();
        $action = $isEarly ? 'Time Out (Early)' : 'Time Out';
    }

    // audit
    try {
        if ($stmtLog = $conn->prepare("INSERT INTO schedule_logs (employee_id, action, action_time, status, performed_by, details) VALUES (?, ?, NOW(), ?, ?, ?)")) {
            $performed_by = $_SESSION['username'] ?? $emp_code;
            $status = $action;
            $details = json_encode(['score'=>$best['dist'],'shift_id'=>$shift_id,'early'=>isset($isEarly)?(int)$isEarly:0,'reason'=>$earlyOutReason], JSON_UNESCAPED_UNICODE);
            $stmtLog->bind_param('issss', $emp_db_id, $action, $status, $performed_by, $details);
            $stmtLog->execute();
            $stmtLog->close();
        }
    } catch (Exception $ex) { vlog("schedule_logs insert failed: ".$ex->getMessage()); }

    // optional image save
    if ($imageData && preg_match('/^data:image\/(\w+);base64,/', $imageData)) {
        $bin = base64_decode(substr($imageData, strpos($imageData, ',') + 1));
        if ($bin !== false) {
            $dir = __DIR__ . '/../assets/images/faces';
            if (!file_exists($dir)) @mkdir($dir, 0755, true);
            $safe = preg_replace('/[^A-Za-z0-9_\\-]/','_',$emp_code);
            $fname = "verify_{$safe}_" . time() . ".jpg";
            @file_put_contents($dir . '/' . $fname, $bin);
        }
    }

    // clear output buffer and respond
    $buf = '';
    if (ob_get_length() !== false && ob_get_length() > 0) $buf = ob_get_clean();
    if (!empty($buf)) vlog("Unexpected output before JSON response: ".substr($buf,0,2000));

    echo json_encode([
        'success'=>true,
        'employee'=>$emp_fullname,
        'employee_id'=>$emp_code,
        'action'=>$action,
        'action_time'=>$now_str,
        'attendance_id'=>$attendance_id,
        'score'=>$best['dist']
    ]);
    exit;

} catch (Throwable $e) {
    $buf = '';
    if (ob_get_length() !== false && ob_get_length() > 0) $buf = ob_get_clean();
    if (!empty($buf)) vlog("Unexpected output in exception: ".substr($buf,0,2000));
    vlog("verify exception: ".$e->getMessage()."\n".$e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Server error (see logs)']);
    exit;
}
?>