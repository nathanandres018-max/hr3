<?php
// daily_rollover.php
// Usage: php daily_rollover.php [--date=YYYY-MM-DD] [--delete-live=0|1]
// Example: php daily_rollover.php --date=2026-02-03 --delete-live=0
// Place in scripts directory and run from cron. Make a backup before first run.

$BASE = __DIR__;
require_once($BASE . '/../connection.php'); // expects $conn (mysqli)
date_default_timezone_set(date_default_timezone_get()); // ensure php.ini timezone set appropriately

// Config
define('ROLLBACK_ON_ERROR', true);
define('ARCHIVE_DELETE_LIVE', false);
define('DEFAULT_SHIFT_LENGTH_HOURS', 8);
define('AUTO_CLOSE_POLICY', 'shift_end_or_shift_start_plus_default'); // or 'time_in_plus_default'

$argv_options = [];
foreach ($argv as $a) {
    if (strpos($a, '--date=') === 0) $argv_options['date'] = substr($a,8);
    if (strpos($a, '--delete-live=') === 0) $argv_options['delete_live'] = intval(substr($a,14));
}

$delete_live = isset($argv_options['delete_live']) ? (bool)$argv_options['delete_live'] : ARCHIVE_DELETE_LIVE;

// Determine rollover date: default yesterday
if (!empty($argv_options['date'])) {
    $rollover_date = DateTime::createFromFormat('Y-m-d', $argv_options['date']);
    if (!$rollover_date) {
        echo "Invalid --date. Use YYYY-MM-DD.\n"; exit(1);
    }
} else {
    $rollover_date = new DateTime('yesterday');
}
$rollover_date_str = $rollover_date->format('Y-m-d');

$lock_file = sys_get_temp_dir() . '/hr3_daily_rollover.lock';
$fp = fopen($lock_file, 'c');
if (!$fp) {
    echo "Unable to open lock file.\n"; exit(1);
}
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo "Another rollover is running. Exiting.\n"; exit(0);
}

$start_ts = (new DateTime())->format('Y-m-d H:i:s');
$run_by = get_current_user();

