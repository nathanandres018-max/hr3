<?php
// filepath: admin/attendance_stats_fetch.php
// Returns aggregated attendance statistics and per-employee summaries for enrolled employees.
// Supports month=YYYY-MM or from/to date range, and optional emp filter (enforced enrolled).
// Uses attendance_daily_summary when present for efficient aggregation; falls back to attendance rows.
// Layout/CSS/topbar not relevant for this endpoint — it returns JSON only.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$LOG = __DIR__ . '/attendance_stats_fetch.log';
function log_debug($m) {
    global $LOG;
    @file_put_contents($LOG, '['.date('Y-m-d H:i:s').'] '.(is_string($m)?$m:print_r($m,true)).PHP_EOL, FILE_APPEND|LOCK_EX);
}

// capture stray output
ob_start();
ini_set('display_errors','0');

require_once(__DIR__ . '/../connection.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    $buf = ob_get_clean();
    if (!empty($buf)) log_debug("Unexpected output: ".substr($buf,0,2000));
    http_response_code(500);
    echo json_encode(['error'=>'DB error']);
    exit();
}

// helper
function table_exists(mysqli $conn, string $table): bool {
    $st = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    if (!$st) return false;
    $st->bind_param('s', $table);
    $st->execute();
    $res = $st->get_result();
    $exists = (bool)$res->fetch_row();
    $st->close();
    return $exists;
}

// parse params
$emp = isset($_GET['emp']) && trim($_GET['emp']) !== '' ? trim($_GET['emp']) : null;
$month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : null;
$from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : null;
$to = isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']) ? $_GET['to'] : null;

// compute date range when month provided
if ($month && !$from && !$to) {
    $start = $month . '-01';
    $dt = DateTime::createFromFormat('Y-m-d', $start);
    if ($dt) {
        $from = $start;
        $to = $dt->format('Y-m-t');
    } else { $from = $to = null; }
}
if (($from && !$to) || (!$from && $to)) {
    http_response_code(400);
    echo json_encode(['error'=>'Both from and to must be provided for range queries']);
    exit();
}

// validate emp is enrolled if provided
if ($emp !== null) {
    $chk = $conn->prepare("SELECT 1 FROM employees WHERE employee_id = ? AND face_enrolled = 1 LIMIT 1");
    if (!$chk) { log_debug("Prepare failed emp check: ".$conn->error); http_response_code(500); echo json_encode(['error'=>'DB error']); exit(); }
    $chk->bind_param('s', $emp);
    $chk->execute();
    $res = $chk->get_result();
    $ok = (bool)$res->fetch_row();
    $chk->close();
    if (!$ok) {
        echo json_encode(['employees'=>[], 'top_employees'=>[], 'day_labels'=>[], 'day_hours_seconds'=>[], 'summary'=>[
            'enrolled_employees'=>0,'total_working_days'=>0,'total_work_seconds'=>0,'total_late_entries'=>0,'total_early_timeouts'=>0,'total_missing_timeouts'=>0
        ]]);
        exit();
    }
}

// Determine whether attendance_daily_summary exists
$has_summary = table_exists($conn, 'attendance_daily_summary');

// Build base conditions
$whereParts = ["e.face_enrolled = 1"];
$params = []; $types = '';
if ($emp !== null) { $whereParts[] = "e.employee_id = ?"; $params[] = $emp; $types .= 's'; }
if ($from !== null && $to !== null) { $whereParts[] = "a.date BETWEEN ? AND ?"; $params[] = $from; $params[] = $to; $types .= 'ss'; }
$where = implode(' AND ', $whereParts);

// Summary initialize
$summary = [
    'enrolled_employees' => 0,
    'total_working_days' => 0,
    'total_work_seconds' => 0,
    'total_late_entries' => 0,
    'total_early_timeouts' => 0,
    'total_missing_timeouts' => 0
];

// enrolled count
try {
    if ($emp !== null) {
        $summary['enrolled_employees'] = 1;
    } else {
        $st = $conn->prepare("SELECT COUNT(*) FROM employees WHERE face_enrolled = 1");
        $st->execute();
        $res = $st->get_result();
        $summary['enrolled_employees'] = (int)($res->fetch_row()[0] ?? 0);
        $st->close();
    }
} catch (Exception $e) { log_debug("enrolled count err: ".$e->getMessage()); }

