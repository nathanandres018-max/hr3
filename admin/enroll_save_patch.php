<?php
// --- PATCH: duplicate-check + force override (insert/replace inside enroll_save.php) ---

// read 'force' flag from input (boolean)
$forceFlag = isset($input['force']) && ($input['force'] === true || $input['force'] === '1');

// ... earlier code where $rows = $conn->query($sql); ...

$matched = null; // store first matching existing employee if found

$keyBin = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '') ? hash('sha256', ENCRYPTION_KEY, true) : null;
while ($r = $rows->fetch_assoc()) {
    // skip self (allow updating own template)
    if (isset($r['employee_id']) && $r['employee_id'] === $employee_id) continue;

    $plain = null;
    if ($encCol && isset($r[$encCol]) && $r[$encCol] !== '' && $keyBin) {
        $plain = decrypt_payload($r[$encCol], $keyBin);
        if ($plain === false && $plainCol && isset($r[$plainCol]) && $r[$plainCol] !== '') $plain = $r[$plainCol];
    } elseif ($plainCol && isset($r[$plainCol]) && $r[$plainCol] !== '') {
        $plain = $r[$plainCol];
    }
    if (!$plain) continue;
    $stored = json_decode($plain, true);
    if (!is_array($stored)) { log_debug("Stored template JSON decode failed for emp={$r['employee_id']}"); continue; }
    $stored = array_map('floatval', $stored);
    if (count($stored) !== count($probeArr)) continue;
    $dist = euclidean_distance($probeArr, $stored);

    if ($dist < $DUPLICATE_THRESHOLD) {
        // record the first conflict details
        $matched = [
            'employee_id' => $r['employee_id'],
            'fullname' => $r['fullname'] ?? '',
            'distance' => $dist
        ];
        // If admin explicitly asked to force the operation, allow it but log it.
        if ($forceFlag) {
            log_debug("Duplicate threshold exceeded but forceFlag=true; proceeding. Match: " . json_encode($matched));
            break; // proceed with enrollment/update
        }

        // Otherwise reject with 409 and include match details for inspection
        log_debug("Enrollment rejected: new template too close to existing emp={$r['employee_id']} dist={$dist}");
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'Enrollment too similar to existing employee (possible duplicate)',
            'match' => $matched,
            'note' => 'Run duplicate_check.php or duplicate_scan.php to review similar templates. To override, resend with { "force": true } (HR3 Admin only).'
        ]);
        exit();
    }
}
$rows->free();

// ... continue with enrollment (encrypt/store) ...