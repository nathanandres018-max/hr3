<?php
// filepath: admin/attendance_logs_export.php
// Export attendance logs (CSV and PDF/printable HTML).
// UPDATED: supports bi-monthly payroll cut-off extraction filter.

declare(strict_types=1);
session_start();
require_once(__DIR__ . '/../connection.php');

// Authentication removed intentionally.

$format = isset($_GET['format']) && strtolower($_GET['format']) === 'pdf' ? 'pdf' : 'csv';
$emp = isset($_GET['emp']) && trim($_GET['emp']) !== '' ? trim($_GET['emp']) : null;
$month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : null;
$cutoff_start = isset($_GET['cutoff_start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['cutoff_start']) ? $_GET['cutoff_start'] : null;
$cutoff_end   = isset($_GET['cutoff_end']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['cutoff_end']) ? $_GET['cutoff_end'] : null;

// Validate enrolled employee if provided
if ($emp !== null) {
    $chk = $conn->prepare("SELECT 1 FROM employees WHERE employee_id = ? AND face_enrolled = 1 LIMIT 1");
    if (!$chk) { http_response_code(500); echo "DB error"; exit; }
    $chk->bind_param('s', $emp); $chk->execute();
    $res = $chk->get_result(); $found = (bool)$res->fetch_row(); $chk->close();
    if (!$found) { http_response_code(403); echo "Employee not enrolled"; exit; }
}

// Build date range
$startDate = null; $endDate = null;
if ($month) {
    $startDate = $month . '-01';
    $dt = DateTime::createFromFormat('Y-m-d', $startDate);
    if ($dt !== false) $endDate = $dt->format('Y-m-t'); else { $startDate = null; $endDate = null; }
}
if ($cutoff_start && $cutoff_end) {
    // Use cut-off filter instead of month
    $startDate = $cutoff_start;
    $endDate = $cutoff_end;
}

$sql = "
  SELECT a.date, e.fullname, e.employee_id AS emp_code, a.time_in, a.time_out, a.status, a.method, a.ip_in, a.ip_out, s.shift_type, s.shift_start, s.shift_end
  FROM attendance a
  JOIN employees e ON e.employee_id = a.employee_id AND e.face_enrolled = 1
  LEFT JOIN shifts s ON s.employee_id = e.id AND s.shift_date = a.date
  WHERE 1=1
";
$params = []; $types = '';
if ($emp !== null) { $sql .= " AND a.employee_id = ? "; $params[] = $emp; $types .= 's'; }
if ($startDate !== null && $endDate !== null) { $sql .= " AND a.date BETWEEN ? AND ? "; $params[] = $startDate; $params[] = $endDate; $types .= 'ss'; }
$sql .= " ORDER BY a.date ASC, e.fullname ASC";

$st = $conn->prepare($sql);
if (!$st) { http_response_code(500); echo "DB error"; exit; }
if (count($params) > 0) {
    $bind = array_merge([$types], $params);
    $refs = [];
    foreach ($bind as $k => $v) $refs[$k] = &$bind[$k];
    call_user_func_array([$st, 'bind_param'], $refs);
}
$st->execute();
$res = $st->get_result();

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    $fname = 'attendance_export_' . ($startDate ?? 'all') . '_' . ($endDate ?? 'all') . '.csv';
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date','Name','Emp ID','Time In','Time Out','Status','Method','IP In','IP Out','Shift','Shift Start','Shift End']);
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [
            $r['date'] ?? '',
            $r['fullname'] ?? '',
            $r['emp_code'] ?? '',
            $r['time_in'] ?? '',
            $r['time_out'] ?? '',
            $r['status'] ?? '',
            $r['method'] ?? '',
            $r['ip_in'] ?? '',
            $r['ip_out'] ?? '',
            $r['shift_type'] ?? '',
            $r['shift_start'] ?? '',
            $r['shift_end'] ?? ''
        ]);
    }
    fclose($out);
    $st->close();
    exit;
}

// PDF / printable HTML fallback
$html = '<!doctype html><html><head><meta charset="utf-8"><title>Attendance Export</title>';
$html .= '<style>body{font-family:Arial,sans-serif;font-size:12px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:6px;text-align:left}th{background:#f5f5f5}</style>';
$html .= '</head><body>';
$html .= '<h3>Attendance Export ' . htmlspecialchars((string)($startDate ?? 'All')) . ' to ' . htmlspecialchars((string)($endDate ?? 'All')) . '</h3>';
$html .= '<table><thead><tr><th>Date</th><th>Name</th><th>Emp ID</th><th>Time In</th><th>Time Out</th><th>Status</th><th>Method</th><th>IP In</th><th>IP Out</th><th>Shift</th></tr></thead><tbody>';
while ($r = $res->fetch_assoc()) {
    $date = (string)($r['date'] ?? '');
    $fullname = (string)($r['fullname'] ?? '');
    $emp_code = (string)($r['emp_code'] ?? '');
    $time_in = (string)($r['time_in'] ?? '');
    $time_out = (string)($r['time_out'] ?? '');
    $status = (string)($r['status'] ?? '');
    $method = (string)($r['method'] ?? '');
    $ip_in = (string)($r['ip_in'] ?? '');
    $ip_out = (string)($r['ip_out'] ?? '');
    $shift_type = (string)($r['shift_type'] ?? '');
    $shift_start = (string)($r['shift_start'] ?? '');
    $shift_end = (string)($r['shift_end'] ?? '');

    $shiftLabel = $shift_type;
    if ($shift_start !== '') {
        $shiftLabel .= ' ' . $shift_start;
        if ($shift_end !== '') $shiftLabel .= '–' . $shift_end;
    }

    $html .= '<tr>';
    $html .= '<td>' . htmlspecialchars($date) . '</td>';
    $html .= '<td>' . htmlspecialchars($fullname) . '</td>';
    $html .= '<td>' . htmlspecialchars($emp_code) . '</td>';
    $html .= '<td>' . htmlspecialchars($time_in) . '</td>';
    $html .= '<td>' . htmlspecialchars($time_out) . '</td>';
    $html .= '<td>' . htmlspecialchars($status) . '</td>';
    $html .= '<td>' . htmlspecialchars($method) . '</td>';
    $html .= '<td>' . htmlspecialchars($ip_in) . '</td>';
    $html .= '<td>' . htmlspecialchars($ip_out) . '</td>';
    $html .= '<td>' . htmlspecialchars($shiftLabel) . '</td>';
    $html .= '</tr>';
}
$html .= '</tbody></table>';
$html .= '<p>Generated: ' . htmlspecialchars((string)date('Y-m-d H:i:s')) . '</p></body></html>';
$st->close();

// Attempt wkhtmltopdf if available
$wk = trim((string)shell_exec('which wkhtmltopdf 2>/dev/null'));
if (!empty($wk)) {
    $tmpHtml = tempnam(sys_get_temp_dir(), 'att_html_') . '.html';
    $tmpPdf = tempnam(sys_get_temp_dir(), 'att_pdf_') . '.pdf';
    file_put_contents($tmpHtml, $html);
    $cmd = escapeshellcmd($wk) . ' ' . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
    exec($cmd, $output, $ret);
    if ($ret === 0 && file_exists($tmpPdf)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="attendance_export_' . ($startDate ?? 'all') . '_to_' . ($endDate ?? 'all') . '.pdf"');
        readfile($tmpPdf);
        @unlink($tmpHtml); @unlink($tmpPdf);
        exit;
    } else {
        @unlink($tmpHtml);
        @unlink($tmpPdf);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}