<?php
// filepath: admin/attendance_fetch.php
// Returns latest attendance rows as JSON for real-time UI polling and debugging.
// Only return attendance rows for enrolled employees who are scheduled for the attendance.date.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$LOG = __DIR__ . '/attendance_fetch.log';
function log_debug($m) {
    global $LOG;
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . (is_string($m) ? $m : print_r($m, true)) . PHP_EOL;
    @file_put_contents($LOG, $entry, FILE_APPEND | LOCK_EX);
}

// capture stray output and suppress display of PHP warnings to client
ob_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once(__DIR__ . '/../connection.php'); // provides $conn (mysqli)

// basic validation of $conn
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    $buf = ob_get_clean();
    if (!empty($buf)) log_debug("Unexpected output before error: " . substr($buf,0,2000));
    log_debug("No valid mysqli \$conn in attendance_fetch.php");
    echo json_encode(['error' => 'Server DB configuration error']);
    exit();
}

// read GET params (defensive)
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
if ($limit <= 0 || $limit > 500) $limit = 30;

$filterEmp = isset($_GET['emp']) && trim($_GET['emp']) !== '' ? trim($_GET['emp']) : null;
$filterDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : null;
$startDate = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date']) ? $_GET['start_date'] : null;
$endDate = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date']) ? $_GET['end_date'] : null;

// Build base SQL. Only include rows for employees that are enrolled and scheduled (JOIN shifts)
$sql = "
  SELECT a.id, a.employee_id, a.date, a.time_in, a.time_out, a.status, a.ip_in, a.ip_out,
         a.submitted_to_timesheet, a.method, a.created_at,
         COALESCE(e.fullname, a.employee_id) AS fullname_display,
         e.employee_id AS emp_code,
         e.id AS emp_db_id,
         s.id AS shift_id,
         s.shift_type AS assigned_shift_type,
         s.shift_date AS assigned_shift_date,
         s.shift_start, s.shift_end
  FROM attendance a
  JOIN employees e ON e.employee_id = a.employee_id AND e.face_enrolled = 1
  JOIN shifts s ON s.employee_id = e.id AND s.shift_date = a.date
  WHERE 1=1
";

$params = [];
$types = '';

if ($filterEmp !== null) {
    $sql .= " AND a.employee_id = ?";
    $params[] = $filterEmp;
    $types .= 's';
}

if ($filterDate !== null) {
    $sql .= " AND a.date = ?";
    $params[] = $filterDate;
    $types .= 's';
} else {
    if ($startDate !== null) {
        $sql .= " AND a.date >= ?";
        $params[] = $startDate;
        $types .= 's';
    }
    if ($endDate !== null) {
        $sql .= " AND a.date <= ?";
        $params[] = $endDate;
        $types .= 's';
    }
}

$sql .= " ORDER BY a.id DESC LIMIT ?";

$params[] = $limit;
$types .= 'i';

// prepare
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    $buf = ob_get_clean();
    if (!empty($buf)) log_debug("Unexpected output before prepare_failed: " . substr($buf,0,2000));
    log_debug("Prepare failed in attendance_fetch.php: " . $conn->error . " SQL: " . $sql);
    echo json_encode(['error' => 'prepare_failed', 'db_error' => $conn->error]);
    exit();
}

// bind params if any
if (count($params) > 0) {
    $bind_names = [];
    $bind_names[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bind_names[] = &$params[$i];
    }
    if (!call_user_func_array([$stmt, 'bind_param'], $bind_names)) {
        $err = $stmt->error;
        $stmt->close();
        $buf = ob_get_clean();
        if (!empty($buf)) log_debug("Unexpected output before bind_failed: " . substr($buf,0,2000));
        log_debug("bind_param failed in attendance_fetch.php: " . $err . " SQL: " . $sql);
        http_response_code(500);
        echo json_encode(['error' => 'bind_failed', 'stmt_error' => $err]);
        exit();
    }
}

// execute
if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    $buf = ob_get_clean();
    if (!empty($buf)) log_debug("Unexpected output before execute_failed: " . substr($buf,0,2000));
    log_debug("Execute failed in attendance_fetch.php: " . $err . " SQL: " . $sql);
    http_response_code(500);
    echo json_encode(['error' => 'execute_failed', 'stmt_error' => $err]);
    exit();
}

// try to use get_result if available (mysqlnd). If not, fall back to bind_result metadata fetch.
$rows = [];
$res = null;
$use_get_result = method_exists($stmt, 'get_result');

if ($use_get_result) {
    $res = $stmt->get_result();
    if ($res === false) {
        $use_get_result = false;
    }
}

