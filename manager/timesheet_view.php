<?php
session_start();
require_once("../includes/db.php");

// === ANTI-BYPASS: Role enforcement for AJAX endpoint ===
if (!isset($_SESSION['username']) || empty($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'HR Manager') {
    http_response_code(403);
    die("Unauthorized");
}
$_SESSION['last_activity'] = time();

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("
    SELECT t.*, e.fullname, e.username
    FROM timesheets t
    JOIN employees e ON t.employee_id = e.id
    WHERE t.id = ?
");
$stmt->execute([$id]);
$ts = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ts) die("Timesheet not found.");

// Fetch attendance logs for this period using the attendance table structure
$stmt2 = $pdo->prepare("
    SELECT * FROM attendance
    WHERE employee_id = ? AND date >= ? AND date <= ?
    ORDER BY date
");
$stmt2->execute([$ts['employee_id'], $ts['period_from'], $ts['period_to']]);
$logs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Timesheet - HR Manager | ViaHale TNVS HR3</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div style="margin-left:220px; padding:2rem;">
    <a href="timesheet_reports.php" class="btn btn-secondary mb-3">&larr; Back to Timesheet Reports</a>
    <h2>Timesheet Details</h2>
    <div class="card mb-4">
        <div class="card-body">
            <strong>Employee:</strong> <?= htmlspecialchars($ts['fullname']) ?> (@<?= htmlspecialchars($ts['username']) ?>)<br>
            <strong>Period:</strong> <?= htmlspecialchars($ts['period_from']) ?> to <?= htmlspecialchars($ts['period_to']) ?><br>
            <strong>Submitted At:</strong> <?= htmlspecialchars($ts['submitted_at']) ?><br>
            <strong>Status:</strong> <?= htmlspecialchars(ucfirst($ts['status'] ?? 'pending')) ?>
        </div>
    </div>
    <div class="card">
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
                        <th>Early Out</th>
                        <th>For HR Review</th>
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
                        <td><?= $log['is_early_out'] ? 'Yes' : 'No' ?><?= $log['early_out_reason'] ? ' - ' . htmlspecialchars($log['early_out_reason']) : '' ?></td>
                        <td><?= $log['for_hr_review'] ? 'Yes' : 'No' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="8" class="text-center">No attendance records for this period.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>