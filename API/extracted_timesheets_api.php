<?php
// filepath: https://hr3.viahale.com/API/extracted_timesheets_api.php
header("Access-Control-Allow-Origin: *"); 
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

include("../includes/db.php");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Convert JSON POST data to $_POST array
if (
    isset($_SERVER['CONTENT_TYPE']) &&
    (
        $_SERVER['CONTENT_TYPE'] === 'application/json' ||
        (is_string($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
    )
) {
    $json = json_decode(file_get_contents('php://input'), true);
    if (is_array($json)) {
        foreach ($json as $key => $value) {
            $_POST[$key] = $value;
        }
    }
}

// ==================== CREATE: Add extracted timesheet ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $timesheet_id = $_POST['timesheet_id'] ?? null;
    $employee_id = $_POST['employee_id'] ?? null;
    $employee_code = $_POST['employee_code'] ?? '';
    $fullname = $_POST['fullname'] ?? '';
    $period_from = $_POST['period_from'] ?? null;
    $period_to = $_POST['period_to'] ?? null;
    $total_days_worked = $_POST['total_days_worked'] ?? 0;
    $total_hours = $_POST['total_hours'] ?? 0.00;
    $total_minutes = $_POST['total_minutes'] ?? 0;
    $extracted_by = $_POST['extracted_by'] ?? ($_SESSION['fullname'] ?? 'System');
    $status = $_POST['status'] ?? 'pending';
    $notes = $_POST['notes'] ?? null;
    $payload = $_POST['payload'] ?? null;

    $stmt = $pdo->prepare("INSERT INTO extracted_timesheets 
        (timesheet_id, employee_id, employee_code, fullname, period_from, period_to, total_days_worked, total_hours, total_minutes, extracted_by, status, notes, payload, extraction_date) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    
    $success = $stmt->execute([
        $timesheet_id, $employee_id, $employee_code, $fullname, $period_from, $period_to, 
        $total_days_worked, $total_hours, $total_minutes, $extracted_by, $status, $notes, $payload
    ]);

    echo json_encode([
        "success" => $success,
        "message" => $success ? "Extracted timesheet created successfully!" : "Failed to create extracted timesheet.",
        "data" => $success ? ["id" => $pdo->lastInsertId()] : null
    ]);
    exit;
}

// ==================== READ: Get all extracted timesheets ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_all') {
    $stmt = $pdo->prepare("SELECT * FROM extracted_timesheets ORDER BY extraction_date DESC");
    $stmt->execute();
    $timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "count" => count($timesheets),
        "data" => $timesheets
    ]);
    exit;
}

// ==================== READ: Get by ID ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_by_id' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT * FROM extracted_timesheets WHERE id = ?");
    $stmt->execute([$id]);
    $timesheet = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($timesheet) {
        echo json_encode([
            "success" => true,
            "data" => $timesheet
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Extracted timesheet not found."
        ]);
    }
    exit;
}

// ==================== READ: Get by status ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_by_status' && isset($_GET['status'])) {
    $status = $_GET['status'];
    $stmt = $pdo->prepare("SELECT * FROM extracted_timesheets WHERE status = ? ORDER BY extraction_date DESC");
    $stmt->execute([$status]);
    $timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "count" => count($timesheets),
        "data" => $timesheets
    ]);
    exit;
}

// ==================== READ: Get by employee code ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_by_employee' && isset($_GET['employee_code'])) {
    $employee_code = $_GET['employee_code'];
    $stmt = $pdo->prepare("SELECT * FROM extracted_timesheets WHERE employee_code = ? ORDER BY extraction_date DESC");
    $stmt->execute([$employee_code]);
    $timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "count" => count($timesheets),
        "data" => $timesheets
    ]);
    exit;
}

// ==================== READ: Get by date range ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_by_date_range') {
    if (!isset($_GET['start_date']) || !isset($_GET['end_date'])) {
        echo json_encode([
            "success" => false,
            "message" => "Missing start_date or end_date parameters."
        ]);
        exit;
    }

    $start_date = $_GET['start_date'];
    $end_date = $_GET['end_date'];
    $stmt = $pdo->prepare("SELECT * FROM extracted_timesheets WHERE period_from >= ? AND period_to <= ? ORDER BY extraction_date DESC");
    $stmt->execute([$start_date, $end_date]);
    $timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "count" => count($timesheets),
        "data" => $timesheets
    ]);
    exit;
}

