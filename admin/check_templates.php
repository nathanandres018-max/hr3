<?php
// filepath: admin/check_templates.php
// Run in browser (admin) to list template lengths and any decode failures.

declare(strict_types=1);
session_start();
require_once(__DIR__ . '/../connection.php');

header('Content-Type: text/plain; charset=utf-8');

if (!isset($conn) || !($conn instanceof mysqli)) { echo "DB connection failed\n"; exit; }

$key = null;
if (file_exists(__DIR__ . '/../secret.php')) { @require_once(__DIR__ . '/../secret.php'); if (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY!=='') $key = hash('sha256', ENCRYPTION_KEY, true); }

function decrypt_payload($payload_b64, $keyBin) {
    if (!$keyBin) return false;
    $parts = explode(':', $payload_b64);
    if (count($parts)!==2) return false;
    $iv = base64_decode($parts[0]); $cipher = base64_decode($parts[1]);
    if ($iv===false||$cipher===false) return false;
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $keyBin, OPENSSL_RAW_DATA, $iv);
    return $plain===false ? false : $plain;
}

echo "Checking enrolled employee templates...\n\n";

$q = "SELECT id, employee_id, fullname, face_template, face_template_encrypted FROM employees WHERE face_enrolled = 1";
if ($res = $conn->query($q)) {
    while ($r = $res->fetch_assoc()) {
        $line = "{$r['employee_id']} | {$r['fullname']} | db_id={$r['id']} => ";
        if (!empty($r['face_template_encrypted'])) {
            $plain = decrypt_payload($r['face_template_encrypted'], $key);
            if ($plain === false) { $line .= "encrypted: decrypt_failed\n"; echo $line; continue; }
            $decoded = json_decode($plain, true);
            if (!is_array($decoded)) { $line .= "encrypted: json_decode_failed\n"; echo $line; continue; }
            $line .= "encrypted: len=" . count($decoded) . "\n";
            echo $line;
            continue;
        } elseif (!empty($r['face_template'])) {
            $decoded = json_decode($r['face_template'], true);
            if (!is_array($decoded)) { $line .= "plain: json_decode_failed\n"; echo $line; continue; }
            $line .= "plain: len=" . count($decoded) . "\n";
            echo $line;
            continue;
        } else {
            $line .= "no_template\n";
            echo $line;
            continue;
        }
    }
    $res->free();
}
echo "\nDone.\n";
?>