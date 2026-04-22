<?php
session_start();
require_once("../includes/db.php");

// === ANTI-BYPASS: Role enforcement for AJAX endpoint ===
if (!isset($_SESSION['username']) || empty($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'HR3 Admin') {
    http_response_code(403);
    die("Unauthorized");
}
$_SESSION['last_activity'] = time();

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("
    SELECT t.*, e.fullname, e.username, e.employee_id AS emp_code
    FROM timesheets t
    JOIN employees e ON t.employee_id = e.id
    WHERE t.id = ?
");
$stmt->execute([$id]);
$ts = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ts) die("Timesheet not found.");

// Fetch attendance logs for this period using the correct employee code
$stmt2 = $pdo->prepare("
    SELECT * FROM attendance
    WHERE employee_id = ? AND date >= ? AND date <= ?
    ORDER BY date
");
$stmt2->execute([$ts['emp_code'], $ts['period_from'], $ts['period_to']]);
$logs = $stmt2->fetchAll(PDO::FETCH_ASSOC);

function compute_total_time($time_in, $time_out) {
    if (!$time_in || !$time_out) {
        return !$time_out ? 'Invalid' : '';
    }
    
    $start = strtotime($time_in);
    $end = strtotime($time_out);
    if ($start === false || $end === false || $end <= $start) return '';
    
    $seconds = $end - $start;
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}
?>
<div>
    <strong>Employee:</strong> <?= htmlspecialchars($ts['fullname']) ?> (@<?= htmlspecialchars($ts['username']) ?>)<br>
    <strong>Period:</strong> <?= htmlspecialchars($ts['period_from']) ?> to <?= htmlspecialchars($ts['period_to']) ?><br>
    <strong>Submitted At:</strong> <?= htmlspecialchars($ts['submitted_at']) ?><br>
    <strong>Status:</strong> <?= htmlspecialchars(ucfirst($ts['status'] ?? 'pending')) ?>
</div>
<div class="card mt-3">
    <div class="card-header">Attendance Logs</div>
    <div class="card-body p-0">
        <table class="table table-bordered mb-0">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Status</th>
                    <th>IP In</th>
                    <th>IP Out</th>
                    <th>Total Time</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= htmlspecialchars($log['date']) ?></td>
                    <td><?= htmlspecialchars($log['time_in']) ?></td>
                    <td><?= htmlspecialchars($log['time_out']) ?></td>
                    <td><?= htmlspecialchars($log['status']) ?></td>
                    <td><?= htmlspecialchars($log['ip_in'] ?? '') ?></td>
                    <td><?= htmlspecialchars($log['ip_out'] ?? '') ?></td>
                    <td>
                        <?= compute_total_time($log['time_in'], $log['time_out']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?>
                <tr><td colspan="7" class="text-center">No attendance records for this period.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