// ==================== READ: Get unsent timesheets ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_unsent') {
    $stmt = $pdo->prepare("SELECT * FROM extracted_timesheets WHERE sent_to_api = 0 ORDER BY extraction_date DESC");
    $stmt->execute();
    $timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "count" => count($timesheets),
        "data" => $timesheets
    ]);
    exit;
}

// ==================== READ: Get by original timesheet ID ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_by_timesheet_id' && isset($_GET['timesheet_id'])) {
    $timesheet_id = intval($_GET['timesheet_id']);
    $stmt = $pdo->prepare("SELECT * FROM extracted_timesheets WHERE timesheet_id = ? LIMIT 1");
    $stmt->execute([$timesheet_id]);
    $timesheet = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($timesheet) {
        echo json_encode([
            "success" => true,
            "data" => $timesheet
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Extracted timesheet not found."
        ]);
    }
    exit;
}

// ==================== READ: Get by extracted by ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_by_extracted_by' && isset($_GET['extracted_by'])) {
    $extracted_by = $_GET['extracted_by'];
    $stmt = $pdo->prepare("SELECT * FROM extracted_timesheets WHERE extracted_by = ? ORDER BY extraction_date DESC");
    $stmt->execute([$extracted_by]);
    $timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "count" => count($timesheets),
        "data" => $timesheets
    ]);
    exit;
}

// ==================== READ: Get statistics ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_stats') {
    $stmt = $pdo->query("SELECT 
        COUNT(*) as total_count,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'validated' THEN 1 ELSE 0 END) as validated_count,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
        SUM(CASE WHEN sent_to_api = 1 THEN 1 ELSE 0 END) as sent_count,
        SUM(CASE WHEN sent_to_api = 0 THEN 1 ELSE 0 END) as unsent_count,
        SUM(total_hours) as total_hours,
        SUM(total_days_worked) as total_days_worked
    FROM extracted_timesheets");
    
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "data" => $stats
    ]);
    exit;
}

// ==================== UPDATE: Update extracted timesheet ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $id = $_POST['id'] ?? null;
    
    if (!$id) {
        echo json_encode([
            "success" => false,
            "message" => "Missing ID parameter."
        ]);
        exit;
    }

    // Get existing record
    $stmt = $pdo->prepare("SELECT * FROM extracted_timesheets WHERE id = ?");
    $stmt->execute([$id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Extracted timesheet not found."
        ]);
        exit;
    }

    // Update only provided fields
    $status = $_POST['status'] ?? $existing['status'];
    $notes = $_POST['notes'] ?? $existing['notes'];
    $sent_to_api = $_POST['sent_to_api'] ?? $existing['sent_to_api'];
    $api_response = $_POST['api_response'] ?? $existing['api_response'];
    $sent_at = $_POST['sent_at'] ?? $existing['sent_at'];

    $stmt = $pdo->prepare("UPDATE extracted_timesheets SET status = ?, notes = ?, sent_to_api = ?, api_response = ?, sent_at = ?, updated_at = NOW() WHERE id = ?");
    $success = $stmt->execute([$status, $notes, $sent_to_api, $api_response, $sent_at, $id]);

    echo json_encode([
        "success" => $success,
        "message" => $success ? "Extracted timesheet updated successfully!" : "Failed to update extracted timesheet.",
        "data" => $success ? ["id" => $id] : null
    ]);
    exit;
}

// ==================== DELETE: Delete extracted timesheet ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = $_POST['id'] ?? null;
    
    if (!$id) {
        echo json_encode([
            "success" => false,
            "message" => "Missing ID parameter."
        ]);
        exit;
    }

    // Check if exists
    $stmt = $pdo->prepare("SELECT id FROM extracted_timesheets WHERE id = ?");
    $stmt->execute([$id]);
    
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Extracted timesheet not found."
        ]);
        exit;
    }

    // Delete
    $stmt = $pdo->prepare("DELETE FROM extracted_timesheets WHERE id = ?");
    $success = $stmt->execute([$id]);

    echo json_encode([
        "success" => $success,
        "message" => $success ? "Extracted timesheet deleted successfully!" : "Failed to delete extracted timesheet."
    ]);
    exit;
}

// ==================== DEFAULT: Get all (if no action specified) ====================
$stmt = $pdo->prepare("SELECT * FROM extracted_timesheets ORDER BY extraction_date DESC");
$stmt->execute();
$timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    "success" => true,
    "count" => count($timesheets),
    "data" => $timesheets
]);
?>