<?php
require_once(__DIR__ . "/../connection.php");
require_once('/home/hr3.viahale.com/public_html/secret.php');

header('Content-Type: text/plain');

if (!defined('ENCRYPTION_KEY') || empty(ENCRYPTION_KEY)) {
    echo "ENCRYPTION_KEY missing\n";
    exit;
}
$key = hash('sha256', ENCRYPTION_KEY, true);

// get one employee with encrypted template
if (!isset($conn)) { echo "No DB connection (\$conn missing)\n"; exit; }
if (!($conn instanceof mysqli)) { echo "conn is not mysqli, class=" . (is_object($conn)?get_class($conn):gettype($conn)) . "\n"; exit; }

$res = $conn->query("SELECT employee_id, fullname, face_template_encrypted FROM employees WHERE face_template_encrypted IS NOT NULL AND face_template_encrypted <> '' LIMIT 3");
if (!$res) { echo "Query failed: " . $conn->error . "\n"; exit; }
while ($row = $res->fetch_assoc()) {
    echo "EMP: {$row['employee_id']} - {$row['fullname']}\n";
    $p = $row['face_template_encrypted'];
    echo " payload_len=" . strlen($p) . "\n";
    $parts = explode(':', $p);
    if (count($parts) !== 2) { echo " bad payload format\n\n"; continue; }
    $iv = base64_decode($parts[0]); $cipher = base64_decode($parts[1]);
    if ($iv === false || $cipher === false) { echo " base64 decode failed\n\n"; continue; }
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($plain === false) { echo " openssl_decrypt FAILED\n\n"; continue; }
    echo " decrypted length=" . strlen($plain) . "\n";
    $arr = json_decode($plain, true);
    if (!is_array($arr)) { echo " json decode failed\n\n"; continue; }
    echo " descriptor len=" . count($arr) . "\n\n";
}