// total days & seconds
if ($has_summary) {
    $sql = "SELECT COUNT(DISTINCT s.date) AS total_days, SUM(s.work_seconds) AS total_seconds
            FROM attendance_daily_summary s
            JOIN employees e ON e.employee_id = s.employee_id AND e.face_enrolled = 1";
    $conds=[]; $p=[]; $t='';
    if ($emp !== null) { $conds[] = "e.employee_id = ?"; $p[] = $emp; $t .= 's'; }
    if ($from !== null && $to !== null) { $conds[] = "s.date BETWEEN ? AND ?"; $p[] = $from; $p[] = $to; $t .= 'ss'; }
    if (!empty($conds)) $sql .= " WHERE " . implode(' AND ', $conds);
    $st = $conn->prepare($sql);
    if ($st) {
        if (count($p)>0) {
            $bind = array_merge([$t], $p);
            $refs=[]; foreach ($bind as $k=>$v) $refs[$k] = &$bind[$k];
            call_user_func_array([$st,'bind_param'],$refs);
        }
        $st->execute();
        $res = $st->get_result();
        $r = $res->fetch_assoc() ?: [];
        $summary['total_working_days'] = (int)($r['total_days'] ?? 0);
        $summary['total_work_seconds'] = (int)($r['total_seconds'] ?? 0);
        $st->close();
    }
} else {
    $sql = "SELECT COUNT(DISTINCT a.date) AS total_days, SUM(COALESCE(TIMESTAMPDIFF(SECOND,a.time_in,a.time_out),0)) AS total_seconds
            FROM attendance a
            JOIN employees e ON e.employee_id = a.employee_id AND e.face_enrolled = 1";
    $conds=[]; $p=[]; $t='';
    if ($emp !== null) { $conds[] = "e.employee_id = ?"; $p[] = $emp; $t .= 's'; }
    if ($from !== null && $to !== null) { $conds[] = "a.date BETWEEN ? AND ?"; $p[] = $from; $p[] = $to; $t .= 'ss'; }
    if (!empty($conds)) $sql .= " WHERE " . implode(' AND ', $conds);
    $st = $conn->prepare($sql);
    if ($st) {
        if (count($p)>0) {
            $bind = array_merge([$t], $p);
            $refs=[]; foreach ($bind as $k=>$v) $refs[$k] = &$bind[$k];
            call_user_func_array([$st,'bind_param'],$refs);
        }
        $st->execute();
        $res = $st->get_result();
        $r = $res->fetch_assoc() ?: [];
        $summary['total_working_days'] = (int)($r['total_days'] ?? 0);
        $summary['total_work_seconds'] = (int)($r['total_seconds'] ?? 0);
        $st->close();
    }
}

// late/early/missing
$sql = "SELECT 
          SUM(CASE WHEN s.shift_start IS NOT NULL AND a.time_in IS NOT NULL AND a.time_in > CONCAT(a.date,' ',s.shift_start) THEN 1 ELSE 0 END) AS late_count,
          SUM(CASE WHEN s.shift_end IS NOT NULL AND a.time_out IS NOT NULL AND a.time_out < CONCAT(a.date,' ',s.shift_end) THEN 1 ELSE 0 END) AS early_count,
          SUM(CASE WHEN a.time_out IS NULL THEN 1 ELSE 0 END) AS missing_count
        FROM attendance a
        JOIN employees e ON e.employee_id = a.employee_id AND e.face_enrolled = 1
        LEFT JOIN shifts s ON s.employee_id = e.id AND s.shift_date = a.date";
$conds=[];$p=[];$t='';
if ($emp !== null) { $conds[] = "e.employee_id = ?"; $p[] = $emp; $t .= 's'; }
if ($from !== null && $to !== null) { $conds[] = "a.date BETWEEN ? AND ?"; $p[] = $from; $p[] = $to; $t .= 'ss'; }
if (!empty($conds)) $sql .= " WHERE " . implode(' AND ', $conds);
$st = $conn->prepare($sql);
if ($st) {
    if (count($p)>0) {
        $bind = array_merge([$t], $p);
        $refs=[]; foreach ($bind as $k=>$v) $refs[$k] = &$bind[$k];
        call_user_func_array([$st,'bind_param'],$refs);
    }
    $st->execute();
    $res = $st->get_result();
    $r = $res->fetch_assoc() ?: [];
    $summary['total_late_entries'] = (int)($r['late_count'] ?? 0);
    $summary['total_early_timeouts'] = (int)($r['early_count'] ?? 0);
    $summary['total_missing_timeouts'] = (int)($r['missing_count'] ?? 0);
    $st->close();
}