$log_errors = [];
$log_notes = [];

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception("No mysqli \$conn available from connection.php");
    }

    $conn->begin_transaction();

    $logStmt = $conn->prepare("INSERT INTO daily_rollover_logs (rollover_date, started_at, run_by, notes) VALUES (?, NOW(), ?, '')");
    $logStmt->bind_param('ss', $rollover_date_str, $run_by);
    if (!$logStmt->execute()) throw new Exception("Failed to create rollover log: " . $conn->error);
    $log_id = $conn->insert_id;
    $logStmt->close();

    $sel = $conn->prepare("SELECT a.id, a.employee_id, a.date, a.time_in, a.time_out, a.method, a.created_at,
                                  s.shift_type, s.shift_start, s.shift_end, s.id AS shift_id, e.id AS emp_db_id
                           FROM attendance a
                           LEFT JOIN employees e ON (e.employee_id = a.employee_id)
                           LEFT JOIN shifts s ON s.employee_id = e.id AND s.shift_date = a.date
                           WHERE a.date = ?
                           FOR UPDATE");
    $sel->bind_param('s', $rollover_date_str);
    if (!$sel->execute()) throw new Exception("Failed selecting attendance rows: " . $sel->error);
    $res = $sel->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $sel->close();

    $processed = 0;
    $archived = 0;
    $auto_closed = 0;

    foreach ($rows as $row) {
        $processed++;
        $warnings = [];

        $orig_id = $row['id'];
        $emp_code = $row['employee_id'];
        $time_in = $row['time_in'] ? new DateTime($row['time_in']) : null;
        $time_out = $row['time_out'] ? new DateTime($row['time_out']) : null;
        $shift_start = !empty($row['shift_start']) ? DateTime::createFromFormat('Y-m-d H:i', $row['date'].' '.$row['shift_start']) : null;
        $shift_end   = !empty($row['shift_end'])   ? DateTime::createFromFormat('Y-m-d H:i', $row['date'].' '.$row['shift_end']) : null;
        if ($shift_end && $shift_start && $shift_end <= $shift_start) {
            $shift_end->modify('+1 day');
        }

        if (is_null($time_out) && $time_in) {
            if (AUTO_CLOSE_POLICY === 'shift_end_or_shift_start_plus_default') {
                if ($shift_end) {
                    $time_out = clone $shift_end;
                } elseif ($shift_start) {
                    $to = clone $shift_start;
                    $to->modify('+' . DEFAULT_SHIFT_LENGTH_HOURS . ' hours');
                    $time_out = $to;
                } else {
                    $to = clone $time_in;
                    $to->modify('+' . DEFAULT_SHIFT_LENGTH_HOURS . ' hours');
                    $time_out = $to;
                    $warnings[] = "no_shift_times: used time_in + default";
                }
            } else {
                $to = clone $time_in;
                $to->modify('+' . DEFAULT_SHIFT_LENGTH_HOURS . ' hours');
                $time_out = $to;
                $warnings[] = "auto_closed_by_policy_time_in_plus_default";
            }
            $auto_closed++;
        }

        $work_seconds = 0;
        if ($time_in && $time_out) {
            $work_seconds = max(0, $time_out->getTimestamp() - $time_in->getTimestamp());
        }

        $ins = $conn->prepare("INSERT INTO attendance_archive (original_id, employee_id, date, time_in, time_out, method, created_at, archived_by, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $note_text = implode("; ", $warnings);
        $created_at_val = $row['created_at'] ? $row['created_at'] : null;
        $time_in_val = $time_in ? $time_in->format('Y-m-d H:i:s') : null;
        $time_out_val = $time_out ? $time_out->format('Y-m-d H:i:s') : null;
        $ins->bind_param('issssssis', $orig_id, $emp_code, $row['date'], $time_in_val, $time_out_val, $row['method'], $created_at_val, $run_by, $note_text);
        if (!$ins->execute()) {
            $log_errors[] = "Archive insert failed for attendance.id={$orig_id}: " . $ins->error;
            $ins->close();
            continue;
        }
        $ins->close();
        $archived++;

        $summaryStmt = $conn->prepare("INSERT INTO attendance_daily_summary (employee_id, date, time_in, time_out, work_seconds, created_at)
                                       VALUES (?, ?, ?, ?, ?, NOW())
                                       ON DUPLICATE KEY UPDATE
                                         time_in = LEAST( COALESCE(time_in, ?), COALESCE(?, time_in) ),
                                         time_out = GREATEST( COALESCE(time_out, ?), COALESCE(?, time_out) ),
                                         work_seconds = GREATEST(work_seconds, ?)");
        $time_in_str = $time_in_val;
        $time_out_str = $time_out_val;
        $sum_seconds = (int)$work_seconds;
        $summaryStmt->bind_param('sssssissi', $emp_code, $row['date'], $time_in_str, $time_out_str, $sum_seconds,
                                 $time_in_str, $time_in_str, $time_out_str, $time_out_str, $sum_seconds);
        if (!$summaryStmt->execute()) {
            $log_errors[] = "Summary upsert failed emp={$emp_code} date={$row['date']}: " . $summaryStmt->error;
        }
        $summaryStmt->close();

        if ($delete_live) {
            $del = $conn->prepare("DELETE FROM attendance WHERE id = ?");
            $del->bind_param('i', $orig_id);
            if (!$del->execute()) {
                $log_errors[] = "Failed deleting attendance.id={$orig_id}: " . $del->error;
            }
            $del->close();
        }
    }

    $errors_text = empty($log_errors) ? null : implode("\n", $log_errors);
    $notes_text = empty($log_notes) ? null : implode("\n", $log_notes);

    $upd = $conn->prepare("UPDATE daily_rollover_logs SET processed_rows = ?, archived_rows = ?, auto_closed_rows = ?, errors = ?, notes = ?, finished_at = NOW() WHERE id = ?");
    $processed_int = $processed;
    $archived_int = $archived;
    $auto_closed_int = $auto_closed;
    $upd->bind_param('iiissi', $processed_int, $archived_int, $auto_closed_int, $errors_text, $notes_text, $log_id);
    if (!$upd->execute()) {
        throw new Exception("Failed updating rollover log: " . $conn->error);
    }
    $upd->close();

    $conn->commit();

    echo "Rollover completed for date {$rollover_date_str}. processed={$processed} archived={$archived} auto_closed={$auto_closed}\n";
    if ($errors_text) {
        echo "Errors:\n{$errors_text}\n";
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    exit(0);

} catch (Exception $ex) {
    if (isset($conn) && $conn instanceof mysqli && $conn->in_transaction) {
        $conn->rollback();
    }
    $errmsg = $ex->getMessage();
    if (!empty($log_id) && isset($conn) && ($conn instanceof mysqli)) {
        $errUpd = $conn->prepare("UPDATE daily_rollover_logs SET errors = ?, finished_at = NOW() WHERE id = ?");
        $e_text = $errmsg;
        $errUpd->bind_param('si', $e_text, $log_id);
        $errUpd->execute();
        $errUpd->close();
    }
    echo "Rollover failed: " . $errmsg . "\n";
    @flock($fp, LOCK_UN);
    @fclose($fp);
    exit(1);
}