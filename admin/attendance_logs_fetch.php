<?php
// filepath: admin/attendance_logs_fetch.php
// AJAX endpoint for Attendance Logs page with pagination, sorting, and summary statistics.
// UPDATED: supports bi-monthly payroll cut-off extraction feature.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$LOG = __DIR__ . '/attendance_logs_fetch.log';
function log_debug($m) {
    global $LOG;
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . (is_string($m) ? $m : print_r($m, true)) . PHP_EOL;
    @file_put_contents($LOG, $entry, FILE_APPEND | LOCK_EX);
}

ob_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once(__DIR__ . '/../connection.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    $buf = ob_get_clean();
    if (!empty($buf)) log_debug("Unexpected output before DB error: " . substr($buf,0,2000));
    http_response_code(500);
    echo json_encode(['error' => 'Server DB configuration error']);
    exit();
}

// Input parameters
$emp = isset($_GET['emp']) && trim($_GET['emp']) !== '' ? trim($_GET['emp']) : null;
$month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : null;
$cutoff_start = isset($_GET['cutoff_start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['cutoff_start']) ? $_GET['cutoff_start'] : null;
$cutoff_end   = isset($_GET['cutoff_end']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['cutoff_end']) ? $_GET['cutoff_end'] : null;

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = isset($_GET['per_page']) ? max(1, min(500, (int)$_GET['per_page'])) : 25;
$sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'date';
$sort_dir = (isset($_GET['sort_dir']) && strtolower($_GET['sort_dir']) === 'asc') ? 'ASC' : 'DESC';

// Validate employee if provided (must be enrolled)
if ($emp !== null) {
    $chk = $conn->prepare("SELECT 1 FROM employees WHERE employee_id = ? AND face_enrolled = 1 LIMIT 1");
    if (!$chk) { log_debug("Prepare failed: " . $conn->error); http_response_code(500); echo json_encode(['error'=>'DB error']); exit(); }
    $chk->bind_param('s', $emp);
    $chk->execute();
    $res = $chk->get_result();
    $found = (bool)$res->fetch_row();
    $chk->close();
    if (!$found) {
        ob_end_clean();
        echo json_encode(['rows'=>[], 'total_rows'=>0, 'summary'=>['total_working_days'=>0,'total_late_entries'=>0,'total_early_timeouts'=>0,'total_missing_timeouts'=>0,'total_work_seconds'=>0]]);
        exit();
    }
}

// Build date range for month & cutoff
$startDate = null; $endDate = null;
if ($month) {
    $startDate = $month . '-01';
    $dt = DateTime::createFromFormat('Y-m-d', $startDate);
    if ($dt !== false) $endDate = $dt->format('Y-m-t');
    else { $startDate = null; $endDate = null; }
}
if ($cutoff_start && $cutoff_end) {
    // Use cutoff range instead of month if provided
    $startDate = $cutoff_start;
    $endDate = $cutoff_end;
}

// Map safe sort columns
$sortMap = [
    'date' => 'a.date',
    'time_in' => 'a.time_in',
    'time_out' => 'a.time_out',
    'fullname' => 'e.fullname',
    'status' => 'a.status'
];
$orderBy = $sortMap[$sort_by] ?? 'a.date';
$orderDir = $sort_dir;

// Build WHERE clause and bind params
$where = " WHERE 1=1 AND e.face_enrolled = 1 ";
$params = []; $types = '';

if ($emp !== null) { $where .= " AND a.employee_id = ? "; $params[] = $emp; $types .= 's'; }
if ($startDate !== null && $endDate !== null) { $where .= " AND a.date BETWEEN ? AND ? "; $params[] = $startDate; $params[] = $endDate; $types .= 'ss'; }

// Summary SQL (no pagination)
$summarySql = "
  SELECT 
    COUNT(*) AS total_rows,
    COUNT(DISTINCT a.date) AS total_working_days,
    SUM(CASE WHEN s.shift_start IS NOT NULL AND a.time_in IS NOT NULL AND a.time_in > CONCAT(a.date,' ',s.shift_start) THEN 1 ELSE 0 END) AS total_late_entries,
    SUM(CASE WHEN s.shift_end IS NOT NULL AND a.time_out IS NOT NULL AND a.time_out < CONCAT(a.date,' ',s.shift_end) THEN 1 ELSE 0 END) AS total_early_timeouts,
    SUM(CASE WHEN a.time_out IS NULL THEN 1 ELSE 0 END) AS total_missing_timeouts,
    SUM(COALESCE(TIMESTAMPDIFF(SECOND, a.time_in, a.time_out),0)) AS total_work_seconds
  FROM attendance a
  JOIN employees e ON e.employee_id = a.employee_id
  LEFT JOIN shifts s ON s.employee_id = e.id AND s.shift_date = a.date
  {$where}
";
$st = $conn->prepare($summarySql);
if (!$st) { log_debug("Prepare failed summary: " . $conn->error); http_response_code(500); echo json_encode(['error'=>'DB error']); exit(); }
if (count($params) > 0) {
    $bind = array_merge([$types], $params);
    $refs = [];
    foreach ($bind as $k => $v) $refs[$k] = &$bind[$k];
    call_user_func_array([$st, 'bind_param'], $refs);
}
if (!$st->execute()) { log_debug("Execute failed summary: " . $st->error); $st->close(); http_response_code(500); echo json_encode(['error'=>'DB error']); exit(); }
$res = $st->get_result();
$summaryRow = $res->fetch_assoc() ?: [];
$st->close();

$total_rows = (int)($summaryRow['total_rows'] ?? 0);

// Paginated rows
$offset = ($page - 1) * $per_page;
$dataSql = "
  SELECT a.id, a.employee_id, a.date, a.time_in, a.time_out, a.status, a.method, a.ip_in, a.ip_out, a.created_at,
         e.fullname AS fullname_display, e.employee_id AS emp_code,
         s.shift_type AS assigned_shift_type, s.shift_start, s.shift_end,
         (CASE WHEN s.shift_start IS NOT NULL AND a.time_in IS NOT NULL AND a.time_in > CONCAT(a.date,' ',s.shift_start) THEN 1 ELSE 0 END) AS is_late,
         (CASE WHEN s.shift_end IS NOT NULL AND a.time_out IS NOT NULL AND a.time_out < CONCAT(a.date,' ',s.shift_end) THEN 1 ELSE 0 END) AS is_early,
         (CASE WHEN a.time_out IS NULL THEN 1 ELSE 0 END) AS is_missing_out
  FROM attendance a
  JOIN employees e ON e.employee_id = a.employee_id AND e.face_enrolled = 1
  LEFT JOIN shifts s ON s.employee_id = e.id AND s.shift_date = a.date
  {$where}
  ORDER BY {$orderBy} {$orderDir}
  LIMIT ? OFFSET ?
";
$st2 = $conn->prepare($dataSql);
if (!$st2) { log_debug("Prepare failed data: " . $conn->error); http_response_code(500); echo json_encode(['error'=>'DB error']); exit(); }

// bind params + per_page + offset
$bindParams = $params;
$bindTypes = $types;
$bindParams[] = $per_page; $bindTypes .= 'i';
$bindParams[] = $offset; $bindTypes .= 'i';
$bind = array_merge([$bindTypes], $bindParams);
$refs = [];
foreach ($bind as $k => $v) $refs[$k] = &$bind[$k];
call_user_func_array([$st2, 'bind_param'], $refs);

if (!$st2->execute()) { log_debug("Execute failed data: " . $st2->error); $st2->close(); http_response_code(500); echo json_encode(['error'=>'DB error']); exit(); }
$res2 = $st2->get_result();
$rows = [];
while ($r = $res2->fetch_assoc()) {
    $shift_start = isset($r['shift_start']) && $r['shift_start'] !== null ? substr($r['shift_start'], 0, 5) : null;
    $shift_end   = isset($r['shift_end']) && $r['shift_end'] !== null ? substr($r['shift_end'], 0, 5) : null;
    $rows[] = [
        'id' => (int)$r['id'],
        'employee_id' => $r['employee_id'],
        'emp_code' => $r['emp_code'],
        'fullname_display' => $r['fullname_display'],
        'date' => $r['date'],
        'time_in' => $r['time_in'],
        'time_out' => $r['time_out'],
        'status' => $r['status'],
        'method' => $r['method'],
        'ip_in' => $r['ip_in'],
        'ip_out' => $r['ip_out'],
        'assigned_shift_type' => $r['assigned_shift_type'],
        'shift_start' => $shift_start,
        'shift_end' => $shift_end,
        'created_at' => $r['created_at'],
        'is_late' => (bool)$r['is_late'],
        'is_early' => (bool)$r['is_early'],
        'is_missing_out' => (bool)$r['is_missing_out']
    ];
}
$st2->close();

$buf = '';
if (ob_get_length() !== false && ob_get_length() > 0) $buf = ob_get_clean(); else @ob_end_clean();
if (!empty($buf)) log_debug("Unexpected output captured in attendance_logs_fetch: " . substr($buf,0,2000));

$summary = [
    'total_working_days' => (int)($summaryRow['total_working_days'] ?? 0),
    'total_late_entries' => (int)($summaryRow['total_late_entries'] ?? 0),
    'total_early_timeouts' => (int)($summaryRow['total_early_timeouts'] ?? 0),
    'total_missing_timeouts' => (int)($summaryRow['total_missing_timeouts'] ?? 0),
    'total_work_seconds' => (int)($summaryRow['total_work_seconds'] ?? 0),
];

echo json_encode(['rows'=>$rows, 'total_rows'=>$total_rows, 'summary'=>$summary], JSON_UNESCAPED_UNICODE);
exit();