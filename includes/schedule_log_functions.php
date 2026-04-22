<?php
function log_schedule_action($pdo, $employee_id, $department, $action, $details, $performed_by, $status, $notes = null) {
    $stmt = $pdo->prepare("INSERT INTO schedule_logs
        (employee_id, department, action, details, performed_by, status, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $employee_id,
        $department,
        $action,
        is_array($details) ? json_encode($details) : $details,
        $performed_by,
        $status,
        $notes
    ]);
}
?>