<?php
session_start();
header("Content-Type: application/json");
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");

// Check if session is valid: user logged in, correct role, not timed out (15 min)
$expired = true;
if (
    isset($_SESSION['username']) && !empty($_SESSION['username']) &&
    isset($_SESSION['role']) &&
    ($_SESSION['role'] === 'Benefits Officer' || $_SESSION['role'] === 'HR3 Admin')
) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 900)) {
        // Session timed out
        session_unset();
        session_destroy();
        $expired = true;
    } else {
        $expired = false;
    }
}

echo json_encode(["expired" => $expired]);
?>