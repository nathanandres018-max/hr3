<?php
session_start();
header("Content-Type: application/json");

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'HR3 Admin') {
    echo json_encode(["expired" => true]);
} else {
    echo json_encode(["expired" => false]);
}
?>