// per-day series
$day_labels = []; $day_seconds = [];
if ($from !== null && $to !== null) {
    $start = new DateTime($from);
    $end = new DateTime($to);
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start,$interval,$end->modify('+1 day'));
    $dates = [];
    foreach ($period as $dt) $dates[] = $dt->format('Y-m-d');

    if ($has_summary) {
        $sql = "SELECT s.date, SUM(s.work_seconds) AS total_seconds
                FROM attendance_daily_summary s
                JOIN employees e ON e.employee_id = s.employee_id AND e.face_enrolled = 1
                WHERE s.date BETWEEN ? AND ?
                GROUP BY s.date";
        $st = $conn->prepare($sql);
        $st->bind_param('ss', $from, $to);
        $st->execute();
        $res = $st->get_result();
        $map = [];
        while ($row = $res->fetch_assoc()) $map[$row['date']] = (int)$row['total_seconds'];
        $st->close();
    } else {
        $sql = "SELECT a.date, SUM(COALESCE(TIMESTAMPDIFF(SECOND,a.time_in,a.time_out),0)) AS total_seconds
                FROM attendance a
                JOIN employees e ON e.employee_id = a.employee_id AND e.face_enrolled = 1
                WHERE a.date BETWEEN ? AND ?
                GROUP BY a.date";
        $st = $conn->prepare($sql);
        $st->bind_param('ss', $from, $to);
        $st->execute();
        $res = $st->get_result();
        $map = [];
        while ($row = $res->fetch_assoc()) $map[$row['date']] = (int)$row['total_seconds'];
        $st->close();
    }
    foreach ($dates as $d) {
        $day_labels[] = $d;
        $day_seconds[] = $map[$d] ?? 0;
    }
}

// per-employee aggregates
$base = [];
if ($has_summary) {
    $sql = "SELECT e.employee_id, e.fullname, COUNT(s.date) AS days_present, COALESCE(SUM(s.work_seconds),0) AS total_seconds,
                   COALESCE(AVG(s.work_seconds),0) AS avg_seconds
            FROM attendance_daily_summary s
            JOIN employees e ON e.employee_id = s.employee_id AND e.face_enrolled = 1";
    $conds=[]; $p=[]; $t='';
    if ($emp !== null) { $conds[] = "e.employee_id = ?"; $p[] = $emp; $t .= 's'; }
    if ($from !== null && $to !== null) { $conds[] = "s.date BETWEEN ? AND ?"; $p[] = $from; $p[] = $to; $t .= 'ss'; }
    if (!empty($conds)) $sql .= " WHERE " . implode(' AND ', $conds);
    $sql .= " GROUP BY e.employee_id, e.fullname ORDER BY SUM(s.work_seconds) DESC LIMIT 200";
    $st = $conn->prepare($sql);
    if ($st) {
        if (count($p)>0) {
            $bind = array_merge([$t], $p);
            $refs=[]; foreach ($bind as $k=>$v) $refs[$k] = &$bind[$k];
            call_user_func_array([$st,'bind_param'],$refs);
        }
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $base[$r['employee_id']] = [
                'employee_id' => $r['employee_id'],
                'fullname' => $r['fullname'],
                'days_present' => (int)$r['days_present'],
                'total_work_seconds' => (int)$r['total_seconds'],
                'avg_work_seconds' => (int)$r['avg_seconds'],
                'late_count' => 0,
                'early_count' => 0,
                'missing_count' => 0
            ];
        }
        $st->close();
    }
} else {
    $sql = "SELECT e.employee_id, e.fullname,
                   COUNT(DISTINCT a.date) AS days_present,
                   SUM(COALESCE(TIMESTAMPDIFF(SECOND,a.time_in,a.time_out),0)) AS total_seconds,
                   AVG(COALESCE(TIMESTAMPDIFF(SECOND,a.time_in,a.time_out),0)) AS avg_seconds
            FROM attendance a
            JOIN employees e ON e.employee_id = a.employee_id AND e.face_enrolled = 1";
    $conds=[];$p=[];$t='';
    if ($emp !== null) { $conds[] = "e.employee_id = ?"; $p[] = $emp; $t .= 's'; }
    if ($from !== null && $to !== null) { $conds[] = "a.date BETWEEN ? AND ?"; $p[] = $from; $p[] = $to; $t .= 'ss'; }
    if (!empty($conds)) $sql .= " WHERE " . implode(' AND ', $conds);
    $sql .= " GROUP BY e.employee_id, e.fullname ORDER BY SUM(COALESCE(TIMESTAMPDIFF(SECOND,a.time_in,a.time_out),0)) DESC LIMIT 200";
    $st = $conn->prepare($sql);
    if ($st) {
        if (count($p)>0) {
            $bind = array_merge([$t], $p);
            $refs=[]; foreach ($bind as $k=>$v) $refs[$k] = &$bind[$k];
            call_user_func_array([$st,'bind_param'],$refs);
        }
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $base[$r['employee_id']] = [
                'employee_id' => $r['employee_id'],
                'fullname' => $r['fullname'],
                'days_present' => (int)$r['days_present'],
                'total_work_seconds' => (int)$r['total_seconds'],
                'avg_work_seconds' => (int)$r['avg_seconds'],
                'late_count' => 0,
                'early_count' => 0,
                'missing_count' => 0
            ];
        }
        $st->close();
    }
}

