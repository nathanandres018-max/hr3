<?php
require_once(__DIR__ . "/../connection.php");
require_once(__DIR__ . "/../secrets.php");

$employee_id = 'EMP007'; // change
$stmt = $conn->prepare("SELECT face_template_encrypted FROM employees WHERE employee_id = ? LIMIT 1");
$stmt->bind_param('s', $employee_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();
if (!$row || empty($row['face_template_encrypted'])) { echo "No template\n"; exit; }

$parts = explode(':', $row['face_template_encrypted']);
if (count($parts) !== 2) { echo "Bad payload\n"; exit; }
$iv = base64_decode($parts[0]);
$cipher = base64_decode($parts[1]);
$key = hash('sha256', ENCRYPTION_KEY, true);
$plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
echo "Decrypted JSON:\n";
echo $plain . "\n";