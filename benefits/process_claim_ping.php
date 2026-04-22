<?php
// benefits/process_claim_ping.php
include_once(__DIR__ . '/../connection.php');
session_start();
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
  'ok' => true,
  'time' => date('c'),
  'is_logged_in' => isset($_SESSION['username']) ? true : false,
  'username' => $_SESSION['username'] ?? null,
  'role' => $_SESSION['role'] ?? null
], JSON_PRETTY_PRINT);