<?php
// filepath: admin/daily_rollover.php
// Run daily (cron) to finalize/lock previous day's attendance,
// archive if needed, and log the rollover event.

declare(strict_types=1);
require_once(__DIR__ . '/../connection.php');
$yesterday = (new DateTime('yesterday'))->format('Y-m-d');
$LOG = __DIR__ . '/daily_rollover.log';
function logit($m){ global $LOG; @file_put_contents($LOG,'['.date('Y-m-d H:i:s').'] '.(is_string($m)?$m:print_r($m,true)).PHP_EOL, FILE_APPEND|LOCK_EX); }

// 1) Lock previous day's attendance by marking submitted_to_timesheet=1 for all rows not already flagged.
try {
    $stmt = $conn->prepare("UPDATE attendance SET submitted_to_timesheet = 1 WHERE date = ? AND submitted_to_timesheet = 0");
    $stmt->bind_param('s', $yesterday);
    $stmt->execute();
    logit("Locked attendance rows for date {$yesterday}, affected: ".$stmt->affected_rows);
    $stmt->close();
} catch (Exception $e) { logit("Locking failed: ".$e->getMessage()); }

// 2) Archive/move rows if desired (not implemented by default).
// 3) Log rollover event
try {
    $note = json_encode(['action'=>'daily_rollover','date'=>$yesterday]);
    $stmt2 = $conn->prepare("INSERT INTO schedule_logs (employee_id, action, action_time, status, performed_by, details) VALUES (?, ?, NOW(), ?, ?, ?)");
    $dummy_emp = 0; $action = 'Daily Rollover'; $performed_by = 'system';
    $stmt2->bind_param('issss', $dummy_emp, $action, $action, $performed_by, $note);
    $stmt2->execute(); $stmt2->close();
    logit("Daily rollover logged.");
} catch (Exception $e) { logit("Rollover log failed: ".$e->getMessage()); }

echo "OK";
?>