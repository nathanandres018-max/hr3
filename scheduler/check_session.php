<?php
session_start();
header("Content-Type: application/json");

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'Schedule Officer') {
    echo json_encode(["expired" => true]);
} else {
    echo json_encode(["expired" => false]);
}
?>