if ($use_get_result) {
    while ($r = $res->fetch_assoc()) {
        $shift_start = isset($r['shift_start']) && $r['shift_start'] !== null ? substr($r['shift_start'], 0, 5) : null;
        $shift_end   = isset($r['shift_end'])   && $r['shift_end'] !== null   ? substr($r['shift_end'],   0, 5) : null;

        $rows[] = [
            'id' => isset($r['id']) ? (int)$r['id'] : null,
            'employee_id' => $r['employee_id'] ?? null,
            'emp_code' => $r['emp_code'] ?? ($r['employee_id'] ?? null),
            'emp_db_id' => isset($r['emp_db_id']) ? (int)$r['emp_db_id'] : null,
            'fullname_display' => $r['fullname_display'] ?? ($r['employee_id'] ?? null),
            'date' => $r['date'] ?? null,
            'time_in' => $r['time_in'] ?? null,
            'time_out' => $r['time_out'] ?? null,
            'status' => $r['status'] ?? null,
            'method' => array_key_exists('method', $r) ? $r['method'] : null,
            'ip_in' => array_key_exists('ip_in', $r) ? $r['ip_in'] : null,
            'ip_out' => array_key_exists('ip_out', $r) ? $r['ip_out'] : null,
            'assigned_shift_type' => $r['assigned_shift_type'] ?? null,
            'assigned_shift_date' => $r['assigned_shift_date'] ?? null,
            'shift_start' => $shift_start,
            'shift_end' => $shift_end,
            'shift_id' => isset($r['shift_id']) ? (int)$r['shift_id'] : null,
            'created_at' => $r['created_at'] ?? null
        ];
    }
    $res->free();
} else {
    $meta = $stmt->result_metadata();
    if ($meta) {
        $fields = [];
        $row = [];
        $bindVars = [];
        while ($field = $meta->fetch_field()) {
            $fields[] = $field->name;
            $row[$field->name] = null;
            $bindVars[] = &$row[$field->name];
        }
        $meta->free();

        if (count($bindVars) > 0) {
            if (!call_user_func_array([$stmt, 'bind_result'], $bindVars)) {
                $stmt->close();
                $buf = ob_get_clean();
                if (!empty($buf)) log_debug("Unexpected output before bind_result_failed: " . substr($buf,0,2000));
                log_debug("bind_result failed in attendance_fetch.php");
                http_response_code(500);
                echo json_encode(['error' => 'bind_result_failed']);
                exit();
            }
            while ($stmt->fetch()) {
                $r = [];
                foreach ($row as $k => $v) $r[$k] = $v;

                $shift_start = isset($r['shift_start']) && $r['shift_start'] !== null ? substr($r['shift_start'], 0, 5) : null;
                $shift_end   = isset($r['shift_end']) && $r['shift_end'] !== null ? substr($r['shift_end'], 0, 5) : null;

                $rows[] = [
                    'id' => isset($r['id']) ? (int)$r['id'] : null,
                    'employee_id' => $r['employee_id'] ?? null,
                    'emp_code' => $r['emp_code'] ?? ($r['employee_id'] ?? null),
                    'emp_db_id' => isset($r['emp_db_id']) ? (int)$r['emp_db_id'] : null,
                    'fullname_display' => $r['fullname_display'] ?? ($r['employee_id'] ?? null),
                    'date' => $r['date'] ?? null,
                    'time_in' => $r['time_in'] ?? null,
                    'time_out' => $r['time_out'] ?? null,
                    'status' => $r['status'] ?? null,
                    'method' => array_key_exists('method', $r) ? $r['method'] : null,
                    'ip_in' => array_key_exists('ip_in', $r) ? $r['ip_in'] : null,
                    'ip_out' => array_key_exists('ip_out', $r) ? $r['ip_out'] : null,
                    'assigned_shift_type' => $r['assigned_shift_type'] ?? null,
                    'assigned_shift_date' => $r['assigned_shift_date'] ?? null,
                    'shift_start' => $shift_start,
                    'shift_end' => $shift_end,
                    'shift_id' => isset($r['shift_id']) ? (int)$r['shift_id'] : null,
                    'created_at' => $r['created_at'] ?? null
                ];
            }
        }
    } else {
        log_debug("No result metadata and get_result unavailable for SQL: " . $sql);
    }
}

$stmt->close();

$buf = '';
if (ob_get_length() !== false && ob_get_length() > 0) {
    $buf = ob_get_clean();
} else {
    @ob_end_clean();
}
if (!empty($buf)) {
    log_debug("Unexpected output captured in attendance_fetch: " . substr($buf, 0, 2000));
}

echo json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE);
exit();
?>