// compute per-employee late/early/missing counts
$sql = "SELECT e.employee_id,
               SUM(CASE WHEN s.shift_start IS NOT NULL AND a.time_in IS NOT NULL AND a.time_in > CONCAT(a.date,' ',s.shift_start) THEN 1 ELSE 0 END) AS late_count,
               SUM(CASE WHEN s.shift_end IS NOT NULL AND a.time_out IS NOT NULL AND a.time_out < CONCAT(a.date,' ',s.shift_end) THEN 1 ELSE 0 END) AS early_count,
               SUM(CASE WHEN a.time_out IS NULL THEN 1 ELSE 0 END) AS missing_count
        FROM attendance a
        JOIN employees e ON e.employee_id = a.employee_id AND e.face_enrolled = 1
        LEFT JOIN shifts s ON s.employee_id = e.id AND s.shift_date = a.date";
$conds=[];$p=[];$t='';
if ($emp !== null) { $conds[] = "e.employee_id = ?"; $p[] = $emp; $t .= 's'; }
if ($from !== null && $to !== null) { $conds[] = "a.date BETWEEN ? AND ?"; $p[] = $from; $p[] = $to; $t .= 'ss'; }
if (!empty($conds)) $sql .= " WHERE " . implode(' AND ', $conds);
$sql .= " GROUP BY e.employee_id";
$st = $conn->prepare($sql);
if ($st) {
    if (count($p)>0) {
        $bind = array_merge([$t], $p);
        $refs=[]; foreach ($bind as $k=>$v) $refs[$k] = &$bind[$k];
        call_user_func_array([$st,'bind_param'],$refs);
    }
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) {
        $eid = $r['employee_id'];
        if (!isset($base[$eid])) {
            $base[$eid] = [
                'employee_id'=>$eid,
                'fullname'=>'',
                'days_present'=>0,
                'total_work_seconds'=>0,
                'avg_work_seconds'=>0,
                'late_count'=>0,'early_count'=>0,'missing_count'=>0
            ];
        }
        $base[$eid]['late_count'] = (int)($r['late_count'] ?? 0);
        $base[$eid]['early_count'] = (int)($r['early_count'] ?? 0);
        $base[$eid]['missing_count'] = (int)($r['missing_count'] ?? 0);
    }
    $st->close();
}

// finalize arrays
$employees = array_values($base);
usort($employees, function($a,$b){ return ($b['total_work_seconds'] ?? 0) <=> ($a['total_work_seconds'] ?? 0); });
$top_employees = array_slice($employees, 0, 10);

$response = [
    'summary' => $summary,
    'day_labels' => $day_labels,
    'day_hours_seconds' => $day_seconds,
    'employees' => $employees,
    'top_employees' => $top_employees
];

$buf = '';
if (ob_get_length() !== false && ob_get_length() > 0) $buf = ob_get_clean();
if (!empty($buf)) log_debug("Unexpected output: ".substr($buf,0,2000));

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit();